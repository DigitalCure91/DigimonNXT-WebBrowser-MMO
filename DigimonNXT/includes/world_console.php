<?php
declare(strict_types=1);
/** Local journal and request policy. No HTTP command transport is provided. */
if (PHP_SAPI !== 'cli' && realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404); exit;
}

const DN_WORLD_LOG_HEADER = "<?php http_response_code(404); exit; ?>\n";
const DN_WORLD_LOG_MAX_BYTES = 10485760;
const DN_WORLD_LOG_ROTATIONS = 5;

function dn_world_clean(string $value, int $limit=320): string {
    $value=preg_replace('/\x1B(?:\[[0-?]*[ -\/]*[@-~]|\][^\x07]*(?:\x07|$))/', '', $value) ?? '';
    $value=preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '';
    $value=preg_replace('/[\x{0080}-\x{009F}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u', '', $value) ?? '';
    return trim(substr($value,0,$limit));
}
function dn_world_ip(): string {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    return filter_var($ip,FILTER_VALIDATE_IP)!==false ? $ip : (PHP_SAPI==='cli'?'local':'unknown');
}
function dn_world_log_directory(): string { return dirname(__DIR__).'/storage/world-console'; }
function dn_world_prepare_directory(): ?string {
    $root=dirname(__DIR__);$dir=$root;
    foreach(['storage','world-console'] as $part){
        $dir.='/'.$part;
        if(is_link($dir))return null;
        if(!is_dir($dir)&&!@mkdir($dir,0700)&&!is_dir($dir))return null;
        if(!is_dir($dir)||is_link($dir))return null;
    }
    return $dir;
}
function dn_world_open_safe(string $path, string $mode='c+b') {
    if(is_link($path)||(file_exists($path)&&!is_file($path)))return false;
    $stream=@fopen($path,$mode);if(!$stream)return false;
    $stat=fstat($stream);
    if(!$stat||($stat['mode']&0170000)!==0100000||is_link($path)){fclose($stream);return false;}
    @chmod($path,0600);return $stream;
}
function dn_world_log_failure(string $reason): void {
    $GLOBALS['dn_world_log_failures']=($GLOBALS['dn_world_log_failures']??0)+1;
    if(empty($GLOBALS['dn_world_log_warned'])){
        $GLOBALS['dn_world_log_warned']=true;
        error_log('Digimon NXT world-console journal unavailable: '.dn_world_clean($reason,50).'. Game requests continue; inspect storage/world-console permissions and disk space.');
    }
    // A separate bounded counter makes congestion/loss visible after the next write.
    $dir=dn_world_prepare_directory();if(!$dir)return;
    $f=dn_world_open_safe($dir.'/losses.php');if(!$f)return;
    if(flock($f,LOCK_EX|LOCK_NB)){
        $raw=stream_get_contents($f,256);$count=(int)trim(substr((string)$raw,strlen(DN_WORLD_LOG_HEADER)));
        rewind($f);ftruncate($f,0);fwrite($f,DN_WORLD_LOG_HEADER.(min(PHP_INT_MAX-1,$count)+1)."\n");fflush($f);flock($f,LOCK_UN);
    }
    fclose($f);
}
/** Write already-sanitized metadata. Never pass request bodies, headers or raw exceptions. */
function dn_world_event(string $category,string $event,string $message,array $context=[]): bool {
    try{
        $identity=$GLOBALS['dn_world_identity']??[];
        $uid=max(0,(int)($context['user_id']??($identity['user_id']??($_SESSION['myid']??0))));
        $name=(string)($context['account']??($identity['account']??($_SESSION['myuser']??($uid?'#'.$uid:'guest'))));
        $at=microtime(true);$seconds=(int)$at;
        $record=['ts'=>gmdate('Y-m-d\TH:i:s',$seconds).sprintf('.%03dZ',(int)(($at-$seconds)*1000)),
            'level'=>in_array($context['level']??'info',['info','warning','error'],true)?($context['level']??'info'):'info',
            'category'=>dn_world_clean($category,24),'event'=>dn_world_clean($event,64),
            'ip'=>!empty($context['bot'])?'local':dn_world_ip(),'account'=>dn_world_clean($name,32),'user_id'=>$uid,'message'=>dn_world_clean($message,480)];
        if(isset($GLOBALS['dn_world_request_id']))$record['request_id']=$GLOBALS['dn_world_request_id'];
        // This allowlist intentionally excludes arbitrary details, queries, tokens and credentials.
        foreach(['method'=>12,'path'=>180,'result'=>24,'area'=>64,'species'=>80,'opponent'=>32,'source'=>24] as $key=>$limit)
            if(isset($context[$key])&&is_scalar($context[$key]))$record[$key]=dn_world_clean((string)$context[$key],$limit);
        foreach(['status','duration_ms','digimon_id','species_id','slot','bits','exp','scan','quantity','target_id','processed','errors','count','journal_losses'] as $key)
            if(isset($context[$key])&&is_numeric($context[$key]))$record[$key]=max(-PHP_INT_MAX,min(PHP_INT_MAX,(int)$context[$key]));
        if(isset($context['bot']))$record['bot']=(bool)$context['bot'];
        $losses=dn_world_journal_losses();if($losses>0)$record['journal_losses']=$losses;
        $line=json_encode($record,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR)."\n";
        $dir=dn_world_prepare_directory();if(!$dir){dn_world_log_failure('directory unavailable');return false;}
        $lock=dn_world_open_safe($dir.'/events.lock');if(!$lock){dn_world_log_failure('unsafe lock file');return false;}
        $deadline=hrtime(true)+50000000;$locked=false;
        do{if(flock($lock,LOCK_EX|LOCK_NB)){$locked=true;break;}usleep(1000);}while(hrtime(true)<$deadline);
        if(!$locked){fclose($lock);dn_world_log_failure('writer busy');return false;}
        try{
            $path=$dir.'/events.php';
            if(is_link($path))throw new RuntimeException('unsafe journal');
            clearstatcache(true,$path);
            if(is_file($path)&&(int)filesize($path)+strlen($line)>DN_WORLD_LOG_MAX_BYTES){
                for($i=DN_WORLD_LOG_ROTATIONS;$i>=1;$i--){
                    $to=$dir.'/events.'.$i.'.php';$from=$i===1?$path:$dir.'/events.'.($i-1).'.php';
                    if(is_link($to)||is_link($from))throw new RuntimeException('unsafe rotation');
                    if(is_file($to)&&!@unlink($to))throw new RuntimeException('rotation removal');
                    if(is_file($from)&&!@rename($from,$to))throw new RuntimeException('rotation rename');
                }
            }
            $out=dn_world_open_safe($path);if(!$out)throw new RuntimeException('journal unavailable');
            try{
                $stat=fstat($out);
                if((int)$stat['size']===0){if(fwrite($out,DN_WORLD_LOG_HEADER)!==strlen(DN_WORLD_LOG_HEADER))throw new RuntimeException('header write');}
                else {rewind($out);if(fread($out,strlen(DN_WORLD_LOG_HEADER))!==DN_WORLD_LOG_HEADER)throw new RuntimeException('invalid journal header');}
                fseek($out,0,SEEK_END);if(fwrite($out,$line)!==strlen($line))throw new RuntimeException('event write');fflush($out);
            }finally{fclose($out);}
        }finally{flock($lock,LOCK_UN);fclose($lock);}
        return true;
    }catch(Throwable){dn_world_log_failure('write failed');return false;}
}
function dn_world_identity(int $uid,string $name): void { $GLOBALS['dn_world_identity']=['user_id'=>$uid,'account'=>dn_world_clean($name,32)]; }
/** Invoke only after the caller's transaction commits. */
function dn_world_game_event(mysqli $db,int $uid,string $event,string $message,array $context=[]): void {
    try{
        $stmt=$db->prepare('SELECT m.username,IF(b.owner_id IS NULL,0,1) is_bot FROM members m LEFT JOIN nxt_bots b ON b.owner_id=m.id WHERE m.id=?');
        $stmt->bind_param('i',$uid);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
        $context['user_id']=$uid;$context['account']=$row['username']??('#'.$uid);$context['bot']=!empty($row['is_bot']);
        dn_world_event($context['bot']?'bots':'game',$event,$message,$context);
    }catch(Throwable){dn_world_log_failure('action identity unavailable');}
}
function dn_world_setting(mysqli $db,string $key,mixed $default=null,bool $refresh=false): mixed {
    $cacheKey=spl_object_id($db).':'.$key;
    if(!$refresh&&array_key_exists($cacheKey,$GLOBALS['dn_world_settings_cache']??[]))return $GLOBALS['dn_world_settings_cache'][$cacheKey];
    try{
        $s=$db->prepare('SELECT value_json FROM nxt_world_settings WHERE setting_key=?');$s->bind_param('s',$key);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
        $value=$row?json_decode($row['value_json'],true,64,JSON_THROW_ON_ERROR):$default;
    }catch(mysqli_sql_exception $e){if($e->getCode()!==1146)throw $e;$value=$default;}
    $GLOBALS['dn_world_settings_cache'][$cacheKey]=$value;return $value;
}
function dn_world_setting_set(mysqli $db,string $key,mixed $value): void {
    if(!preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D',$key))throw new InvalidArgumentException('Invalid world setting.');
    $json=json_encode($value,JSON_THROW_ON_ERROR);$now=time();
    $s=$db->prepare('INSERT INTO nxt_world_settings(setting_key,value_json,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=VALUES(updated_at)');
    $s->bind_param('ssi',$key,$json,$now);$s->execute();$s->close();unset($GLOBALS['dn_world_settings_cache'][spl_object_id($db).':'.$key]);
}
/** Authentication callers only; never invoke inside a gameplay transaction. */
function dn_world_expire_ban(mysqli $db,int $uid): bool {
    try{
        $db->begin_transaction();
        $s=$db->prepare('SELECT id FROM members WHERE id=? FOR UPDATE');$s->bind_param('i',$uid);$s->execute();$s->get_result()->fetch_assoc();$s->close();
        $s=$db->prepare('SELECT expires_at FROM nxt_world_bans WHERE owner_id=? FOR UPDATE');$s->bind_param('i',$uid);$s->execute();$ban=$s->get_result()->fetch_assoc();$s->close();
        $expired=$ban&&(int)$ban['expires_at']>0&&(int)$ban['expires_at']<=time();
        if($expired){
            $s=$db->prepare('UPDATE members SET banned=0 WHERE id=?');$s->bind_param('i',$uid);$s->execute();$s->close();
            $s=$db->prepare('DELETE FROM nxt_world_bans WHERE owner_id=?');$s->bind_param('i',$uid);$s->execute();$s->close();
        }
        $db->commit();return (bool)$expired;
    }catch(Throwable $e){$db->rollback();if($e instanceof mysqli_sql_exception&&$e->getCode()===1146)return false;throw $e;}
}
function dn_world_session_version(mysqli $db,int $uid): int {
    try{$s=$db->prepare('SELECT session_version FROM members WHERE id=?');$s->bind_param('i',$uid);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();return (int)($row['session_version']??0);}
    catch(mysqli_sql_exception $e){if($e->getCode()===1054)return 0;throw $e;}
}
function dn_world_maintenance(mysqli $db,bool $refresh=false): array {
    $value=dn_world_setting($db,'world.maintenance',['enabled'=>false,'message'=>'The server is receiving maintenance. Please try again shortly.'],$refresh);
    return is_array($value)?$value:['enabled'=>(bool)$value,'message'=>'The server is receiving maintenance. Please try again shortly.'];
}
function dn_world_stop(int $status,string $title,string $message): never {
    http_response_code($status);header('Cache-Control: no-store');if($status===503)header('Retry-After: 60');
    if(str_contains((string)($_SERVER['HTTP_ACCEPT']??''),'application/json')||str_starts_with(basename((string)($_SERVER['SCRIPT_NAME']??'')),'ajax')||in_array(basename((string)($_SERVER['SCRIPT_NAME']??'')),['tamer_pulse.php','world_notice.php','world_map_move.php','world_map_presence.php','world_encounter.php'],true)){
        header('Content-Type: application/json; charset=UTF-8');echo json_encode(['ok'=>false,'error'=>$title,'message'=>$message],JSON_INVALID_UTF8_SUBSTITUTE);exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.pv_h($title).' · Digimon NXT</title><style>body{margin:0;background:#081525;color:#eaf5ff;font:18px/1.65 system-ui,sans-serif;display:grid;min-height:100vh;place-items:center}main{max-width:620px;margin:24px;padding:36px;border:1px solid #29475d;border-radius:16px;background:#102236}small{color:#5de7fa;letter-spacing:.12em}h1{line-height:1.2}a{color:#e5fa55}</style><main><small>DIGIMON NXT / TAMER NETWORK</small><h1>'.pv_h($title).'</h1><p>'.pv_h($message).'</p><a href="'.pv_h(pv_url('login.php')).'">Return to sign in</a></main></html>';exit;
}
function dn_world_clear_session(): void {
    $_SESSION=[];
    if(session_status()===PHP_SESSION_ACTIVE)session_regenerate_id(true);
}
/** Runs before page code, including AJAX, so kicks/bans apply to existing sessions. */
function dn_world_request_policy(): void {
    if(PHP_SAPI==='cli')return;
    $script=basename((string)($_SERVER['SCRIPT_NAME']??''));
    if(in_array($script,['_setup.php','_health.php','_client_manifest.php'],true))return;
    $uid=(int)($_SESSION['myid']??0);$name=(string)($_SESSION['myuser']??'');
    if($uid>0)dn_world_identity($uid,$name);
    try{
        $db=pv_db();$role='';
        if(dn_world_ip_banned($db)){dn_world_event('auth','ip.blocked','Blocked a suspended network address',['level'=>'warning']);dn_world_stop(403,'Connection unavailable','This network address has been suspended. Contact the server administrator.');}
        if($uid>0){
            $s=$db->prepare('SELECT id,username,banned,role FROM members WHERE id=?');$s->bind_param('i',$uid);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
            if(!$row){dn_world_clear_session();dn_world_stop(401,'Please sign in again','This tamer session is no longer available.');}
            dn_world_identity($uid,(string)$row['username']);
            if((int)$row['banned']!==0&&!dn_world_expire_ban($db,$uid)){
                dn_world_event('auth','session.banned','Blocked a banned tamer session',['level'=>'warning']);dn_world_clear_session();dn_world_stop(403,'Account unavailable','This account has been suspended. Contact the server administrator.');
            }
            $version=dn_world_session_version($db,$uid);
            if((int)($_SESSION['session_version']??0)!==$version){
                dn_world_event('auth','session.revoked','An administrator revoked this session',['level'=>'warning']);dn_world_clear_session();dn_world_stop(401,'Please sign in again','Your session has ended. Sign in again to continue.');
            }
            $_SESSION['session_version']=$version;$_SESSION['myuser']=(string)$row['username'];$role=(string)$row['role'];
        }
        if(!in_array($script,['login.php','checklogin.php','logout.php'],true)){
            $maintenance=dn_world_maintenance($db);
            if(!empty($maintenance['enabled'])&&$role!=='admin')dn_world_stop(503,'World maintenance',dn_world_clean((string)($maintenance['message']??'Please try again shortly.'),240));
        }
    }catch(Throwable){
        // Unauthenticated setup remains reachable before a database exists. Existing
        // sessions fail closed if account policy cannot be checked.
        if($uid>0)dn_world_stop(503,'World temporarily unavailable','The server could not verify your tamer session. Please try again shortly.');
    }
}
function dn_world_request_start(): void {
    if(PHP_SAPI==='cli'||isset($GLOBALS['dn_world_request_started']))return;
    $GLOBALS['dn_world_request_started']=(float)($_SERVER['REQUEST_TIME_FLOAT']??microtime(true));$GLOBALS['dn_world_request_id']=bin2hex(random_bytes(8));
    if(!empty($_SESSION['myid']))dn_world_identity((int)$_SESSION['myid'],(string)($_SESSION['myuser']??''));
    register_shutdown_function(static function():void {
        $status=(int)http_response_code();if($status<100)$status=200;
        $error=error_get_last();if($error&&in_array($error['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true)){
            $status=max(500,$status);dn_world_event('system','request.failed','Request ended with a PHP error; details are restricted to the PHP error log',['level'=>'error']);
        }
        $path=explode('?',(string)($_SERVER['SCRIPT_NAME']??'/'),2)[0];$path=preg_replace('~(\.php)/.*$~i','$1',$path)??'/';
        // SCRIPT_NAME, rather than REQUEST_URI, deliberately excludes PATH_INFO and query values.
        dn_world_event('request','http.request','HTTP request completed',['level'=>$status>=500?'error':($status>=400?'warning':'info'),'method'=>strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET')),'path'=>$path,'status'=>$status,'duration_ms'=>(int)round((microtime(true)-$GLOBALS['dn_world_request_started'])*1000)]);
    });
}

function dn_world_battle_completed(mysqli $db,int $uid,array $b): void {
    dn_world_game_event($db,$uid,'wild.complete','Wild battle '.$b['result'].' in '.$b['area'].'; party fully restored',['result'=>$b['result'],'area'=>$b['area'],'species'=>$b['enemy_name']??'','bits'=>$b['bits_gain']??0,'exp'=>$b['exp_gain']??0,'scan'=>$b['scan_reward']??0]);
}

function dn_world_journal_losses(): int {
    $dir=dn_world_log_directory();$path=$dir.'/losses.php';
    if(is_link(dirname($dir))||is_link($dir)||is_link($path)||!is_file($path))return 0;
    $s=@fopen($path,'rb');if(!$s)return 0;$value=0;
    if(flock($s,LOCK_SH|LOCK_NB)){$raw=stream_get_contents($s,256);if(str_starts_with((string)$raw,DN_WORLD_LOG_HEADER))$value=max(0,(int)trim(substr($raw,strlen(DN_WORLD_LOG_HEADER))));flock($s,LOCK_UN);}
    fclose($s);return $value;
}
function dn_world_ip_banned(mysqli $db): bool {
    $ip=dn_world_ip();$packed=@inet_pton($ip);if($packed===false)return false;$ip=(string)inet_ntop($packed);$now=time();
    try{$s=$db->prepare('SELECT ip FROM nxt_world_ip_bans WHERE ip=? AND (expires_at=0 OR expires_at>?)');$s->bind_param('si',$ip,$now);$s->execute();$found=(bool)$s->get_result()->fetch_assoc();$s->close();return $found;}
    catch(mysqli_sql_exception $e){if($e->getCode()===1146)return false;throw $e;}
}
