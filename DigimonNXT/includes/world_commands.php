<?php
declare(strict_types=1);

// This engine is deliberately unavailable to HTTP, including localhost HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/digimon.php';
require_once __DIR__.'/economy.php';
require_once __DIR__.'/bots.php';
require_once __DIR__.'/world_console.php';

/** Authoritative command catalog used by the console and generated command guide. */
function dn_world_commands(): array {
    $spec = [
        ['help','help [command]','List commands or explain one command.',0,0,0,0,2],
        ['status','status','Show database, player, battle, bot and maintenance state.',0,0,0,0,0],
        ['online','online [limit]','List human accounts seen in the past five minutes, with IP and activity.',0,0,0,0,1],
        ['account list','account list [search] [page]','Search human and AI accounts, 30 per page. Quote names containing spaces.',0,0,0,0,2],
        ['account info','account info <account>','Inspect an account, role, ban, funds and progression totals.',0,0,0,1,1],
        ['account create','account create <username> <email> [starter]','Create a playable account with three level-5 partners. Password is prompted privately.',1,1,0,2,3],
        ['account password','account password <account>','Set a password through a private prompt and revoke existing sessions.',1,1,0,1,1],
        ['account role','account role <account> <tamer|moderator|admin>','Change access role and revoke existing sessions. Protects the last unbanned human admin.',1,0,1,2,2],
        ['account ban','account ban <account> <minutes|permanent> <reason>','Ban an account and revoke sessions. Timed bans expire automatically.',1,0,1,3,3],
        ['account unban','account unban <account>','Remove an account ban. Does not re-enable a disabled bot.',1,0,0,1,1],
        ['account kick','account kick <account> [reason]','Revoke every existing login for this account without banning it.',1,0,0,1,2],
        ['account unlock','account unlock <account>','Clear failed-login throttle records for this username.',1,0,0,1,1],
        ['banlist','banlist [page]','List banned accounts, reasons and expiry, 30 per page.',0,0,0,0,1],
        ['lookup species','lookup species <search> [page]','Find catalog species IDs/slugs and stages, 30 per page.',0,0,0,1,2],
        ['lookup items','lookup items <search> [page]','Find item IDs/slugs and stack limits, 30 per page.',0,0,0,1,2],
        ['givemoney','givemoney <account> <amount>','Add 1–999,999,999 Bits, without exceeding the balance limit.',1,0,0,2,2],
        ['takemoney','takemoney <account> <amount>','Remove Bits; refuses insufficient funds.',1,0,1,2,2],
        ['setmoney','setmoney <account> <amount>','Set the Bits balance to 0–999,999,999.',1,0,1,2,2],
        ['givedigimon','givedigimon <account> <species> [level] [abi]','Grant a catalog Digimon to collection with valid stats, moves and discovery data. Defaults: level 1, ABI 0.',1,0,0,2,4],
        ['removedigimon','removedigimon <account> <instance-id>','Permanently remove a reserve Digimon. Refuses party members, trade listings and active battles.',1,0,1,2,2],
        ['digimon list','digimon list <account> [page]','List owned instance IDs, forms, levels and party slots, 30 per page.',0,0,0,1,2],
        ['digimon info','digimon info <account> <instance-id>','Inspect owned Digimon stats, ABI, CAM and learned moves.',0,0,0,2,2],
        ['digimon level','digimon level <account> <instance-id> <level>','Set level 1–99, reset current EXP, recalculate stats, restore vitals and learn eligible moves. Existing moves remain learned.',1,0,1,3,3],
        ['digimon abi','digimon abi <account> <instance-id> <abi>','Set ABI 0–200; refuses reductions below the existing training budget.',1,0,1,3,3],
        ['digimon cam','digimon cam <account> <instance-id> <cam>','Set CAM 0–100.',1,0,0,3,3],
        ['heal','heal <account> [party|all]','Fully restore HP and SP outside combat. Defaults to party.',1,0,0,1,2],
        ['party','party <account>','Inspect all six party slots; the first three are the battle team.',0,0,0,1,1],
        ['party set','party set <account> <slot> <instance-id>','Assign an owned, unlisted Digimon to slot 1–6 outside combat.',1,0,0,3,3],
        ['party remove','party remove <account> <slot>','Remove a party slot outside combat. Keeps at least one active partner.',1,0,1,2,2],
        ['inventory','inventory <account>','List owned item quantities and stack limits.',0,0,0,1,1],
        ['giveitem','giveitem <account> <item> <quantity>','Add items within their catalog stack limit.',1,0,0,3,3],
        ['takeitem','takeitem <account> <item> <quantity>','Remove owned items; refuses insufficient quantity.',1,0,1,3,3],
        ['scan list','scan list <account> [page]','List discovered species and scan percentages, 30 per page.',0,0,0,1,2],
        ['scan give','scan give <account> <species> <percent>','Add 1–200 scan points, capped at 200%.',1,0,0,3,3],
        ['scan set','scan set <account> <species> <percent>','Set scan data to 0–200% without altering battle records.',1,0,1,3,3],
        ['announce','announce <message> [seconds]','Show a live banner for 1–86400 seconds (default 300) and notify every human account. Maximum 500 bytes.',1,0,1,1,2],
        ['announce clear','announce clear','Clear the current live announcement banner. Previously delivered notifications remain.',1,0,0,0,0],
        ['ip ban','ip ban <address> <minutes|permanent> <reason>','Block one exact IPv4 or IPv6 address. Local loopback addresses cannot be banned.',1,0,1,3,3],
        ['ip unban','ip unban <address>','Remove an exact IP address ban.',1,0,0,1,1],
        ['ip list','ip list [page]','List current exact IP address bans, 30 per page.',0,0,0,0,1],
        ['mail','mail <account> <title> <message>','Deliver a system support notification. Quote title and message separately.',1,0,0,3,3],
        ['maintenance','maintenance','Show maintenance status and player-facing message.',0,0,0,0,0],
        ['maintenance on','maintenance on [message]','Block non-admin gameplay and new logins, and pause autonomous actions.',1,0,1,0,1],
        ['maintenance off','maintenance off','Reopen the game. A separate manual bots pause stays paused.',1,0,0,0,0],
        ['bots status','bots status','Show autonomous tamer counts, scheduler heartbeat and pause state.',0,0,0,0,0],
        ['bots run','bots run [batch]','Run one bounded scheduler pulse of 1–250 due bots; respects maintenance/pause and worker lock.',1,0,0,0,1],
        ['bots pause','bots pause','Pause new autonomous actions for console, browser and standalone workers.',1,0,0,0,0],
        ['bots resume','bots resume','Resume autonomous actions; maintenance still takes precedence.',1,0,0,0,0],
        ['bots enable','bots enable <account>','Enable one AI tamer unless banned; preserves its history.',1,0,0,1,1],
        ['bots disable','bots disable <account>','Disable one AI tamer; preserves its history.',1,0,0,1,1],
        ['audit','audit [limit] [account]','Read the last 1–100 redacted administrator command records.',0,0,0,0,2],
        ['logs','logs [limit] [category]','Read the last 1–100 retained world journal events, optionally filtered by category.',0,0,0,0,2],
    ];
    $out=[];
    foreach ($spec as [$name,$usage,$description,$mutates,$secret,$confirmation,$min,$max]) {
        $out[]=['name'=>$name,'usage'=>$usage,'description'=>$description,'mutates'=>(bool)$mutates,'secret'=>(bool)$secret,'confirmation'=>(bool)$confirmation,'min_args'=>$min,'max_args'=>$max];
    }
    return $out;
}

