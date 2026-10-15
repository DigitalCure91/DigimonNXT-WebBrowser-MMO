<?php
declare(strict_types=1);require_once __DIR__.'/includes/bootstrap.php';
try{if(pv_is_logged_in()){$db=pv_db();$uid=(int)$_SESSION['myid'];$stmt=$db->prepare('DELETE FROM online WHERE id=?');if($stmt){$stmt->bind_param('i',$uid);$stmt->execute();$stmt->close();}$stmt=$db->prepare('DELETE FROM mapusers WHERE id=?');if($stmt){$stmt->bind_param('i',$uid);$stmt->execute();$stmt->close();}}}catch(Throwable){}
if(pv_is_logged_in())dn_world_event('auth','logout','Tamer signed out');
$_SESSION=[];if(ini_get('session.use_cookies')){$p=session_get_cookie_params();setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']);}session_destroy();pv_redirect('index.php');
