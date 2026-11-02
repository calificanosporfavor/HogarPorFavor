<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS community_mail_preferences(user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,enabled INTEGER NOT NULL DEFAULT 1,last_sent INTEGER NOT NULL DEFAULT 0,day_start INTEGER NOT NULL DEFAULT 0,day_count INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS community_mail_queue(user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,pending_since INTEGER NOT NULL,next_attempt INTEGER NOT NULL,attempts INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS community_mail_notified(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,thread_id TEXT NOT NULL REFERENCES community_threads(id) ON DELETE CASCADE,last_notified_id INTEGER NOT NULL,PRIMARY KEY(user_id,thread_id));
CREATE TABLE IF NOT EXISTS community_mail_worker(id INTEGER PRIMARY KEY CHECK(id=1),last_run INTEGER NOT NULL,sent INTEGER NOT NULL DEFAULT 0,deferred INTEGER NOT NULL DEFAULT 0,failed INTEGER NOT NULL DEFAULT 0);');
function pendingCommunityMail(int $uid): array {
 return query('SELECT m.id,m.thread_id,m.created FROM community_messages m JOIN community_threads t ON t.id=m.thread_id JOIN users sender ON sender.id=m.sender JOIN users recipient ON recipient.id=? LEFT JOIN community_reads r ON r.thread_id=t.id AND r.user_id=? LEFT JOIN community_mail_notified n ON n.thread_id=t.id AND n.user_id=? LEFT JOIN community_mail_preferences pref ON pref.user_id=? WHERE (t.a=? OR t.b=?) AND m.sender<>? AND m.id>COALESCE(r.last_read_id,0) AND m.id>COALESCE(n.last_notified_id,0) AND sender.status="active" AND recipient.status="active" AND recipient.email_verified_at IS NOT NULL AND COALESCE(pref.enabled,1)=1 AND NOT EXISTS(SELECT 1 FROM community_blocks b WHERE (b.user_id=? AND b.target=m.sender) OR (b.target=? AND b.user_id=m.sender)) ORDER BY m.id',[$uid,$uid,$uid,$uid,$uid,$uid,$uid,$uid,$uid])->fetchAll();
}
function queueCommunityMail(int $uid): void {
 $pending=pendingCommunityMail($uid);if(!$pending){query('DELETE FROM community_mail_queue WHERE user_id=?',[$uid]);return;}
 query('INSERT OR IGNORE INTO community_mail_preferences(user_id) VALUES(?)',[$uid]);$pref=query('SELECT * FROM community_mail_preferences WHERE user_id=?',[$uid])->fetch();$first=min(array_column($pending,'created'));$due=max((int)$first+300,(int)$pref['last_sent']+3600);
 if((int)$pref['day_start']>time()-86400&&(int)$pref['day_count']>=3)$due=max($due,(int)$pref['day_start']+86400);
 query('INSERT OR IGNORE INTO community_mail_queue(user_id,pending_since,next_attempt) VALUES(?,?,?)',[$uid,$first,$due]);
}
function handleCommunityMail(string $action,string $method,array $input): void {
 if(!in_array($action,['community-mail-settings','community-mail-update'],true))return;
 $u=requireUser();$uid=(int)$u['id'];
 if($action==='community-mail-settings'&&$method==='GET'){$v=query('SELECT enabled FROM community_mail_preferences WHERE user_id=?',[$uid])->fetchColumn();reply(['enabled'=>$v===false||(int)$v===1]);}
 if($action==='community-mail-update'&&$method==='POST'){
  if(!is_bool($input['enabled']??null))fail('Elige si deseas recibir estos avisos.');
  query('INSERT INTO community_mail_preferences(user_id,enabled) VALUES(?,?) ON CONFLICT(user_id) DO UPDATE SET enabled=excluded.enabled',[$uid,$input['enabled']?1:0]);
  queueCommunityMail($uid);reply(['enabled'=>$input['enabled']]);
 }
 fail('Operación no disponible.',405);
}
function deliverCommunityMail(string $recipient): bool {
 global $local,$dataDir;
 $subject='Tienes mensajes sin leer — Hogar Por Favor';
 $text="Hola,\n\nTienes mensajes nuevos sin leer en tu cuenta de Hogar Por Favor.\n\nPara revisarlos, escribe hogarporfavor.com directamente en tu navegador. Entra en Mi cuenta y elige Ver mis mensajes.\n\nNo incluimos el contenido del chat ni datos de otras personas en este aviso. Si ya revisaste los mensajes, puedes ignorar este correo.\n\nConversa con calma: verifica a las personas, el espacio y las condiciones antes de compartir documentos, pagar o firmar. Nunca compartas contraseñas ni códigos.\n\nPuedes desactivar los avisos de mensajes por correo en Mi cuenta → Mi perfil y mis mensajes. Los avisos de seguridad de tu cuenta se conservan.\n\nHogar Por Favor · Imperio Porfavor\nhogarporfavor.com\nMensaje automático; no respondas a no-reply@hogarporfavor.com.\n";
 if($local){$dir=$dataDir.'/mail-test';if(!is_dir($dir))mkdir($dir,0700,true);return file_put_contents($dir.'/'.bin2hex(random_bytes(10)).'.json',json_encode(['to'=>$recipient,'purpose'=>'community-unread','subject'=>$subject,'body'=>$text],JSON_UNESCAPED_UNICODE))!==false;}
 $headers=['From'=>'Hogar Por Favor <no-reply@hogarporfavor.com>','MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8','Content-Transfer-Encoding'=>'base64','Auto-Submitted'=>'auto-generated','X-Auto-Response-Suppress'=>'All'];
 return function_exists('mail')&&mail($recipient,'=?UTF-8?B?'.base64_encode($subject).'?=',chunk_split(base64_encode($text)),$headers,'-fno-reply@hogarporfavor.com');
}
function communityMailAllowance(): bool {
 // Reserve capacity for access and security mail; both quotas also share globalMailAllowance().
 foreach([['minute',60,2],['day',86400,40]] as [$scope,$window,$max]){
  $key='mail-community-'.$scope.'-'.intdiv(time(),$window);if((int)query('SELECT count FROM limits WHERE key=?',[$key])->fetchColumn()>=$max)return false;
 }
 if(!globalMailAllowance())return false;
 foreach([['minute',60],['day',86400]] as [$scope,$window])query('INSERT INTO limits(key,count,expires) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=count+1',['mail-community-'.$scope.'-'.intdiv(time(),$window),time()+$window]);
 return true;
}
function flushCommunityMail(): array {
 global $db,$local;
 $stats=['sent'=>0,'deferred'=>0,'failed'=>0];$now=time();
 // Discover pending recipients independently of page visits, including messages before deployment.
 $recipient='CASE WHEN m.sender=t.a THEN t.b ELSE t.a END';
 $ids=query('SELECT '.$recipient.' AS recipient FROM community_messages m JOIN community_threads t ON t.id=m.thread_id JOIN users sender ON sender.id=m.sender JOIN users recipient ON recipient.id='.$recipient.' LEFT JOIN community_reads r ON r.thread_id=t.id AND r.user_id='.$recipient.' LEFT JOIN community_mail_notified n ON n.thread_id=t.id AND n.user_id='.$recipient.' LEFT JOIN community_mail_preferences pref ON pref.user_id='.$recipient.' WHERE m.id>COALESCE(r.last_read_id,0) AND m.id>COALESCE(n.last_notified_id,0) AND sender.status="active" AND recipient.status="active" AND recipient.email_verified_at IS NOT NULL AND COALESCE(pref.enabled,1)=1 AND NOT EXISTS(SELECT 1 FROM community_mail_queue q WHERE q.user_id='.$recipient.') AND NOT EXISTS(SELECT 1 FROM community_blocks b WHERE (b.user_id='.$recipient.' AND b.target=m.sender) OR (b.target='.$recipient.' AND b.user_id=m.sender)) GROUP BY recipient.id ORDER BY MIN(m.created) LIMIT 200')->fetchAll(PDO::FETCH_COLUMN);
 foreach($ids as $uid)queueCommunityMail((int)$uid);
 $rows=query('SELECT user_id FROM community_mail_queue WHERE next_attempt<=? ORDER BY next_attempt,user_id LIMIT 20',[$now])->fetchAll(PDO::FETCH_COLUMN);
 foreach($rows as $uid){
  $db->exec('BEGIN IMMEDIATE');
  try{
   $q=query('SELECT * FROM community_mail_queue WHERE user_id=? AND next_attempt<=?',[$uid,time()])->fetch();if(!$q){$db->exec('COMMIT');continue;}
   $pending=pendingCommunityMail((int)$uid);if(!$pending){query('DELETE FROM community_mail_queue WHERE user_id=?',[$uid]);$db->exec('COMMIT');continue;}
   $pref=query('SELECT * FROM community_mail_preferences WHERE user_id=?',[$uid])->fetch();$now=time();$next=(int)$pref['last_sent']+3600;
   if((int)$pref['day_start']>$now-86400&&(int)$pref['day_count']>=3)$next=max($next,(int)$pref['day_start']+86400);
   if($next>$now){query('UPDATE community_mail_queue SET next_attempt=? WHERE user_id=?',[$next,$uid]);$db->exec('COMMIT');$stats['deferred']++;continue;}
   if(!communityMailAllowance()){query('UPDATE community_mail_queue SET next_attempt=? WHERE user_id=?',[$now+300,$uid]);$db->exec('COMMIT');$stats['deferred']++;continue;}
   $recipient=(string)query('SELECT email FROM users WHERE id=?',[$uid])->fetchColumn();
   try{$ok=deliverCommunityMail($recipient);}catch(Throwable $e){$ok=false;}
   if(!$ok){query('UPDATE community_mail_queue SET attempts=attempts+1,next_attempt=? WHERE user_id=?',[$now+min(21600,300*(2**min(6,(int)$q['attempts']))),$uid]);$db->exec('COMMIT');$stats['failed']++;continue;}
   foreach($pending as $m)query('INSERT INTO community_mail_notified(user_id,thread_id,last_notified_id) VALUES(?,?,?) ON CONFLICT(user_id,thread_id) DO UPDATE SET last_notified_id=MAX(last_notified_id,excluded.last_notified_id)',[$uid,$m['thread_id'],$m['id']]);
   $newDay=(int)$pref['day_start']<=$now-86400;query('UPDATE community_mail_preferences SET last_sent=?,day_start=?,day_count=? WHERE user_id=?',[$now,$newDay?$now:$pref['day_start'],$newDay?1:(int)$pref['day_count']+1,$uid]);
   query('DELETE FROM community_mail_queue WHERE user_id=?',[$uid]);$db->exec('COMMIT');$stats['sent']++;
  }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}
 }
 query('INSERT INTO community_mail_worker(id,last_run,sent,deferred,failed) VALUES(1,?,?,?,?) ON CONFLICT(id) DO UPDATE SET last_run=excluded.last_run,sent=excluded.sent,deferred=excluded.deferred,failed=excluded.failed',[time(),$stats['sent'],$stats['deferred'],$stats['failed']]);
 return $stats;
}
