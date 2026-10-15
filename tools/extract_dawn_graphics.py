#!/usr/bin/env python3
"""Decode user-supplied US Digimon World Dawn graphics; never ships a ROM.

Python 3.10+ and Pillow. All numeric source IDs are preserved. Battle names are
read from the ROM's own name table and chrsize.bin, not an Internet sprite list.
Usage: python tools/extract_dawn_graphics.py /path/to/DigimonDawn.nds
"""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import struct
from pathlib import Path
from PIL import Image, ImageDraw

SHAPES = [(8,8),(16,16),(32,32),(64,64),(16,8),(32,8),(32,16),(64,32),(8,16),(8,32),(16,32),(32,64)]
# Visually inspected human appearances. Other early MCHR slots are duplicate
# animation sets, debug outlines, placeholder silhouettes, effects, or Digimon.
TAMER_IDS = list(range(2,6)) + list(range(14,40)) + list(range(44,70)) + list(range(82,90))

def u16(d, p=0): return struct.unpack_from('<H', d, p)[0]
def u32(d, p=0): return struct.unpack_from('<I', d, p)[0]
def write_json(path, data): path.write_text(json.dumps(data, indent=2, ensure_ascii=False)+'\n', encoding='utf-8')

def unpack_data(d):
    if not d or d[0] not in (0x30,0x10): return d
    n=int.from_bytes(d[1:4], 'little'); out=bytearray(); i=4
    if n > 64*1024*1024: raise ValueError('Invalid decompression size')
    if d[0] == 0x30:
        while len(out)<n:
            tag=d[i]; i+=1
            if tag&128: out.extend([d[i]]*((tag&127)+3)); i+=1
            else: out.extend(d[i:i+tag+1]); i+=tag+1
    else:
        while len(out)<n:
            flags=d[i]; i+=1
            for bit in range(7,-1,-1):
                if len(out)>=n: break
                if flags&(1<<bit):
                    a,z=d[i:i+2];i+=2;count=(a>>4)+3;distance=((a&15)<<8|z)+1
                    for _ in range(count):out.append(out[-distance])
                else:out.append(d[i]);i+=1
    if len(out)<n:raise ValueError('Incomplete compressed data')
    return bytes(out[:n])

class Rom:
    def __init__(self, path):
        self.data=Path(path).read_bytes(); self.sha256=hashlib.sha256(self.data).hexdigest()
        if self.data[12:16]!=b'A3VE':raise ValueError('Supported source is US Digimon World Dawn (A3VE).')
        fnt,_,fat,_=struct.unpack_from('<IIII',self.data,0x40);self.files={}
        def walk(did, prefix):
            offset,fid,_=struct.unpack_from('<IHH',self.data,fnt+(did&4095)*8);p=fnt+offset
            while self.data[p]:
                n=self.data[p];p+=1;name=self.data[p:p+(n&127)].decode('ascii');p+=n&127
                if n&128:sub=u16(self.data,p);p+=2;walk(sub,prefix+'/'+name)
                else:
                    start,end=struct.unpack_from('<II',self.data,fat+fid*8)
                    if not 0<=start<=end<=len(self.data):raise ValueError('Invalid NitroFS boundary')
                    self.files[prefix+'/'+name]={'id':fid,'offset':start,'size':end-start};fid+=1
        walk(0xf000,'')
    def read(self,name,decode=False):
        m=self.files[name];d=self.data[m['offset']:m['offset']+m['size']]
        return unpack_data(d) if decode else d
    def pak(self,name):
        d=self.read('/dat/'+name+'.PAK');records=[]
        for i in range(u32(d)):
            off,size=struct.unpack_from('<II',d,4+i*8);raw=d[off:off+(size&0x7fffffff)]
            records.append(unpack_data(raw) if size&0x80000000 else raw)
        return records
    def species_names(self):
        # This address is validated against multiple known table entries before
        # use. Fail closed for a different ROM revision instead of mislabelling.
        base=0x10a6b4;count=u32(self.data,base)//4;names={}
        if not 800<count<2000:raise ValueError('Unrecognized species name table')
        for i in range(count):
            p=base+u32(self.data,base+i*4);chars=[]
            for _ in range(80):
                v=u16(self.data,p);p+=2
                if v==65535:break
                if 32<=v<=57:chars.append(chr(v+33))
                elif 58<=v<=83:chars.append(chr(v+39))
                elif v==0:chars.append(' ')
                else:chars.append({0x0e:"'",0x18:'-',0x19:'=',0x12:'.',0x15:'(',0x16:')'}.get(v,'?'))
            names[i]=''.join(chars)
        if names.get(65)!='Chicchimon' or names.get(98)!='Agumon' or names.get(99)!='Veemon':raise ValueError('Species name table validation failed')
        return names

