<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';

function dn_touch_online(mysqli $db,int $uid,string $activity): void {
    $username=(string)($_SESSION['myuser']??'Tamer');$now=time();$ua=substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,250);$server=substr((string)($_SERVER['SERVER_NAME']??'local'),0,45);
    $stmt=$db->prepare('INSERT INTO online(id,username,activity,time,useragent,server) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE username=VALUES(username),activity=VALUES(activity),time=VALUES(time),useragent=VALUES(useragent),server=VALUES(server)');
    if($stmt){$stmt->bind_param('ississ',$uid,$username,$activity,$now,$ua,$server);$stmt->execute();$stmt->close();}
}

function dn_notify(mysqli $db,int $ownerId,string $title,string $body,string $link='',string $category='system'): void {
    $now=time();$stmt=$db->prepare('INSERT INTO notifications(owner_id,category,title,body,link,created_at) VALUES(?,?,?,?,?,?)');
    if($stmt){$stmt->bind_param('issssi',$ownerId,$category,$title,$body,$link,$now);$stmt->execute();$stmt->close();}
}

function dn_unread_counts(mysqli $db,int $uid): array {
    $out=['messages'=>0,'notifications'=>0];
    $stmt=$db->prepare('SELECT COUNT(*) c FROM messages WHERE recipient_id=? AND recipient_deleted=0 AND read_at IS NULL');if($stmt){$stmt->bind_param('i',$uid);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();$out['messages']=(int)($r['c']??0);}
    $stmt=$db->prepare('SELECT COUNT(*) c FROM notifications WHERE owner_id=? AND read_at IS NULL');if($stmt){$stmt->bind_param('i',$uid);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();$out['notifications']=(int)($r['c']??0);}
    return $out;
}

function dn_member_by_id(mysqli $db,int $id): ?array {
    $stmt=$db->prepare('SELECT m.*,COALESCE(o.tamer,m.created_avatar,1) tamer FROM members m LEFT JOIN members_options o ON o.id=m.id WHERE m.id=? LIMIT 1');
    if(!$stmt)return null;$stmt->bind_param('i',$id);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return$row?:null;
}
function dn_member_by_username(mysqli $db,string $name): ?array {
    $stmt=$db->prepare('SELECT m.*,COALESCE(o.tamer,m.created_avatar,1) tamer FROM members m LEFT JOIN members_options o ON o.id=m.id WHERE m.username=? LIMIT 1');
    if(!$stmt)return null;$stmt->bind_param('s',$name);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();return$row?:null;
}
function dn_member_public_stats(mysqli $db,int $id): array {
    $out=['digimon'=>0,'seen'=>0,'clan'=>'','online'=>false];
    $stmt=$db->prepare('SELECT COUNT(*) c FROM digimon_instances WHERE owner_id=?');if($stmt){$stmt->bind_param('i',$id);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();$out['digimon']=(int)($r['c']??0);}
    $stmt=$db->prepare('SELECT COUNT(*) c FROM digimon_scan WHERE owner_id=?');if($stmt){$stmt->bind_param('i',$id);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();$out['seen']=(int)($r['c']??0);}
    $stmt=$db->prepare('SELECT c.name FROM clan_members cm JOIN clans c ON c.id=cm.clan_id WHERE cm.user_id=? LIMIT 1');if($stmt){$stmt->bind_param('i',$id);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();$out['clan']=(string)($r['name']??'');}
    $stmt=$db->prepare('SELECT 1 FROM online WHERE id=? AND time>=? LIMIT 1');if($stmt){$cut=time()-900;$stmt->bind_param('ii',$id,$cut);$stmt->execute();$out['online']=(bool)$stmt->get_result()->fetch_row();$stmt->close();}
    return $out;
}

function dn_friend_pair(int $a,int $b): array {return $a<$b?[$a,$b]:[$b,$a];}
function dn_friend_status(mysqli $db,int $a,int $b): ?array {
    if($a===$b)return null;[$low,$high]=dn_friend_pair($a,$b);$stmt=$db->prepare('SELECT * FROM friends WHERE user_low=? AND user_high=? LIMIT 1');if(!$stmt)return null;$stmt->bind_param('ii',$low,$high);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();return$r?:null;
}
function dn_friend_request(mysqli $db,int $from,int $to): void {
    if($from===$to)throw new RuntimeException('You cannot add yourself.');[$low,$high]=dn_friend_pair($from,$to);$now=time();$existing=dn_friend_status($db,$from,$to);
    if($existing){if($existing['status']==='accepted')throw new RuntimeException('You are already connected.');if((int)$existing['requested_by']===$to){$stmt=$db->prepare("UPDATE friends SET status='accepted',updated_at=? WHERE user_low=? AND user_high=?");$stmt->bind_param('iii',$now,$low,$high);$stmt->execute();$stmt->close();dn_notify($db,$to,'Connection accepted',(string)($_SESSION['myuser']??'A tamer').' accepted your connection request.','profile.php?id='.$from,'community');return;}throw new RuntimeException('A connection request is already pending.');}
    $stmt=$db->prepare("INSERT INTO friends(user_low,user_high,status,requested_by,created_at,updated_at) VALUES(?,?,'pending',?,?,?)");if(!$stmt)throw new RuntimeException('Connection request unavailable.');$stmt->bind_param('iiiii',$low,$high,$from,$now,$now);if(!$stmt->execute())throw new RuntimeException($stmt->error);$stmt->close();dn_notify($db,$to,'New tamer connection',(string)($_SESSION['myuser']??'A tamer').' sent you a connection request.','profile.php?id='.$from,'community');
}
function dn_friend_remove(mysqli $db,int $a,int $b): void {[$low,$high]=dn_friend_pair($a,$b);$stmt=$db->prepare('DELETE FROM friends WHERE user_low=? AND user_high=?');if($stmt){$stmt->bind_param('ii',$low,$high);$stmt->execute();$stmt->close();}}

function dn_audit(mysqli $db,?int $actor,string $action,string $details=''): void {
    $ip=substr((string)($_SERVER['REMOTE_ADDR']??''),0,64);$now=time();$stmt=$db->prepare('INSERT INTO audit_log(actor_id,action,details,ip,created_at) VALUES(?,?,?,?,?)');if($stmt){$stmt->bind_param('isssi',$actor,$action,$details,$ip,$now);$stmt->execute();$stmt->close();}
}
