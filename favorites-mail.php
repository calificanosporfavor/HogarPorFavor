<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS favorites_mail_preferences(user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,enabled INTEGER NOT NULL DEFAULT 1,last_sent INTEGER NOT NULL DEFAULT 0,day_start INTEGER NOT NULL DEFAULT 0,day_count INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS favorites_mail_queue(user_id INTEGER NOT NULL,property_id TEXT NOT NULL,kind TEXT NOT NULL,operation TEXT NOT NULL,old_price INTEGER NOT NULL,new_price INTEGER NOT NULL,created INTEGER NOT NULL,next_attempt INTEGER NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,property_id),FOREIGN KEY(user_id,property_id) REFERENCES property_favorites(user_id,property_id) ON DELETE CASCADE);
CREATE TRIGGER IF NOT EXISTS favorites_property_changes AFTER UPDATE OF price,status,operation ON properties
WHEN (NEW.status IN ("rented","sold") AND OLD.status<>NEW.status) OR (NEW.status="active" AND OLD.status="active" AND NEW.operation=OLD.operation AND NEW.price<OLD.price)
BEGIN
INSERT INTO favorites_mail_queue(user_id,property_id,kind,operation,old_price,new_price,created,next_attempt)
SELECT f.user_id,NEW.id,CASE WHEN NEW.status IN ("rented","sold") THEN NEW.status ELSE "price" END,NEW.operation,OLD.price,NEW.price,CAST(strftime("%s","now") AS INTEGER),CAST(strftime("%s","now") AS INTEGER)+300
FROM property_favorites f JOIN users u ON u.id=f.user_id LEFT JOIN favorites_mail_preferences pref ON pref.user_id=f.user_id
WHERE f.property_id=NEW.id AND f.user_id<>NEW.user_id AND u.status="active" AND u.email_verified_at IS NOT NULL AND COALESCE(pref.enabled,1)=1
ON CONFLICT(user_id,property_id) DO UPDATE SET
old_price=CASE WHEN favorites_mail_queue.kind="price" AND excluded.kind="price" AND favorites_mail_queue.operation=excluded.operation THEN favorites_mail_queue.old_price ELSE excluded.old_price END,
kind=excluded.kind,operation=excluded.operation,new_price=excluded.new_price,next_attempt=MIN(favorites_mail_queue.next_attempt,excluded.next_attempt),attempts=0;
END;');
function handleFavoritesMail(string $action,string $method,array $input): void {
 if(!in_array($action,['favorites-mail-settings','favorites-mail-update'],true))return;
 $u=requireUser();$uid=(int)$u['id'];
 if($action==='favorites-mail-settings'&&$method==='GET'){$v=query('SELECT enabled FROM favorites_mail_preferences WHERE user_id=?',[$uid])->fetchColumn();reply(['enabled'=>$v===false||(int)$v===1]);}
 if($action==='favorites-mail-update'&&$method==='POST'){
  if(!is_bool($input['enabled']??null))fail('Elige si deseas recibir estos avisos.');
  query('INSERT INTO favorites_mail_preferences(user_id,enabled) VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET enabled=excluded.enabled',[$uid,$input['enabled']?1:0]);
  if(!$input['enabled'])query('DELETE FROM favorites_mail_queue WHERE user_id=?',[$uid]);reply(['enabled'=>$input['enabled']]);
 }
 fail('Método no permitido.',405);
}
function eligibleFavoriteChanges(int $uid): array {
 return query('SELECT q.*,p.title,p.price,p.status,p.operation AS current_operation FROM favorites_mail_queue q JOIN property_favorites f ON f.user_id=q.user_id AND f.property_id=q.property_id JOIN properties p ON p.id=q.property_id JOIN users owner ON owner.id=p.user_id JOIN users recipient ON recipient.id=q.user_id LEFT JOIN favorites_mail_preferences pref ON pref.user_id=q.user_id WHERE q.user_id=? AND p.user_id<>q.user_id AND recipient.status="active" AND recipient.email_verified_at IS NOT NULL AND owner.status="active" AND COALESCE(pref.enabled,1)=1 AND ((q.kind=\'price\' AND p.status="active" AND p.operation=q.operation AND p.price<q.old_price) OR (q.kind IN ("rented","sold") AND p.status=q.kind)) ORDER BY q.created,q.property_id',[$uid])->fetchAll();
}
function deliverFavoritesMail(string $recipient,array $rows): bool {
 global $local,$dataDir;$subject='Hay novedades en tus favoritos — Hogar Por Favor';
 $text="Hola,\n\nHay cambios en propiedades que guardaste en favoritos:\n\n";
 foreach($rows as $p){$reference=substr($p['property_id'],0,8);if($p['kind']==='price'){$title=preg_replace('/[\r\n]+/u',' ', $p['title']);$text.='• '.$title.' (referencia '.$reference.'): bajó de $'.number_format((int)$p['old_price'],0,'.',',').' a $'.number_format((int)$p['price'],0,'.',',').' MXN'.($p['operation']==='Renta'?' al mes':'').".\n";}else{$text.='• Una propiedad guardada (referencia '.$reference.') se marcó como '.($p['kind']==='rented'?'ya rentada':'ya vendida').". Ya no está disponible al público.\n";}}
 $text.="\nEscribe hogarporfavor.com directamente en tu navegador y entra en Mi cuenta → Mis favoritos para revisar los cambios. No incluimos fotos, contacto ni descripción de anuncios retirados.\n\nGuardar un anuncio no reserva el espacio ni garantiza su disponibilidad. Verifica la información, la identidad de quien ofrece y las condiciones antes de pagar o firmar.\n\nPuedes desactivar estos avisos en Mi cuenta → Mis favoritos. También puedes quitar una propiedad para dejar de recibir avisos sobre ella. Agrupamos cambios con un máximo de un correo por hora y tres por cada 24 horas.\n\nHogar Por Favor · Imperio Porfavor\nhogarporfavor.com\nMensaje automático; no respondas a no-reply@hogarporfavor.com.\n";
 if($local){$dir=$dataDir.'/mail-test';if(!is_dir($dir))mkdir($dir,0700,true);return file_put_contents($dir.'/'.bin2hex(random_bytes(10)).'.json',json_encode(['to'=>$recipient,'purpose'=>'favorites-changes','subject'=>$subject,'body'=>$text],JSON_UNESCAPED_UNICODE))!==false;}
 return function_exists('mail')&&mail($recipient,'=?UTF-8?B?'.base64_encode($subject).'?=',chunk_split(base64_encode($text)),['From'=>'Hogar Por Favor <no-reply@hogarporfavor.com>','MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8','Content-Transfer-Encoding'=>'base64','Auto-Submitted'=>'auto-generated'],'-fno-reply@hogarporfavor.com');
}
function flushFavoritesMail(): array {
 global $db;$stats=['sent'=>0,'deferred'=>0,'failed'=>0];$now=time();
 query('DELETE FROM favorites_mail_queue WHERE created<?',[$now-2592000]);
 $ids=query('SELECT user_id FROM favorites_mail_queue WHERE next_attempt<=? GROUP BY user_id ORDER BY MIN(next_attempt) LIMIT 20',[$now])->fetchAll(PDO::FETCH_COLUMN);
 foreach($ids as $uid){$db->exec('BEGIN IMMEDIATE');try{
  $rows=eligibleFavoriteChanges((int)$uid);$valid=array_column($rows,'property_id');$all=query('SELECT property_id FROM favorites_mail_queue WHERE user_id=?',[$uid])->fetchAll(PDO::FETCH_COLUMN);foreach(array_diff($all,$valid) as $id)query('DELETE FROM favorites_mail_queue WHERE user_id=? AND property_id=?',[$uid,$id]);
  $rows=array_values(array_filter($rows,fn($p)=>(int)$p['next_attempt']<=time()));if(!$rows){$db->exec('COMMIT');continue;}
  query('INSERT OR IGNORE INTO favorites_mail_preferences(user_id) VALUES(?)',[$uid]);$pref=query('SELECT * FROM favorites_mail_preferences WHERE user_id=?',[$uid])->fetch();$now=time();$next=(int)$pref['last_sent']+3600;if((int)$pref['day_start']>$now-86400&&(int)$pref['day_count']>=3)$next=max($next,(int)$pref['day_start']+86400);
  if($next>$now||!communityMailAllowance()){query('UPDATE favorites_mail_queue SET next_attempt=MAX(next_attempt,?) WHERE user_id=?',[max($next,$now+300),$uid]);$db->exec('COMMIT');$stats['deferred']++;continue;}
  $batch=array_slice($rows,0,20);$email=(string)query('SELECT email FROM users WHERE id=?',[$uid])->fetchColumn();try{$ok=deliverFavoritesMail($email,$batch);}catch(Throwable $e){$ok=false;}
  if(!$ok){foreach($batch as $p)query('UPDATE favorites_mail_queue SET attempts=attempts+1,next_attempt=? WHERE user_id=? AND property_id=?',[$now+min(21600,300*(2**min(6,(int)$p['attempts']))),$uid,$p['property_id']]);$stats['failed']++;}
  else{foreach($batch as $p)query('DELETE FROM favorites_mail_queue WHERE user_id=? AND property_id=?',[$uid,$p['property_id']]);$newDay=(int)$pref['day_start']<=$now-86400;query('UPDATE favorites_mail_preferences SET last_sent=?,day_start=?,day_count=? WHERE user_id=?',[$now,$newDay?$now:$pref['day_start'],$newDay?1:(int)$pref['day_count']+1,$uid]);$stats['sent']++;}
  $db->exec('COMMIT');
 }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}}
 return $stats;
}
