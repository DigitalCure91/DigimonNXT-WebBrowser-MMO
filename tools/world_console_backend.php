<?php
declare(strict_types=1);

/** Local, elevated CLI only. This program never opens a network listener. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors','0');
error_reporting(E_ALL);

function world_backend_reply(array $reply, int $code=0): never {
    echo json_encode($reply,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
    exit($code);
}
function world_backend_local_admin(): void {
    foreach(['SSH_CONNECTION','SSH_CLIENT','SSH_TTY'] as $name)if(getenv($name)!==false && getenv($name)!=='')throw new RuntimeException('Remote shell sessions cannot access the world console. Open it directly on the server PC.');
    $session=(string)getenv('SESSIONNAME');
    if($session!=='' && stripos($session,'console')!==0)throw new RuntimeException('Use the physical server PC console session. Remote desktop sessions are not supported.');
    if(PHP_OS_FAMILY==='Windows') {
        $root=getenv('SystemRoot')?:'C:\\Windows';
        $exe=$root.'\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if(!is_file($exe))throw new RuntimeException('Windows PowerShell is required to verify local administrator access.');
        $script= <<<'POWERSHELL'
$ErrorActionPreference='Stop'
Add-Type -TypeDefinition 'using System; using System.Diagnostics; using System.Runtime.InteropServices; public static class NxtPhysicalConsole { [DllImport("kernel32.dll")] public static extern uint WTSGetActiveConsoleSessionId(); [DllImport("wtsapi32.dll", SetLastError=true)] private static extern bool WTSQuerySessionInformation(IntPtr server, int session, int info, out IntPtr buffer, out int bytes); [DllImport("wtsapi32.dll")] private static extern void WTSFreeMemory(IntPtr memory); public static bool IsLocal() { int current=Process.GetCurrentProcess().SessionId; if(!Environment.UserInteractive || current==0 || WTSGetActiveConsoleSessionId()!=(uint)current) return false; IntPtr buffer=IntPtr.Zero; int count=0; try { return WTSQuerySessionInformation(IntPtr.Zero,current,16,out buffer,out count) && count>=2 && Marshal.ReadInt16(buffer)==0; } finally { if(buffer!=IntPtr.Zero) WTSFreeMemory(buffer); } } }'
if (-not [NxtPhysicalConsole]::IsLocal()) { exit 6 }
$id=[Security.Principal.WindowsIdentity]::GetCurrent()
$p=New-Object Security.Principal.WindowsPrincipal($id)
if ($p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) { exit 0 } else { exit 5 }
POWERSHELL;
        $process=proc_open([$exe,'-NoLogo','-NoProfile','-NonInteractive','-Command',$script],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Could not verify administrator access.');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$until=microtime(true)+5;$code=-1;
        do{$state=proc_get_status($process);if(!$state['running']){$code=$state['exitcode'];break;}usleep(20000);}while(microtime(true)<$until);
        if($state['running'])proc_terminate($process);fclose($pipes[1]);fclose($pipes[2]);proc_close($process);
        if($code!==0)throw new RuntimeException('Run the world console as a local Windows administrator.');
    } elseif(!function_exists('posix_geteuid') || posix_geteuid()!==0) {
        throw new RuntimeException('Local administrator privileges are required. On Unix, launch the console as root.');
    }
}

try {
    world_backend_local_admin();
    $gameDir=dirname(__DIR__).'/public_html';$mode='';
    for($i=1;$i<$argc;$i++){
        if($argv[$i]==='--game-dir' && isset($argv[$i+1])){$gameDir=$argv[++$i];continue;}
        if(in_array($argv[$i],['--execute','--describe','--status'],true)&&$mode===''){$mode=$argv[$i];continue;}
        throw new InvalidArgumentException('Usage: world_console_backend.php [--game-dir <public_html>] --execute|--describe|--status');
    }
    if($mode==='')throw new InvalidArgumentException('Choose --execute, --describe or --status.');
    $gameDir=realpath($gameDir);
    if($gameDir===false||!is_file($gameDir.'/config/app.php')||!is_file($gameDir.'/includes/world_commands.php'))throw new RuntimeException('Choose the installed DigimonNXT web root containing config/app.php and includes/world_commands.php.');
    // Installed files are selected only by the local OS administrator, never HTTP.
    require_once $gameDir.'/includes/world_commands.php';
    if($mode==='--describe')world_backend_reply(['ok'=>true,'lines'=>[],'commands'=>dn_world_commands(),'protocol'=>1]);
    $db=pv_db();mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db->query("SET time_zone='+00:00'");
    $schema=dn_row($db,"SELECT meta_value FROM schema_meta WHERE meta_key='schema_version'");
    if((int)($schema['meta_value']??0)<8)throw new RuntimeException('World console schema is not installed. Run Create / Repair Schema once; this preserves existing saves.');
    if($mode==='--status') {
        $reply=dn_world_execute($db,['line'=>'status']);
        $reply['data']['game_dir']=$gameDir;$reply['data']['log_path']=$gameDir.'/storage/world-console/events.php';
        $reply['data']['version']=(string)pv_config('asset_version','0.6.0');$reply['data']['protocol']=1;
        world_backend_reply($reply,$reply['ok']?0:1);
    }
    $raw=stream_get_contents(STDIN,8193);
    if($raw===false||strlen($raw)>8192)throw new InvalidArgumentException('Command request must contain at most 8,192 bytes.');
    $request=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
    if(!is_array($request)||array_is_list($request))throw new InvalidArgumentException('Expected one command request object.');
    foreach(array_keys($request) as $key)if(!in_array($key,['line','secret','confirm'],true))throw new InvalidArgumentException('Unsupported command request field.');
    if(isset($request['confirm'])&&!is_bool($request['confirm']))throw new InvalidArgumentException('Confirmation must be a boolean.');
    $raw='';$reply=dn_world_execute($db,$request);unset($request);world_backend_reply($reply,$reply['ok']?0:1);
}catch(Throwable $e){
    // Never return SQL/config details: they can contain credentials or private values.
    $message=$e instanceof mysqli_sql_exception?'Database is unavailable or requires setup. Check the local game configuration and run Create / Repair Schema.':($e instanceof JsonException?'Invalid command JSON.':$e->getMessage());
    world_backend_reply(['ok'=>false,'lines'=>[$message]],1);
}
