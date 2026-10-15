<?php
declare(strict_types=1);
/** Server-rendered battle view shared by live wild turns and recorded ranked matches. */
function dn_battle_card_view(array $u,bool $acting=false,bool $enemy=false,string $role=''): void {
    $key=(string)($u['key']??'');$name=(string)($u['name']??'Digimon');
    ?>
<article class="dn-team-card<?=$acting?' is-acting':''?><?=($u['hp']??0)<=0?' is-defeated':''?><?=$enemy?' is-enemy':''?>" data-unit-key="<?=pv_h($key)?>" data-unit-side="<?=$enemy?'right':'left'?>">
<div class="dn-team-card-label"><span data-unit-label><?=$acting?'YOUR TURN':(($u['hp']??0)<=0?'DEFEATED':($role!==''?$role:($enemy?'OPPONENT':'LINKED PARTNER')))?></span><span data-unit-level>Lv. <?=(int)($u['level']??1)?></span></div>
<div class="dn-unit-art"><img class="dn-unit-sprite" src="<?=pv_h(pv_static((string)($u['sprite']?:'images/digimon/agumon.png')))?>" alt="<?=pv_h($name)?>" width="120" height="120" draggable="false"></div>
<h2 data-unit-name><?=pv_h($name)?></h2><p class="pv-subtle" data-unit-attribute><?=pv_h(($u['attribute']??'').' · '.($u['element']??''))?></p>
<?php foreach(['hp'=>'HP','sp'=>'SP'] as $field=>$label):?><div class="dn-team-vital <?=$field?>"><span><?=$label?></span><progress data-vital="<?=$field?>" aria-label="<?=pv_h($name.' '.$label)?>" max="<?=max(1,(int)($u['max_'.$field]??1))?>" value="<?=max(0,(int)($u[$field]??0))?>"></progress><span data-vital-text="<?=$field?>"><?=(int)($u[$field]??0)?> / <?=(int)($u['max_'.$field]??0)?></span></div><?php endforeach;?>
<div class="dn-team-status" data-unit-effects><?php if(!empty($u['guarding'])):?><span>Guarding</span><?php endif;?><?php foreach($u['effects']??[] as $effect):?><span><?=pv_h(ucwords(str_replace('_',' ',(string)$effect['kind'])).(!empty($effect['stat'])?' '.strtoupper((string)$effect['stat']):''))?> · <?=(int)($effect['turns']??0)?></span><?php endforeach;?></div>
</article>
<?php }
function dn_battle_arena_view(array $team,array $options=[]): void {
    $ranked=($options['mode']??'')==='ranked';$acting=(string)($options['acting']??'');$rightLabel=(string)($options['right_label']??'Wild formation');$leftLabel=(string)($options['left_label']??'Your active team');
    $bg=(string)($options['background']??dawn_battle_background(0));
    ?>
<div class="dn-battle-stage" data-battle-stage>
<div class="dn-battle-toolbar"><div class="dn-battle-cue"><span class="pv-eyebrow">BATTLE SIGNAL</span><strong data-battle-status role="status" aria-live="polite" aria-atomic="true"><?=$ranked?'Ready to replay this match.':'Choose your next command.'?></strong></div><div class="dn-playback-controls"><label>Speed <select data-battle-speed aria-label="Battle animation speed"><option value="1">1×</option><option value="1.5" selected>1.5×</option><option value="2">2×</option></select></label><button type="button" class="pv-button pv-button-secondary" data-battle-skip hidden>Skip animation</button></div></div>
<section class="dn-team-arena dn-battle-arena" aria-label="Battlefield" style="background-image:linear-gradient(180deg,rgba(5,19,29,.57),rgba(7,20,32,.78)),url('<?=pv_h($bg)?>')">
<div class="dn-team-row-heading"><h2><?=pv_h($rightLabel)?></h2><span>Opponent formation</span></div><div class="dn-team-formation enemies" data-formation="right">
<?php foreach(($team['active_right']?:array_slice(array_keys($team['right']),0,3)) as $key):$u=$team['right'][$key];$u['key']=$key;dn_battle_card_view($u,false,true,$ranked?'DEFENDER':'');endforeach;?></div>
<div class="dn-team-divider"><span>VS</span></div>
<div class="dn-team-row-heading"><h2><?=pv_h($leftLabel)?></h2><span>Three partners. One team.</span></div><div class="dn-team-formation allies" data-formation="left">
<?php foreach(($team['active_left']?:array_slice(array_keys($team['left']),0,3)) as $key):$u=$team['left'][$key];$u['key']=$key;dn_battle_card_view($u,$acting===$key,false,$ranked?'ATTACKER':'');endforeach;?></div>
<div class="dn-battle-effects" data-battle-effects aria-hidden="true"></div>
</section></div>
<?php }
function dn_battle_json(array $value): string {return json_encode($value,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
