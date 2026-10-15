<?php
declare(strict_types=1);
require_once __DIR__.'/includes/world_maps.php';
require_once __DIR__.'/includes/ui.php';
pv_require_login();
$db=pv_db();
$areas=pv_world_ready_areas('cyberspace');
$profiles=pv_world_encounter_profiles();
$counts=[];
$cutoff=time()-1800;
$stmt=$db->prepare('SELECT map,COUNT(*) c FROM mapusers m INNER JOIN online o ON o.id=m.id WHERE m.world_key=? AND CAST(o.time AS UNSIGNED)>=? GROUP BY map');
if($stmt){$world='cyberspace';$stmt->bind_param('si',$world,$cutoff);$stmt->execute();$result=$stmt->get_result();while($row=$result->fetch_assoc())$counts[(string)$row['map']]=(int)$row['c'];$stmt->close();}
$query=trim(substr((string)($_GET['q']??''),0,80));
$region=trim(substr((string)($_GET['region']??''),0,80));
$kind=in_array((string)($_GET['kind']??''),['wild','rest'],true)?(string)$_GET['kind']:'';
$regions=[];
foreach($areas as $area){$label=(string)($area['region']??$area['category']);$regions[$label]=($regions[$label]??0)+1;}
ksort($regions,SORT_NATURAL|SORT_FLAG_CASE);
if($region!==''&&!isset($regions[$region]))$region='';
$matches=array_values(array_filter($areas,static function(array $area)use($query,$region,$kind):bool{
    if($region!==''&&(string)($area['region']??$area['category'])!==$region)return false;
    $wild=trim((string)$area['encounter_profile'])!=='';
    if(($kind==='wild'&&!$wild)||($kind==='rest'&&$wild))return false;
    $search=implode(' ',[(string)$area['name'],(string)$area['key'],(string)($area['map_id']??''),(string)($area['region']??''),(string)$area['category']]);
    return $query===''||stripos($search,$query)!==false;
}));
$pageSize=24;$total=count($matches);$pages=max(1,(int)ceil($total/$pageSize));$page=max(1,min($pages,(int)($_GET['page']??1)));
$visible=array_slice($matches,($page-1)*$pageSize,$pageSize);
$pageUrl=static function(int $number)use($query,$region,$kind):string{return pv_url('map_select.php?'.http_build_query(array_filter(['q'=>$query,'region'=>$region,'kind'=>$kind,'page'=>$number],static fn($value)=>$value!=='')));};
$resume=pv_world_area('cyberspace',(string)($_SESSION['world_area']??pv_world_default_area()))??pv_world_area('cyberspace',pv_world_default_area());
pv_page_start('Dawn World','map_select.php',true);
?>
<div class="pv-game-layout"><?php pv_game_side_menu('map_select.php');?><main class="pv-main-column"><section class="pv-page dn-dawn-atlas">
<div class="pv-page-head dn-atlas-head"><div><span class="pv-eyebrow">THE DIGITAL WORLD // DAWN ATLAS</span><h1>A whole world to explore.</h1><p class="pv-subtle"><?=number_format(count($areas))?> Dawn areas. Roaming Digimon, familiar tamers and your next adventure. Choose a destination to travel there.</p></div><?php if($resume):?><a class="pv-button" href="<?=pv_h(pv_world_area_url('cyberspace',(string)$resume['key']))?>">Return to <?=pv_h((string)$resume['name'])?> <span aria-hidden="true">→</span></a><?php endif;?></div>
<?php if(isset($_GET['status'])):?><div class="pv-flash error" role="status">That destination is unavailable. Choose an area below to continue your adventure.</div><?php endif;?>
<form class="dn-atlas-filters" method="get" action="<?=pv_h(pv_url('map_select.php'))?>" role="search" aria-label="Find a Dawn area">
<label class="dn-atlas-search"><span>Find an area</span><input type="search" name="q" value="<?=pv_h($query)?>" maxlength="80" placeholder="Area name or number" autocomplete="off"></label>
<label><span>Environment</span><select name="region"><option value="">Every environment</option><?php foreach($regions as $label=>$count):?><option value="<?=pv_h($label)?>"<?=$region===$label?' selected':''?>><?=pv_h($label)?> (<?=$count?>)</option><?php endforeach;?></select></label>
<label><span>Adventure</span><select name="kind"><option value="">All areas</option><option value="wild"<?=$kind==='wild'?' selected':''?>>Wild Digimon</option><option value="rest"<?=$kind==='rest'?' selected':''?>>Rest areas</option></select></label>
<button type="submit" class="pv-button">Find areas</button><?php if($query!==''||$region!==''||$kind!==''):?><a class="dn-atlas-reset" href="<?=pv_h(pv_url('map_select.php'))?>">Reset</a><?php endif;?>
</form>
<div class="dn-atlas-results"><p><strong><?=number_format($total)?></strong> <?=$total===1?'area':'areas'?><?=$query!==''?' matching “'.pv_h($query).'”':''?><?php if($total):?> <span>· Showing <?=($page-1)*$pageSize+1?>–<?=min($page*$pageSize,$total)?></span><?php endif;?></p><span>Choose a card to travel</span></div>
<?php if(!$visible):?><div class="dn-atlas-empty"><strong>No areas found.</strong><p>Try another name or area number, or select every environment.</p><a class="pv-button pv-button-secondary" href="<?=pv_h(pv_url('map_select.php'))?>">Show all Dawn areas</a></div><?php else:?>
<div class="dn-world-card-grid dn-dawn-area-grid"><?php foreach($visible as $area):
    $thumbnail=(string)($area['thumbnail']??'');$hasWild=trim((string)$area['encounter_profile'])!=='';$minLevel=PHP_INT_MAX;$maxLevel=0;
    foreach((array)($profiles[(string)$area['encounter_profile']]['entries']??[]) as $entry){if(count($entry)>=3){$minLevel=min($minLevel,(int)$entry[1]);$maxLevel=max($maxLevel,(int)$entry[2]);}}
    $landings=count((array)($area['landing_points']??[]));$online=(int)($counts[(string)$area['key']]??0);
