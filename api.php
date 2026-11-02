<?php
declare(strict_types=1);
ini_set('display_errors', '0');
umask(0077);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, private');
header('Referrer-Policy: same-origin');
function reply(array $data, int $status = 200): never { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function fail(string $message, int $status = 400): never { reply(['error'=>$message], $status); }
set_exception_handler(function(Throwable $e): void { error_log('Hogar API: '.$e->getMessage()); reply(['error'=>'No pudimos completar la operación. Intenta de nuevo en unos minutos.'],503); });
$action = $_GET['action'] ?? 'session';
$method = $_SERVER['REQUEST_METHOD'];
$local = PHP_SAPI === 'cli-server';
if (!$local && empty($_SERVER['HTTPS'])) fail('Utiliza la conexión HTTPS.', 400);
$documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/\\');
// DOCUMENT_ROOT retains the stable domain path even when Hostinger swaps Git build symlinks.
$dataDir = $local ? dirname(__DIR__).'/hogar-test-data' : dirname($documentRoot).'/.hogar-private';
if (!is_dir($dataDir) && !mkdir($dataDir,0700,true)) throw new RuntimeException('Private data directory unavailable');
$realRoot = realpath($documentRoot); $realData = realpath($dataDir);
if (!$realData || ($realRoot && str_starts_with($realData.DIRECTORY_SEPARATOR,$realRoot.DIRECTORY_SEPARATOR))) throw new RuntimeException('Data must stay outside document root');
if (!is_dir($dataDir.'/photos')) mkdir($dataDir.'/photos',0700,true);
if (!is_dir($dataDir.'/sessions')) mkdir($dataDir.'/sessions',0700,true);
ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1'); ini_set('session.gc_maxlifetime','86400');
session_save_path($dataDir.'/sessions'); session_name('hogar_session');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!$local,'httponly'=>true,'samesite'=>'Lax']); session_start();
if (isset($_SESSION['seen']) && time()-(int)$_SESSION['seen']>86400) { $_SESSION=[]; session_regenerate_id(true); }
$_SESSION['seen']=time();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$db = new PDO('sqlite:'.$dataDir.'/hogar.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL;');
require_once __DIR__.'/location-keys.php';
$db->sqliteCreateFunction('location_key','location_key',1);
$db->sqliteCreateFunction('state_key','state_key',1);
$db->exec('CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, name TEXT NOT NULL, email TEXT NOT NULL UNIQUE, password TEXT NOT NULL, recovery TEXT NOT NULL, created INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS properties (id TEXT PRIMARY KEY, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, title TEXT NOT NULL, operation TEXT NOT NULL, type TEXT NOT NULL, price INTEGER NOT NULL, state TEXT NOT NULL, city TEXT NOT NULL, neighborhood TEXT NOT NULL, description TEXT NOT NULL, contact_type TEXT NOT NULL, contact TEXT NOT NULL, bedrooms INTEGER NOT NULL DEFAULT 0, bathrooms INTEGER NOT NULL DEFAULT 0, area INTEGER NOT NULL DEFAULT 0, status TEXT NOT NULL DEFAULT "active", created INTEGER NOT NULL, updated INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS photos (id TEXT PRIMARY KEY, property_id TEXT NOT NULL REFERENCES properties(id) ON DELETE CASCADE, file TEXT NOT NULL, position INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS limits (key TEXT PRIMARY KEY, count INTEGER NOT NULL, expires INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS reports (id INTEGER PRIMARY KEY, property_id TEXT NOT NULL REFERENCES properties(id) ON DELETE CASCADE, user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE, reason TEXT NOT NULL, created INTEGER NOT NULL, UNIQUE(property_id,user_id));
CREATE INDEX IF NOT EXISTS property_status_created ON properties(status,created);');
define('HOGAR_INTERNAL',true);
require __DIR__.'/auth-mail.php';
migrateEmail();
require __DIR__.'/admin-api.php';
migrateAdmin();
require __DIR__.'/terms.php';
require __DIR__.'/account-delete.php';
require __DIR__.'/account-profile.php';
require __DIR__.'/account-email.php';
require __DIR__.'/security-mail.php';
require __DIR__.'/account-sessions.php';
require __DIR__.'/login-devices.php';
require __DIR__.'/hero.php';
flushSecurityNotices();
function query(string $sql,array $params=[]): PDOStatement { global $db; $s=$db->prepare($sql); $s->execute($params); return $s; }
function user(): ?array { if (!isset($_SESSION['uid'])) return null; $u=query('SELECT id,name,email,password,email_verified_at,status,revoked_at FROM users WHERE id=?',[(int)$_SESSION['uid']])->fetch(); if(currentSessionRevoked()||!$u||$u['status']!=='active'||(float)($_SESSION['issued']??0)<(float)$u['revoked_at']||!hash_equals(hash('sha256',$u['password']),(string)($_SESSION['auth']??''))){unset($_SESSION['uid'],$_SESSION['auth']);return null;}$u['email_verified']=$u['email_verified_at']!==null;trackAccountSession($u);$u['role']=strtolower($u['email'])===HOGAR_SUPERADMIN&&$u['email_verified']?'superadmin':'user';unset($u['password'],$u['email_verified_at'],$u['status'],$u['revoked_at']);return $u; }
function requireUser(): array { return user() ?? fail('Inicia sesión para continuar.',401); }
function strvalInput(array $input,string $name,int $min,int $max): string { $v=$input[$name]??''; if (!is_string($v)) fail('Revisa el campo '.$name.'.'); $v=trim($v); if(mb_strlen($v)<$min||mb_strlen($v)>$max) fail('Revisa el campo '.$name.'.'); return $v; }
function integerInput(array $input,string $name,int $min,int $max): int { $v=filter_var($input[$name]??null,FILTER_VALIDATE_INT); if($v===false||$v<$min||$v>$max) fail('Revisa el campo '.$name.'.'); return $v; }
function choice(array $input,string $name,array $values): string { $v=strvalInput($input,$name,1,30); if(!in_array($v,$values,true)) fail('Selecciona una opción válida.'); return $v; }
function limitRate(string $scope,int $maximum,int $window): void { $identity=$_SERVER['REMOTE_ADDR']??'local'; $key=hash('sha256',$scope.'|'.$identity); $now=time(); query('DELETE FROM limits WHERE expires<?',[$now]); query('INSERT INTO limits(key,count,expires) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=count+1',[$key,$now+$window]); $row=query('SELECT count FROM limits WHERE key=?',[$key])->fetch(); if((int)$row['count']>$maximum) { header('Retry-After: '.$window); fail('Demasiados intentos. Espera unos minutos antes de intentar otra vez.',429); } }
function propertyData(array $p,bool $owner=false): array { $p['price']=(int)$p['price']; $p['bedrooms']=(int)$p['bedrooms']; $p['bathrooms']=(int)$p['bathrooms']; $p['area']=(int)$p['area']; $p['photos']=query('SELECT id FROM photos WHERE property_id=? ORDER BY position',[$p['id']])->fetchAll(PDO::FETCH_COLUMN); unset($p['user_id']); if(!$owner) unset($p['email']); return $p; }
function selectProperty(string $id): ?array { return query('SELECT p.*,u.name AS owner_name,u.status AS owner_status FROM properties p JOIN users u ON u.id=p.user_id WHERE p.id=?',[$id])->fetch() ?: null; }
if(isset($_SESSION['uid']))user();
if ($method==='POST') {
  $origin=$_SERVER['HTTP_ORIGIN']??'';
  $expected=($local?'http://':'https://').($_SERVER['HTTP_HOST']??'');
  if ($origin!=='' && $origin!==$expected) fail('Solicitud no permitida.',403);
  if (!hash_equals($_SESSION['csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??''))) fail('Tu sesión cambió. Actualiza la página e intenta de nuevo.',403);
  $contentType=$_SERVER['CONTENT_TYPE']??'';
  if (str_starts_with($contentType,'application/json')) { $raw=file_get_contents('php://input',false,null,0,16385); if(strlen($raw)>16384) fail('La solicitud es demasiado grande.',413); $input=json_decode($raw,true); if(!is_array($input)) fail('Solicitud inválida.'); } else $input=$_POST;
} elseif ($method!=='GET') fail('Método no permitido.',405);
if(defined('HOGAR_ADMIN_PAGE')) {
 header('Content-Type: text/html; charset=utf-8'); header('X-Robots-Tag: noindex, nofollow, noarchive');
 if($method!=='GET'||!isSuperadmin()){http_response_code(404);echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>No encontrado</title><h1>Página no encontrada.</h1></html>';exit;}
 require __DIR__.'/admin-view.php';exit;
}
handleHero($action,$method,$input??[]);
handleAccountSessions($action,$method,$input??[]);
handleAccountEmail($action,$method,$input??[]);
handleAccountProfile($action,$method,$input??[]);
handleAccountDeletion($action,$method,$input??[]);
handleEmailAction($action,$method,$input??[]);
handleAdminAction($action,$method,$input??[]);
if ($action==='health' && $method==='GET') reply(['ready'=>true,'images'=>function_exists('imagecreatefromstring'),'email'=>function_exists('mail'),'version'=>5]);
if ($action==='session' && $method==='GET') reply(['user'=>user(),'csrf'=>$_SESSION['csrf']]);
if ($action==='register' && $method==='POST') {
  limitRate('register',8,3600); requireTerms($input);
  $name=strvalInput($input,'name',2,70); $email=strtolower(strvalInput($input,'email',5,254)); $password=strvalInput($input,'password',12,72);
  if(strlen($password)>72) fail('Usa una contraseña de hasta 72 bytes.');
  if(!filter_var($email,FILTER_VALIDATE_EMAIL)) fail('Escribe un correo válido.');
  if(($input['privacy']??false)!==true) fail('Confirma que leíste la información de privacidad.');
  if(query('SELECT id FROM users WHERE email=?',[$email])->fetch()) fail('No se pudo registrar ese correo. Si ya tienes cuenta, inicia sesión o recupera tu contraseña por correo.',409);
  $recovery=bin2hex(random_bytes(16));
  $db->beginTransaction();
  query('INSERT INTO users(name,email,password,recovery,created) VALUES(?,?,?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT),password_hash($recovery,PASSWORD_DEFAULT),time()]);
  $newUserId=(int)$db->lastInsertId(); recordTerms($newUserId,'register'); $db->commit();
  rotateSession(); $_SESSION['uid']=$newUserId; $_SESSION['auth']=hash('sha256',(string)query('SELECT password FROM users WHERE id=?',[$_SESSION['uid']])->fetchColumn()); $_SESSION['csrf']=bin2hex(random_bytes(32));
  $_SESSION['issued']=microtime(true);
  rememberLoginDevice(['id'=>$newUserId,'email'=>$email],false);
  $sent=sendAuthEmail(user(),'verify');
  reply(['user'=>user(),'csrf'=>$_SESSION['csrf'],'email_sent'=>$sent,'message'=>$sent?'Cuenta creada. Revisa tu correo para confirmar tu dirección.':'Cuenta creada, pero no pudimos enviar el correo ahora. Reintenta el envío desde Mi cuenta.'],201);
}
if ($action==='login' && $method==='POST') {
  limitRate('login',20,900); $email=strtolower(strvalInput($input,'email',5,254)); $password=strvalInput($input,'password',1,72);
  $u=query('SELECT * FROM users WHERE email=?',[$email])->fetch();
  $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
  if(!password_verify($password,$u['password']??$dummy)||!$u) fail('Correo o contraseña incorrectos.',401);
  if($u['status']!=='active')fail('Esta cuenta está suspendida. Contacta a la administración.',403);
  $_SESSION['issued']=microtime(true);
  rotateSession(); $_SESSION['uid']=(int)$u['id']; $_SESSION['auth']=hash('sha256',$u['password']); $_SESSION['csrf']=bin2hex(random_bytes(32)); rememberLoginDevice($u,true); reply(['user'=>user(),'csrf'=>$_SESSION['csrf']]);
}
if ($action==='recover' && $method==='POST') {
  limitRate('recover',8,3600); $email=strtolower(strvalInput($input,'email',5,254)); $code=strvalInput($input,'code',32,32); $password=strvalInput($input,'password',12,72);
  if(strlen($password)>72) fail('Usa una contraseña de hasta 72 bytes.');
  $u=query('SELECT * FROM users WHERE email=?',[$email])->fetch(); if(!$u || !password_verify($code,$u['recovery'])) fail('Correo o código de recuperación incorrectos.',401);
  if($u['status']!=='active')fail('Esta cuenta está suspendida. Contacta a la administración.',403);
  $_SESSION['issued']=microtime(true);
  $db->beginTransaction();
  $newCode=bin2hex(random_bytes(16)); query('UPDATE users SET password=?,recovery=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),password_hash($newCode,PASSWORD_DEFAULT),$u['id']]);
  query('UPDATE email_tokens SET used=? WHERE user_id=? AND used IS NULL',[time(),$u['id']]);
  query('UPDATE email_codes SET used=? WHERE user_id=? AND used IS NULL',[time(),$u['id']]);
  queuePasswordNotice((int)$u['id']);$db->commit();flushSecurityNotices();
  // Revoke all active sessions for this account by rotating its credential fingerprint.
  rotateSession(); $_SESSION['uid']=(int)$u['id']; $_SESSION['auth']=hash('sha256',(string)query('SELECT password FROM users WHERE id=?',[$u['id']])->fetchColumn()); $_SESSION['csrf']=bin2hex(random_bytes(32)); reply(['user'=>user(),'csrf'=>$_SESSION['csrf'],'recovery'=>$newCode]);
}
if ($action==='logout' && $method==='POST') { $_SESSION=[]; rotateSession(); $_SESSION['csrf']=bin2hex(random_bytes(32)); reply(['csrf'=>$_SESSION['csrf']]); }
if ($action==='list' && $method==='GET') {
  $where=['p.status="active"','EXISTS(SELECT 1 FROM users owner WHERE owner.id=p.user_id AND owner.status="active")']; $params=[];
  foreach(['state','city','neighborhood'] as $field){$value=$_GET[$field]??'';if(!is_string($value)||mb_strlen($value)>100)fail('Filtro de ubicación inválido.');if(trim($value)!==''){$function=$field==='state'?'state_key':'location_key';$where[]=$function.'(p.'.$field.')=?';$params[]=$function($value);}}
  $operation=$_GET['operation']??''; if(in_array($operation,['Renta','Venta'],true)){$where[]='p.operation=?';$params[]=$operation;}
  $type=$_GET['type']??''; if(in_array($type,['Casa','Departamento','Habitación','Terreno'],true)){$where[]='p.type=?';$params[]=$type;}
  $location=mb_substr(trim((string)($_GET['location']??'')),0,100);
  if($location!==''){$where[]='(p.city LIKE ? OR p.state LIKE ? OR p.neighborhood LIKE ?)';$q='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$location).'%';$where[count($where)-1]='(p.city LIKE ? ESCAPE "\\" OR p.state LIKE ? ESCAPE "\\" OR p.neighborhood LIKE ? ESCAPE "\\")';array_push($params,$q,$q,$q);}
  $budget=filter_var($_GET['budget']??null,FILTER_VALIDATE_INT); if($budget && $budget>0){$where[]='p.price<=?';$params[]=$budget;}
  $page=max(1,min(10000,(int)($_GET['page']??1))); $offset=($page-1)*12;
  $count=(int)query('SELECT COUNT(*) FROM properties p WHERE '.implode(' AND ',$where),$params)->fetchColumn();
  $rows=query('SELECT p.*,u.name AS owner_name FROM properties p JOIN users u ON u.id=p.user_id WHERE '.implode(' AND ',$where).' ORDER BY p.created DESC,p.id DESC LIMIT 12 OFFSET '.$offset,$params)->fetchAll();
  reply(['properties'=>array_map(fn($p)=>propertyData($p),$rows),'total'=>$count,'page'=>$page,'pages'=>(int)ceil($count/12)]);
}
if($action==='mine' && $method==='GET') { $u=requireUser(); $rows=query('SELECT p.*,u.name AS owner_name FROM properties p JOIN users u ON u.id=p.user_id WHERE user_id=? AND p.status!="archived" ORDER BY created DESC',[$u['id']])->fetchAll(); reply(['properties'=>array_map(fn($p)=>propertyData($p,true),$rows)]); }
if($action==='detail' && $method==='GET') { $p=selectProperty((string)($_GET['id']??''));$viewer=user();if(!$p||(($p['status']!=='active'||$p['owner_status']!=='active')&&((int)$p['user_id']!==(int)($viewer['id']??0))&&!isSuperadmin($viewer))) fail('Este anuncio no está disponible.',404); reply(['property'=>propertyData($p)]); }
if($action==='photo' && $method==='GET') {
  $photo=query('SELECT ph.*,p.status,p.user_id,u.status AS owner_status FROM photos ph JOIN properties p ON p.id=ph.property_id JOIN users u ON u.id=p.user_id WHERE ph.id=?',[(string)($_GET['id']??'')])->fetch();
  $viewer=user();if(!$photo||(($photo['status']!=='active'||$photo['owner_status']!=='active')&&(int)$photo['user_id']!==(int)($viewer['id']??0)&&!isSuperadmin($viewer))) fail('Imagen no disponible.',404);
  $file=$dataDir.'/photos/'.$photo['file']; if(!is_file($file)) fail('Imagen no disponible.',404);
  session_write_close(); header('Content-Type: image/jpeg'); header('Content-Length: '.filesize($file)); readfile($file); exit;
}
if(in_array($action,['create','update'],true) && $method==='POST') {
  $u=requireUser(); requireTerms($input); limitRate('publish',30,3600);
  $id=$action==='create'?bin2hex(random_bytes(12)):strvalInput($input,'id',24,24);
  if($action==='update'){ $old=selectProperty($id); if(!$old||(int)$old['user_id']!==(int)$u['id']) fail('Anuncio no disponible.',404);if($old['status']==='archived')fail('La publicación está archivada por administración.',403); }
  if($action==='create'&&(int)query('SELECT COUNT(*) FROM properties WHERE user_id=?',[$u['id']])->fetchColumn()>=30) fail('Puedes mantener hasta 30 anuncios. Edita o pausa uno de los existentes.');
  $title=strvalInput($input,'title',8,100); $op=choice($input,'operation',['Renta','Venta']); $type=choice($input,'type',['Casa','Departamento','Habitación','Terreno']);
  $price=integerInput($input,'price',1,1000000000); $state=strvalInput($input,'state',2,70); $city=strvalInput($input,'city',2,100); $zone=strvalInput($input,'neighborhood',0,100); $desc=strvalInput($input,'description',30,5000);
  $contactType=choice($input,'contact_type',['WhatsApp','Teléfono','Correo']); $contact=strvalInput($input,'contact',5,254);
  if($contactType==='Correo') {if(!filter_var($contact,FILTER_VALIDATE_EMAIL)) fail('Escribe un correo de contacto válido.');} else {$contact=preg_replace('/[^0-9]/','',$contact);if(strlen($contact)<10||strlen($contact)>15)fail('Usa un teléfono de 10 a 15 dígitos, con código de país para WhatsApp.');if(strlen($contact)===10)$contact='52'.$contact;}
  if(($input['contact_consent']??'')!=='1') fail('Autoriza mostrar el contacto de este anuncio.');
  $beds=integerInput($input,'bedrooms',0,100);$baths=integerInput($input,'bathrooms',0,100);$area=integerInput($input,'area',0,10000000);
  $status=choice($input,'status',['active','paused']);
  if($status==='active')requireVerified($u);
  $uploads=$_FILES['photos']??null; $count=0;
  if($uploads && is_array($uploads['error'])) foreach($uploads['error'] as $error) {if($error!==UPLOAD_ERR_NO_FILE)$count++;}
  if($count>8) fail('Sube como máximo 8 fotos.');
  $existing=$action==='update'?(int)query('SELECT COUNT(*) FROM photos WHERE property_id=?',[$id])->fetchColumn():0;
  if($count===0&&$existing===0) fail('Agrega al menos una foto.');
  $prepared=[];
  try {
    if($count>0) {
      if(!function_exists('imagecreatefromstring')) fail('La carga de imágenes no está disponible temporalmente.',503);
      foreach($uploads['error'] as $i=>$error) {
        if($error===UPLOAD_ERR_NO_FILE)continue;
        if($error!==UPLOAD_ERR_OK || $uploads['size'][$i]>6*1024*1024) throw new InvalidArgumentException('Cada foto debe pesar como máximo 6 MB.');
        $tmp=$uploads['tmp_name'][$i]; if(!is_uploaded_file($tmp))throw new InvalidArgumentException('Archivo inválido.');
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp); if(!in_array($mime,['image/jpeg','image/png','image/webp'],true))throw new InvalidArgumentException('Solo se admiten fotos JPG, PNG o WebP.');
        $dimensions=getimagesize($tmp); if(!$dimensions||$dimensions[0]<100||$dimensions[1]<100||$dimensions[0]*$dimensions[1]>20000000)throw new InvalidArgumentException('Usa fotos de al menos 100 píxeles y hasta 20 megapíxeles.');
        $source=imagecreatefromstring(file_get_contents($tmp));if(!$source)throw new InvalidArgumentException('No se pudo procesar una foto.');
        $scale=min(1,1600/max($dimensions[0],$dimensions[1]));$w=max(1,(int)round($dimensions[0]*$scale));$h=max(1,(int)round($dimensions[1]*$scale));
        $image=imagecreatetruecolor($w,$h);imagefill($image,0,0,imagecolorallocate($image,255,255,255));imagecopyresampled($image,$source,0,0,0,0,$w,$h,$dimensions[0],$dimensions[1]);
        $photoId=bin2hex(random_bytes(16));$filename=$photoId.'.jpg';if(!imagejpeg($image,$dataDir.'/photos/'.$filename,85))throw new RuntimeException('Cannot store image');chmod($dataDir.'/photos/'.$filename,0600);$prepared[]=['id'=>$photoId,'file'=>$filename];unset($image,$source);
      }
    }
    $db->beginTransaction();$now=time();
    if($action==='create')query('INSERT INTO properties(id,user_id,title,operation,type,price,state,city,neighborhood,description,contact_type,contact,bedrooms,bathrooms,area,status,created,updated) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,$u['id'],$title,$op,$type,$price,$state,$city,$zone,$desc,$contactType,$contact,$beds,$baths,$area,$status,$now,$now]);
    else query('UPDATE properties SET title=?,operation=?,type=?,price=?,state=?,city=?,neighborhood=?,description=?,contact_type=?,contact=?,bedrooms=?,bathrooms=?,area=?,status=?,updated=? WHERE id=? AND user_id=?',[$title,$op,$type,$price,$state,$city,$zone,$desc,$contactType,$contact,$beds,$baths,$area,$status,$now,$id,$u['id']]);
    $oldPhotos=[];if(count($prepared)>0){$oldPhotos=query('SELECT file FROM photos WHERE property_id=?',[$id])->fetchAll(PDO::FETCH_COLUMN);query('DELETE FROM photos WHERE property_id=?',[$id]);foreach($prepared as $i=>$ph)query('INSERT INTO photos(id,property_id,file,position) VALUES(?,?,?,?)',[$ph['id'],$id,$ph['file'],$i]);}
    recordTerms((int)$u['id'],$action,$id);
    $db->commit();foreach($oldPhotos as $file)@unlink($dataDir.'/photos/'.$file);
  } catch(Throwable $e) {if($db->inTransaction())$db->rollBack();foreach($prepared as $ph)@unlink($dataDir.'/photos/'.$ph['file']);if($e instanceof InvalidArgumentException)fail($e->getMessage());throw $e;}
  reply(['property'=>propertyData(selectProperty($id),true)],$action==='create'?201:200);
}
if($action==='status' && $method==='POST') {$u=requireUser();$id=strvalInput($input,'id',24,24);$status=choice($input,'status',['active','paused']);$p=selectProperty($id);if(!$p||(int)$p['user_id']!==(int)$u['id'])fail('Anuncio no disponible.',404);if($p['status']==='archived')fail('La publicación está archivada por administración.',403);if($status==='active'){requireVerified($u);requireTerms($input);}$db->beginTransaction();query('UPDATE properties SET status=?,updated=? WHERE id=?',[$status,time(),$id]);if($status==='active')recordTerms((int)$u['id'],'reactivate',$id);$db->commit();reply(['ok'=>true]);}
if($action==='report' && $method==='POST') {$u=requireUser();limitRate('report',10,3600);$id=strvalInput($input,'id',24,24);$reason=strvalInput($input,'reason',10,1000);$p=selectProperty($id);if(!$p||$p['status']!=='active')fail('Anuncio no disponible.',404);query('INSERT INTO reports(property_id,user_id,reason,created) VALUES(?,?,?,?) ON CONFLICT(property_id,user_id) DO UPDATE SET reason=excluded.reason,created=excluded.created,resolved_at=NULL',[$id,$u['id'],$reason,time()]);reply(['ok'=>true]);}
fail('Operación no disponible.',404);
