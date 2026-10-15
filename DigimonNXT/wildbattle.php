<?php
declare(strict_types=1);
require_once __DIR__.'/includes/ui.php';require_once __DIR__.'/includes/combat.php';require_once __DIR__.'/includes/battle_presentation.php';pv_require_login();
$db=pv_db();$uid=(int)$_SESSION['myid'];$error='';$presentation=null;
$fragment=($_SERVER['HTTP_X_NXT_BATTLE']??'')==='1';
header('Cache-Control: no-store');
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try {
        pv_require_csrf();
        if(isset($_POST['start_wild_battle'])){
            $updated=dn_combat_start($db,$uid,(array)($_SESSION['dn_pending_encounter']??[]),(string)($_POST['encounter_token']??''),true);
            unset($_SESSION['dn_pending_encounter'],$_SESSION['dn_battle']);
        } elseif(isset($_POST['battle_action'])){
            $updated=dn_combat_action($db,$uid,(string)($_POST['battle_token']??''),(string)$_POST['battle_action'],$_POST,true);
        } else throw new RuntimeException('Choose a battle command.');
        $presentation=$updated['presentation']??null;
        if(!$fragment){
            if($presentation)$_SESSION['dn_battle_presentation']=['token'=>$updated['token'],'at'=>time(),'presentation'=>$presentation];
            pv_redirect('wildbattle.php');
        }
    } catch(Throwable $e){$error=$e instanceof RuntimeException?$e->getMessage():'Battle update failed. Refresh the battle before trying again.';pv_log('Battle: '.$e->getMessage());}
}
$b=dn_active_battle($db,$uid);$party=dn_party_rows($db,$uid);$items=dn_inventory($db,$uid);
if(!$fragment&&$presentation===null&&isset($_SESSION['dn_battle_presentation'])){
    $pending=$_SESSION['dn_battle_presentation'];unset($_SESSION['dn_battle_presentation']);
    if($b&&hash_equals((string)$b['token'],(string)($pending['token']??''))&&time()-(int)($pending['at']??0)<120)$presentation=$pending['presentation'];
}
function dn_wild_battle_view(?array $b,array $party,array $items,string $error): string {
    $team=$b['team']??null;$next=$team?dn_team_next($team):null;$actor=$next&&$next['side']==='left'?$team['left'][$next['key']]:null;$finished=!empty($b['finished']);
    ob_start();
?>
<div class="pv-page-head"><?php nxt_page_sprites();?><div><span class="pv-eyebrow">CYBERSPACE // TEAM BATTLE</span><h1>Link. Command. Evolve.</h1><p class="pv-subtle">Three active partners. Speed decides who acts next. Every battle ends with full party restoration.</p></div><span class="dn-team-mode">3 vs 3</span></div>
<?php if($error):?><div class="pv-flash error" role="alert"><?=pv_h($error)?></div><?php endif;?>
<?php if($team):?>
<div class="dn-battle-topline"><span><strong>Round <?=(int)$team['round']?></strong> · <?=pv_h(str_replace('-',' ',$b['area']))?></span><span class="dn-scan-secured">Scan data secured · +<?=(int)$b['scan_reward']?>%</span></div>
<?php if(!$finished):?><section class="dn-initiative" aria-label="Upcoming turns"><strong>TURN ORDER</strong><?php foreach(array_slice($team['queue'],0,6) as $i=>$entry):$u=$team[$entry['side']][$entry['key']];if($u['hp']<=0||!in_array($entry['key'],$team['active_'.$entry['side']],true))continue;?><span class="<?=$entry['side']==='left'?'ally':'foe'?><?=$i===0?' next':''?>"><?=pv_h($u['name'])?><?=$i===0?' · NOW':''?></span><?php endforeach;?></section><?php endif;?>
<?php
$displayTeam=$team;
if($finished)foreach($displayTeam['left'] as $key=>&$u)foreach($party as $d)if((int)$d['id']===(int)$u['id']){$u=dn_combat_unit($d);$u['key']=$key;break;}unset($u);
$activeKeys=$team['active_left']?:array_slice(array_keys($team['left']),0,3);
dn_battle_arena_view($displayTeam,['acting'=>!$finished&&$actor?$actor['key']:'']);
?>
<?php $reserves=array_filter($team['left'],fn($u)=>!in_array($u['key'],$activeKeys,true));if($reserves):?><section class="dn-reserve-strip"><strong>RESERVES</strong><?php foreach($reserves as $u):?><span><img src="<?=pv_h(pv_static($u['sprite']))?>" alt="" width="40" height="40"><?=pv_h($u['name'])?><small><?=$finished?'Restored':($u['hp']>0?'Ready':'Defeated')?></small></span><?php endforeach;?></section><?php endif;?>
<?php if(!$finished&&$actor):$moves=$actor['moves'];?>
<form method="post" class="dn-team-command" data-battle-command><?=pv_csrf_field()?><input type="hidden" name="battle_token" value="<?=pv_h($b['token'])?>">
<div class="dn-command-heading"><div><span class="pv-eyebrow">AWAITING YOUR COMMAND</span><h2><?=pv_h($actor['name'])?></h2></div><span><?=(int)$actor['sp']?> SP available</span></div>
<div class="dn-team-command-grid"><div><label for="enemy-target">Enemy target</label><select id="enemy-target" name="enemy_target"><?php foreach($team['active_right'] as $key):$u=$team['right'][$key];?><option value="<?=pv_h($key)?>"><?=pv_h($u['name'])?> · <?=(int)$u['hp']?> HP</option><?php endforeach;?></select><button class="pv-button" name="battle_action" value="attack" data-dawn-sfx="confirm">Attack · No SP cost</button></div>
<div><label for="move">Learned skill</label><select id="move" name="move_slug"><?php foreach($moves as $key=>$m):?><option value="<?=pv_h($key)?>" <?=(int)$m['sp']>(int)$actor['sp']?'disabled':''?>><?=pv_h($m['name'])?> · <?=(int)$m['sp']?> SP · <?=pv_h(str_replace('_',' ',$m['target']))?></option><?php endforeach;?></select><button class="pv-button" name="battle_action" value="skill" data-dawn-sfx="confirm" <?=!$moves?'disabled':''?>>Use skill</button></div>
<div><label for="ally-target">Ally target · support or capsule</label><select id="ally-target" name="target_id"><?php foreach($team['left'] as $key=>$u):?><option value="<?=(int)$u['id']?>" <?=$actor['key']===$key?'selected':''?>><?=pv_h($u['name'])?> · <?=(int)$u['hp']?> HP<?=in_array($key,$team['active_left'],true)?'':' · reserve'?></option><?php endforeach;?></select><button class="pv-button pv-button-secondary" name="battle_action" value="guard">Guard · Reduce damage</button></div></div>
<details class="dn-team-more"><summary>Reserve switch &amp; recovery capsules</summary><p class="pv-subtle">Switching and using a capsule each spend this partner’s action. A defeated active partner is replaced automatically. Capsules remain useful during battle; the entire party restores free afterward.</p><div class="dn-team-command-grid"><div><label for="reserve">Bring in a reserve</label><select id="reserve" name="reserve_id"><option value="0">Choose a reserve</option><?php foreach($reserves as $u):if($u['hp']<=0)continue;?><option value="<?=(int)$u['id']?>"><?=pv_h($u['name'])?> · <?=(int)$u['hp']?> HP</option><?php endforeach;?></select><button class="pv-button pv-button-secondary" name="battle_action" value="switch">Switch partner</button></div><div><label for="capsule">Capsule for selected ally</label><select id="capsule" name="item_id"><option value="0">Choose a capsule</option><?php foreach($items as $it):if((int)$it['quantity']<1||!isset(dn_recovery_catalog()[$it['slug']]))continue;?><option value="<?=(int)$it['id']?>"><?=pv_h($it['name'])?> · ×<?=(int)$it['quantity']?></option><?php endforeach;?></select><button class="pv-button pv-button-secondary" name="battle_action" value="item">Use capsule</button></div></div></details>
<div class="dn-command-footer"><p>All-target skills affect the active formation. Scanning is awarded once on contact.</p><button class="pv-button pv-button-secondary" name="battle_action" value="flee" data-dawn-sfx="cancel">Disconnect &amp; restore</button></div>
</form>
<?php elseif($finished):?><section class="dn-team-result" data-dawn-result="<?=pv_h($b['result'])?>"><span class="pv-eyebrow">BATTLE COMPLETE</span><h2><?=$b['result']==='win'?'Victory. Your team is ready again.':($b['result']==='loss'?'Regroup. Your party is fully restored.':'Signal disconnected. Party fully restored.')?></h2><p>Every partner has full HP and SP. Your scan data stays with you.</p><div class="dn-result-rewards"><span><strong>+<?=(int)$b['scan_reward']?>%</strong> encounter scan</span><span><strong>+<?=(int)$b['exp_gain']?></strong> total party EXP</span><span><strong>+<?=(int)$b['bits_gain']?></strong> Bits</span></div><div class="pv-actions"><a class="pv-button" href="<?=pv_h(pv_url('world_map.php?world=cyberspace&area='.rawurlencode($b['area'])))?>">Continue exploring</a><a class="pv-button pv-button-secondary" href="<?=pv_h(pv_url('materialize.php'))?>">Materialize in DigiLab</a></div></section><?php endif;?>
<details class="dn-team-history" open><summary>Battle feed <span><?=count($b['log'])?> events</span></summary><div class="dn-battle-log" aria-label="Battle log"><?php foreach(array_slice($b['log'],-24) as $line):?><div><?=pv_h($line)?></div><?php endforeach;?></div></details>
<?php else:?><div class="pv-empty"><?=nxt_sprite('guilmon','nxt-empty-sprite')?><h2>Your next encounter awaits</h2><p>Explore Cyberspace and click a roaming Digimon to scan its formation and begin a team battle.</p><a class="pv-button" href="<?=pv_h(pv_url('map_select.php'))?>">Enter Cyberspace</a></div><?php endif;?>
<?php return (string)ob_get_clean(); }
$html=dn_wild_battle_view($b,$party,$items,$error);
if($fragment){
    header('Content-Type: application/json; charset=utf-8');
    echo dn_battle_json(['ok'=>$error==='','error'=>$error,'html'=>$html,'presentation'=>$error===''?$presentation:null,'token'=>$b['token']??'','result'=>$b['result']??'','finished'=>!empty($b['finished'])]);exit;
}
pv_page_start('Wild Team Battle','wildbattle.php',true);
?>
<div class="pv-game-layout"><?php pv_game_side_menu('wildbattle.php');?><main class="pv-main-column"><section class="pv-page dn-team-page" data-battle-live data-dawn-managed data-static-base="<?=pv_h(pv_static(''))?>">
<?=$html?>
<?php if($presentation):?><script type="application/json" class="dn-battle-live-config"><?=dn_battle_json(['id'=>$b['token'],'initial'=>$presentation['initial'],'events'=>$presentation['events'],'final'=>$presentation['final'],'truncated'=>$presentation['truncated']??false,'result'=>$b['result']??'','scene'=>'battle'])?></script><?php endif;?>
</section></main></div><script defer src="<?=pv_h(pv_asset('js/battle-director.js'))?>?v=<?=pv_asset_version()?>"></script><?php pv_page_end();?>