?><a class="dn-world-card dn-dawn-area-card" href="<?=pv_h(pv_world_area_url('cyberspace',(string)$area['key']))?>"><div class="dn-area-preview"><?php if($thumbnail!==''):?><img src="<?=pv_h(pv_static($thumbnail))?>" alt="" width="480" height="300" loading="lazy" decoding="async"><?php endif;?><span class="dn-area-number"><?=pv_h((string)$area['key'])?></span><?php if($online>0):?><span class="dn-area-online"><i></i> <?=$online?> <?=$online===1?'tamer':'tamers'?></span><?php endif;?></div><div class="dn-area-card-body"><small><?=pv_h((string)($area['region']??$area['category']))?></small><h2><?=pv_h((string)$area['name'])?></h2><p><?=pv_h((string)$area['description'])?></p><div class="dn-area-card-meta"><span<?=$hasWild?' class="has-wild"':''?>><?=$hasWild?($maxLevel>0?'Wild · Lv. '.$minLevel.'–'.$maxLevel:'Wild Digimon'):'Rest area'?></span><?php if($landings>1):?><span><?=$landings?> landings</span><?php endif;?><b aria-hidden="true">Explore →</b></div></div></a><?php endforeach;?></div>
<?php endif;?>
<?php if($pages>1):?><nav class="dn-atlas-pagination" aria-label="Dawn atlas pages"><?php if($page>1):?><a class="pv-button pv-button-secondary" rel="prev" href="<?=pv_h($pageUrl($page-1))?>">← Previous</a><?php else:?><span></span><?php endif;?><span>Page <strong><?=$page?></strong> of <?=$pages?></span><?php if($page<$pages):?><a class="pv-button pv-button-secondary" rel="next" href="<?=pv_h($pageUrl($page+1))?>">Next →</a><?php else:?><span></span><?php endif;?></nav><?php endif;?>
<p class="dn-atlas-note">Movement, roaming encounters and nearby tamers remain live in every area. Open an area's landing menu to reach its separate paths and platforms.</p>
</section></main></div>
<?php pv_page_end(); ?>
