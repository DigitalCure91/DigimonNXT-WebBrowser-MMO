<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Journal tests operate only in a private temporary tree, never the live journal.
$testRoot=sys_get_temp_dir().'/dn-world-journal-'.bin2hex(random_bytes(6));
mkdir($testRoot.'/public_html/includes',0700,true);
copy(dirname(__DIR__).'/public_html/includes/world_console.php',$testRoot.'/public_html/includes/world_console.php');
require $testRoot.'/public_html/includes/world_console.php';
ini_set('log_errors','1');ini_set('error_log',$testRoot.'/errors.log');
$passed=[];
function check(bool $ok,string $label): void {global $passed;if(!$ok)throw new RuntimeException($label);$passed[]=$label;}
function records(): array {$raw=(string)file_get_contents(dn_world_log_directory().'/events.php');return array_values(array_filter(array_map(static fn($line)=>json_decode($line,true),explode("\n",$raw)),static fn($row)=>is_array($row)));}
function cleanup(string $path): void {if(is_link($path)||!is_dir($path)){@unlink($path);return;}foreach(new DirectoryIterator($path) as $entry){if(!$entry->isDot())cleanup($entry->getPathname());}@rmdir($path);}
try {
    $_SERVER['REMOTE_ADDR']='192.0.2.3';$_SERVER['HTTP_X_FORWARDED_FOR']='203.0.113.90';$_SESSION=['myid'=>42,'myuser'=>'SafeTamer'];
    check(dn_world_event('game','test.action',"Safe\nmessage\x1b[31m RED\x1b[0m",['password'=>'NeverWriteThisPassword','query'=>'token=NeverWriteThisToken','duration_ms'=>12]),'event write');
    $raw=(string)file_get_contents(dn_world_log_directory().'/events.php');$row=records()[0];
    check(str_starts_with($raw,DN_WORLD_LOG_HEADER),'PHP exit guard');
    check(!str_contains($raw,'NeverWriteThis'),'unlisted credentials and queries omitted');
    check(!str_contains($row['message'],"\n")&&!str_contains($row['message'],"\x1b"),'terminal control bytes scrubbed');
    check($row['ip']==='192.0.2.3'&&$row['account']==='SafeTamer'&&$row['user_id']===42,'real peer and authenticated identity');
    check((bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D',$row['ts']),'UTC timestamp');
    $path=dn_world_log_directory().'/events.php';$s=fopen($path,'c+b');ftruncate($s,DN_WORLD_LOG_MAX_BYTES);fclose($s);
    check(dn_world_event('system','rotate','Rotate'),'rotation write');
    check(is_file(dn_world_log_directory().'/events.1.php')&&count(records())===1,'bounded rotation preserves prior file');
    $worker=$testRoot.'/writer.php';file_put_contents($worker,'<?php require '.var_export($testRoot.'/public_html/includes/world_console.php',true).';for($i=0;$i<12;$i++)if(!dn_world_event("system","parallel","Parallel writer"))exit(1);');
    $procs=[];for($i=0;$i<4;$i++)$procs[]=proc_open([PHP_BINARY,$worker],[['pipe','r'],['file',$testRoot.'/worker-out','a'],['file',$testRoot.'/worker-error','a']],$pipes);
    foreach($procs as $proc)check(is_resource($proc)&&proc_close($proc)===0,'concurrent writer completed');
    check(count(records())===49,'concurrent writes preserve all complete JSON records');
    $lock=fopen(dn_world_log_directory().'/events.lock','c+b');flock($lock,LOCK_EX);$start=microtime(true);
    check(!dn_world_event('system','busy','Lock contention'),'busy writer returns failure');
    check(microtime(true)-$start<0.5,'contention bounded under half a second');flock($lock,LOCK_UN);fclose($lock);
    check(dn_world_journal_losses()===1,'write loss counter visible');dn_world_event('system','recovered','Recovered');$rows=records();
    check(end($rows)['journal_losses']===1,'write loss count included after recovery');
    $prior=(string)file_get_contents($path);file_put_contents($path,'UNGUARDED');check(!dn_world_event('system','invalid','Invalid journal'),'unguarded journal refused');check(file_get_contents($path)==='UNGUARDED','invalid journal unchanged');file_put_contents($path,$prior);
    if(function_exists('symlink')){
        $outside=$testRoot.'/outside';file_put_contents($outside,'DO NOT CHANGE');unlink($path);
        if(@symlink($outside,$path)){check(!dn_world_event('system','symlink','Unsafe'),'symlink journal refused');check(file_get_contents($outside)==='DO NOT CHANGE','symlink target preserved');unlink($path);}
    }
    echo json_encode(['ok'=>true,'checks'=>count($passed),'passed'=>$passed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
} finally {cleanup($testRoot);}
