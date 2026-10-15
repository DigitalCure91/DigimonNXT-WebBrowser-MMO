#!/usr/bin/env python3
"""Normalize supplied v7 poses; existing runtime art is preserved unless --rebuild is set.

Usage: python tools/normalize_supplied_sprites.py --normal-assets "/path/to/v7 root" --paradox-assets "/path/to/merged parts"
Needs Pillow, numpy and scipy. Does not generate or recolor artwork.
"""
import argparse,json
from pathlib import Path
import numpy as np
from PIL import Image
from scipy import ndimage as ndi
P=Path(__file__).resolve().parents[1]

def normalize(source,paradox=False):
    im=Image.open(source).convert('RGBA');a=np.array(im)
    if not paradox and a[:,:,3].min()==255:
        border=np.concatenate([a[0,:,:3],a[-1,:,:3],a[:,0,:3],a[:,-1,:3]])
        colors,count=np.unique(border,axis=0,return_counts=True);bg=colors[np.argmax(count)]
        candidate=np.max(np.abs(a[:,:,:3].astype(int)-bg.astype(int)),axis=2)<=20
        regions,n=ndi.label(candidate,np.ones((3,3)));touching=np.unique(np.concatenate([regions[0,:],regions[-1,:],regions[:,0],regions[:,-1]]));touching=touching[touching!=0]
        a[:,:,3]=np.where(np.isin(regions,touching),0,255).astype('uint8')
        labels,n=ndi.label(a[:,:,3]>0,np.ones((3,3)));sizes=np.bincount(labels.ravel());sizes[0]=0
        if n:
            keep=np.where(sizes>=max(8,sizes.max()*.015))[0];a[:,:,3]=np.where(np.isin(labels,keep),a[:,:,3],0).astype('uint8')
        im=Image.fromarray(a)
    box=im.getchannel('A').getbbox()
    if not box:raise ValueError('Empty source pose: '+str(source))
    im=im.crop(box);edge=232 if paradox else 220;scale=min(edge/im.width,edge/im.height)
    im=im.resize((max(1,round(im.width*scale)),max(1,round(im.height*scale))),Image.Resampling.NEAREST)
    canvas=Image.new('RGBA',(256,256));canvas.alpha_composite(im,((256-im.width)//2,(256-im.height)//2));return canvas

def main():
    ap=argparse.ArgumentParser(description=__doc__);ap.add_argument('--normal-assets',type=Path);ap.add_argument('--paradox-assets',type=Path);ap.add_argument('--rebuild',action='store_true');args=ap.parse_args();written=0;preserved=0
    for kind,root in [('normal',args.normal_assets),('paradox',args.paradox_assets)]:
        if root is None:continue
        root=root.resolve();records=json.loads((P/'tools/data'/f'{kind}_asset_manifest.json').read_text())
        for r in records:
            source=(root/r.get('sprite_source',r.get('source'))).resolve()
            if not source.is_relative_to(root):raise ValueError('Source escaped asset root')
            dest=P/'public_html/html/static/images/digimon'/(r['slug']+'.png')
            if dest.exists() and not args.rebuild:preserved+=1;continue
            dest.parent.mkdir(parents=True,exist_ok=True);normalize(source,kind=='paradox').save(dest,optimize=True);written+=1
    print(f'Wrote {written} runtime sprites; preserved {preserved} existing sprites.')
if __name__=='__main__':main()