/** Quotes are supported; nothing is evaluated or delegated to a command shell. */
function dn_world_tokenize(string $line): array {
    if (strlen($line)>2048 || preg_match('/[\x00-\x1f\x7f]/', $line)) throw new InvalidArgumentException('Use one command of at most 2,048 bytes, without control characters.');
    $line=trim($line); if (str_starts_with($line,'.')) $line=substr($line,1);
    $tokens=[];$value='';$quote=null;$active=false;$length=strlen($line);
    for($i=0;$i<$length;$i++) {
        $c=$line[$i];
        if($quote!==null) {
            if($c===$quote){$quote=null;continue;}
            if($c==='\\' && $i+1<$length && in_array($line[$i+1],[$quote,'\\'],true)) $c=$line[++$i];
            $value.=$c;continue;
        }
        if($c==='"'||$c==="'"){$quote=$c;$active=true;continue;}
        if(ctype_space($c)){if($active){$tokens[]=$value;$value='';$active=false;}continue;}
        $value.=$c;$active=true;
    }
    if($quote!==null)throw new InvalidArgumentException('Close the quoted argument before running the command.');
    if($active)$tokens[]=$value;
    if(!$tokens)throw new InvalidArgumentException('Type help to see the command list.');
    return $tokens;
}

function dn_world_resolve(array $tokens): array {
    $aliases=['ban'=>['account','ban'],'unban'=>['account','unban'],'kick'=>['account','kick'],'setlevel'=>['digimon','level']];
    $first=strtolower($tokens[0]);if(isset($aliases[$first]))$tokens=array_merge($aliases[$first],array_slice($tokens,1));
    foreach([2,1] as $n){$name=strtolower(implode(' ',array_slice($tokens,0,$n)));foreach(dn_world_commands() as $command)if($command['name']===$name)return[$command,array_slice($tokens,$n)];}
    throw new InvalidArgumentException('Unknown command. Type help to see the supported commands.');
}
function dn_world_number(string $input,int $min,int $max,string $label): int {
    if(!preg_match('/^[0-9]{1,10}$/D',$input)||($value=(int)$input)<$min||$value>$max)throw new InvalidArgumentException($label.' must be '.$min.'–'.$max.'.');
    return $value;
}
function dn_world_text(string $value,int $max,string $label): string {
    $value=trim($value);
    if($value===''||strlen($value)>$max||preg_match('/[\x00-\x1f\x7f]/',$value))throw new InvalidArgumentException($label.' must be 1–'.$max.' bytes without control characters.');
    return $value;
}
function dn_world_page(array $args,int $index=0): int {return isset($args[$index])?dn_world_number($args[$index],1,100000,'Page'):1;}
function dn_world_account(mysqli $db,string $selector,bool $lock=false): array {
    if(preg_match('/^#[0-9]+$/D',$selector))$where='m.id=?';else $where='m.username=?';
    $value=$where==='m.id=?'?dn_world_number(substr($selector,1),1,2147483647,'Account ID'):$selector;
    $r=dn_row($db,'SELECT m.*,EXISTS(SELECT 1 FROM nxt_bots b WHERE b.owner_id=m.id) is_bot FROM members m WHERE '.$where.($lock?' FOR UPDATE':''),[$value]);
    if(!$r)throw new InvalidArgumentException('Account not found. Use its exact username or #account-id.');
    return $r;
}
function dn_world_species(mysqli $db,string $value): array {
    $r=ctype_digit($value)?dn_species_by_id($db,(int)$value):dn_row($db,'SELECT * FROM digimon_species WHERE slug=? OR name=?',[$value,$value]);
    if(!$r)throw new InvalidArgumentException('Species not found. Use lookup species to find its ID or exact slug.');return$r;
}
function dn_world_item(mysqli $db,string $value): array {
    $r=ctype_digit($value)?dn_row($db,'SELECT * FROM items WHERE id=?',[(int)$value]):dn_row($db,'SELECT * FROM items WHERE slug=? OR name=?',[$value,$value]);
    if(!$r)throw new InvalidArgumentException('Item not found. Use lookup items to find its ID or exact slug.');return$r;
}
function dn_world_owned(mysqli $db,array $owner,string $value): array {
    dn_require_idle($db,(int)$owner['id']);
    $d=dn_owned_instance($db,(int)$owner['id'],dn_world_number($value,1,2147483647,'Instance ID'),true);
    if(!$d)throw new InvalidArgumentException('This Digimon does not belong to the selected account.');
    if((int)$d['locked_trade']||dn_row($db,"SELECT id FROM trade_listings WHERE digimon_id=? AND status='active'",[(int)$d['id']]))throw new InvalidArgumentException('Cancel this Digimon’s active exchange listing before changing it.');
    return$d;
}
function dn_world_human(array $owner): void {if((int)$owner['is_bot'])throw new InvalidArgumentException('This account is an autonomous tamer. Login and role changes are restricted to human accounts.');}
function dn_world_keep_admin(mysqli $db,array $owner): void {
    if($owner['role']!=='admin'||(int)$owner['banned']||(int)$owner['is_bot'])return;
    $admins=dn_rows($db,"SELECT m.id FROM members m LEFT JOIN nxt_bots b ON b.owner_id=m.id WHERE m.role='admin' AND m.banned=0 AND b.owner_id IS NULL ORDER BY m.id FOR UPDATE");
    if(count($admins)<=1)throw new InvalidArgumentException('Create or promote another human admin before removing access from the last active admin.');
}
function dn_world_revoke(mysqli $db,int $uid): void {
    dn_write($db,'UPDATE members SET session_version=session_version+1 WHERE id=?',[$uid]);
    dn_write($db,'DELETE FROM online WHERE id=?',[$uid]);
    dn_write($db,'DELETE FROM mapusers WHERE id=?',[$uid]);
    dn_write($db,'DELETE FROM nxt_ranked_tickets WHERE owner_id=?',[$uid]);
}
function dn_world_audit_command(mysqli $db,string $name,string $target,bool $success,string $summary): void {
    $operator=substr((string)(getenv('USERNAME')?:getenv('USER')?:'local-admin'),0,120);
    $operator=preg_replace('/[\x00-\x1f\x7f]/','',$operator);
    dn_write($db,'INSERT INTO nxt_world_admin_audit(created_at,operator_name,command_name,target_name,success,summary) VALUES(?,?,?,?,?,?)',[time(),$operator,substr($name,0,64),substr($target,0,80),(int)$success,substr($summary,0,500)]);
}
function dn_world_result(array $lines,array $data=[]): array {return ['ok'=>true,'lines'=>$lines,'data'=>$data];}
function dn_world_table(array $rows,array $columns): array {
    if(!$rows)return ['No matching records.'];
    $lines=[implode(' | ',array_values($columns))];
    foreach($rows as $row){$values=[];foreach($columns as $key=>$label)$values[]=preg_replace('/[\x00-\x1f\x7f]/',' ',(string)($row[$key]??'—'));$lines[]=implode(' | ',$values);}return$lines;
}

