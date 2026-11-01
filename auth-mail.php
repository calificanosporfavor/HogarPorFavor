<?php
declare(strict_types=1);
if (!defined('HOGAR_INTERNAL')) { http_response_code(404); exit; }
function migrateEmail(): void {
 global $db;
 if ((int)$db->query('PRAGMA user_version')->fetchColumn()>=4) return;
 $db->exec('BEGIN IMMEDIATE');
 try {
  $columns=$db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN,1);
  if(!in_array('email_verified_at',$columns,true))$db->exec('ALTER TABLE users ADD COLUMN email_verified_at INTEGER DEFAULT NULL');
  $db->exec('CREATE TABLE IF NOT EXISTS email_tokens (digest TEXT PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,purpose TEXT NOT NULL,expires INTEGER NOT NULL,created INTEGER NOT NULL,used INTEGER DEFAULT NULL);
   CREATE INDEX IF NOT EXISTS email_tokens_owner ON email_tokens(user_id,purpose);
   CREATE TABLE IF NOT EXISTS email_codes(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,purpose TEXT NOT NULL,hash TEXT NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,created INTEGER NOT NULL,expires INTEGER NOT NULL,used INTEGER DEFAULT NULL);
   CREATE INDEX IF NOT EXISTS email_codes_owner ON email_codes(user_id,purpose,id);
   PRAGMA user_version=4; COMMIT;');
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
 global $local,$dataDir,$db;
 if(!$local&&!globalMailAllowance())return false;
 $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);$now=time();$verify=$purpose==='verify';
 $expires=$now+600;
 $subject=$verify?'Confirma tu correo — Hogar Por Favor':'Recupera tu contraseña — Hogar Por Favor';
 $text=$verify?"Tu código para confirmar tu correo es:\n\n$code\n\nEscríbelo en la pantalla Confirma tu correo de Hogar Por Favor.":"Tu código para recuperar tu contraseña es:\n\n$code\n\nEscríbelo en la pantalla de recuperación de Hogar Por Favor y elige una contraseña nueva. Si no solicitaste este cambio, ignora el mensaje: tu contraseña sigue igual.";
 $text="Hola,\n\n".$text."\n\nEl código vence en 10 minutos y solo se puede usar una vez.\nNo compartas este código. Nuestro equipo nunca te lo pedirá por teléfono, chat ni correo.\n\nHogar Por Favor\nhogarporfavor.com\n\nEste es un mensaje automático; no respondas a esta dirección.\n";
 query('DELETE FROM email_tokens WHERE expires<?',[$now-86400]);
 query('DELETE FROM email_codes WHERE expires<?',[$now-86400]);
 query('INSERT INTO email_codes(user_id,purpose,hash,expires,created) VALUES(?,?,?,?,?)',[$u['id'],$purpose,password_hash($code,PASSWORD_DEFAULT),$expires,$now]);$codeId=(int)$db->lastInsertId();
 if($local){
  $dir=$dataDir.'/mail-test';if(!is_dir($dir))mkdir($dir,0700,true);
  $ok=file_put_contents($dir.'/'.bin2hex(random_bytes(10)).'.json',json_encode(['to'=>$u['email'],'purpose'=>$purpose,'subject'=>$subject,'body'=>$text,'code'=>$code],JSON_UNESCAPED_UNICODE))!==false;
 }else{
  $headers=['From'=>'Hogar Por Favor <no-reply@hogarporfavor.com>','MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8','Content-Transfer-Encoding'=>'base64','Auto-Submitted'=>'auto-generated','X-Auto-Response-Suppress'=>'All'];
  $ok=function_exists('mail')&&mail($u['email'],'=?UTF-8?B?'.base64_encode($subject).'?=',chunk_split(base64_encode($text)),$headers,'-fno-reply@hogarporfavor.com');
 }
 if(!$ok){query('DELETE FROM email_codes WHERE id=?',[$codeId]);error_log('Hogar: transactional email could not be accepted');return false;}
 query('UPDATE email_codes SET used=? WHERE user_id=? AND purpose=? AND id<? AND used IS NULL',[$now,$u['id'],$purpose,$codeId]);
 return true;
}
function requireVerified(array $u): void { if(!$u['email_verified'])fail('Confirma tu correo antes de publicar. Puedes solicitar el código desde Mi cuenta.',403); }
function handleEmailAction(string $action,string $method,array $input): void {
 global $db;
 if($method!=='POST')return;
 if(in_array($action,['verify-code','reset-code'],true)){
  limitRate('email-code',25,900);$code=strvalInput($input,'code',6,6);if(!preg_match('/^[0-9]{6}$/D',$code))fail('Escribe los 6 dígitos del código.');
  $purpose=$action==='verify-code'?'verify':'reset';
  if($purpose==='verify')$u=requireUser();else{$email=strtolower(strvalInput($input,'email',5,254));$u=query('SELECT id FROM users WHERE email=?',[$email])->fetch();$password=strvalInput($input,'password',12,72);if(strlen($password)>72)fail('Usa una contraseña de hasta 72 bytes.');$hash=password_hash($password,PASSWORD_DEFAULT);}
  $db->exec('BEGIN IMMEDIATE');
  try{
   $row=$u?query('SELECT * FROM email_codes WHERE user_id=? AND purpose=? ORDER BY id DESC LIMIT 1',[$u['id'],$purpose])->fetch():false;
   if(!$row||$row['used']!==null||(int)$row['expires']<=time()||(int)$row['attempts']>=5){$db->exec('ROLLBACK');fail('El código no es válido, venció o alcanzó sus intentos. Solicita uno nuevo.');}
   if(!password_verify($code,$row['hash'])){query('UPDATE email_codes SET attempts=attempts+1 WHERE id=?',[$row['id']]);$db->exec('COMMIT');fail('Código incorrecto. Revisa el correo; tienes hasta 5 intentos por código.');}
   $now=time();query('UPDATE users SET email_verified_at=COALESCE(email_verified_at,?) WHERE id=?',[$now,$u['id']]);
   if($purpose==='reset'){query('UPDATE users SET password=?,recovery=? WHERE id=?',[$hash,password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT),$u['id']]);query('UPDATE email_tokens SET used=? WHERE user_id=? AND used IS NULL',[$now,$u['id']]);query('UPDATE email_codes SET used=? WHERE user_id=? AND used IS NULL',[$now,$u['id']]);}
   else{query('UPDATE email_codes SET used=? WHERE user_id=? AND purpose="verify" AND used IS NULL',[$now,$u['id']]);query('UPDATE email_tokens SET used=? WHERE user_id=? AND purpose="verify" AND used IS NULL',[$now,$u['id']]);}
   $db->exec('COMMIT');
  }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}
  if($purpose==='reset'){$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));reply(['message'=>'Tu contraseña cambió. Inicia sesión con la nueva contraseña.','csrf'=>$_SESSION['csrf']]);}
  reply(['message'=>'Correo confirmado. Ya puedes publicar tus propiedades.','user'=>user()]);
 }
 if($action==='resend-verification'){
  $u=requireUser();if($u['email_verified'])reply(['message'=>'Tu correo ya está confirmado.','user'=>$u]);
  limitRate('verify-mail',10,3600);emailThrottle('verify-hour',$u['email'],4,3600);emailThrottle('verify-minute',$u['email'],1,60);
  if(!sendAuthEmail($u,'verify'))fail('No pudimos enviar el correo ahora. Espera unos minutos e inténtalo de nuevo.',503);
  reply(['message'=>'Solicitamos el envío de tu código de 6 dígitos. Revisa tu bandeja de entrada y spam.']);
 }
 if($action==='forgot-password'){
  $start=microtime(true);limitRate('forgot-mail',10,3600);$email=strtolower(strvalInput($input,'email',5,254));if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Escribe un correo válido.');
  emailThrottle('reset-hour',$email,4,3600);emailThrottle('reset-minute',$email,1,60);
  $u=query('SELECT id,name,email FROM users WHERE email=?',[$email])->fetch();if($u)sendAuthEmail($u,'reset');
  $left=400000-(int)((microtime(true)-$start)*1000000);if($left>0)usleep($left);
  reply(['message'=>'Si existe una cuenta con ese correo, recibirás un código de 6 dígitos. Revisa también spam.']);
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
  else {query('UPDATE users SET password=?,email_verified_at=COALESCE(email_verified_at,?),recovery=? WHERE id=?',[$hash,$now,password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT),$row['user_id']]);query('UPDATE email_tokens SET used=? WHERE user_id=? AND used IS NULL',[$now,$row['user_id']]);query('UPDATE email_codes SET used=? WHERE user_id=? AND used IS NULL',[$now,$row['user_id']]);}
  $db->exec('COMMIT');
 }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}
 if($purpose==='reset'){$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));reply(['message'=>'Tu contraseña cambió. Inicia sesión con la nueva contraseña.','csrf'=>$_SESSION['csrf']]);}
 reply(['message'=>'Correo confirmado. Ya puedes publicar tus propiedades.','user'=>user()]);
}