def colors_bgr555(raw, transparent_every=16):
    return [((v&31)*255//31,((v>>5)&31)*255//31,((v>>10)&31)*255//31,0 if j%transparent_every==0 else 255)
            for j,(v,) in enumerate(struct.iter_unpack('<H',raw[:len(raw)//2*2]))]

def tiles_image(raw, palette, width, height, bpp=4):
    im=Image.new('RGBA',(width,height));pixels=im.load()
    if width%8 or height%8:raise ValueError('Tile dimensions must be aligned to eight pixels')
    for y in range(height):
        for x in range(width):
            ti=(y//8)*(width//8)+x//8;pixel=ti*64+(y%8)*8+x%8
            if bpp==8:ci=raw[pixel] if pixel<len(raw) else 0
            else:ci=(raw[pixel//2]>>(4 if pixel%2 else 0))&15 if pixel//2<len(raw) else 0
            if ci<len(palette):pixels[x,y]=palette[ci]
    return im

def mchr_frame(chars,pals,anms,index,frame=0):
    d=chars[index];count,size=struct.unpack_from('<II',d);shape=u16(anms[index]);w,h=SHAPES[shape]
    if not 0<=frame<count:frame=0
    return tiles_image(d[8+frame*size:8+(frame+1)*size],colors_bgr555(pals[index]),w,h)

def mchr_sequences(d):
    sequences=[];current=[]
    for p in range(10,len(d)-13,14):
        row=struct.unpack_from('<7h',d,p)
        if row==(-1,)*7:
            sequences.append(current);current=[]
            if len(sequences)==16:break
        else:current.append(row)
    return sequences

def nitro_palette(d):
    end=16+u32(d,20);values=colors_bgr555(d[40:end]);idx=d.find(b'PMCP');banks=list(range((len(values)+15)//16))
    if idx>=0:banks=list(struct.unpack_from('<'+'H'*u16(d,idx+8),d,idx+16))
    result=[(0,0,0,0)]*4096
    for j,color in enumerate(values):
        bank=banks[j//16] if j//16<len(banks) else j//16
        if bank*16+j%16<len(result):result[bank*16+j%16]=color
    return result

def battle_frame(records,index,frame=0):
    ch,pa,ce=records[index*5+1:index*5+4]
    if ch[:4]!=b'RGCN' or pa[:4]!=b'RLCN' or ce[:4]!=b'RECN':raise ValueError('Unexpected battle record structure')
    data=ch[48:48+u32(ch,40)];palette=colors_bgr555(pa[40:16+u32(pa,20)],256)
    count,flag=struct.unpack_from('<HH',ce,24);start=24+u32(ce,28);entrysize=16 if flag&1 else 8
    if frame>=count:frame=0
    num,_,offset=struct.unpack_from('<HHI',ce,start+frame*entrysize);objects=[]
    for j in range(num):
        aa,bb,cc=struct.unpack_from('<HHH',ce,start+count*entrysize+offset+j*6)
        x=bb&511;y=aa&255;x=x if x<256 else x-512;y=y if y<128 else y-256
        w,h=SHAPES[(aa>>14)*4+(bb>>14)];pos=(cc&1023)*(32<<u32(ce,32))
        bpp=8 if aa&0x2000 else 4;pal=palette if bpp==8 else palette[(cc>>12)*16:(cc>>12)*16+16]
        image=tiles_image(data[pos:pos+w*h*bpp//8],pal,w,h,bpp)
        if bb&0x1000:image=image.transpose(Image.Transpose.FLIP_LEFT_RIGHT)
        if bb&0x2000:image=image.transpose(Image.Transpose.FLIP_TOP_BOTTOM)
        objects.append((x,y,image))
    left=min(x for x,y,im in objects);top=min(y for x,y,im in objects)
    right=max(x+im.width for x,y,im in objects);bottom=max(y+im.height for x,y,im in objects)
    image=Image.new('RGBA',(right-left,bottom-top))
    for x,y,part in reversed(objects):image.alpha_composite(part,(x-left,y-top))
    box=image.getbbox()
    return image.crop(box) if box else image

def screen_image(ch,pa,sc,custom=False):
    if custom:
        w,h=struct.unpack_from('<HH',sc);tiles=ch[4:];palette=colors_bgr555(pa[4:]);screen=sc[4:];block_order=False
    else:
        w,h=struct.unpack_from('<HH',sc,24);tiles=ch[48:48+u32(ch,40)];palette=nitro_palette(pa);screen=sc[36:];block_order=w>=256 and h>=256
    if not 0<w<=4096 or not 0<h<=4096 or len(screen)<w*h//32:raise ValueError('Invalid screen dimensions')
    image=Image.new('RGBA',(w,h));cache={}
    for y in range(h//8):
        for x in range(w//8):
            at=((y//32)*(w//256)+x//32)*1024+(y%32)*32+x%32 if block_order else y*(w//8)+x
            value=u16(screen,at*2)
            if value not in cache:
                ti=value&1023;bank=value>>12;raw=tiles[ti*32:ti*32+32]
                tile=tiles_image(raw,palette[bank*16:bank*16+16],8,8)
                if value&1024:tile=tile.transpose(Image.Transpose.FLIP_LEFT_RIGHT)
                if value&2048:tile=tile.transpose(Image.Transpose.FLIP_TOP_BOTTOM)
                cache[value]=tile
            image.paste(cache[value],(x*8,y*8))
    return image

def normalize(text):return re.sub('[^a-z0-9]','',text.lower())

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('rom',type=Path);parser.add_argument('--project',type=Path,default=Path(__file__).resolve().parents[1]);parser.add_argument('--skip-maps',action='store_true');args=parser.parse_args()
    rom=Rom(args.rom);root=args.project;public=root/'public_html';assets=public/'assets/dawn'
    for folder in ['tamers','characters','digimon','backgrounds','maps','ui']: (assets/folder).mkdir(parents=True,exist_ok=True)
    manifest={'schema':1,'source':{'title':'Digimon World Dawn (USA)','game_code':'A3VE','sha256':rom.sha256,'nitrofs_files':len(rom.files)},'tamers':{},'digimon':[],'backgrounds':[],'maps':[],'ui':[],'roster_mapping':[],'errors':[]}
    chars=rom.pak('MCHR_CHR');pals=rom.pak('MCHR_PAL');anms=rom.pak('MCHR_ANM');records=rom.pak('BTCHR');names=rom.species_names()
    print('Decoded source archives; extracting characters.',flush=True)
    characters=[]
    for i in range(len(chars)):
        im=mchr_frame(chars,pals,anms,i);path=f'assets/dawn/characters/character_{i:03d}.png';im.save(public/path)
        characters.append({'source_id':i,'sprite':path,'frames':u32(chars[i]),'shape':u16(anms[i]),'width':im.width,'height':im.height})
    write_json(assets/'characters/catalog.json',characters)
    for i in TAMER_IDS:
        sequences=mchr_sequences(anms[i]);image=mchr_frame(chars,pals,anms,i);w,h=image.size
        sheet=Image.new('RGBA',(w*3,h*4));direction_groups=[6,2,5,0]
        for row,group in enumerate(direction_groups):
            idle=sequences[group] if len(sequences)>group else []
            walk=sequences[group+8] if len(sequences)>group+8 else idle
            unique=[]
            for record in walk:
                if record[5]>=0 and (record[5],record[4]) not in [(r[5],r[4]) for r in unique]:unique.append(record)
            if not unique:unique=idle
            for col in range(3):
                rec=unique[col%len(unique)] if unique else (0,0,0,0,0,0,0)
                frame=mchr_frame(chars,pals,anms,i,rec[5])
                if rec[4]&1:frame=frame.transpose(Image.Transpose.FLIP_LEFT_RIGHT)
                sheet.alpha_composite(frame,(col*w,row*h))
        key=f'dawn_{i:03d}';path=f'assets/dawn/tamers/{key}.png';sheetpath=f'assets/dawn/tamers/{key}_walk.png'
        sheet.crop((0,0,w,h)).save(public/path);sheet.save(public/sheetpath)
        name={2:'Dawn Hero',3:'Dawn Heroine',4:'Dusk Hero',5:'Dusk Heroine'}.get(i,f'Dawn Tamer {TAMER_IDS.index(i)+1:02d}')
        manifest['tamers'][key]={'id':key,'numeric_id':100+i,'name':name,'source_character_id':i,'sprite':path,'sheet':sheetpath,'frame_width':w,'frame_height':h,'directions':['down','right','left','up'],'frames_per_direction':3}
    print('Extracting battle sprites with verified ROM name mapping.',flush=True)
    sizes=rom.read('/dat/btchr/chrsize.bin');images={}
    for i in range(len(records)//5):
        sid=u16(sizes,i*4);name=names.get(sid,'');im=battle_frame(records,i);path=f'assets/dawn/digimon/{sid:03d}_{i:03d}.png';im.save(public/path)
        item={'rom_species_id':sid,'battle_index':i,'name':name or ('Digi-Egg' if sid==0 else f'Unnamed ROM battle record {i}'),'source_name_verified':bool(name) or sid==0,'visible_pixels':im.getbbox() is not None,'sprite':path,'width':im.width,'height':im.height,'mapping_evidence':'chrsize.bin index + ARM9 species name table'}
        manifest['digimon'].append(item)
        if name and '?' not in name:images.setdefault(normalize(name),[]).append((item,im))
    species=json.loads((public/'config/digimon_species.json').read_text());sources=json.loads((public/'config/digimon_sprite_sources.json').read_text());source_by_slug={m['slug']:m for m in sources}
    for species_row in species:
        if species_row.get('variant')!='normal':continue
        matches={}
        for field in ['name','asset_name','reference_name','slug']:
            candidates=images.get(normalize(species_row.get(field,'')),[])
            if len(candidates)==1:
                entry,image=candidates[0];matches[entry['rom_species_id']]=(entry,image,field)
        if len(matches)!=1:continue # ambiguous/source-only forms are never guessed
        entry,im,matched_field=next(iter(matches.values()));scale=max(1,min(224//im.width,224//im.height));sized=im.resize((im.width*scale,im.height*scale),Image.Resampling.NEAREST)
        if sized.width>240 or sized.height>240:sized.thumbnail((240,240),Image.Resampling.NEAREST)
        canvas=Image.new('RGBA',(256,256));canvas.alpha_composite(sized,((256-sized.width)//2,(256-sized.height)//2));target=public/'html/static'/species_row['sprite'];canvas.save(target)
        mapping={'slug':species_row['slug'],'name':species_row['name'],'rom_species_id':entry['rom_species_id'],'battle_index':entry['battle_index'],'source_sprite':entry['sprite'],'output':'html/static/'+species_row['sprite'],'method':'exact normalized equality against existing roster '+matched_field};manifest['roster_mapping'].append(mapping)
        source_by_slug[species_row['slug']].update({'source':'Digimon World Dawn USA /dat/BTCHR.PAK record '+str(entry['battle_index']*5+1),'source_sha256':rom.sha256,'rom_species_id':entry['rom_species_id'],'battle_index':entry['battle_index'],'mode':'ROM Nitro NCGR/NCLR/NCER decode; nearest-neighbor centered','size':[256,256]})
    write_json(public/'config/digimon_sprite_sources.json',sources)
    print('Extracting original battle backgrounds and UI screens.',flush=True)
    for name in sorted(rom.files):
        m=re.fullmatch(r'/dat/btmap/(\d+)ac',name)
        if not m:continue
        index=int(m.group(1));prefix=f'/dat/btmap/{index}'
        try:
            im=screen_image(*(rom.read(prefix+s,True) for s in ['ac','ap','as']));path=f'assets/dawn/backgrounds/battle_{index:03d}.png';im.save(public/path)
            manifest['backgrounds'].append({'source_id':index,'sprite':path,'width':im.width,'height':im.height,'source':prefix+'a*','layer':'base'})
        except (KeyError,ValueError,IndexError,struct.error) as e:manifest['errors'].append({'source':prefix,'reason':str(e)})
    for name in sorted(rom.files):
        if not name.startswith('/dat/bg/') or not name.endswith('.NCGR'):continue
        prefix=name[:-5]
        if prefix+'.NCLR' not in rom.files or prefix+'.NSCR' not in rom.files:continue
        try:
            im=screen_image(*(rom.read(prefix+s,True) for s in ['.NCGR','.NCLR','.NSCR']));path='assets/dawn/ui/'+Path(prefix).name+'.png';im.save(public/path)
            manifest['ui'].append({'source':prefix,'sprite':path,'width':im.width,'height':im.height})
        except (KeyError,ValueError,IndexError,struct.error) as e:manifest['errors'].append({'source':prefix,'reason':str(e)})
    if not args.skip_maps:
        print('Extracting map graphics (visual layers only; no guessed collision).',flush=True)
        for name in sorted(rom.files):
            m=re.fullmatch(r'/dat/map/(\d+)([ab])\.c',name)
            if not m:continue
            prefix=name[:-2]
            if prefix+'.p' not in rom.files or prefix+'.s' not in rom.files:continue
            try:
                im=screen_image(*(rom.read(prefix+s,True) for s in ['.c','.p','.s']),custom=True);path=f'assets/dawn/maps/map_{int(m.group(1)):03d}_{m.group(2)}.png';im.save(public/path)
                manifest['maps'].append({'source_id':int(m.group(1)),'layer':m.group(2),'sprite':path,'width':im.width,'height':im.height,'source':prefix})
            except (KeyError,ValueError,IndexError,struct.error) as e:manifest['errors'].append({'source':prefix,'reason':str(e)})
    manifest['coverage']={'character_graphics':len(characters),'selectable_human_tamers':len(manifest['tamers']),'battle_sprite_records':len(manifest['digimon']),'named_battle_species':sum(1 for x in manifest['digimon'] if x['source_name_verified'] and x['rom_species_id']!=0),'visible_battle_sprites':sum(1 for x in manifest['digimon'] if x['visible_pixels']),'matched_normal_roster_species':len(manifest['roster_mapping']),'total_roster_forms':len(species),'battle_backgrounds':len(manifest['backgrounds']),'map_layers':len(manifest['maps']),'ui_screens':len(manifest['ui']),'decode_errors':len(manifest['errors'])}
    manifest['limitations']=['Dawn and Cyber Sleuth have different rosters; forms without an unambiguous exact ROM name match to an existing roster name or explicit alias retain baseline artwork.','Fan-made paradox forms retain their baseline artwork.','Dawn World v0.7 uses the supplied upscaled map layers and original ROM collision through its separate world manifest; original story event scripts, moving platforms and warp conditions are not ported.','The tamer catalog excludes debug silhouettes/outlines, duplicate protagonist action sets, effects, and non-human characters.','Neutral numbered tamer labels are used where original character identity has not been verified.','Extracted UI screens are reference assets; the browser interface remains responsive HTML.','Numeric graphic source IDs are preserved; location names are not inferred.']
    write_json(public/'config/dawn_assets.json',manifest);write_json(root/'docs/DAWN_GRAPHICS_AUDIT.json',manifest)
    # A compact human QA sheet is reproducible and useful when checking releases.
    contact=Image.new('RGBA',(768,((len(TAMER_IDS)+11)//12)*112),'#172539');draw=ImageDraw.Draw(contact)
    for j,(key,item) in enumerate(manifest['tamers'].items()):
        image=Image.open(public/item['sprite']).convert('RGBA');image=image.resize((image.width*2,image.height*2),Image.Resampling.NEAREST);x=j%12*64;y=j//12*112
        contact.alpha_composite(image,(x+(64-image.width)//2,y+18));draw.text((x+4,y),str(item['numeric_id']),fill='white')
    contact.save(root/'docs/DAWN_TAMER_CONTACT_SHEET.png')
    print(json.dumps(manifest['coverage'],indent=2))

if __name__=='__main__':main()
