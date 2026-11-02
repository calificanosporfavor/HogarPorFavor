<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS security_notices(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,event_id TEXT NOT NULL,kind TEXT NOT NULL,recipient TEXT NOT NULL,created INTEGER NOT NULL,status TEXT NOT NULL DEFAULT "pending",attempts INTEGER NOT NULL DEFAULT 0,next_attempt INTEGER NOT NULL DEFAULT 0,claimed INTEGER NOT NULL DEFAULT 0,sent_at INTEGER,UNIQUE(event_id,kind,recipient))');
function queueSecurityNotice(int $userId,string $kind,string $recipient,?string $event=null): void {
 if(!in_array($kind,['password','email-old','email-new'],true)||!filter_var($recipient,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid security notice');
 query('INSERT INTO security_notices(user_id,event_id,kind,recipient,created) VALUES(?,?,?,?,?)',[$userId,$event??bin2hex(random_bytes(16)),$kind,$recipient,time()]);
}
function queuePasswordNotice(int $id): void {$email=query('SELECT email FROM users WHERE id=?',[$id])->fetchColumn();if(is_string($email))queueSecurityNotice($id,'password',$email);}
function deliverSecurityNotice(array $n): bool {
 global $local,$dataDir;
 $date=(new DateTimeImmutable('@'.$n['created']))->setTimezone(new DateTimeZone('America/Mexico_City'))->format('d/m/Y H:i').' (Ciudad de México)';
 $password=$n['kind']==='password';$subject=$password?'Tu contraseña se cambió — Hogar Por Favor':'Tu correo de acceso se cambió — Hogar Por Favor';
 $what=$password?'Se cambió la contraseña de tu cuenta de Hogar Por Favor.':($n['kind']==='email-old'?'Se cambió el correo de acceso de tu cuenta de Hogar Por Favor. Esta dirección ya no es el correo de acceso de esa cuenta.':'Tu nueva dirección quedó verificada y guardada como correo de acceso de tu cuenta de Hogar Por Favor.');
 $text="Hola,\n\n".$what."\nFecha: ".$date."\n\nSi tú realizaste el cambio, no necesitas hacer nada.\n\nSi no reconoces este cambio, escribe hogarporfavor.com directamente en tu navegador y usa la recuperación de contraseña si aún tienes acceso al correo de la cuenta. Si perdiste el acceso, contacta a cueva@hogarporfavor.com e indica que no reconoces un cambio en tu cuenta.\n\nNunca compartas contraseñas ni códigos de acceso. Este aviso no permite iniciar sesión ni revertir cambios.\n\nHogar Por Favor\nhogarporfavor.com\nMensaje automático; no respondas a no-reply@hogarporfavor.com.\n";
 if($local){$dir=$dataDir.'/mail-test';if(!is_dir($dir))mkdir($dir,0700,true);return file_put_contents($dir.'/'.bin2hex(random_bytes(10)).'.json',json_encode(['to'=>$n['recipient'],'purpose'=>'security-'.$n['kind'],'subject'=>$subject,'body'=>$text],JSON_UNESCAPED_UNICODE))!==false;}
 $headers=['From'=>'Hogar Por Favor <no-reply@hogarporfavor.com>','MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8','Content-Transfer-Encoding'=>'base64','Auto-Submitted'=>'auto-generated','X-Auto-Response-Suppress'=>'All'];
 return function_exists('mail')&&mail($n['recipient'],'=?UTF-8?B?'.base64_encode($subject).'?=',chunk_split(base64_encode($text)),$headers,'-fno-reply@hogarporfavor.com');
}
function flushSecurityNotices(): void {
 global $db,$local;
 if($db->inTransaction())return;
 try{
  query('DELETE FROM security_notices WHERE status="sent" AND sent_at<?',[time()-30*86400]);
  for($i=0;$i<3;$i++){
   $db->exec('BEGIN IMMEDIATE');$now=time();
   $n=query('SELECT * FROM security_notices WHERE (status="pending" AND next_attempt<=?) OR (status="sending" AND claimed<?) ORDER BY id LIMIT 1',[$now,$now-300])->fetch();
   if(!$n){$db->exec('COMMIT');break;}
   if(!$local&&!globalMailAllowance()){$db->exec('COMMIT');break;}
   query('UPDATE security_notices SET status="sending",claimed=?,attempts=attempts+1 WHERE id=?',[$now,$n['id']]);$db->exec('COMMIT');
   try{$ok=deliverSecurityNotice($n);}catch(Throwable $e){$ok=false;}
   if($ok)query('UPDATE security_notices SET status="sent",sent_at=? WHERE id=?',[time(),$n['id']]);
   else{query('UPDATE security_notices SET status="pending",next_attempt=? WHERE id=?',[time()+min(21600,60*(2**min(8,(int)$n['attempts']))),$n['id']]);error_log('Hogar: security notice queued for retry');}
  }
 }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');error_log('Hogar: security notice delivery deferred');}
}
