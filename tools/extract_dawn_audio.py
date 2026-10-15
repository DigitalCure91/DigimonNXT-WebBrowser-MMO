#!/usr/bin/env python3
"""Extract and render authentic Dawn SDAT music/effects with the original banks.

Requires Python 3, ffmpeg (libvorbis), and g++/clang++. The checked-in renderer
sources are offline; no third-party soundfont or internet access is needed.
Usage: python tools/extract_dawn_audio.py '/path/to/Digimon World - Dawn (USA).nds'
"""
from __future__ import annotations
import argparse
import concurrent.futures
import hashlib
import json
import os
from pathlib import Path
import shutil
import struct
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]

def u32(data, offset): return struct.unpack_from('<I', data, offset)[0]
def sha(data): return hashlib.sha256(data).hexdigest()
def cstr(data, offset): return data[offset:data.index(0, offset)].decode('ascii')

def extract(rom_path: Path, jobs: int, limit: int | None):
    rom = rom_path.read_bytes()
    candidates=[]; cursor=0
    while (cursor := rom.find(b'SDAT\xff\xfe\x00\x01', cursor)) >= 0:
        length=u32(rom,cursor+8)
        if 64 <= length <= len(rom)-cursor:
            blob=rom[cursor:cursor+length]
            if all(0 < u32(blob,header) < length-8 and blob[u32(blob,header):u32(blob,header)+4] == magic for header,magic in ((24,b'INFO'),(32,b'FAT '))):
                candidates.append((cursor,blob))
        cursor+=4
    if len(candidates)!=1: raise ValueError(f'Expected one SDAT, found {len(candidates)}')
    rom_offset,sdat=candidates[0]
    archive_hash=sha(sdat)
    renderer_sources=[ROOT/'tools/render_dawn_audio.cpp',*sorted((ROOT/'tools/third_party/SSEQPlayer').glob('*.cpp')),*sorted((ROOT/'tools/third_party/SSEQPlayer').glob('*.h'))]
    renderer_hash=sha(b''.join(p.read_bytes() for p in renderer_sources))
    symb,info,fat = (u32(sdat,n) for n in (16,24,32))
    def names(kind):
        table=symb+u32(sdat,symb+8+kind*4)
        return [cstr(sdat,symb+u32(sdat,table+4+i*4)) for i in range(u32(sdat,table))]
    def info_entry(kind,index):
        table=info+u32(sdat,info+8+kind*4)
        return info+u32(sdat,table+4+index*4)
    def file_data(file_id):
        entry=fat+12+file_id*16
        start,length=struct.unpack_from('<II',sdat,entry)
        return sdat[start:start+length]
    bgm_names=names(0)
    source=ROOT/'tools/dawn_source'; source.mkdir(parents=True,exist_ok=True)
    (source/'sound_data.sdat').write_bytes(sdat)
    raw=source/'sequences'; raw.mkdir(exist_ok=True)
    out=ROOT/'public_html/assets/dawn/audio'; out.mkdir(parents=True,exist_ok=True)
    tasks=[]
    for index,name in enumerate(bgm_names):
        entry=info_entry(0,index); fid=u32(sdat,entry); seq=file_data(fid)
        (raw/f'{name}.sseq').write_bytes(seq)
        tasks.append({'id':name,'kind':'music','sequence_index':index,'bank':struct.unpack_from('<H',sdat,entry+4)[0],
                      'sequence_sha256':sha(seq),'sdat':sdat,'start':0,'max_seconds':240})
    arc=file_data(u32(sdat,info_entry(1,0))); (raw/'SEQ_SE.ssar').write_bytes(arc)
    sequence_offset,count=struct.unpack_from('<II',arc,24)
    payload=arc[sequence_offset:]
    # SSEQPlayer consumes SSEQ. Wrap the unchanged SSAR event payload in an
    # SSEQ container; retain its original absolute branch offsets and start PC.
    wrapped=struct.pack('<4sHHIHH4sII',b'SSEQ',0xfeff,0x100,28+len(payload),16,1,b'DATA',12+len(payload),28)+payload
    for index in range(count):
        entry=32+12*index
        start,bank,volume,priority,player_priority,player=struct.unpack_from('<IHBBBB',arc,entry)
        if start==0xffffffff: continue
        custom=bytearray(sdat)
        first=info_entry(0,0); first_fid=u32(sdat,first)
        struct.pack_into('<H',custom,first+4,bank); custom[first+6]=volume
        struct.pack_into('<II',custom,fat+12+first_fid*16,len(custom),len(wrapped))
        custom.extend(wrapped);struct.pack_into('<I',custom,8,len(custom))
        tasks.append({'id':f'se{index:03d}','kind':'effect','sequence_index':index,'bank':bank,
                      'sequence_sha256':sha(arc[entry:entry+12]+payload),'sdat':bytes(custom),'start':start,'max_seconds':15})
    if limit is not None: tasks=tasks[:limit]
    compiler=shutil.which('g++') or shutil.which('clang++')
    if not compiler or not shutil.which('ffmpeg'): raise RuntimeError('Install g++ or clang++, and ffmpeg first.')
    with tempfile.TemporaryDirectory(prefix='dawn_audio_') as tmp:
        tmp=Path(tmp);renderer=tmp/('dawn_renderer.exe' if os.name=='nt' else 'dawn_renderer')
        subprocess.run([compiler,'-std=c++11','-O2',str(ROOT/'tools/render_dawn_audio.cpp'),
                        *map(str,sorted((ROOT/'tools/third_party/SSEQPlayer').glob('*.cpp'))),'-o',str(renderer)],check=True)
        def render(task):
            name=task['id']; raw_sdat=tmp/f'{name}.sdat'; pcm=tmp/f'{name}.pcm'; meta=tmp/f'{name}.json'
            raw_sdat.write_bytes(task['sdat'])
            cached=source/'render_metadata'/f'{name}.json'
            if cached.exists() and (out/f'{name}.ogg').exists():
                previous=json.loads(cached.read_text())
                if previous.get('source_archive_sha256')==archive_hash and previous.get('renderer_source_sha256')==renderer_hash and previous.get('sha256')==sha((out/f'{name}.ogg').read_bytes()):
                    return previous
            result=subprocess.run([str(renderer),str(raw_sdat),str(task['sequence_index'] if task['kind']=='music' else 0),
                                   str(pcm),str(meta),str(task['start']),str(task['max_seconds'])],capture_output=True,text=True,timeout=240)
            if result.returncode not in (0,3):
                if 'zero_time_sequence_loop' in result.stderr:
                    entry={k:v for k,v in task.items() if k not in ('sdat','start','max_seconds')}
                    entry.update({'status':'source_zero_time_loop','note':'Original SSAR entry preserved. An infinite zero-duration note loop cannot be exported as a finite audio cue.'})
                    print(f'{name}: source_zero_time_loop',flush=True)
                    return entry
                raise RuntimeError(f'{name}: {result.stderr}')
            metadata=json.loads(meta.read_text())
            entry={k:v for k,v in task.items() if k not in ('sdat','start','max_seconds')}
            entry.update(metadata)
            entry.update({'source_archive_sha256':archive_hash,'renderer_source_sha256':renderer_hash})
            if result.returncode==3:
                entry['status']='silent_source_sequence'
            else:
                target=out/f'{name}.ogg'
                # Preserve all notes. Nonlooping effects only lose terminal digital silence.
                filters=['atrim=end='+str(metadata['loop_end'])] if metadata['loop_end']>0 else ['anull']
                subprocess.run(['ffmpeg','-nostdin','-hide_banner','-loglevel','error','-y','-f','s16le','-ar','32768','-ac','2','-i',str(pcm),
                    '-af',','.join(filters),'-c:a','libvorbis','-q:a','5','-metadata',f'title=Digimon World Dawn {name}',str(target)],check=True)
                entry.update({'status':'rendered','file':f'dawn/audio/{name}.ogg','sha256':sha(target.read_bytes()),'bytes':target.stat().st_size})
            cached.parent.mkdir(parents=True,exist_ok=True);cached.write_text(json.dumps(entry,indent=2)+'\n')
            for p in (raw_sdat,pcm,meta): p.unlink(missing_ok=True)
            print(f'{name}: {entry["status"]} ({metadata["duration"]:.2f}s)',flush=True)
            return entry
        with concurrent.futures.ThreadPoolExecutor(max_workers=jobs) as pool:
            entries=list(pool.map(render,tasks))
    catalog={'schema':1,'source_game':'Digimon World - Dawn (USA)','rom_sha256':sha(rom),'rom_game_code':rom[12:16].decode('ascii'),
             'sdat_offset':rom_offset,'sdat_bytes':len(sdat),'sdat_sha256':sha(sdat),'renderer':'SSEQPlayer 77222d3657adff358fb4e610d3e56bb7ada8ec24',
             'music_count':len(bgm_names),'effect_sequence_count':count,'rendered_count':sum(e['status']=='rendered' for e in entries),
             'note':'ROM sequence IDs retained. Scene assignments are NXT arrangements; track titles/roles are not asserted as original game metadata.',
             'tracks':{e['id']:e for e in entries}}
    (ROOT/'public_html/config/dawn_audio.json').write_text(json.dumps(catalog,indent=2)+'\n')
    (ROOT/'docs/DAWN_AUDIO_VERIFICATION.json').write_text(json.dumps({k:v for k,v in catalog.items() if k!='tracks'},indent=2)+'\n')
    print(f'Rendered {catalog["rendered_count"]}/{len(tasks)} sequences. Manifest: public_html/config/dawn_audio.json')

if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('rom',type=Path);parser.add_argument('--jobs',type=int,default=min(4,os.cpu_count() or 1));parser.add_argument('--limit',type=int)
    args=parser.parse_args();extract(args.rom,max(1,args.jobs),args.limit)
