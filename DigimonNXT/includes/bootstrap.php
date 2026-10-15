<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('DIGIMONNXTSESSID');
    session_set_cookie_params([
        'lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly'=>true,'samesite'=>'Lax'
    ]);
    session_start();
}

$GLOBALS['pv_config'] = require dirname(__DIR__) . '/config/app.php';
$GLOBALS['pv_config']['asset_version'] = require dirname(__DIR__) . '/config/version.php';
if (function_exists('mysqli_report')) mysqli_report(MYSQLI_REPORT_OFF);

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
}

function pv_config(?string $key=null, mixed $default=null): mixed {
    $cfg=$GLOBALS['pv_config'] ?? [];
    if($key===null)return $cfg;
    return $cfg[$key] ?? $default;
}
function pv_base(string $path=''): string {
    $base=rtrim((string)pv_config('base_path',''),'/');
    if($path==='')return $base;
    return $base.'/'.ltrim($path,'/');
}
function pv_url(string $path=''): string { return pv_base($path); }
function pv_asset(string $path): string { return pv_base('assets/'.ltrim($path,'/')); }
function pv_static(string $path): string { return pv_base('html/static/'.ltrim($path,'/')); }
function pv_asset_version(): string { return rawurlencode((string)pv_config('asset_version','0')); }
function pv_static_file(string $path,string $fallback='favicon.png'): string {
    $rel=str_replace('\\','/',ltrim($path,'/'));
    $abs=dirname(__DIR__).'/html/static/'.$rel;
    if(is_file($abs))return pv_static($rel);
    if($fallback==='favicon.png')return pv_url('favicon.png');
    return pv_static(ltrim($fallback,'/'));
}
function pv_h(?string $value): string { return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function pv_redirect(string $path,int $status=302): never { header('Location: '.pv_url($path),true,$status); exit; }
function pv_csrf_token(): string {
    if(empty($_SESSION['csrf_token'])||!is_string($_SESSION['csrf_token']))$_SESSION['csrf_token']=bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}
function pv_csrf_field(): string { return '<input type="hidden" name="csrf_token" value="'.pv_h(pv_csrf_token()).'">'; }
function pv_verify_csrf(): bool {
    $given=(string)($_POST['csrf_token']??($_SERVER['HTTP_X_CSRF_TOKEN']??''));
    $known=(string)($_SESSION['csrf_token']??'');
    return $known!==''&&$given!==''&&hash_equals($known,$given);
}
function pv_require_csrf(): void { if(!pv_verify_csrf())throw new RuntimeException('The form expired. Please try again.'); }
function pv_is_logged_in(): bool { return !empty($_SESSION['myid'])&&is_numeric($_SESSION['myid'])&&!empty($_SESSION['myuser']); }
function pv_require_login(): void { if(!pv_is_logged_in())pv_redirect('login.php?expired=1'); }
function pv_password_hash(string $plain): string { return password_hash($plain,PASSWORD_DEFAULT); }
function pv_password_matches(string $plain,string $stored): bool {
    if(strlen($stored)===32&&ctype_xdigit($stored))return hash_equals(strtolower($stored),md5($plain));
    return password_verify($plain,$stored);
}
function pv_log(string $message): void {
    $dir=dirname(__DIR__).'/storage/logs'; if(!is_dir($dir))@mkdir($dir,0775,true);
    @file_put_contents($dir.'/digimon-nxt.log','['.date('c').'] '.$message.PHP_EOL,FILE_APPEND|LOCK_EX);
}
function pv_db(): mysqli {
    if(!class_exists('mysqli'))throw new RuntimeException('PHP mysqli extension is required.');
    if(isset($GLOBALS['mysql_connection'])&&$GLOBALS['mysql_connection'] instanceof mysqli)return $GLOBALS['mysql_connection'];
    $cfg=(array)(pv_config('db',[]));
    $conn=@new mysqli((string)($cfg['host']??'127.0.0.1'),(string)($cfg['user']??'root'),(string)($cfg['pass']??''),(string)($cfg['name']??'digimon_nxt'),(int)($cfg['port']??3306));
    if($conn->connect_errno)throw new RuntimeException('Game database connection failed.');
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset((string)($cfg['charset']??'utf8mb4'));
    $GLOBALS['mysql_connection']=$conn; return $conn;
}
function pv_table_exists(string $table): bool {
    try{$db=pv_db();$stmt=$db->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');if(!$stmt)return false;$stmt->bind_param('s',$table);$stmt->execute();$ok=(bool)$stmt->get_result()->fetch_row();$stmt->close();return $ok;}catch(Throwable){return false;}
}
function pv_request_int(string $key,int $default=0,?int $min=null,?int $max=null): int {
    $v=filter_input(INPUT_POST,$key,FILTER_VALIDATE_INT); if($v===false||$v===null)$v=$default;
    $v=(int)$v;if($min!==null)$v=max($min,$v);if($max!==null)$v=min($max,$v);return$v;
}

require_once __DIR__.'/world_console.php';
dn_world_request_start();
dn_world_request_policy();
