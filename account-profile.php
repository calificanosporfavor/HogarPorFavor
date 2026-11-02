<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
function handleAccountProfile(string $action,string $method,array $input): void {
 if(!in_array($action,['update-name','change-password'],true)||$method!=='POST')return;
 global $db;
 $u=requireUser();limitRate('account-profile',10,900);
 $password=$input['current_password']??null;
 if(!is_string($password)||strlen($password)<1||strlen($password)>72)fail('Escribe tu contraseña actual.');
 $stored=(string)query('SELECT password FROM users WHERE id=?',[$u['id']])->fetchColumn();
 if(!password_verify(trim($password),$stored))fail('La contraseña actual no es correcta. No se realizaron cambios.',403);
 if($action==='update-name'){
  $name=strvalInput($input,'name',2,70);
  if(preg_match('/[\p{Cc}\p{Cf}]/u',$name))fail('Escribe tu nombre sin caracteres de control.');
  $q=query('UPDATE users SET name=? WHERE id=? AND password=? AND status="active" AND revoked_at<=?',[$name,$u['id'],$stored,(float)($_SESSION['issued']??0)]);
  if(!$q->rowCount())fail('Tu sesión cambió. Actualiza la página e inténtalo de nuevo.',409);
  reply(['user'=>user(),'message'=>'Tu nombre se actualizó. También aparecerá en tus publicaciones.']);
 }
 $next=$input['new_password']??null;$confirm=$input['confirm_password']??null;
 if(!is_string($next)||mb_strlen($next)<12||$next!==trim($next))fail('Usa una contraseña nueva de al menos 12 caracteres, sin espacios al inicio o al final.');
 if(strlen($next)>72)fail('La contraseña es demasiado larga. Elige una más corta.');
 if(!is_string($confirm)||!hash_equals($next,$confirm))fail('La confirmación no coincide con la contraseña nueva.');
 if(password_verify($next,$stored))fail('Elige una contraseña diferente de la actual.');
 $hash=password_hash($next,PASSWORD_DEFAULT);$now=microtime(true);
 $db->beginTransaction();
 try{
  $q=query('UPDATE users SET password=?,recovery=?,revoked_at=? WHERE id=? AND password=? AND status="active" AND revoked_at<=?',[$hash,password_hash(bin2hex(random_bytes(24)),PASSWORD_DEFAULT),$now,$u['id'],$stored,(float)($_SESSION['issued']??0)]);
  if(!$q->rowCount()){$db->rollBack();fail('Tu sesión cambió. Inicia sesión de nuevo antes de continuar.',409);}
  query('DELETE FROM email_tokens WHERE user_id=?',[$u['id']]);query('DELETE FROM email_codes WHERE user_id=?',[$u['id']]);
  queuePasswordNotice((int)$u['id']);
  $db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 flushSecurityNotices();
 rotateSession();$_SESSION['auth']=hash('sha256',$hash);$_SESSION['issued']=microtime(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
 reply(['user'=>user(),'csrf'=>$_SESSION['csrf'],'message'=>'Tu contraseña se cambió. Las demás sesiones se cerraron y los códigos de recuperación anteriores dejaron de funcionar.']);
}
