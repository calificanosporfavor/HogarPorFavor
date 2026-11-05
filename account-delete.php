<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
function cleanupDeletedPhotos(): bool {
 global $dataDir;$ok=true;
 foreach(glob($dataDir.'/erase-*',GLOB_ONLYDIR)?:[] as $dir){
  if(!preg_match('/^erase-[a-f0-9]{32}$/D',basename($dir)))continue;
  if(!is_file($dir.'/.ready')&&time()-(int)filemtime($dir)<=600)continue;
  foreach(glob($dir.'/*.jpg')?:[] as $path){if(!preg_match('/^[a-f0-9]{32}\.jpg$/D',basename($path)))continue;if(query('SELECT 1 FROM photos WHERE file=?',[basename($path)])->fetchColumn()){if(time()-(int)filemtime($dir)>600&&!is_file($dataDir.'/photos/'.basename($path)))@rename($path,$dataDir.'/photos/'.basename($path));continue;}if(!@unlink($path))$ok=false;}
  if(!(glob($dir.'/*.jpg')?:[])){@unlink($dir.'/.ready');@rmdir($dir);}
 }
 return $ok;
}
cleanupDeletedPhotos();
function handleAccountDeletion(string $action,string $method,array $input): void {
 if($action!=='delete-account'||$method!=='POST')return;
 global $db,$dataDir;
 $u=requireUser();limitRate('delete-account',5,900);
 if(($input['confirmation']??null)!=='ELIMINAR'||($input['confirm_delete']??null)!==true)fail('Confirma que deseas eliminar definitivamente tu cuenta y escribe ELIMINAR.');
 $password=strvalInput($input,'password',1,72);
 $hash=(string)query('SELECT password FROM users WHERE id=?',[$u['id']])->fetchColumn();
 if(!password_verify($password,$hash))fail('La contraseña no es correcta. Tu cuenta sigue intacta.',403);
 $staged=[];$stage=$dataDir.'/erase-'.bin2hex(random_bytes(16));
 $db->exec('PRAGMA secure_delete=ON');
 $db->exec('BEGIN IMMEDIATE');
 try{
  $photos=query('SELECT ph.file FROM photos ph JOIN properties p ON p.id=ph.property_id WHERE p.user_id=?',[$u['id']])->fetchAll(PDO::FETCH_COLUMN);$avatars=query('SELECT avatar_file FROM community_profiles WHERE user_id=? AND avatar_file IS NOT NULL',[$u['id']])->fetchAll(PDO::FETCH_COLUMN);$photos=array_merge($photos,$avatars);
  if($photos&&!mkdir($stage,0700))throw new RuntimeException('Deletion staging unavailable');
  foreach($photos as $file){if(!preg_match('/^[a-f0-9]{32}\.jpg$/D',$file))throw new RuntimeException('Invalid stored photo name');$from=$dataDir.'/photos/'.$file;if(!is_file($from))continue;if(!rename($from,$stage.'/'.$file))throw new RuntimeException('Cannot stage photo deletion');$staged[]=$file;}
  query('DELETE FROM admin_audit WHERE actor_id=? OR (action LIKE "user_%" AND target=?) OR (action LIKE "property_%" AND target IN (SELECT id FROM properties WHERE user_id=?)) OR (action LIKE "report_%" AND target IN (SELECT CAST(id AS TEXT) FROM reports WHERE user_id=? OR property_id IN (SELECT id FROM properties WHERE user_id=?)))',[$u['id'],(string)$u['id'],$u['id'],$u['id'],$u['id']]);
  foreach(['verify-hour','verify-minute','reset-hour','reset-minute'] as $scope)query('DELETE FROM limits WHERE key=?',[hash('sha256','mail-recipient|'.$scope.'|'.strtolower($u['email']))]);
  query('DELETE FROM admin_audit WHERE action LIKE "roomie_%" AND target IN (SELECT id FROM roomie_posts WHERE user_id=?)',[$u['id']]);
  query('DELETE FROM admin_audit WHERE action="community_report_resolved" AND target IN (SELECT CAST(id AS TEXT) FROM community_reports WHERE reporter=? OR target=?)',[$u['id'],$u['id']]);
  query('DELETE FROM terms_acceptances WHERE user_id=?',[$u['id']]);
  query('DELETE FROM users WHERE id=?',[$u['id']]);
  $db->exec('COMMIT');
 }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');foreach($staged as $file)@rename($stage.'/'.$file,$dataDir.'/photos/'.$file);@rmdir($stage);throw $e;}
 try{$db->exec('PRAGMA wal_checkpoint(TRUNCATE)');}catch(Throwable $ignored){}
 $_SESSION=[];session_destroy();
 foreach(glob($dataDir.'/sessions/sess_*')?:[] as $path){$raw=@file_get_contents($path);if(is_string($raw)&&str_contains($raw,'uid|i:'.$u['id'].';'))@unlink($path);}
 setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/','secure'=>!($GLOBALS['local']??false),'httponly'=>true,'samesite'=>'Lax']);
 if(is_dir($stage))@touch($stage.'/.ready');
 $clean=cleanupDeletedPhotos()&&!is_dir($stage);
 reply(['ok'=>true,'photos_pending'=>!$clean,'message'=>$clean?'Tu cuenta, publicaciones y fotos se eliminaron.':'Tu cuenta y publicaciones se eliminaron. Algunas fotos quedaron fuera de acceso y su borrado se reintentará automáticamente.']);
}