/** Read backward so a filtered tail includes all retained rotations without loading whole files. */
function dn_world_tail_events(string $directory,int $limit,string $category=''): array {
    $out=[];
    for($rotation=0;$rotation<=5&&count($out)<$limit;$rotation++) {
        $path=$directory.($rotation===0?'/events.php':'/events.'.$rotation.'.php');
        if(!is_file($path)||is_link($path))continue;
        $h=@fopen($path,'rb');if(!$h)continue;
        try {
            $stat=fstat($h);$position=(int)($stat['size']??0);$carry='';
            while($position>0&&count($out)<$limit) {
                $size=min(65536,$position);$position-=$size;fseek($h,$position);
                $chunk=fread($h,$size);if($chunk===false||$chunk==='')break;
                $parts=explode("\n",$chunk.$carry);$carry=array_shift($parts);
                for($i=count($parts)-1;$i>=0&&count($out)<$limit;$i--) {
                    $row=json_decode($parts[$i],true);
                    if(!is_array($row)||($category!==''&&strtolower((string)($row['category']??''))!==$category))continue;
                    $out[]=json_encode($row,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
                }
            }
            // The first line is the PHP guard, so any final carry is intentionally ignored.
        } finally {fclose($h);}
    }
    return array_reverse($out);
}

/** All edits use prepared statements, account row locks and an atomic database audit. */
function dn_world_execute(mysqli $db,array $request): array {
    $name='invalid';$target='';$transaction=false;$command=null;
    try {
        if(!isset($request['line'])||!is_string($request['line']))throw new InvalidArgumentException('A command line is required.');
        [$command,$args]=dn_world_resolve(dn_world_tokenize($request['line']));$name=$command['name'];
        if(count($args)<$command['min_args']||count($args)>$command['max_args'])throw new InvalidArgumentException('Usage: '.$command['usage'].($command['secret']?' Passwords must be entered only at the private prompt.':''));
        if($command['secret'] && !array_key_exists('secret',$request))return ['ok'=>true,'lines'=>['Enter the new password privately (8–72 bytes).'],'requires_secret'=>true];
        if($command['secret'] && (!is_string($request['secret'])||strlen($request['secret'])<8||strlen($request['secret'])>72||str_contains($request['secret'],"\0")))throw new InvalidArgumentException('Password must contain 8–72 bytes and no NUL character.');
        if($command['confirmation'] && ($request['confirm']??false)!==true)return ['ok'=>true,'lines'=>['Confirmation required: '.$command['description']],'requires_confirmation'=>true,'confirmation'=>'CONFIRM'];
        if($command['mutates'] && $name!=='bots run'){$db->begin_transaction();$transaction=true;}
        $result=dn_world_dispatch($db,$name,$args,$request,$target);
        if($command['mutates'])dn_world_audit_command($db,$name,$target,true,(string)($result['lines'][0]??'Command completed.'));
        if($transaction){$db->commit();$transaction=false;}
        if($command['mutates'] && function_exists('dn_world_event'))dn_world_event('admin','command.completed',$name.' completed'.($target!==''?' for '.$target:''),['account'=>'console:'.(string)(getenv('USERNAME')?:getenv('USER')?:'local-admin')]);
        return$result;
    }catch(Throwable $e){
        if($transaction){try{$db->rollback();}catch(Throwable){}}
        // A SQL exception may contain private SQL values. Do not return or log it.
        $message=$e instanceof mysqli_sql_exception?((int)$e->getCode()===1062?'That username or email is already in use.':'The database could not complete this command. No uncommitted changes were saved.'):$e->getMessage();
        if(!($e instanceof InvalidArgumentException)&&!($e instanceof RuntimeException)&&!($e instanceof mysqli_sql_exception))$message='The command could not complete. No uncommitted changes were saved.';
        try{dn_world_audit_command($db,$name,$target,false,'Command rejected or failed.');}catch(Throwable){}
        if(function_exists('dn_world_event'))dn_world_event('admin','command.failed',$name.' rejected or failed',['account'=>'console:'.(string)(getenv('USERNAME')?:getenv('USER')?:'local-admin')]);
        return ['ok'=>false,'lines'=>[$message]];
    }
}

function dn_world_dispatch(mysqli $db,string $name,array $a,array $request,string &$target): array {
    if($name==='help') {
        $query=strtolower(implode(' ',$a));$rows=[];
        foreach(dn_world_commands() as $cmd)if($query===''||$cmd['name']===$query||str_starts_with($cmd['name'],$query.' '))$rows[]=$cmd['usage'].' — '.$cmd['description'];
        if(!$rows)throw new InvalidArgumentException('No help entry found. Type help for the full list.');
        return dn_world_result(array_merge(['Use quotes around multi-word arguments. Accounts accept exact username or #ID. Leading . is optional.'], $rows));
    }
    if($name==='status') {
        $counts=dn_row($db,'SELECT (SELECT COUNT(*) FROM members) accounts,(SELECT COUNT(*) FROM nxt_bots) bots,(SELECT COUNT(*) FROM online WHERE time>?) online,(SELECT COUNT(*) FROM digimon_battle_state) saved_battles,(SELECT COUNT(*) FROM digimon_instances) partners',[time()-300]);
        $data=['database'=>dn_row($db,'SELECT DATABASE() name')['name'],'schema'=>dn_row($db,"SELECT meta_value FROM schema_meta WHERE meta_key='schema_version'")['meta_value']??'unknown','counts'=>$counts,'maintenance'=>dn_world_setting($db,'world.maintenance',['enabled'=>false]),'bots_paused'=>dn_world_setting($db,'bots.paused',false)];
        return dn_world_result(['Server time: '.gmdate('Y-m-d H:i:s').' UTC','Database: '.$data['database'].' | schema '.$data['schema'],'Accounts: '.$counts['accounts'].' | AI tamers: '.$counts['bots'].' | online: '.$counts['online'],'Partners: '.$counts['partners'].' | saved battles: '.$counts['saved_battles'],'Maintenance: '.(!empty($data['maintenance']['enabled'])?'ON':'OFF').' | bots manually paused: '.($data['bots_paused']?'YES':'NO')],$data);
    }
    if($name==='online') {
        $limit=isset($a[0])?dn_world_number($a[0],1,100,'Limit'):30;
        $rows=dn_rows($db,'SELECT m.id,m.username,m.ip,o.activity,FROM_UNIXTIME(o.time) seen FROM online o JOIN members m ON m.id=o.id LEFT JOIN nxt_bots b ON b.owner_id=m.id WHERE o.time>? AND b.owner_id IS NULL ORDER BY o.time DESC LIMIT '.$limit,[time()-300]);
        return dn_world_result(dn_world_table($rows,['id'=>'ID','username'=>'Account','ip'=>'IP','activity'=>'Activity','seen'=>'Last seen (UTC)']));
    }
    if($name==='account list') {
        $page=dn_world_page($a,1);$search='%'.($a[0]??'').'%';$rows=dn_rows($db,'SELECT m.id,m.username,m.role,m.banned,IF(b.owner_id IS NULL,\'Human\',\'AI\') kind FROM members m LEFT JOIN nxt_bots b ON b.owner_id=m.id WHERE m.username LIKE ? ORDER BY m.id LIMIT 30 OFFSET '.(($page-1)*30),[$search]);
        return dn_world_result(array_merge(['Page '.$page.' (30 per page)'],dn_world_table($rows,['id'=>'ID','username'=>'Account','role'=>'Role','banned'=>'Banned','kind'=>'Kind'])));
    }
    if($name==='account create') {
        $username=$a[0];$email=$a[1];$starter=$a[2]??'agumon';
        if(!preg_match('/^[A-Za-z0-9_]{3,24}$/D',$username))throw new InvalidArgumentException('Username must contain 3–24 letters, numbers or underscores.');
        if(strlen($email)>160||!filter_var($email,FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Enter a valid email address of at most 160 bytes.');
        if(!in_array($starter,['agumon','gabumon','tentomon','veemon','guilmon','terriermon'],true))throw new InvalidArgumentException('Starter must be agumon, gabumon, tentomon, veemon, guilmon or terriermon.');
        $target=$username;$hash=pv_password_hash($request['secret']);$now=time();
        dn_write($db,'INSERT INTO members(username,password,email,registered,created_avatar) VALUES(?,?,?,?,102)',[$username,$hash,$email,$now]);$uid=(int)$db->insert_id;
        dn_write($db,'INSERT INTO members_options(id,tamer) VALUES(?,102)',[$uid]);
        $slugs=array_slice(array_values(array_unique(array_merge([$starter],['terriermon','tentomon','gabumon']))),0,3);
        foreach($slugs as $i=>$slug){$s=dn_world_species($db,$slug);$id=dn_create_instance($db,$uid,(int)$s['id'],'starter',5);dn_write($db,'INSERT INTO digimon_party(owner_id,slot,digimon_id) VALUES(?,?,?)',[$uid,$i+1,$id]);}
        foreach(['hp-capsule-s','sp-capsule-s'] as $slug){$it=dn_world_item($db,$slug);dn_add_item($db,$uid,(int)$it['id'],5);}
        return dn_world_result(['Created '.$username.' (#'.$uid.') with three level-5 partners and starter supplies.'],['account_id'=>$uid]);
    }
    if($name==='banlist') {
        $page=dn_world_page($a);$rows=dn_rows($db,"SELECT m.id,m.username,COALESCE(w.reason,'Legacy / unspecified') reason,IF(COALESCE(w.expires_at,0)=0,'Permanent',FROM_UNIXTIME(w.expires_at)) expires FROM members m LEFT JOIN nxt_world_bans w ON w.owner_id=m.id WHERE m.banned=1 ORDER BY m.id LIMIT 30 OFFSET ".(($page-1)*30));
        return dn_world_result(array_merge(['Page '.$page.'; expired bans clear when next checked by the server.'],dn_world_table($rows,['id'=>'ID','username'=>'Account','reason'=>'Reason','expires'=>'Expiry (UTC)'])));
    }
    if(str_starts_with($name,'lookup ')) {
        $page=dn_world_page($a,1);$q='%'.$a[0].'%';$species=$name==='lookup species';$table=$species?'digimon_species':'items';$select=$species?'id,slug,name,stage,attribute':'id,slug,name,category,stack_limit';
        $rows=dn_rows($db,'SELECT '.$select.' FROM '.$table.' WHERE name LIKE ? OR slug LIKE ? OR CAST(id AS CHAR)=? ORDER BY id LIMIT 30 OFFSET '.(($page-1)*30),[$q,$q,$a[0]]);
        return dn_world_result(array_merge(['Page '.$page],dn_world_table($rows,$species?['id'=>'ID','slug'=>'Slug','name'=>'Species','stage'=>'Stage','attribute'=>'Attribute']:['id'=>'ID','slug'=>'Slug','name'=>'Item','category'=>'Category','stack_limit'=>'Stack limit'])));
    }
    if($name==='maintenance')return dn_world_result(['Maintenance: '.json_encode(dn_world_setting($db,'world.maintenance',['enabled'=>false]),JSON_UNESCAPED_SLASHES)]);
    if($name==='maintenance on'||$name==='maintenance off') {
        $on=$name==='maintenance on';$message=$on?dn_world_text($a[0]??'The server is undergoing maintenance. Please try again shortly.',500,'Maintenance message'):'';
        dn_world_setting_set($db,'world.maintenance',['enabled'=>$on,'message'=>$message]);
        return dn_world_result([$on?'Maintenance enabled. Human admins retain access; new player actions and AI pulses pause.':'Maintenance disabled. Player access restored; any manual bots pause is unchanged.']);
    }
    if($name==='bots pause'||$name==='bots resume') {
        dn_world_setting_set($db,'bots.paused',$name==='bots pause');
        return dn_world_result([$name==='bots pause'?'Autonomous tamers paused. An action already running may finish.':'Autonomous tamers resumed, subject to maintenance and normal due times.']);
    }
    if($name==='bots status') {
        $row=dn_row($db,'SELECT COUNT(*) total,SUM(enabled=1) enabled,SUM(enabled=1 AND next_action_at<=?) due,SUM(activity_step) actions,SUM(wild_wins) wild_wins,SUM(wild_losses) wild_losses FROM nxt_bots',[time()]);
        $worker=dn_row($db,"SELECT * FROM nxt_worker_state WHERE worker_key='tamers'")??[];
        return dn_world_result(['Tamers: '.$row['total'].' | enabled: '.$row['enabled'].' | due: '.$row['due'],'Completed actions: '.$row['actions'].' | wild W/L: '.$row['wild_wins'].'/'.$row['wild_losses'],'Last scheduler pulse: '.(!empty($worker['last_run'])?gmdate('Y-m-d H:i:s',(int)$worker['last_run']).' UTC':'not yet'),'Manual pause: '.(dn_world_setting($db,'bots.paused',false)?'ON':'OFF').' | maintenance: '.(!empty(dn_world_setting($db,'world.maintenance',[])['enabled'])?'ON':'OFF')],['bots'=>$row,'worker'=>$worker]);
    }
    if($name==='bots run') {
        $batch=isset($a[0])?dn_world_number($a[0],1,250,'Batch'):40;
        if(dn_world_setting($db,'bots.paused',false)||!empty(dn_world_setting($db,'world.maintenance',[])['enabled']))throw new InvalidArgumentException('Bots are paused or maintenance is enabled. Resume the world before running a pulse.');
        $result=dn_bots_tick($db,$batch,0,3.0);
        return dn_world_result(['Scheduler pulse: '.(int)$result['processed'].' completed'.(!empty($result['skipped'])?' | skipped: '.($result['reason']??'busy'):'').' | errors: '.count($result['errors']??[]).'.'],['processed'=>(int)$result['processed'],'skipped'=>(bool)$result['skipped'],'errors'=>count($result['errors']??[])]);
    }
    if($name==='ip list') {
        $page=dn_world_page($a);$rows=dn_rows($db,"SELECT ip,reason,IF(expires_at=0,'Permanent',FROM_UNIXTIME(expires_at)) expires FROM nxt_world_ip_bans WHERE expires_at=0 OR expires_at>? ORDER BY ip LIMIT 30 OFFSET ".(($page-1)*30),[time()]);
        return dn_world_result(array_merge(['Page '.$page],dn_world_table($rows,['ip'=>'IP address','reason'=>'Reason','expires'=>'Expiry (UTC)'])));
    }
    if($name==='ip ban'||$name==='ip unban') {
        if(!filter_var($a[0],FILTER_VALIDATE_IP))throw new InvalidArgumentException('Use one valid IPv4 or IPv6 address, without a port or subnet mask.');
        $ip=inet_ntop(inet_pton($a[0]));$target=$ip;
        if($name==='ip unban'){dn_write($db,'DELETE FROM nxt_world_ip_bans WHERE ip=?',[$ip]);return dn_world_result(['Removed any ban for '.$ip.'.']);}
        if(str_starts_with($ip,'127.')||$ip==='::1'||str_starts_with($ip,'::ffff:127.'))throw new InvalidArgumentException('Loopback addresses cannot be banned.');
        $ipv4=str_starts_with($ip,'::ffff:')?substr($ip,7):$ip;
        if($ipv4==='0.0.0.0'||$ip==='::'||str_starts_with(strtolower($ip),'ff')||(filter_var($ipv4,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)&&((int)explode('.',$ipv4)[0]>=224)))throw new InvalidArgumentException('Unspecified and multicast addresses cannot be banned.');
        $minutes=$a[1]==='permanent'?0:dn_world_number($a[1],1,525600,'Ban minutes');$reason=dn_world_text($a[2],255,'Reason');$expires=$minutes?time()+60*$minutes:0;
        dn_write($db,'INSERT INTO nxt_world_ip_bans(ip,reason,banned_at,expires_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason),banned_at=VALUES(banned_at),expires_at=VALUES(expires_at)',[$ip,$reason,time(),$expires]);
        return dn_world_result(['Blocked '.$ip.($expires?' until '.gmdate('Y-m-d H:i:s',$expires).' UTC':' permanently').'.']);
    }
    if($name==='announce clear'){dn_world_setting_set($db,'announcement',['message'=>'','expires_at'=>0]);return dn_world_result(['Live announcement cleared.']);}
    if($name==='announce') {
        $message=dn_world_text($a[0],500,'Announcement');$ttl=isset($a[1])?dn_world_number($a[1],1,86400,'Seconds'):300;dn_world_setting_set($db,'announcement',['message'=>$message,'expires_at'=>time()+$ttl,'operator'=>(string)(getenv('USERNAME')?:getenv('USER')?:'local-admin')]);
        $count=dn_write($db,"INSERT INTO notifications(owner_id,category,title,body,link,created_at) SELECT m.id,'system','Server announcement',?,'',? FROM members m LEFT JOIN nxt_bots b ON b.owner_id=m.id WHERE b.owner_id IS NULL",[$message,time()]);
        return dn_world_result(['Live banner active for '.$ttl.' seconds; announcement delivered to '.$count.' human accounts.']);
    }
    if($name==='audit') {
        $limit=isset($a[0])?dn_world_number($a[0],1,100,'Limit'):20;$params=[];$where='';
        if(isset($a[1])){$owner=dn_world_account($db,$a[1]);$where=' WHERE target_name=?';$params[]=$owner['username'];}
        $rows=dn_rows($db,'SELECT id,FROM_UNIXTIME(created_at) at,operator_name,command_name,target_name,IF(success=1,\'OK\',\'FAILED\') outcome,summary FROM nxt_world_admin_audit'.$where.' ORDER BY id DESC LIMIT '.$limit,$params);
        return dn_world_result(dn_world_table(array_reverse($rows),['id'=>'ID','at'=>'Time (UTC)','operator_name'=>'Operator','command_name'=>'Command','target_name'=>'Target','outcome'=>'Result','summary'=>'Summary']));
    }
    if($name==='logs') {
        $limit=isset($a[0])?dn_world_number($a[0],1,100,'Limit'):30;$category=strtolower($a[1]??'');
        $lines=dn_world_tail_events(dirname(__DIR__).'/storage/world-console',$limit,$category);
        return dn_world_result($lines?:['No matching retained world events.']);
    }

    // Remaining commands target one account. Mutations lock the same row as gameplay.
    $mutating=false;foreach(dn_world_commands() as $cmd)if($cmd['name']===$name){$mutating=$cmd['mutates'];break;}
    $owner=dn_world_account($db,$a[0],$mutating);$uid=(int)$owner['id'];$target=$owner['username'];
    if($name==='account info') {
        $summary=dn_row($db,'SELECT (SELECT COUNT(*) FROM digimon_instances WHERE owner_id=?) partners,(SELECT COUNT(*) FROM battle_log WHERE owner_id=?) battles,(SELECT COUNT(*) FROM digimon_scan WHERE owner_id=? AND scan_percent>=100) ready',[$uid,$uid,$uid]);
        return dn_world_result(['Account: '.$target.' (#'.$uid.') | '.((int)$owner['is_bot']?'AI tamer':'Human').' | role: '.$owner['role'],'Banned: '.((int)$owner['banned']?'YES':'NO').' | session version: '.$owner['session_version'],'Email: '.$owner['email'].' | last recorded IP: '.($owner['ip']?:'none'),'Bits: '.$owner['money'].' | partners: '.$summary['partners'].' | battles: '.$summary['battles'].' | ready scans: '.$summary['ready'],'Registered: '.gmdate('Y-m-d H:i:s',(int)$owner['registered']).' UTC']);
    }
    if($name==='account password') {dn_world_human($owner);dn_write($db,'UPDATE members SET password=? WHERE id=?',[pv_password_hash($request['secret']),$uid]);dn_world_revoke($db,$uid);return dn_world_result(['Password changed for '.$target.'. Existing sessions revoked.']);}
    if($name==='account role') {
        dn_world_human($owner);if(!in_array($a[1],['tamer','moderator','admin'],true))throw new InvalidArgumentException('Role must be tamer, moderator or admin.');if($a[1]!=='admin')dn_world_keep_admin($db,$owner);
        dn_write($db,'UPDATE members SET role=? WHERE id=?',[$a[1],$uid]);dn_world_revoke($db,$uid);return dn_world_result([$target.' now has role '.$a[1].'. Existing sessions revoked.']);
    }
    if($name==='account ban') {
        dn_world_keep_admin($db,$owner);$minutes=$a[1]==='permanent'?0:dn_world_number($a[1],1,525600,'Ban minutes');$reason=dn_world_text($a[2],255,'Reason');$expires=$minutes?time()+$minutes*60:0;
        dn_write($db,'UPDATE members SET banned=1 WHERE id=?',[$uid]);dn_write($db,'INSERT INTO nxt_world_bans(owner_id,reason,banned_at,expires_at) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE reason=VALUES(reason),banned_at=VALUES(banned_at),expires_at=VALUES(expires_at)',[$uid,$reason,time(),$expires]);dn_world_revoke($db,$uid);
        if((int)$owner['is_bot'])dn_write($db,'UPDATE nxt_bots SET enabled=0 WHERE owner_id=?',[$uid]);
        return dn_world_result([$target.' banned '.($expires?'until '.gmdate('Y-m-d H:i:s',$expires).' UTC':'permanently').'. Existing sessions revoked.']);
    }
    if($name==='account unban'){dn_write($db,'UPDATE members SET banned=0 WHERE id=?',[$uid]);dn_write($db,'DELETE FROM nxt_world_bans WHERE owner_id=?',[$uid]);return dn_world_result([$target.' is unbanned. Disabled AI tamers require bots enable separately.']);}
    if($name==='account kick'){if(isset($a[1]))dn_world_text($a[1],255,'Reason');dn_world_revoke($db,$uid);return dn_world_result(['Existing sessions revoked for '.$target.'. Saved battles remain resumable.']);}
    if($name==='account unlock'){$n=dn_write($db,'DELETE FROM login_trys WHERE username=?',[$target]);return dn_world_result(['Cleared '.$n.' failed-login throttle records for '.$target.'.']);}
    if(in_array($name,['givemoney','takemoney','setmoney'],true)) {
        $amount=dn_world_number($a[1],$name==='setmoney'?0:1,999999999,'Bits');$before=(int)$owner['money'];$after=$name==='setmoney'?$amount:($name==='givemoney'?$before+$amount:$before-$amount);
        if($after<0||$after>999999999)throw new InvalidArgumentException('Resulting balance must remain between 0 and 999,999,999 Bits.');dn_write($db,'UPDATE members SET money=? WHERE id=?',[$after,$uid]);return dn_world_result([$target.': '.number_format($before).' → '.number_format($after).' Bits.']);
    }
    if($name==='givedigimon') {
        dn_require_idle($db,$uid);$species=dn_world_species($db,$a[1]);$level=isset($a[2])?dn_world_number($a[2],1,99,'Level'):1;$abi=isset($a[3])?dn_world_number($a[3],0,200,'ABI'):0;
        $id=dn_create_instance($db,$uid,(int)$species['id'],'admin', $level,$abi);return dn_world_result(['Granted '.$species['name'].' (#'.$id.', level '.$level.', ABI '.$abi.') to '.$target.'’s collection. Use party set to assign a slot.'],['instance_id'=>$id]);
    }
    if($name==='removedigimon'){$d=dn_world_owned($db,$owner,$a[1]);if(dn_row($db,'SELECT slot FROM digimon_party WHERE owner_id=? AND digimon_id=?',[$uid,(int)$d['id']]))throw new InvalidArgumentException('Remove this Digimon from the party before deleting it.');dn_write($db,'DELETE FROM digimon_instances WHERE owner_id=? AND id=?',[$uid,(int)$d['id']]);return dn_world_result(['Removed reserve Digimon #'.$d['id'].' from '.$target.'.']);}
    if($name==='digimon list'){$page=dn_world_page($a,1);$rows=dn_rows($db,'SELECT d.id,s.name,d.level,d.abi,d.cam,p.slot,d.locked_trade FROM digimon_instances d JOIN digimon_species s ON s.id=d.species_id LEFT JOIN digimon_party p ON p.owner_id=d.owner_id AND p.digimon_id=d.id WHERE d.owner_id=? ORDER BY d.id LIMIT 30 OFFSET '.(($page-1)*30),[$uid]);return dn_world_result(array_merge([$target.' — page '.$page],dn_world_table($rows,['id'=>'Instance','name'=>'Species','level'=>'Level','abi'=>'ABI','cam'=>'CAM','slot'=>'Party','locked_trade'=>'Listed'])));}
    if($name==='digimon info') {
        $id=dn_world_number($a[1],1,2147483647,'Instance ID');$d=dn_owned_instance($db,$uid,$id);if(!$d)throw new InvalidArgumentException('This Digimon does not belong to the selected account.');$moves=dn_rows($db,'SELECT move_slug FROM digimon_learned_moves WHERE digimon_id=? ORDER BY move_slug',[$id]);
        return dn_world_result([$d['name'].' #'.$id.' | owner '.$target.' | level '.$d['level'].' | EXP '.$d['exp'],'HP '.$d['hp'].'/'.$d['max_hp'].' | SP '.$d['sp'].'/'.$d['max_sp'].' | ATK '.$d['attack_stat'].' | DEF '.$d['defense_stat'].' | INT '.$d['intelligence_stat'].' | SPD '.$d['speed_stat'],'ABI '.$d['abi'].' | CAM '.$d['cam'].' | trade lock '.$d['locked_trade'],'Learned moves: '.implode(', ',array_column($moves,'move_slug'))]);
    }
    if($name==='digimon level') {
        $d=dn_world_owned($db,$owner,$a[1]);$level=dn_world_number($a[2],1,99,'Level');$st=dn_level_stats($d,$level,0,dn_training($d));
        dn_write($db,'UPDATE digimon_instances SET level=?,exp=0,hp=?,max_hp=?,sp=?,max_sp=?,attack_stat=?,defense_stat=?,intelligence_stat=?,speed_stat=? WHERE id=? AND owner_id=?',[$level,$st['max_hp'],$st['max_hp'],$st['max_sp'],$st['max_sp'],$st['attack'],$st['defense'],$st['intelligence'],$st['speed'],(int)$d['id'],$uid]);dn_learn_moves($db,(int)$d['id'],$d['slug'],$level);
        return dn_world_result(['Digimon #'.$d['id'].' set to level '.$level.'; stats, vitals and eligible skills refreshed. Current EXP reset; previously learned moves retained.']);
    }
    if($name==='digimon abi'||$name==='digimon cam') {
        $d=dn_world_owned($db,$owner,$a[1]);$abi=$name==='digimon abi';$value=dn_world_number($a[2],0,$abi?200:100,$abi?'ABI':'CAM');
        if($abi && array_sum(dn_training($d))>50+intdiv($value,2))throw new InvalidArgumentException('This ABI would place existing training above its allowed budget.');
        dn_write($db,'UPDATE digimon_instances SET '.($abi?'abi':'cam').'=? WHERE owner_id=? AND id=?',[$value,$uid,(int)$d['id']]);return dn_world_result(['Digimon #'.$d['id'].' '.($abi?'ABI':'CAM').' set to '.$value.'.']);
    }
    if($name==='heal') {
        dn_require_idle($db,$uid);$scope=$a[1]??'party';if(!in_array($scope,['party','all'],true))throw new InvalidArgumentException('Heal scope must be party or all.');
        $sql=$scope==='all'?'UPDATE digimon_instances SET hp=max_hp,sp=max_sp WHERE owner_id=?':'UPDATE digimon_instances d JOIN digimon_party p ON p.digimon_id=d.id AND p.owner_id=d.owner_id SET d.hp=d.max_hp,d.sp=d.max_sp WHERE d.owner_id=?';
        $n=dn_write($db,$sql,[$uid]);return dn_world_result(['Restored '.$target.'’s '.$scope.'; '.$n.' Digimon needed recovery.']);
    }
    if($name==='party')return dn_world_result(dn_world_table(array_values(dn_party_rows($db,$uid)),['slot'=>'Slot','id'=>'Instance','name'=>'Species','level'=>'Level','hp'=>'HP','sp'=>'SP']));
    if($name==='party set') {
        $slot=dn_world_number($a[1],1,6,'Slot');$d=dn_world_owned($db,$owner,$a[2]);
        $remaining=dn_row($db,'SELECT COUNT(*) n FROM digimon_party WHERE owner_id=? AND slot<=3 AND slot<>? AND digimon_id<>?',[$uid,$slot,(int)$d['id']]);if($slot>3&&(int)$remaining['n']===0)throw new InvalidArgumentException('Keep at least one Digimon in active slots 1–3.');
        dn_write($db,'DELETE FROM digimon_party WHERE owner_id=? AND (slot=? OR digimon_id=?)',[$uid,$slot,(int)$d['id']]);dn_write($db,'INSERT INTO digimon_party(owner_id,slot,digimon_id) VALUES(?,?,?)',[$uid,$slot,(int)$d['id']]);return dn_world_result([$target.' slot '.$slot.' now contains '.$d['name'].' #'.$d['id'].'.']);
    }
    if($name==='party remove') {
        dn_require_idle($db,$uid);$slot=dn_world_number($a[1],1,6,'Slot');$party=dn_party_rows($db,$uid);if(!isset($party[$slot]))throw new InvalidArgumentException('That party slot is empty.');if($slot<=3&&count(array_filter(array_keys($party),fn($s)=>$s<=3))<=1)throw new InvalidArgumentException('Keep at least one Digimon in active slots 1–3.');dn_write($db,'DELETE FROM digimon_party WHERE owner_id=? AND slot=?',[$uid,$slot]);return dn_world_result(['Cleared '.$target.'’s slot '.$slot.'. The Digimon remains in its collection.']);
    }
    if($name==='inventory')return dn_world_result(dn_world_table(dn_rows($db,'SELECT i.id,i.name,i.slug,mi.quantity,i.stack_limit FROM member_items mi JOIN items i ON i.id=mi.item_id WHERE mi.owner_id=? AND mi.quantity>0 ORDER BY i.id',[$uid]),['id'=>'ID','name'=>'Item','slug'=>'Slug','quantity'=>'Owned','stack_limit'=>'Limit']));
    if($name==='giveitem'||$name==='takeitem') {
        dn_require_idle($db,$uid);$it=dn_world_item($db,$a[1]);$qty=dn_world_number($a[2],1,999999,'Quantity');$r=dn_row($db,'SELECT quantity FROM member_items WHERE owner_id=? AND item_id=? FOR UPDATE',[$uid,(int)$it['id']]);$before=(int)($r['quantity']??0);$after=$before+($name==='giveitem'?$qty:-$qty);
        if($after<0)throw new InvalidArgumentException('This account does not own enough of that item.');if($name==='giveitem'&&(!(int)$it['is_active']||$after>(int)$it['stack_limit']))throw new InvalidArgumentException('This grant would exceed the item stack limit, or the item is inactive.');
        dn_write($db,'INSERT INTO member_items(owner_id,item_id,quantity) VALUES(?,?,?) ON DUPLICATE KEY UPDATE quantity=VALUES(quantity)',[$uid,(int)$it['id'],$after]);return dn_world_result([$target.' now owns '.$after.' × '.$it['name'].'.']);
    }
    if($name==='scan list'){$page=dn_world_page($a,1);$rows=dn_rows($db,'SELECT s.id,s.name,x.scan_percent,x.encounters,x.wins FROM digimon_scan x JOIN digimon_species s ON s.id=x.species_id WHERE x.owner_id=? ORDER BY s.id LIMIT 30 OFFSET '.(($page-1)*30),[$uid]);return dn_world_result(array_merge(['Page '.$page],dn_world_table($rows,['id'=>'Species ID','name'=>'Species','scan_percent'=>'Scan %','encounters'=>'Encounters','wins'=>'Wins'])));}
    if($name==='scan give'||$name==='scan set') {
        dn_require_idle($db,$uid);$s=dn_world_species($db,$a[1]);$value=dn_world_number($a[2],$name==='scan set'?0:1,200,'Scan %');dn_discover_species($db,$uid,(int)$s['id']);$row=dn_row($db,'SELECT scan_percent FROM digimon_scan WHERE owner_id=? AND species_id=? FOR UPDATE',[$uid,(int)$s['id']]);$total=$name==='scan give'?min(200,(int)$row['scan_percent']+$value):$value;dn_write($db,'UPDATE digimon_scan SET scan_percent=? WHERE owner_id=? AND species_id=?',[$total,$uid,(int)$s['id']]);return dn_world_result([$target.'’s '.$s['name'].' scan is now '.$total.'%.']);
    }
    if($name==='mail'){$title=dn_world_text($a[1],120,'Title');$message=dn_world_text($a[2],500,'Message');dn_notify($db,$uid,$title,$message,'notifications.php','system');return dn_world_result(['Support notification delivered to '.$target.'.']);}
    if($name==='bots enable'||$name==='bots disable'){if(!(int)$owner['is_bot'])throw new InvalidArgumentException('This command requires an AI tamer account.');$on=$name==='bots enable';if($on&&(int)$owner['banned'])throw new InvalidArgumentException('Unban this AI tamer before enabling it.');dn_write($db,'UPDATE nxt_bots SET enabled=? WHERE owner_id=?',[(int)$on,$uid]);return dn_world_result([$target.' '.($on?'enabled':'disabled').'.']);}
    throw new InvalidArgumentException('Command is not available.');
}
