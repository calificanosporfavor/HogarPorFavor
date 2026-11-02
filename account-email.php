<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS pending_email_changes(user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,email TEXT NOT NULL,old_email TEXT NOT NULL,code_id INTEGER NOT NULL,auth TEXT NOT NULL,session_digest TEXT NOT NULL,created INTEGER NOT NULL)');
query('DELETE FROM pending_email_changes WHERE created<?',[time()-600]);
function handleAccountEmail(string $action,string $method,array $input): void {
 if(!in_array($action,['request-email-change','confirm-email-change','cancel-email-change'],true)||$method!=='POST')return;
 global $db;
 $u=requireUser();
 if($action==='cancel-email-change'){query('DELETE FROM pending_email_changes WHERE user_id=?',[$u['id']]);query('DELETE FROM email_codes WHERE user_id=? AND purpose="email-change"',[$u['id']]);reply(['ok'=>true]);}
 if(strtolower($u['email'])===HOGAR_SUPERADMIN)fail('El correo de la cuenta superadmin está reservado y no se puede cambiar desde este formulario.',403);
 if($action==='request-email-change'){
  limitRate('email-change-request',10,3600);
  $password=$input['current_password']??null;
  if(!is_string($password)||strlen($password)<1||strlen($password)>72)fail('Escribe tu contraseña actual.');
  $stored=(string)query('SELECT password FROM users WHERE id=?',[$u['id']])->fetchColumn();
  if(!password_verify(trim($password),$stored))fail('La contraseña actual no es correcta. Tu correo sigue igual.',403);
  $email=strtolower(strvalInput($input,'new_email',5,254));if(!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Escribe un correo válido.');
  if($email===strtolower($u['email']))fail('Escribe una dirección diferente de tu correo actual.');
  if($email===HOGAR_SUPERADMIN||query('SELECT 1 FROM users WHERE email=?',[$email])->fetchColumn())fail('No se puede utilizar ese correo. Prueba con otra dirección.',409);
  emailThrottle('change-minute',$u['email'],1,60);emailThrottle('change-hour',$u['email'],4,3600);emailThrottle('change-destination',$email,4,3600);
  $db->exec('BEGIN IMMEDIATE');
  try{
   $fresh=user();if(!$fresh||$fresh['email']!==$u['email']){$db->exec('ROLLBACK');fail('Tu sesión cambió. Inicia sesión de nuevo.',409);}
   if(!sendAuthEmail(['id'=>$u['id'],'email'=>$email],'email-change')){$db->exec('ROLLBACK');fail('No pudimos enviar el código. Tu correo sigue igual. Inténtalo más tarde.',503);}
   $codeId=(int)query('SELECT id FROM email_codes WHERE user_id=? AND purpose="email-change" ORDER BY id DESC LIMIT 1',[$u['id']])->fetchColumn();
   query('INSERT INTO pending_email_changes(user_id,email,old_email,code_id,auth,session_digest,created) VALUES(?,?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET email=excluded.email,old_email=excluded.old_email,code_id=excluded.code_id,auth=excluded.auth,session_digest=excluded.session_digest,created=excluded.created',[$u['id'],$email,$u['email'],$codeId,$_SESSION['auth'],hash('sha256',session_id()),time()]);
   $db->exec('COMMIT');
  }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}
  reply(['message'=>'Enviamos un código de 6 dígitos a tu nuevo correo. Confírmalo en esta misma sesión. Tu correo actual sigue igual.','pending_email'=>$email]);
 }
 limitRate('email-change-code',25,900);$code=strvalInput($input,'code',6,6);if(!preg_match('/^[0-9]{6}$/D',$code))fail('Escribe los 6 dígitos del código.');
 $db->exec('BEGIN IMMEDIATE');
 try{
  $pending=query('SELECT * FROM pending_email_changes WHERE user_id=?',[$u['id']])->fetch();
  $r=$pending?query('SELECT * FROM email_codes WHERE id=? AND user_id=? AND purpose="email-change"',[$pending['code_id'],$u['id']])->fetch():false;
  if(!$pending||!$r||$r['used']!==null||(int)$r['expires']<=time()||(int)$r['attempts']>=5||!hash_equals($pending['auth'],(string)($_SESSION['auth']??''))||!hash_equals($pending['session_digest'],hash('sha256',session_id()))||$pending['old_email']!==$u['email']||!user()){$db->exec('ROLLBACK');fail('La solicitud venció o ya no es válida. Inicia el cambio de correo otra vez.');}
  if(!password_verify($code,$r['hash'])){query('UPDATE email_codes SET attempts=attempts+1 WHERE id=?',[$r['id']]);$db->exec('COMMIT');fail('Código incorrecto. Revisa el nuevo correo; tienes hasta 5 intentos.');}
  if(query('SELECT 1 FROM users WHERE email=? AND id<>?',[$pending['email'],$u['id']])->fetchColumn()){$db->exec('ROLLBACK');fail('Ese correo ya no está disponible. Inicia el cambio con otra dirección.',409);}
  $now=microtime(true);query('UPDATE users SET email=?,email_verified_at=?,revoked_at=?,recovery=? WHERE id=?',[$pending['email'],time(),$now,password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT),$u['id']]);
  query('DELETE FROM pending_email_changes WHERE user_id=?',[$u['id']]);query('DELETE FROM email_codes WHERE user_id=?',[$u['id']]);query('DELETE FROM email_tokens WHERE user_id=?',[$u['id']]);
  $db->exec('COMMIT');
 }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}
 session_regenerate_id(true);$_SESSION['issued']=microtime(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
 reply(['user'=>user(),'csrf'=>$_SESSION['csrf'],'message'=>'Tu nuevo correo quedó confirmado y guardado. Úsalo para entrar y recuperar tu contraseña. Las demás sesiones se cerraron.']);
}
