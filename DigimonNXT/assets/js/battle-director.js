/* Authoritative battle presentation. Animations never calculate damage or settle rewards. */
(() => {
  'use strict';
  const roots = document.querySelectorAll('[data-battle-live], [data-battle-replay]');
  const colors = {Fire:'#ffad72',Water:'#78caff',Plant:'#9ae6a4',Electric:'#ffe67e',Wind:'#9bf5eb',Earth:'#e8bc89',Light:'#fff1b0',Dark:'#d4a0ff',Neutral:'#a3eeef'};
  const clone = value => JSON.parse(JSON.stringify(value));
  const validKey = key => /^[lr][0-5]$/.test(String(key));
  const label = value => String(value || '').replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase());
  const audio = (cue, pan=0) => window.DawnAudio?.battleCue(cue,{pan});
  const resultScene = result => result==='win'?'victory':result==='loss'?'defeat':result==='disconnect'?'town':'ranked';

  class BattleDirector {
    constructor(root) {
      this.root=root;this.live=root.hasAttribute('data-battle-live');this.busy=false;this.playing=false;
      this.animations=new Set();this.floating=new Set();this.popups=new Map();this.cancelled=false;this.state=null;this.slots={left:[],right:[]};this.action=null;
      this.staticBase=root.dataset.staticBase || `${location.pathname.slice(0,location.pathname.lastIndexOf('/')+1)}html/static/`;
      this.speed=1.5;
      try { const saved=Number(localStorage.getItem('nxt-battle-speed'));if([1,1.5,2].includes(saved))this.speed=saved; } catch (_) {}
      this.motion=window.matchMedia('(prefers-reduced-motion: reduce)');
      root.addEventListener('change',e=>{if(e.target.matches('[data-battle-speed]')){this.speed=Number(e.target.value)||1.5;try{localStorage.setItem('nxt-battle-speed',String(this.speed));}catch(_){} }});
      root.addEventListener('click',e=>{
        if(e.target.closest('[data-battle-skip]'))this.skip();
        if(e.target.closest('[data-battle-replay-start]')&&!this.busy&&this.replay){window.DawnAudio?.unlock();this.replayMatch();}
        if(e.target.closest('[data-battle-refresh]')){e.preventDefault();location.reload();}
      });
      root.addEventListener('submit',e=>{if(this.live&&e.target.matches('[data-battle-command]')){e.preventDefault();this.submit(e.target,e.submitter);}});
      document.addEventListener('visibilitychange',()=>{if(document.hidden&&this.playing)this.skip();});
      window.addEventListener('pagehide',()=>this.skip());
      this.motion.addEventListener?.('change',()=>{if(this.reduced()&&this.playing)this.skip();});
      this.bindView();
      const script=root.querySelector(this.live?'.dn-battle-live-config':'.dn-battle-replay-config');
      if(script){
        try {
          const cfg=JSON.parse(script.textContent);script.remove();
          if(this.live){
            const finalHTML=root.innerHTML;this.lock(true);
            this.play(cfg).then(()=>{this.replace(finalHTML);this.result(cfg.result);}).catch(()=>{this.replace(finalHTML);this.status('Battle updated. Choose your next command.');}).finally(()=>this.lock(false));
          } else {this.replay=cfg;if(cfg.autoplay)this.replayMatch();else this.status('Ready to replay this match.');}
        } catch (_) {this.status('Battle is ready.');this.lock(false);}
      } else if(this.live) {
        const outcome=root.querySelector('[data-dawn-result]')?.dataset.dawnResult;
        if(outcome)this.result(outcome);
      }
    }
    reduced(){return this.motion.matches||document.body.classList.contains('nxt-motion-paused');}
    bindView(){
      this.stage=this.root.querySelector('[data-battle-stage]');this.arena=this.root.querySelector('.dn-battle-arena');this.layer=this.root.querySelector('[data-battle-effects]');
      this.template=this.root.querySelector('.dn-team-card')?.cloneNode(true);
      this.root.querySelectorAll('[data-battle-speed]').forEach(x=>x.value=String(this.speed));
    }
    status(text){const el=this.root.querySelector('[data-battle-status]');if(el)el.textContent=text;}
    lock(value){
      this.busy=value;this.root.classList.toggle('is-battle-busy',value);this.root.setAttribute('aria-busy',String(value));
      this.root.querySelectorAll('[data-battle-command] button,[data-battle-command] select,[data-battle-replay-start]').forEach(el=>{
        if(value){if(!el.hasAttribute('data-was-disabled'))el.dataset.wasDisabled=el.disabled?'1':'0';el.disabled=true;}
        else if(el.hasAttribute('data-was-disabled')){el.disabled=el.dataset.wasDisabled==='1';delete el.dataset.wasDisabled;}
      });
    }
    replace(html){window.DawnAudio?.stopBattleEffects();this.root.innerHTML=html;this.bindView();this.root.dispatchEvent(new CustomEvent('nxt:battle-updated',{bubbles:true}));}
    skip(){
      if(!this.playing)return;this.cancelled=true;window.DawnAudio?.stopBattleEffects();
      for(const animation of this.animations){try{animation.cancel();}catch(_){}}
    }
    async animate(el,frames,duration,free=false){
      if(!el||this.cancelled)return;
      if(typeof el.animate!=='function')return;
      const animation=el.animate(frames,{duration:free?duration:duration/this.speed,easing:'cubic-bezier(.2,.75,.25,1)',fill:'none'});
      this.animations.add(animation);try{await animation.finished;}catch(_){}finally{this.animations.delete(animation);}
    }
    async beat(ms){if(!this.cancelled)await this.animate(this.stage,[{opacity:1},{opacity:1}],ms);}
    card(key){return validKey(key)?this.arena?.querySelector(`[data-unit-key="${key}"]`):null;}
    unit(key){return validKey(key)?this.state?.[String(key).startsWith('l')?'left':'right']?.[key]:null;}
    name(key){return this.unit(key)?.name||'Digimon';}
    spriteURL(path){return new URL(String(path||'images/digimon/agumon.png'),new URL(this.staticBase,location.href)).href;}
    updateCard(card,u){
      card.dataset.unitKey=u.key;card.classList.toggle('is-defeated',Number(u.hp)<=0);
      card.querySelector('[data-unit-name]').textContent=u.name||'Digimon';
      card.querySelector('[data-unit-level]').textContent=`Lv. ${u.level||1}`;
      card.querySelector('[data-unit-attribute]').textContent=`${u.attribute||'Free'} · ${u.element||'Neutral'}`;
      const img=card.querySelector('.dn-unit-sprite');const src=this.spriteURL(u.sprite);if(img.src!==src)img.src=src;img.alt=u.name||'Digimon';
      for(const field of ['hp','sp']){
        const max=Math.max(1,Number(u[`max_${field}`])||1),value=Math.max(0,Number(u[field])||0);
        const bar=card.querySelector(`[data-vital="${field}"]`);bar.max=max;bar.value=value;bar.setAttribute('aria-label',`${u.name} ${field.toUpperCase()}`);
        card.querySelector(`[data-vital-text="${field}"]`).textContent=`${value} / ${max}`;
      }
      card.querySelector('[data-unit-label]').textContent=Number(u.hp)<=0?'DEFEATED':!this.live?(u.key.startsWith('l')?'ATTACKER':'DEFENDER'):(u.key.startsWith('l')?'LINKED PARTNER':'OPPONENT');
      const effects=card.querySelector('[data-unit-effects]');effects.replaceChildren();
      const add=text=>{const span=document.createElement('span');span.textContent=text;effects.append(span);};
      if(u.guarding)add('Guarding');for(const effect of u.effects||[])add(`${label(effect.kind)}${effect.stat?' '+String(effect.stat).toUpperCase():''} · ${effect.turns}`);
    }
    lineup(left,right,reset=false){
      for(const [side,keys] of [['left',left],['right',right]]){
        const active=(Array.isArray(keys)?keys:[]).filter(k=>this.state[side]?.[k]).slice(0,3);
        if(reset)this.slots[side]=active.length?[...active]:Object.keys(this.state[side]||{}).slice(0,3);
        else for(const key of active)if(!this.slots[side].includes(key)){
          const vacant=this.slots[side].findIndex(k=>!active.includes(k));if(vacant>=0)this.slots[side][vacant]=key;else if(this.slots[side].length<3)this.slots[side].push(key);
        }
        const formation=this.arena.querySelector(`[data-formation="${side}"]`);
        const existing=new Map([...formation.children].map(c=>[c.dataset.unitKey,c]));
        const cards=this.slots[side].map(key=>{
          const card=existing.get(key)||this.template.cloneNode(true);card.classList.remove('is-acting','is-attacking','is-targeted');card.classList.toggle('is-enemy',side==='right');card.dataset.unitSide=side;
          this.updateCard(card,this.state[side][key]);return card;
        });formation.replaceChildren(...cards);
      }
    }
    patch(units){
      for(const [key,patch] of Object.entries(units||{})){
        const current=this.unit(key);if(!current)continue;Object.assign(current,patch);const card=this.card(key);if(card)this.updateCard(card,current);
      }
    }
    anchor(key){
      const img=this.card(key)?.querySelector('.dn-unit-sprite');if(!img)return null;
      const r=img.getBoundingClientRect(),a=this.arena.getBoundingClientRect();
      return {x:r.left-a.left+r.width/2,y:r.top-a.top+r.height/2,left:r.left-a.left,top:r.top-a.top,width:r.width,height:r.height,img};
    }
    pan(key){const p=this.anchor(key);return p?Math.max(-.65,Math.min(.65,(p.x/this.arena.clientWidth-.5)*1.3)):0;}
    effectNode(className,key){const point=this.anchor(key);if(!point||!this.layer)return null;const el=document.createElement('span');el.className=className;el.style.left=`${point.x}px`;el.style.top=`${point.y}px`;this.layer.append(el);return el;}
    float(key,amount,subtitle='',kind=''){
      const el=this.effectNode(`dn-floating-value ${kind}`,key);if(!el)return;
      const previous=this.popups.get(key);if(previous){previous.getAnimations().forEach(a=>a.cancel());previous.remove();}this.popups.set(key,el);
      const p=this.anchor(key),width=this.arena.clientWidth,half=width<560?54:78;
      el.style.left=`${Math.max(half+4,Math.min(width-half-4,p.x))}px`;
      el.style.top=`${Math.max(50,p.y-20)}px`;
      const value=document.createElement('span');value.textContent=String(amount);el.append(value);
      if(subtitle){const detail=document.createElement('small');detail.textContent=subtitle;el.append(detail);}
      const frames=this.reduced()?[{opacity:0},{opacity:1,offset:.12},{opacity:1,offset:.75},{opacity:0}]:[{opacity:0,transform:'translateY(9px) scale(.86)'},{opacity:1,transform:'translateY(0) scale(1.06)',offset:.14},{opacity:1,transform:'translateY(-10px) scale(1)',offset:.7},{opacity:0,transform:'translateY(-23px) scale(.98)'}];
      const task=this.animate(el,frames,1050,true).finally(()=>{el.remove();if(this.popups.get(key)===el)this.popups.delete(key);this.floating.delete(task);});this.floating.add(task);
    }
    async aura(key,kind=''){
      if(this.reduced())return;
      const el=this.effectNode(`dn-aura ${kind}`,key);if(!el)return;
      await this.animate(el,[{opacity:0,transform:'scale(.6)'},{opacity:.85,transform:'scale(1.05)',offset:.35},{opacity:0,transform:'scale(1.45)'}],350);el.remove();
    }
    async travel(actor,target,magic){
      if(this.reduced()||this.cancelled)return null;
      const from=this.anchor(actor),to=this.anchor(target);if(!from||!to||actor===target)return null;
      const dx=to.x-from.x,dy=to.y-from.y;
      if(magic){
        const orb=this.effectNode('dn-projectile',actor);await this.animate(orb,[{transform:'translate(0,0) scale(.4)',opacity:0},{opacity:1,offset:.14},{transform:`translate(${dx}px,${dy}px) scale(1)`,opacity:1}],180);orb?.remove();return null;
      }
      const ghost=from.img.cloneNode();ghost.removeAttribute('id');ghost.className='dn-flight';ghost.alt='';ghost.style.cssText=`left:${from.left}px;top:${from.top}px;width:${from.width}px;height:${from.height}px`;
      this.layer.append(ghost);from.img.style.visibility='hidden';
      const reachX=.88,reachY=.94;await this.animate(ghost,[{transform:'translate(0,0) scale(1)'},{transform:`translate(${-dx*.025}px,${-dy*.035}px) scale(.98)`,offset:.18},{transform:`translate(${dx*reachX}px,${dy*reachY}px) scale(1.08)`,offset:1}],190);
      ghost.style.transform=`translate(${dx*reachX}px,${dy*reachY}px) scale(1.08)`;
      return async()=>{await this.animate(ghost,[{transform:ghost.style.transform},{transform:'translate(0,0) scale(1)'}],175);ghost.remove();from.img.style.visibility='';};
    }
    async hit(event){
      const physical=(this.action?.move?.type||'Physical')!=='Magic';
      const target=event.target,actor=event.actor;const damage=Math.max(0,Number(event.damage)||0),mult=Number(event.effectiveness)||1;
      audio(physical?'physical':'magic',this.pan(actor));
      const retreat=await this.travel(actor,target,!physical);if(this.cancelled){if(retreat)await retreat();return;}
      this.patch(event.units);
      const tags=[];if(Number(event.hit_count)>1)tags.push(`Hit ${event.hit_index}/${event.hit_count}`);if(event.critical)tags.push('CRITICAL');if(damage>0&&mult>1)tags.push(`Effective ×${mult}`);else if(damage>0&&mult<1)tags.push(`Resisted ×${mult}`);if(event.guarded)tags.push('Guarded');if(event.endured)tags.push('Endured');
      const kind=event.blocked?'is-blocked':event.missed?'is-miss':event.critical?'is-critical':mult>1?'is-effective':mult<1?'is-resisted':'';
      this.float(target,event.blocked?'BLOCKED':event.missed?'MISS':`−${damage}`,tags.join(' · '),kind);
      this.status(`${this.name(actor)} → ${this.name(target)} · ${event.blocked?'blocked':event.missed?'miss':`${damage} damage`}${tags.length?' · '+tags.join(' · '):''}`);
      audio(event.blocked?'guard':event.missed?'miss':mult>1?'effective':mult<1?'resist':'hit',this.pan(target));
      const sprite=this.card(target)?.querySelector('.dn-unit-sprite');
      if(!this.reduced()&&damage>0){
        const ring=this.effectNode(`dn-impact ${kind}`,target);
        this.animate(ring,[{opacity:1,transform:'scale(.35) rotate(-15deg)'},{opacity:.85,transform:'scale(1.05) rotate(0deg)',offset:.25},{opacity:0,transform:'scale(1.55) rotate(12deg)'}],300).finally(()=>ring?.remove());
        await this.animate(sprite,[{transform:'translateX(0)'},{transform:'translateX(6px)',offset:.22},{transform:'translateX(-4px)',offset:.5},{transform:'translateX(2px)',offset:.72},{transform:'translateX(0)'}],180);
      } else await this.beat(130);
      if(retreat)await retreat();
    }
    async event(e){
      if(this.cancelled)return;
      if(e.type==='hit'){await this.hit(e);return;}
      this.patch(e.units);
      if(e.type==='action'){
        this.action=e;this.arena.style.setProperty('--battle-accent',colors[e.move?.element]||colors.Neutral);
        this.arena.querySelectorAll('.is-attacking,.is-targeted').forEach(c=>c.classList.remove('is-attacking','is-targeted'));
        this.card(e.actor)?.classList.add('is-attacking');for(const key of e.targets||[])this.card(key)?.classList.add('is-targeted');
        this.status(`${this.name(e.actor)} · ${e.move?.name||'Attack'}`);
        if(e.move?.type==='Magic'||!e.offensive)await this.aura(e.actor);else await this.beat(150);
      } else if(e.type==='effect'){
        for(const [key,change] of Object.entries(e.changes||{})){
          const hp=Number(change.hp_change)||0,sp=Number(change.sp_change)||0;
          const added=(change.effects_after||[]).filter(after=>!(change.effects_before||[]).some(before=>before.kind===after.kind&&before.stat===after.stat&&before.amount===after.amount&&before.turns===after.turns));
          const effectText=added.length?added.map(effect=>`${label(effect.kind)}${effect.stat?' · '+String(effect.stat).toUpperCase():''}`).join(' · '):label(e.effect);
          if(hp){this.float(key,`${hp>0?'+':'−'}${Math.abs(hp)}`,`${hp>0?'HP restored':effectText}${sp?` · ${sp>0?'+':'−'}${Math.abs(sp)} SP`:''}`,hp>0?'is-heal':'is-status');audio(hp>0?'heal':'hit',this.pan(key));}
          else if(sp){this.float(key,`${sp>0?'+':'−'}${Math.abs(sp)} SP`,'',sp>0?'is-sp':'is-status');if(sp>0)audio('heal',this.pan(key));}
          else this.float(key,effectText,'','is-status');
          this.status(`${this.name(key)} · ${hp>0?`${hp} HP restored`:effectText}`);await this.aura(key,hp>0?'is-heal':'is-status');
        }await this.beat(180);
      } else if(e.type==='guard'){
        this.status(`${this.name(e.actor)} braces for the next attack.`);this.float(e.actor,'GUARD','Damage reduced','is-blocked');audio('guard',this.pan(e.actor));await this.aura(e.actor,'is-blocked');await this.beat(120);
      } else if(e.type==='switch'){
        this.status(`${this.name(e.actor)} → ${this.name(e.target)} · Partner switch`);await this.aura(e.actor);
        this.lineup(e.active_left,e.active_right);this.float(e.target,'LINKED','Partner ready','is-heal');audio('heal',this.pan(e.target));await this.aura(e.target,'is-heal');
      } else if(e.type==='skip'){
        this.status(`${this.name(e.actor||e.target)} · ${label(e.reason||'Unable to act')}`);this.float(e.actor||e.target,label(e.reason||'Turn skipped'),'','is-status');await this.beat(300);
      } else if(e.type==='ko'){
        this.card(e.target)?.classList.add('is-defeated');this.status(`${this.name(e.target)} was defeated.`);await this.beat(180);
      } else if(e.type==='lineup'||e.type==='state'){
        this.lineup(e.active_left,e.active_right);
      } else if(e.type==='flee'){
        this.status('Disconnecting safely. Your party is restored.');await this.beat(240);
      }
    }
    async play(cfg){
      if(!this.stage||!this.arena||!this.template||!cfg?.initial)return;
      this.playing=true;this.cancelled=false;this.state=clone(cfg.initial);this.action=null;this.lock(true);
      this.stage.classList.add('is-battle-busy');this.root.querySelector('[data-battle-skip]').hidden=false;
      this.root.querySelectorAll('.dn-team-result').forEach(el=>el.hidden=true);
      this.lineup(this.state.active_left,this.state.active_right,true);
      window.DawnAudio?.stopBattleEffects();window.DawnAudio?.prepareBattle();window.DawnAudio?.scene(cfg.scene||'battle');
      try {
        const images=[...this.arena.querySelectorAll('img')];
        await Promise.race([Promise.allSettled(images.map(img=>img.decode?.())),new Promise(resolve=>setTimeout(resolve,400))]);
        if(document.hidden)this.cancelled=true;
        for(const event of Array.isArray(cfg.events)?cfg.events:[]){if(this.cancelled)break;await this.event(event);}
        if(!this.cancelled)await Promise.allSettled([...this.floating]);
      } finally {
        for(const animation of this.animations){try{animation.cancel();}catch(_){}}
        this.animations.clear();this.layer?.replaceChildren();this.popups.clear();
        if(cfg.final){this.state=clone(cfg.final);this.lineup(this.state.active_left,this.state.active_right);}
        this.arena.querySelectorAll('.dn-unit-sprite').forEach(img=>img.style.visibility='');
        this.arena.querySelectorAll('.is-attacking,.is-targeted').forEach(el=>el.classList.remove('is-attacking','is-targeted'));
        this.stage.classList.remove('is-battle-busy');this.root.querySelector('[data-battle-skip]').hidden=true;
        this.root.querySelectorAll('.dn-team-result').forEach(el=>el.hidden=false);
        this.playing=false;window.DawnAudio?.stopBattleEffects();
        this.root.dispatchEvent(new CustomEvent('nxt:battle-playback-complete',{bubbles:true,detail:{skipped:this.cancelled}}));
      }
    }
    result(result){
      if(!result){this.status('Choose your next command.');return;}
      this.status(result==='win'?'Victory · Party fully restored.':result==='loss'?'Battle complete · Party fully restored.':result==='disconnect'?'Disconnected · Party fully restored.':'Match complete · Party fully restored.');
      const scene=resultScene(result);if(window.DawnAudio)window.DawnAudio.scene(scene);else document.addEventListener('nxt:audio-ready',()=>window.DawnAudio?.scene(scene),{once:true});
    }
    async replayMatch(){
      if(this.busy)return;this.lock(true);this.stage?.scrollIntoView({behavior:'auto',block:'start'});
      try{await this.play(this.replay);this.result(this.replay.result);if(this.replay.truncated)this.status('Match highlights complete. The full battle is in the report.');}
      catch(_){this.status('Replay stopped. Your saved result is shown below.');}
      finally{this.lock(false);}
    }
    async request(options={}){
      const controller=new AbortController(),timeout=setTimeout(()=>controller.abort(),20000);
      try{
        const response=await fetch(location.pathname,{...options,credentials:'same-origin',cache:'no-store',headers:{'X-NXT-Battle':'1','Accept':'application/json',...(options.headers||{})},signal:controller.signal});
        if(response.redirected||response.status===401){location.assign(response.url||location.href);throw new Error('Sign in again to continue.');}
        if(!response.ok)throw new Error('The battle could not be refreshed.');
        const data=await response.json();if(typeof data.html!=='string')throw new Error('The battle response was incomplete.');return data;
      }finally{clearTimeout(timeout);}
    }
    async submit(form,button){
      if(this.busy||!button?.name)return;
      const focusId=document.activeElement?.id;const values=new FormData(form);values.set(button.name,button.value);
      this.lock(true);this.status('Command received. Resolving the turn…');window.DawnAudio?.prepareBattle();
      try{
        const data=await this.request({method:'POST',body:values});
        if(data.ok&&data.presentation){
          // A command below the arena should still let the player see contact.
          const bounds=this.stage?.getBoundingClientRect();
          if(bounds&&(bounds.top<0||bounds.bottom>window.innerHeight))this.stage.scrollIntoView({block:'start',behavior:'instant'});
          await this.play({...data.presentation,result:data.result,scene:'battle'});
        }
        this.replace(data.html);this.result(data.result);
        if(!data.ok)this.status(data.error||'Choose another command.');
        const next=this.root.querySelector(focusId?`#${CSS.escape(focusId)}`:'[data-battle-command] button[name="battle_action"][value="attack"]');next?.focus({preventScroll:true});
      }catch(_){
        window.DawnAudio?.stopBattleEffects();
        try{const recovered=await this.request();this.replace(recovered.html);this.result(recovered.result);this.status('Battle refreshed. Check the latest turn before choosing a command.');}
        catch(_){
          this.status('Connection interrupted. Refresh to check your turn.');
          const notice=document.createElement('div');notice.className='pv-flash error dn-battle-action-error';notice.setAttribute('role','alert');
          notice.textContent='Your command may already have completed. Refresh the battle before continuing. ';
          const link=document.createElement('a');link.href=location.href;link.dataset.battleRefresh='';link.textContent='Refresh battle';notice.append(link);this.root.prepend(notice);
          // A committed command must never be blindly retried after an ambiguous response.
          return;
        }
      }
      this.lock(false);
    }
  }
  roots.forEach(root=>{if(!root.dataset.battleDirector){root.dataset.battleDirector='1';new BattleDirector(root);}});
})();
