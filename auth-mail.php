<?php
declare(strict_types=1);
if (!defined('HOGAR_INTERNAL')) { http_response_code(404); exit; }
function migrateEmail(): void {
 global $db;
 if ((int)$db->query('PRAGMA user_version')->fetchColumn()>=3) return;
 $db->exec('BEGIN IMMEDIATE');
 try {
  $columns=$db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN,1);
  if(!in_array('email_verified_at',$columns,true))$db->exec('ALTER TABLE users ADD COLUMN email_verified_at INTEGER DEFAULT NULL');
  $db->exec('CREATE TABLE IF NOT EXISTS email_tokens (digest TEXT PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,purpose TEXT NOT NULL,expires INTEGER NOT NULL,created INTEGER NOT NULL,used INTEGER DEFAULT NULL);
   CREATE INDEX IF NOT EXISTS email_tokens_owner ON email_tokens(user_id,purpose); PRAGMA user_version=3; COMMIT;');
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function emailThrottle(string $scope,string $email,int $maximum,int $seconds): void {
 $key=hash('sha256','mail-recipient|'.$scope.'|'.strtolower($email));$now=time();
 query('DELETE FROM limits WHERE key=? AND expires<?',[$key,$now]);
 query('INSERT INTO limits(key,count,expires) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=count+1',[$key,$now+$seconds]);
 if((int)query('SELECT count FROM limits WHERE key=?',[$key])->fetchColumn()>$maximum)fail('Espera unos minutos antes de pedir otro correo.',429);
}
function globalMailAllowance(): bool {
 // Shared-host PHP mail has a limited allowance. Stay below the published server limits.
 foreach([['minute',60,8],['day',86400,80]] as [$scope,$window,$max]){
  $key='mail-global-'.$scope.'-'.(string)intdiv(time(),$window);
  query('INSERT INTO limits(key,count,expires) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=count+1',[$key,time()+$window]);
  if((int)query('SELECT count FROM limits WHERE key=?',[$key])->fetchColumn()>$max)return false;
 }
 return true;
}
function sendAuthEmail(array $u,string $purpose): bool {
 global $local,$dataDir;
 if(!$local&&!globalMailAllowance())return false;
 $token=bin2hex(random_bytes(32));$digest=hash('sha256',$token);$now=time();$verify=$purpose==='verify';
 $expires=$now+($verify?86400:1800);
 $base=$local?'http://127.0.0.1:4173/':'https://hogarporfavor.com/';
 $url=$base.'#'.($verify?'verify':'reset').'='.$token;
 $subject=$verify?'Confirma tu correo — Hogar Por Favor':'Recupera tu contraseña — Hogar Por Favor';
 $text=$verify?"Confirma tu correo para publicar propiedades gratis.\n\nAbre el enlace y pulsa Confirmar correo:\n$url\n\nEl enlace vence en 24 horas y solo puede utilizarse una vez.":"Recibimos una solicitud para cambiar la contraseña de tu cuenta.\n\nAbre el enlace para elegir una contraseña nueva:\n$url\n\nEl enlace vence en 30 minutos y solo puede utilizarse una vez. Si no solicitaste el cambio, ignora este mensaje: tu contraseña sigue igual.";
 $text="Hola,\n\n".$text."\n\nNo compartas este enlace. Nunca te pediremos contraseñas ni pagos por correo.\n\nHogar Por Favor\nhttps://hogarporfavor.com/\n\nEste es un mensaje automático; no respondas a esta dirección.\n";
 query('DELETE FROM email_tokens WHERE expires<?',[$now-86400]);
 query('INSERT INTO email_tokens(digest,user_id,purpose,expires,created) VALUES(?,?,?,?,?)',[$digest,$u['id'],$purpose,$expires,$now]);
 if($local){
  $dir=$dataDir.'/mail-test';if(!is_dir($dir))mkdir($dir,0700,true);
  $ok=file_put_contents($dir.'/'.bin2hex(random_bytes(10)).'.json',json_encode(['to'=>$u['email'],'purpose'=>$purpose,'subject'=>$subject,'body'=>$text,'token'=>$token],JSON_UNESCAPED_UNICODE))!==false;
 }else{
  $headers=['From'=>'Hogar Por Favor <no-reply@hogarporfavor.com>','MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8','Content-Transfer-Encoding'=>'base64','Auto-Submitted'=>'auto-generated','X-Auto-Response-Suppress'=>'All'];
  $ok=function_exists('mail')&&mail($u['email'],'=?UTF-8?B?'.base64_encode($subject).'?=',chunk_split(base64_encode($text)),$headers,'-fno-reply@hogarporfavor.com');
 }
 if(!$ok){query('DELETE FROM email_tokens WHERE digest=?',[$digest]);error_log('Hogar: transactional email could not be accepted');return false;}
 // Keep earlier unexpired links valid until one is consumed. A scanner opening a GET never consumes them.
 return true;
}
function requireVerified(array $u): void { if(!$u['email_verified'])fail('Confirma tu correo antes de publicar. Puedes reenviar el enlace desde Mi cuenta.',403); }
function handleEmailAction(string $action,string $method,array $input): void {
 global $db;
 if($method!=='POST')return;
 if($action==='resend-verification'){
  $u=requireUser();if($u['email_verified'])reply(['message'=>'Tu correo ya está confirmado.','user'=>$u]);
  limitRate('verify-mail',10,3600);emailThrottle('verify-hour',$u['email'],4,3600);emailThrottle('verify-minute',$u['email'],1,60);
  if(!sendAuthEmail($u,'verify'))fail('No pudimos enviar el correo ahora. Espera unos minutos e inténtalo de nuevo.',503);
  reply(['message'=>'Solicitamos el envío del enlace de verificación. Revisa tu bandeja de entrada y spam.']);
 }
 if($action==='forgot-password'){
  $start=microtime(true);limitRate('forgot-mail',10,3600);$email=strtolower(strvalInput($input,'email',5,254));if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Escribe un correo válido.');
  emailThrottle('reset-hour',$email,4,3600);emailThrottle('reset-minute',$email,1,60);
  $u=query('SELECT id,name,email FROM users WHERE email=?',[$email])->fetch();if($u)sendAuthEmail($u,'reset');
  $left=400000-(int)((microtime(true)-$start)*1000000);if($left>0)usleep($left);
  reply(['message'=>'Si existe una cuenta con ese correo, recibirás un enlace para recuperar tu contraseña. Revisa también spam.']);
 }
 if(!in_array($action,['verify-email','reset-password'],true))return;
 limitRate('email-token',25,900);$token=strvalInput($input,'token',64,64);if(!ctype_xdigit($token))fail('El enlace no es válido o ya venció. Solicita uno nuevo.');
 $newPassword=null;
 if($action==='reset-password'){$newPassword=strvalInput($input,'password',12,72);if(strlen($newPassword)>72)fail('Usa una contraseña de hasta 72 bytes.');$hash=password_hash($newPassword,PASSWORD_DEFAULT);}
 $digest=hash('sha256',$token);$purpose=$action==='verify-email'?'verify':'reset';
 $db->exec('BEGIN IMMEDIATE');
 try {
  $row=query('SELECT * FROM email_tokens WHERE digest=? AND purpose=? AND used IS NULL AND expires>?',[$digest,$purpose,time()])->fetch();
  if(!$row){$db->exec('ROLLBACK');fail('El enlace no es válido, ya se utilizó o venció. Solicita uno nuevo.');}
  $now=time();
  if($purpose==='verify'){query('UPDATE users SET email_verified_at=COALESCE(email_verified_at,?) WHERE id=?',[$now,$row['user_id']]);query('UPDATE email_tokens SET used=? WHERE user_id=? AND purpose="verify" AND used IS NULL',[$now,$row['user_id']]);}
  else {query('UPDATE users SET password=?,email_verified_at=COALESCE(email_verified_at,?),recovery=? WHERE id=?',[$hash,$now,password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT),$row['user_id']]);query('UPDATE email_tokens SET used=? WHERE user_id=? AND used IS NULL',[$now,$row['user_id']]);}
  $db->exec('COMMIT');
 }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}
 if($purpose==='reset'){$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));reply(['message'=>'Tu contraseña cambió. Inicia sesión con la nueva contraseña.','csrf'=>$_SESSION['csrf']]);}
 reply(['message'=>'Correo confirmado. Ya puedes publicar tus propiedades.','user'=>user()]);
}
