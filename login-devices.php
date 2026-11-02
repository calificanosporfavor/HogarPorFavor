<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS login_devices(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,device_key TEXT NOT NULL,last_seen INTEGER NOT NULL,PRIMARY KEY(user_id,device_key))');
query('DELETE FROM login_devices WHERE last_seen<?',[time()-90*86400]);
// A separate random cookie recognizes this browser; it never authorizes access.
function rememberLoginDevice(array $u,bool $notify): void {
 global $db,$local;
 $token=$_COOKIE['hogar_device']??'';
 if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))$token=bin2hex(random_bytes(32));
 $key=hash('sha256',$token);$now=time();
 $db->exec('BEGIN IMMEDIATE');
 try{
  $known=query('SELECT 1 FROM login_devices WHERE user_id=? AND device_key=? AND last_seen>=?',[$u['id'],$key,$now-90*86400])->fetchColumn();
  query('INSERT INTO login_devices(user_id,device_key,last_seen) VALUES(?,?,?) ON CONFLICT(user_id,device_key) DO UPDATE SET last_seen=excluded.last_seen',[$u['id'],$key,$now]);
  if($notify&&!$known)queueSecurityNotice((int)$u['id'],'login-new',$u['email'],null,sessionDevice());
  $db->exec('COMMIT');
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
 $_SESSION['device_key']=$key;
 setcookie('hogar_device',$token,['expires'=>$now+90*86400,'path'=>'/','secure'=>!$local,'httponly'=>true,'samesite'=>'Lax']);
 if($notify)flushSecurityNotices();
}
