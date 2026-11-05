<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS property_availability(property_id TEXT PRIMARY KEY REFERENCES properties(id) ON DELETE CASCADE,confirmed_at INTEGER NOT NULL,reminded_at INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS property_reminder_accounts(user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,last_sent INTEGER NOT NULL DEFAULT 0);');
query('INSERT OR IGNORE INTO property_availability(property_id,confirmed_at) SELECT id,? FROM properties',[time()]);
function confirmPropertyAvailability(string $id): void {query('INSERT INTO property_availability(property_id,confirmed_at,reminded_at) VALUES(?,?,0) ON CONFLICT(property_id) DO UPDATE SET confirmed_at=excluded.confirmed_at,reminded_at=0',[$id,time()]);}
function validatePropertyOutcome(string $status,string $operation): void {if(($status==='rented'&&$operation!=='Renta')||($status==='sold'&&$operation!=='Venta'))fail('El estado debe corresponder a renta o venta.');}
function handlePropertyAvailability(string $action,string $method,array $input): void {
 if($action!=='confirm-availability')return;
 if($method!=='POST')fail('Método no permitido.',405);
 $u=requireUser();requireVerified($u);$id=strvalInput($input,'id',24,24);$p=selectProperty($id);
 if(!$p||(int)$p['user_id']!==(int)$u['id'])fail('Anuncio no disponible.',404);
 if($p['status']!=='active')fail('Solo puedes confirmar anuncios activos.');
 confirmPropertyAvailability($id);reply(['ok'=>true,'message'=>'Disponibilidad confirmada. Gracias por mantener tu anuncio al día.']);
}
function flushPropertyReminders(): array {
 global $db,$local,$dataDir;$stats=['sent'=>0,'deferred'=>0,'failed'=>0];$now=time();
 $ids=query('SELECT DISTINCT p.user_id FROM properties p JOIN property_availability a ON a.property_id=p.id JOIN users u ON u.id=p.user_id LEFT JOIN property_reminder_accounts r ON r.user_id=u.id WHERE p.status="active" AND u.status="active" AND u.email_verified_at IS NOT NULL AND a.confirmed_at<=? AND a.reminded_at<=? AND COALESCE(r.last_sent,0)<=? ORDER BY a.confirmed_at LIMIT 20',[$now-2592000,$now-604800,$now-604800])->fetchAll(PDO::FETCH_COLUMN);
 foreach($ids as $uid){$db->exec('BEGIN IMMEDIATE');try{
  $rows=query('SELECT p.id FROM properties p JOIN property_availability a ON a.property_id=p.id JOIN users u ON u.id=p.user_id LEFT JOIN property_reminder_accounts r ON r.user_id=u.id WHERE p.user_id=? AND p.status="active" AND u.status="active" AND u.email_verified_at IS NOT NULL AND a.confirmed_at<=? AND a.reminded_at<=? AND COALESCE(r.last_sent,0)<=?',[$uid,$now-2592000,$now-604800,$now-604800])->fetchAll(PDO::FETCH_COLUMN);
  if(!$rows){$db->exec('COMMIT');continue;}
  if(!communityMailAllowance()){$db->exec('COMMIT');$stats['deferred']++;continue;}
  $recipient=(string)query('SELECT email FROM users WHERE id=?',[$uid])->fetchColumn();
  $subject='¿Tu propiedad sigue disponible? — Hogar Por Favor';
  $text="Hola,\n\nTienes anuncios activos cuya disponibilidad no has confirmado en los últimos 30 días.\n\nEscribe hogarporfavor.com en tu navegador, entra en Mi cuenta y revisa tus propiedades. Puedes elegir Sigue disponible, Ya rentada o Ya vendida, según corresponda. También puedes pausar el anuncio.\n\nLos anuncios marcados como rentados o vendidos dejan de mostrarse al público; conservas sus fotos y puedes volver a publicarlos. No retiramos automáticamente un anuncio por no responder este recordatorio.\n\nAgrupamos estos avisos y enviamos como máximo uno por semana mientras haya anuncios pendientes. Al confirmar disponibilidad, el siguiente recordatorio será después de 30 días. Si ya no deseas recibirlos, pausa o cierra tus anuncios.\n\nHogar Por Favor\nhogarporfavor.com\nMensaje automático; no respondas a no-reply@hogarporfavor.com.\n";
  try{if($local){$dir=$dataDir.'/mail-test';if(!is_dir($dir))mkdir($dir,0700,true);$ok=file_put_contents($dir.'/'.bin2hex(random_bytes(10)).'.json',json_encode(['to'=>$recipient,'purpose'=>'property-availability','subject'=>$subject,'body'=>$text],JSON_UNESCAPED_UNICODE))!==false;}else{$ok=mail($recipient,'=?UTF-8?B?'.base64_encode($subject).'?=',chunk_split(base64_encode($text)),['From'=>'Hogar Por Favor <no-reply@hogarporfavor.com>','MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8','Content-Transfer-Encoding'=>'base64','Auto-Submitted'=>'auto-generated'],'-fno-reply@hogarporfavor.com');}}catch(Throwable $e){$ok=false;}
  if($ok){foreach($rows as $id)query('UPDATE property_availability SET reminded_at=? WHERE property_id=?',[$now,$id]);query('INSERT INTO property_reminder_accounts(user_id,last_sent) VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET last_sent=excluded.last_sent',[$uid,$now]);$stats['sent']++;}else{$stats['failed']++;}
  $db->exec('COMMIT');
 }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}}
 return $stats;
}
