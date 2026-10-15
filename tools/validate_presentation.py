#!/usr/bin/env python3
"""Check the Digimon NXT visual release assets and integration contracts.
Requires Python 3 and Pillow. Does not access a database or launch a browser.
"""
from pathlib import Path
import json,re
from PIL import Image
ROOT=Path(__file__).resolve().parents[1]
P=ROOT/'public_html'

def check(condition,message):
    if not condition:raise AssertionError(message)

def main():
    for i in range(1,7):
        path=P/f'html/static/images/tamers/{i}.png'
        with Image.open(path) as im:
            check(im.mode=='RGBA' and im.size==(128,128),f'Invalid tamer canvas {i}')
            alpha=im.getchannel('A');check(alpha.getextrema()==(0,255),f'Tamer {i} needs genuine transparency')
            bounds=alpha.getbbox();check(bounds is not None and bounds[3]==128,f'Tamer {i} feet alignment changed')
    for alias,canonical in [(3,1),(4,2),(5,1),(6,2)]:
        check((P/f'html/static/images/tamers/{alias}.png').read_bytes()==(P/f'html/static/images/tamers/{canonical}.png').read_bytes(),'Legacy skin alias mismatch')
    with Image.open(P/'assets/ui/shopkeeper.png') as im:
        check(im.mode=='RGBA' and im.size==(256,256),'Shopkeeper canvas mismatch')
        alpha=im.getchannel('A');check(alpha.getextrema()==(0,255),'Shopkeeper has no true alpha')
        check(all(alpha.getpixel(x)==0 for x in [(0,0),(255,0),(0,255),(255,255)]),'Shopkeeper background corner is opaque')
    print('PASS  transparent shopkeeper and original avatar compatibility')
    dawn=json.loads((P/'config/dawn_assets.json').read_text())
    check(len(dawn['tamers'])==64,'Expected all 64 Dawn human appearances')
    ids=set()
    for tamer in dawn['tamers'].values():
        check(tamer['numeric_id'] not in ids,'Duplicate Dawn avatar ID');ids.add(tamer['numeric_id'])
        with Image.open(P/tamer['sprite']) as im:check(im.mode=='RGBA' and im.getchannel('A').getbbox() is not None,'Invalid Dawn avatar')
        with Image.open(P/tamer['sheet']) as im:check(im.size==(tamer['frame_width']*3,tamer['frame_height']*4),'Invalid Dawn directional sheet')
    print('PASS  64 original Dawn avatars and directional animation sheets')
    world=json.loads((P/'config/worlds/dawn_world.json').read_text())
    check(len(world['areas'])==264,'Expected 264 Dawn atlas areas')
    for key,area in world['areas'].items():
        with Image.open(P/'html/static'/area['thumbnail']) as im:check(im.size==(320,200),'Invalid Dawn atlas thumbnail '+key)
    print('PASS  264 optimized Dawn atlas thumbnails')
    helper=(P/'includes/presentation.php').read_text()
    for a,b in re.findall(r"=>\['([a-z0-9-]+)','([a-z0-9-]+)'\]",helper):
        if a in ['Tai','Matt']:continue
        for slug in [a,b]:check((P/f'html/static/images/digimon/{slug}.png').is_file(),'Missing decorative sprite '+slug)
    for f in list(P.glob('*.php'))+[P/'includes/ui.php']:
        for asset in re.findall(r"pv_asset\('([^']+)'\)",f.read_text()):check((P/'assets'/asset).is_file(),'Missing UI asset '+asset)
        for slug in re.findall(r"nxt_sprite\('([a-z0-9-]+)'",f.read_text()):check((P/f'html/static/images/digimon/{slug}.png').is_file(),'Missing sprite '+slug)
    print('PASS  static presentation and decorative sprite references')
    battle=(P/'wildbattle.php').read_text()
    check(battle.index('class="dn-team-command"')<battle.index('class="dn-battle-log"'),'Battle controls must precede transcript')
    for page in ['signup.php','options.php']:check('nxt_skin_picker(' in (P/page).read_text(),'Missing playable selector '+page)
    js=(P/'assets/js/digimon-nxt.js').read_text()
    for token in ['nxt-js','data-nxt-side-toggle','data-nxt-motion','prefers-reduced-motion','visibilitychange']:
        check(token in js,'Missing visual control '+token)
    css=(P/'assets/css/digimon-nxt.css').read_text()
    for url in re.findall(r'url\([\'"]?([^\)\'\"]+)',css):check((P/'assets/css'/url).resolve().is_file(),'Missing CSS asset '+url)
    check('0.7.2' in (P/'config/version.php').read_text(),'Asset cache version mismatch')
    print('PASS  battle action order, selectors, motion controls and local CSS assets')

if __name__=='__main__':main()
