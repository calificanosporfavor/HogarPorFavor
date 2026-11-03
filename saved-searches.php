<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS property_publications(id INTEGER PRIMARY KEY AUTOINCREMENT,property_id TEXT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,created INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS saved_searches(id TEXT PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,name TEXT NOT NULL,operation TEXT NOT NULL,state TEXT NOT NULL,city TEXT NOT NULL,neighborhood TEXT NOT NULL,type TEXT NOT NULL,budget INTEGER NOT NULL,enabled INTEGER NOT NULL DEFAULT 1,cursor INTEGER NOT NULL,created INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS searches_owner ON saved_searches(user_id,created);
CREATE TABLE IF NOT EXISTS search_mail_seen(search_id TEXT NOT NULL REFERENCES saved_searches(id) ON DELETE CASCADE,property_id TEXT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,PRIMARY KEY(search_id,property_id));
CREATE TABLE IF NOT EXISTS search_mail_queue(search_id TEXT NOT NULL REFERENCES saved_searches(id) ON DELETE CASCADE,property_id TEXT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,created INTEGER NOT NULL,next_attempt INTEGER NOT NULL,attempts INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(search_id,property_id));
CREATE TABLE IF NOT EXISTS search_mail_limits(user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,last_sent INTEGER NOT NULL DEFAULT 0,day_start INTEGER NOT NULL DEFAULT 0,day_count INTEGER NOT NULL DEFAULT 0);
CREATE TRIGGER IF NOT EXISTS search_new_property AFTER INSERT ON properties WHEN NEW.status="active" BEGIN INSERT INTO property_publications(property_id,created) VALUES(NEW.id,CAST(strftime("%s","now") AS INTEGER));END;
CREATE TRIGGER IF NOT EXISTS search_activated_property AFTER UPDATE OF status ON properties WHEN NEW.status="active" AND OLD.status<>"active" BEGIN INSERT INTO property_publications(property_id,created) VALUES(NEW.id,CAST(strftime("%s","now") AS INTEGER));END;');
function publicationCursor(): int {return (int)query('SELECT COALESCE(MAX(id),0) FROM property_publications')->fetchColumn();}
function searchMatches(array $s,array $p): bool {
 return $p['status']==='active'&&$p['owner_status']==='active'&&(int)$p['user_id']!==(int)$s['user_id']&&$p['operation']===$s['operation']&&state_key($p['state'])===state_key($s['state'])&&($s['city']===''||location_key($p['city'])===location_key($s['city']))&&($s['neighborhood']===''||location_key($p['neighborhood'])===location_key($s['neighborhood']))&&($s['type']===''||$p['type']===$s['type'])&&((int)$s['budget']===0||(int)$p['price']<=(int)$s['budget']);
}
function handleSavedSearches(string $action,string $method,array $input): void {
 if(!str_starts_with($action,'saved-search-'))return;
 global $db;$u=requireUser();$uid=(int)$u['id'];
 if($action==='saved-search-list'&&$method==='GET'){reply(['items'=>query('SELECT id,name,operation,state,city,neighborhood,type,budget,enabled,created FROM saved_searches WHERE user_id=? ORDER BY created DESC,id',[$uid])->fetchAll()]);}
 if($method!=='POST')fail('Método no permitido.',405);
 if(in_array($action,['saved-search-create','saved-search-update'],true)){
  $editing=$action==='saved-search-update';
  requireVerified($u);$name=strvalInput($input,'name',2,70);$op=choice($input,'operation',['Renta','Venta']);$state=strvalInput($input,'state',2,70);$city=strvalInput($input,'city',0,100);$zone=strvalInput($input,'neighborhood',0,100);$type=$input['type']??'';if(!is_string($type)||!in_array($type,['','Casa','Departamento','Habitación','Terreno'],true))fail('Tipo de propiedad inválido.');$budget=integerInput($input,'budget',0,1000000000);
  if($zone!==''&&$city==='')fail('Indica una ciudad o municipio para guardar una colonia.');if(!$editing&&($input['email_consent']??false)!==true)fail('Confirma que deseas recibir avisos de esta búsqueda.');
  $db->exec('BEGIN IMMEDIATE');try{
   if($editing){
    $id=strvalInput($input,'id',24,24);if(!query('SELECT 1 FROM saved_searches WHERE id=? AND user_id=?',[$id,$uid])->fetchColumn()){$db->exec('ROLLBACK');fail('Búsqueda no disponible.',404);}
    query('UPDATE saved_searches SET name=?,operation=?,state=?,city=?,neighborhood=?,type=?,budget=?,cursor=? WHERE id=? AND user_id=?',[$name,$op,$state,$city,$zone,$type,$budget,publicationCursor(),$id,$uid]);
    query('DELETE FROM search_mail_queue WHERE search_id=?',[$id]);
   }else{
    if((int)query('SELECT COUNT(*) FROM saved_searches WHERE user_id=?',[$uid])->fetchColumn()>=10){$db->exec('ROLLBACK');fail('Puedes guardar hasta 10 búsquedas. Elimina alguna para agregar otra.');}
    $id=bin2hex(random_bytes(12));query('INSERT INTO saved_searches(id,user_id,name,operation,state,city,neighborhood,type,budget,cursor,created) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,$uid,$name,$op,$state,$city,$zone,$type,$budget,publicationCursor(),time()]);
   }
   $db->exec('COMMIT');
  }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}reply(['id'=>$id],$editing?200:201);
 }
 $id=strvalInput($input,'id',24,24);if(!query('SELECT 1 FROM saved_searches WHERE id=? AND user_id=?',[$id,$uid])->fetchColumn())fail('Búsqueda no disponible.',404);
 if($action==='saved-search-toggle'){
  if(!is_bool($input['enabled']??null))fail('Selecciona una opción válida.');if($input['enabled'])requireVerified($u);
  $db->beginTransaction();query('UPDATE saved_searches SET enabled=?,cursor=? WHERE id=? AND user_id=?',[$input['enabled']?1:0,publicationCursor(),$id,$uid]);query('DELETE FROM search_mail_queue WHERE search_id=?',[$id]);$db->commit();reply(['ok'=>true]);
 }
 if($action==='saved-search-delete'){query('DELETE FROM saved_searches WHERE id=? AND user_id=?',[$id,$uid]);reply(['ok'=>true]);}
 fail('Operación no disponible.',404);
}
function discoverSearchMatches(): void {
 global $db;query('DELETE FROM property_publications WHERE created<?',[time()-2592000]);query('DELETE FROM search_mail_queue WHERE created<?',[time()-2592000]);
 $searches=query('SELECT s.* FROM saved_searches s JOIN users u ON u.id=s.user_id WHERE s.enabled=1 AND u.status="active" AND u.email_verified_at IS NOT NULL')->fetchAll();
 foreach($searches as $s){$db->exec('BEGIN IMMEDIATE');try{
  $fresh=query('SELECT s.* FROM saved_searches s JOIN users u ON u.id=s.user_id WHERE s.id=? AND s.enabled=1 AND u.status="active" AND u.email_verified_at IS NOT NULL',[$s['id']])->fetch();if(!$fresh){$db->exec('COMMIT');continue;}$s=$fresh;
  $events=query('SELECT e.id AS event_id,p.*,u.status AS owner_status FROM property_publications e JOIN properties p ON p.id=e.property_id JOIN users u ON u.id=p.user_id WHERE e.id>? ORDER BY e.id LIMIT 200',[$s['cursor']])->fetchAll();
  foreach($events as $p){if(searchMatches($s,$p)&&!query('SELECT 1 FROM search_mail_seen WHERE search_id=? AND property_id=?',[$s['id'],$p['id']])->fetchColumn()){query('INSERT INTO search_mail_seen(search_id,property_id) VALUES(?,?)',[$s['id'],$p['id']]);query('INSERT OR IGNORE INTO search_mail_queue(search_id,property_id,created,next_attempt) VALUES(?,?,?,?)',[$s['id'],$p['id'],time(),time()+300]);}}
  if($events)query('UPDATE saved_searches SET cursor=? WHERE id=?',[(int)end($events)['event_id'],$s['id']]);$db->exec('COMMIT');
 }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}}
}
function deliverSearchMail(string $recipient,array $items): bool {
 global $local,$dataDir;$subject='Nuevas propiedades para tu búsqueda — Hogar Por Favor';$text="Hola,\n\nSe publicaron propiedades que coinciden con tus búsquedas guardadas:\n\n";
 foreach($items as $p){$clean=fn($v)=>preg_replace('/[\r\n]+/u',' ',(string)$v);$text.='• '.$clean($p['title']).' · '.$p['operation'].' · $'.number_format((int)$p['price'],0,'.',',').' MXN'.($p['operation']==='Renta'?' al mes':'').' · '.$clean(implode(', ',array_filter([$p['neighborhood'],$p['city'],$p['state']]))).".\n";}
 $text.="\nEscribe hogarporfavor.com directamente en tu navegador y entra en Mi cuenta → Mis búsquedas guardadas → Ver resultados. Puedes pausar los avisos o eliminar cada búsqueda desde esa sección.\n\nLa disponibilidad y el precio pueden cambiar. Verifica identidad, espacio y condiciones antes de pagar, compartir documentos o firmar. No se reserva ninguna propiedad con este aviso.\n\nAgrupamos coincidencias: hasta un correo por hora y tres cada 24 horas.\n\nHogar Por Favor · Imperio Porfavor\nhogarporfavor.com\nMensaje automático; no respondas a no-reply@hogarporfavor.com.\n";
 if($local){$dir=$dataDir.'/mail-test';if(!is_dir($dir))mkdir($dir,0700,true);return file_put_contents($dir.'/'.bin2hex(random_bytes(10)).'.json',json_encode(['to'=>$recipient,'purpose'=>'saved-search','subject'=>$subject,'body'=>$text],JSON_UNESCAPED_UNICODE))!==false;}
 return function_exists('mail')&&mail($recipient,'=?UTF-8?B?'.base64_encode($subject).'?=',chunk_split(base64_encode($text)),['From'=>'Hogar Por Favor <no-reply@hogarporfavor.com>','MIME-Version'=>'1.0','Content-Type'=>'text/plain; charset=UTF-8','Content-Transfer-Encoding'=>'base64','Auto-Submitted'=>'auto-generated'],'-fno-reply@hogarporfavor.com');
}
function flushSearchMail(): array {
 global $db;discoverSearchMatches();$stats=['sent'=>0,'deferred'=>0,'failed'=>0];
 $ids=query('SELECT s.user_id FROM search_mail_queue q JOIN saved_searches s ON s.id=q.search_id WHERE q.next_attempt<=? GROUP BY s.user_id ORDER BY MIN(q.next_attempt) LIMIT 20',[time()])->fetchAll(PDO::FETCH_COLUMN);
 foreach($ids as $uid){$db->exec('BEGIN IMMEDIATE');try{
  $u=query('SELECT * FROM users WHERE id=?',[$uid])->fetch();$rows=query('SELECT q.*,s.enabled,s.operation AS search_operation,s.state AS search_state,s.city AS search_city,s.neighborhood AS search_neighborhood,s.type AS search_type,s.budget,s.user_id,p.title,p.operation,p.state,p.city,p.neighborhood,p.type,p.price,p.status,p.user_id AS owner_id,owner.status AS owner_status FROM search_mail_queue q JOIN saved_searches s ON s.id=q.search_id JOIN properties p ON p.id=q.property_id JOIN users owner ON owner.id=p.user_id WHERE s.user_id=? ORDER BY q.created,q.property_id',[$uid])->fetchAll();$eligible=[];
  foreach($rows as $p){$s=['operation'=>$p['search_operation'],'state'=>$p['search_state'],'city'=>$p['search_city'],'neighborhood'=>$p['search_neighborhood'],'type'=>$p['search_type'],'budget'=>$p['budget'],'user_id'=>$uid];$check=$p;$check['user_id']=$p['owner_id'];if(!$u||$u['status']!=='active'||$u['email_verified_at']===null||!(int)$p['enabled']||!searchMatches($s,$check)){query('DELETE FROM search_mail_queue WHERE search_id=? AND property_id=?',[$p['search_id'],$p['property_id']]);continue;}if((int)$p['next_attempt']<=time())$eligible[]=$p;}
  if(!$eligible){$db->exec('COMMIT');continue;}
  query('INSERT OR IGNORE INTO search_mail_limits(user_id) VALUES(?)',[$uid]);$pref=query('SELECT * FROM search_mail_limits WHERE user_id=?',[$uid])->fetch();$now=time();$next=(int)$pref['last_sent']+3600;if((int)$pref['day_start']>$now-86400&&(int)$pref['day_count']>=3)$next=max($next,(int)$pref['day_start']+86400);
  if($next>$now||!communityMailAllowance()){query('UPDATE search_mail_queue SET next_attempt=MAX(next_attempt,?) WHERE search_id IN(SELECT id FROM saved_searches WHERE user_id=?)',[max($next,$now+300),$uid]);$db->exec('COMMIT');$stats['deferred']++;continue;}
  $items=[];foreach($eligible as $p){if(count($items)>=20&&!isset($items[$p['property_id']]))continue;$items[$p['property_id']]=$p;}
  try{$ok=deliverSearchMail($u['email'],array_values($items));}catch(Throwable $e){$ok=false;}
  foreach($rows as $p){if(!isset($items[$p['property_id']]))continue;if($ok)query('DELETE FROM search_mail_queue WHERE search_id=? AND property_id=?',[$p['search_id'],$p['property_id']]);else query('UPDATE search_mail_queue SET attempts=attempts+1,next_attempt=? WHERE search_id=? AND property_id=?',[$now+min(21600,300*(2**min(6,(int)$p['attempts']))),$p['search_id'],$p['property_id']]);}
  if($ok){$newDay=(int)$pref['day_start']<=$now-86400;query('UPDATE search_mail_limits SET last_sent=?,day_start=?,day_count=? WHERE user_id=?',[$now,$newDay?$now:$pref['day_start'],$newDay?1:(int)$pref['day_count']+1,$uid]);$stats['sent']++;}else{$stats['failed']++;}$db->exec('COMMIT');
 }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}}
 return $stats;
}
