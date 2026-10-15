<?php
declare(strict_types=1);
/** Build fresh-install SQL from the real migration and seed functions. */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../public_html/includes/bootstrap.php';require_once __DIR__.'/../public_html/includes/schema.php';
mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$cfg=pv_config('db');$db=new mysqli($cfg['host'],$cfg['user'],$cfg['pass'],'',(int)$cfg['port']);$db->set_charset('utf8mb4');$temporary='digimon_nxt_build_'.bin2hex(random_bytes(5));$db->query('CREATE DATABASE `'.$temporary.'`');$db->select_db($temporary);
try {
 dn_apply_schema_migrations($db);
 $lines=["-- Digimon NXT v0.7.0: FRESH installation only.","-- Existing saves: use _setup.php > Create / Repair Schema to apply the upgrade.","-- 1000 forms, 768 moves in JSON, schema revision 8. Includes 1000 AI-only rival accounts; bot login is disabled.","SET NAMES utf8mb4;","SET FOREIGN_KEY_CHECKS=0;","CREATE DATABASE IF NOT EXISTS `digimon_nxt` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;","USE `digimon_nxt`;",''];
 $r=$db->query('SHOW TABLES');$tables=[];while($row=$r->fetch_row())$tables[]=$row[0];
 foreach($tables as $table){$ddl=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch_row()[1];$ddl=preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl);$ddl=str_replace('CREATE TABLE `','CREATE TABLE IF NOT EXISTS `',$ddl);$lines[]=$ddl.';';$lines[]='';}
 foreach(['schema_meta','digimon_species','items','news','events','members','members_options','digimon_instances','digimon_party','digimon_scan','digimon_learned_moves','nxt_bots','nxt_ranked_profiles','nxt_ranked_seasons'] as $table){
  $r=$db->query('SELECT * FROM `'.$table.'`');while($row=$r->fetch_assoc()){
   $cols=implode(',',array_map(fn($c)=>'`'.$c.'`',array_keys($row)));$values=implode(',',array_map(fn($v)=>$v===null?'NULL':"'".$db->real_escape_string((string)$v)."'",array_values($row)));
   $lines[]='INSERT INTO `'.$table.'` ('.$cols.') VALUES ('.$values.');';
  }
  $lines[]='';
 }
 $lines[]='SET FOREIGN_KEY_CHECKS=1;';$text=implode("\n",$lines)."\n";
 foreach([__DIR__.'/../database/digimon_nxt_full.sql',__DIR__.'/../public_html/database/digimon_nxt_full.sql',__DIR__.'/../public_html/database/digimon_nxt.sql'] as $file)if(file_put_contents($file,$text)===false)throw new RuntimeException('Unable to write '.$file);
 echo 'Built '.count($tables).' tables and 1000 species in three matching SQL copies.',PHP_EOL;
} finally {$db->select_db('information_schema');$db->query('DROP DATABASE `'.$temporary.'`');}
