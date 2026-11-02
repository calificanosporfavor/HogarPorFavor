<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS account_sessions(digest TEXT PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,auth TEXT NOT NULL,issued REAL NOT NULL,started INTEGER,last_seen INTEGER NOT NULL,device TEXT NOT NULL);CREATE INDEX IF NOT EXISTS account_sessions_owner ON account_sessions(user_id,last_seen)');
if(!in_array('revoked',array_column(query('PRAGMA table_info(account_sessions)')->fetchAll(),'name'),true))$db->exec('ALTER TABLE account_sessions ADD COLUMN revoked INTEGER NOT NULL DEFAULT 0');
if(!in_array('device_key',array_column(query('PRAGMA table_info(account_sessions)')->fetchAll(),'name'),true))$db->exec('ALTER TABLE account_sessions ADD COLUMN device_key TEXT');
query('DELETE FROM account_sessions WHERE last_seen<?',[time()-86400]);
function currentSessionRevoked(): bool {return (bool)query('SELECT revoked FROM account_sessions WHERE digest=?',[hash('sha256',session_id())])->fetchColumn();}
function sessionControlId(string $digest): string {return hash_hmac('sha256',$digest,$_SESSION['csrf']);}
function forgetCurrentSession(): void {query('DELETE FROM account_sessions WHERE digest=?',[hash('sha256',session_id())]);}
function rotateSession(): void {forgetCurrentSession();session_regenerate_id(true);}
function sessionDevice(): string {
 $ua=substr($_SERVER['HTTP_USER_AGENT']??'',0,500);
 $browser=str_contains($ua,'Edg/')?'Edge':(str_contains($ua,'OPR/')?'Opera':(str_contains($ua,'Firefox/')?'Firefox':(str_contains($ua,'Chrome/')?'Chrome':(str_contains($ua,'Safari/')?'Safari':'Navegador no identificado'))));
 $os=preg_match('/iPhone|iPad|iPod/',$ua)?'iPhone / iPad':(str_contains($ua,'Android')?'Android':(str_contains($ua,'Windows')?'Windows':(str_contains($ua,'Macintosh')?'Mac':(str_contains($ua,'Linux')?'Linux':'Dispositivo no identificado'))));
 return $browser.' · '.$os;
}
function trackAccountSession(array $u): void {
 query('INSERT INTO account_sessions(digest,user_id,auth,issued,started,last_seen,device,device_key) VALUES(?,?,?,?,?,?,?,?) ON CONFLICT(digest) DO UPDATE SET last_seen=excluded.last_seen,device=excluded.device,device_key=excluded.device_key', [hash('sha256',session_id()),$u['id'],$_SESSION['auth'],(float)($_SESSION['issued']??0),isset($_SESSION['issued'])?(int)$_SESSION['issued']:null,time(),sessionDevice(),$_SESSION['device_key']??null]);
}
function importExistingSessions(array $u): void {
 global $dataDir;
 $fingerprint=(string)($_SESSION['auth']??'');
 $revoked=(float)query('SELECT revoked_at FROM users WHERE id=?',[$u['id']])->fetchColumn();
 foreach(glob($dataDir.'/sessions/sess_*')?:[] as $path){
  $sid=substr(basename($path),5);if(!preg_match('/^[a-zA-Z0-9,-]{16,256}$/D',$sid))continue;
  $raw=@file_get_contents($path);if(!is_string($raw)||strlen($raw)>16384)continue;
  if(!preg_match('/(?:^|;)uid\|i:([0-9]+);/',$raw,$m)||(int)$m[1]!== (int)$u['id'])continue;
  if(!preg_match('/(?:^|;)auth\|s:64:"([a-f0-9]{64})";/',$raw,$m)||!hash_equals($fingerprint,$m[1]))continue;
  if(!preg_match('/(?:^|;)seen\|i:([0-9]+);/',$raw,$m)||(int)$m[1]<time()-86400)continue;$seen=(int)$m[1];
  $issued=preg_match('/(?:^|;)issued\|(?:d|i):([0-9.]+);/',$raw,$m)?(float)$m[1]:0;if($issued<$revoked)continue;
  query('INSERT INTO account_sessions(digest,user_id,auth,issued,started,last_seen,device) VALUES(?,?,?,?,?,?,?) ON CONFLICT(digest) DO NOTHING',[hash('sha256',$sid),$u['id'],$fingerprint,$issued,$issued>0?(int)$issued:null,$seen,'Sesión anterior · Dispositivo no identificado']);
 }
}
function handleAccountSessions(string $action,string $method,array $input): void {
 if(!in_array($action,['sessions','logout-all','logout-session'],true))return;
 global $db;
 $u=requireUser();
 if($action==='sessions'&&$method==='GET'){
  importExistingSessions($u);$current=hash('sha256',session_id());$revoked=(float)query('SELECT revoked_at FROM users WHERE id=?',[$u['id']])->fetchColumn();
  $rows=query('SELECT digest,device,started,last_seen FROM account_sessions WHERE user_id=? AND auth=? AND issued>=? AND last_seen>=? AND revoked=0 ORDER BY (digest=?) DESC,last_seen DESC',[$u['id'],$_SESSION['auth'],$revoked,time()-86400,$current])->fetchAll();
  reply(['sessions'=>array_map(fn($r)=>['id'=>sessionControlId($r['digest']),'device'=>$r['device'],'started'=>$r['started']===null?null:(int)$r['started'],'last_active'=>(int)$r['last_seen'],'current'=>hash_equals($current,$r['digest'])],$rows)]);
 }
 if($action==='logout-session'){
  if($method!=='POST')fail('Operación no disponible.',405);
  limitRate('logout-session',30,900);
  if(($input['confirm']??null)!==true)fail('Confirma que deseas cerrar esta sesión.');
  $id=$input['session_id']??null;if(!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id))fail('Selecciona una sesión válida.');
  $target=null;foreach(query('SELECT digest FROM account_sessions WHERE user_id=? AND auth=? AND revoked=0',[$u['id'],$_SESSION['auth']])->fetchAll() as $row){if(hash_equals(sessionControlId($row['digest']),$id)){$target=$row['digest'];break;}}
  if($target===null)fail('Esta sesión ya no está disponible. Actualiza la lista.',404);
  // Retain the marker so a cookie or legacy-file import cannot restore access.
  query('DELETE FROM login_devices WHERE user_id=? AND device_key IN (SELECT device_key FROM account_sessions WHERE digest=?)',[$u['id'],$target]);
  query('UPDATE account_sessions SET revoked=1 WHERE digest=? AND user_id=?',[$target,$u['id']]);
  $current=hash_equals(hash('sha256',session_id()),$target);
  if($current){$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));reply(['closed_current'=>true,'csrf'=>$_SESSION['csrf'],'message'=>'Cerraste esta sesión. Las demás siguen abiertas.']);}
  reply(['closed_current'=>false,'message'=>'Sesión cerrada. Las demás siguen abiertas.']);
 }
 if($action!=='logout-all'||$method!=='POST')fail('Operación no disponible.',405);
 limitRate('logout-all',5,900);
 if(($input['confirm']??null)!==true)fail('Confirma que deseas cerrar todas las sesiones, incluida esta.');
 $password=$input['current_password']??null;if(!is_string($password)||strlen($password)<1||strlen($password)>72)fail('Escribe tu contraseña actual.');
 $stored=(string)query('SELECT password FROM users WHERE id=?',[$u['id']])->fetchColumn();if(!password_verify(trim($password),$stored))fail('La contraseña actual no es correcta. No se cerraron tus sesiones.',403);
 $db->beginTransaction();
 try{
  $q=query('UPDATE users SET revoked_at=? WHERE id=? AND password=? AND status="active" AND revoked_at<=?',[microtime(true),$u['id'],$stored,(float)($_SESSION['issued']??0)]);
  if(!$q->rowCount()){$db->rollBack();fail('Tu sesión cambió. Inicia sesión nuevamente.',409);}
  query('DELETE FROM login_devices WHERE user_id=?',[$u['id']]);query('DELETE FROM account_sessions WHERE user_id=?',[$u['id']]);query('DELETE FROM pending_email_changes WHERE user_id=?',[$u['id']]);query('DELETE FROM email_codes WHERE user_id=? AND purpose="email-change"',[$u['id']]);$db->commit();
 }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 $_SESSION=[];rotateSession();$_SESSION['csrf']=bin2hex(random_bytes(32));reply(['csrf'=>$_SESSION['csrf'],'message'=>'Cerraste sesión en todos los dispositivos, incluido este. Inicia sesión para volver a entrar.']);
}
