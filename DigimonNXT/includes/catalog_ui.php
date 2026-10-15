<?php
declare(strict_types=1);
require_once __DIR__.'/digimon.php';
function dn_catalog_listing(mysqli $db,int $uid): array {
    $q=mb_substr_available(trim((string)($_GET['q']??'')),80);$stage=(string)($_GET['stage']??'');$ready=(string)($_GET['ready']??'')==='1';$variant=in_array($_GET['variant']??'',['normal','paradox'],true)?$_GET['variant']:'';$page=max(1,(int)($_GET['page']??1));$size=30;
    $stages=['Baby','In-Training','Rookie','Champion','Armor','Ultimate','Mega','Ultra'];$where=' WHERE 1=1';$params=[$uid];
    if($q!==''){$where.=' AND s.name LIKE ?';$params[]='%'.$q.'%';}
    if(in_array($stage,$stages,true)){$where.=' AND s.stage=?';$params[]=$stage;}else $stage='';
    if($variant!=='')$where.=$variant==='paradox'?" AND s.slug LIKE 'paradox-%'":" AND s.slug NOT LIKE 'paradox-%'";
    if($ready)$where.=' AND COALESCE(sc.scan_percent,0)>=100';
    $from=' FROM digimon_species s LEFT JOIN digimon_scan sc ON sc.species_id=s.id AND sc.owner_id=?';
    $total=(int)(dn_row($db,'SELECT COUNT(*) n'.$from.$where,$params)['n']??0);$pages=max(1,(int)ceil($total/$size));$page=min($page,$pages);
    $sql='SELECT s.*,COALESCE(sc.scan_percent,0) scan_percent,COALESCE(sc.encounters,0) encounters,COALESCE(sc.first_seen,0) first_seen,(SELECT COUNT(*) FROM digimon_instances d WHERE d.owner_id=? AND d.species_id=s.id) owned'.$from.$where.' ORDER BY FIELD(s.stage,"Baby","In-Training","Rookie","Champion","Armor","Ultimate","Mega","Ultra"),s.name LIMIT ? OFFSET ?';
    $rows=dn_rows($db,$sql,array_merge([$uid],$params,[$size,($page-1)*$size]));return compact('rows','total','pages','page','q','stage','ready','stages','variant');
}
function mb_substr_available(string $text,int $length): string {return function_exists('mb_substr')?mb_substr($text,0,$length):substr($text,0,$length);}
function dn_catalog_filters(array $list): void { ?>
<form class="dn-catalog-tools" method="get"><input aria-label="Search Digimon" type="search" name="q" value="<?=pv_h($list['q'])?>" placeholder="Search Digimon"><select aria-label="Stage" name="stage"><option value="">All stages</option><?php foreach($list['stages'] as $s):?><option value="<?=pv_h($s)?>" <?=$s===$list['stage']?'selected':''?>><?=pv_h($s)?></option><?php endforeach;?></select><select name="variant" aria-label="Variant"><option value="">All variants</option><option value="normal" <?=$list['variant']==='normal'?'selected':''?>>Standard</option><option value="paradox" <?=$list['variant']==='paradox'?'selected':''?>>Paradox</option></select><label><input type="checkbox" name="ready" value="1" <?=$list['ready']?'checked':''?>> Ready to materialize</label><button class="pv-button pv-button-secondary">Filter</button></form>
<?php }
function dn_catalog_pager(array $list,string $page): void {
    $query=['q'=>$list['q'],'stage'=>$list['stage'],'variant'=>$list['variant'],'ready'=>$list['ready']?'1':'0'];echo '<nav class="dn-pagination" aria-label="Catalog pages">';
    if($list['page']>1)echo '<a href="'.pv_h(pv_url($page.'?'.http_build_query($query+['page'=>$list['page']-1]))).'">Previous</a>';
    echo '<span>'.$list['total'].' forms · Page '.$list['page'].' / '.$list['pages'].'</span>';
    if($list['page']<$list['pages'])echo '<a href="'.pv_h(pv_url($page.'?'.http_build_query($query+['page'=>$list['page']+1]))).'">Next</a>';echo '</nav>';
}
