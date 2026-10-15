#!/usr/bin/env python3
"""Check shipped Dawn audio integrity and optionally decode every complete track."""
import argparse
import concurrent.futures
import hashlib
import json
from pathlib import Path
import subprocess

ROOT=Path(__file__).resolve().parents[1]

def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--decode',action='store_true');args=parser.parse_args()
    manifest=json.loads((ROOT/'public_html/config/dawn_audio.json').read_text())
    entries=[(key,value) for key,value in manifest['tracks'].items() if value['status']=='rendered']
    def check(item):
        key,track=item;path=ROOT/'public_html/assets'/track['file']
        if not path.is_file():return {'id':key,'error':'missing file'}
        if hashlib.sha256(path.read_bytes()).hexdigest()!=track['sha256']:return {'id':key,'error':'SHA-256 mismatch'}
        if not track['nonzero_samples']:return {'id':key,'error':'silent render'}
        if args.decode:
            result=subprocess.run(['ffmpeg','-nostdin','-v','error','-i',str(path),'-f','null','-'],capture_output=True,text=True)
            if result.returncode:return {'id':key,'error':result.stderr.strip()}
        return None
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:failures=[result for result in pool.map(check,entries) if result]
    report={key:value for key,value in manifest.items() if key!='tracks'}
    report.update({'ogg_sha256_checks_passed':len(entries)-len(failures),
        'ogg_full_decode_checks_passed':len(entries)-len(failures) if args.decode else None,'validation_failures':failures,
        'looping_music_tracks':sum(t.get('loop_end',0)>0 for t in manifest['tracks'].values() if t['kind']=='music'),
        'nonlooping_music':[k for k,t in entries if t['kind']=='music' and t['loop_end']<0],
        'omitted_effects':{k:t.get('note',t['status']) for k,t in manifest['tracks'].items() if t['status']!='rendered'},
        'playable_audio_bytes':sum(t['bytes'] for _,t in entries),
        'all_rendered_tracks_have_nonzero_pcm':all(t['nonzero_samples']>0 for _,t in entries)})
    (ROOT/'docs/DAWN_AUDIO_VERIFICATION.json').write_text(json.dumps(report,indent=2)+'\n')
    print(json.dumps(report,indent=2))
    return bool(failures)

if __name__=='__main__':raise SystemExit(main())
