<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS community_profiles(user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,id TEXT NOT NULL UNIQUE,bio TEXT NOT NULL,intent TEXT NOT NULL,state TEXT NOT NULL,city TEXT NOT NULL,budget INTEGER NOT NULL,visible INTEGER NOT NULL DEFAULT 0,updated INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS community_threads(id TEXT PRIMARY KEY,a INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,b INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,created INTEGER NOT NULL,UNIQUE(a,b));
CREATE TABLE IF NOT EXISTS community_messages(id INTEGER PRIMARY KEY,thread_id TEXT NOT NULL REFERENCES community_threads(id) ON DELETE CASCADE,sender INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,body TEXT NOT NULL,created INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS community_message_thread ON community_messages(thread_id,id);
CREATE TABLE IF NOT EXISTS community_reads(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,thread_id TEXT NOT NULL REFERENCES community_threads(id) ON DELETE CASCADE,last_read_id INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(user_id,thread_id));
CREATE TABLE IF NOT EXISTS community_blocks(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,target INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,created INTEGER NOT NULL,PRIMARY KEY(user_id,target));
CREATE TABLE IF NOT EXISTS community_reports(id INTEGER PRIMARY KEY,reporter INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,target INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,reason TEXT NOT NULL,thread_id TEXT REFERENCES community_threads(id) ON DELETE CASCADE,created INTEGER NOT NULL,resolved_at INTEGER,context TEXT NOT NULL DEFAULT "[]");');
function socialProfile(string $id): ?array {return query('SELECT p.*,u.name,u.status,u.email_verified_at FROM community_profiles p JOIN users u ON u.id=p.user_id WHERE p.id=?',[$id])->fetch()?:null;}
function socialPublic(array $p): array {return ['id'=>$p['id'],'name'=>$p['name'],'bio'=>$p['bio'],'intent'=>$p['intent'],'state'=>$p['state'],'city'=>$p['city'],'budget'=>(int)$p['budget']];}
function socialAvailable(?array $p): bool {return $p&&(int)$p['visible']===1&&$p['status']==='active'&&$p['email_verified_at']!==null;}
function socialBlocked(int $a,int $b): bool {return (bool)query('SELECT 1 FROM community_blocks WHERE (user_id=? AND target=?) OR (user_id=? AND target=?)',[$a,$b,$b,$a])->fetchColumn();}
function socialThread(string $id,array $u): array {$t=query('SELECT * FROM community_threads WHERE id=? AND (a=? OR b=?)',[$id,$u['id'],$u['id']])->fetch();if(!$t)fail('Conversación no disponible.',404);$t['other']=(int)$t['a']===(int)$u['id']?(int)$t['b']:(int)$t['a'];return $t;}
function socialUnread(int $uid): array {
 $r=query('SELECT COUNT(*) AS unread,COUNT(DISTINCT m.thread_id) AS threads,COALESCE(MAX(m.id),0) AS latest FROM community_messages m JOIN community_threads t ON t.id=m.thread_id JOIN users sender ON sender.id=m.sender LEFT JOIN community_reads r ON r.thread_id=t.id AND r.user_id=? WHERE (t.a=? OR t.b=?) AND m.sender<>? AND m.id>COALESCE(r.last_read_id,0) AND sender.status="active" AND NOT EXISTS(SELECT 1 FROM community_blocks b WHERE (b.user_id=? AND b.target=m.sender) OR (b.target=? AND b.user_id=m.sender))',[$uid,$uid,$uid,$uid,$uid,$uid])->fetch();
 return ['unread'=>(int)$r['unread'],'threads'=>(int)$r['threads'],'latest'=>(int)$r['latest']];
}
function handleCommunity(string $action,string $method,array $input): void {
 if(!str_starts_with($action,'community-')&&!str_starts_with($action,'admin-community'))return;
 if($action==='community-profiles'&&$method==='GET'){
  $where=['p.visible=1','u.status="active"','u.email_verified_at IS NOT NULL'];$args=[];
  foreach(['state','city'] as $field){$v=$_GET[$field]??'';if(!is_string($v)||mb_strlen($v)>100)fail('Revisa la ubicación.');if(trim($v)!==''){$fn=$field==='state'?'state_key':'location_key';$where[]=$fn.'(p.'.$field.')=?';$args[]=$fn($v);}}
  $intent=$_GET['intent']??'';if(!is_string($intent)||!in_array($intent,['','Busco espacio','Ofrezco espacio','Busco roomie'],true))fail('Revisa tu búsqueda.');if($intent!==''){$where[]='p.intent=?';$args[]=$intent;}
  $max=$_GET['budget']??'';if($max!==''){$max=filter_var($max,FILTER_VALIDATE_INT);if($max===false||$max<1||$max>1000000)fail('Revisa el presupuesto.');$where[]='p.budget<=?';$args[]=$max;}
  $page=max(1,min(10000,(int)($_GET['page']??1)));$sql=implode(' AND ',$where);$total=(int)query('SELECT COUNT(*) FROM community_profiles p JOIN users u ON u.id=p.user_id WHERE '.$sql,$args)->fetchColumn();$items=query('SELECT p.*,u.name FROM community_profiles p JOIN users u ON u.id=p.user_id WHERE '.$sql.' ORDER BY p.updated DESC,p.id LIMIT 12 OFFSET '.(($page-1)*12),$args)->fetchAll();reply(['items'=>array_map('socialPublic',$items),'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/12)]);
 }
 if($action==='community-profile'&&$method==='GET'){
  $p=socialProfile((string)($_GET['id']??''));if(!socialAvailable($p))fail('Este perfil no está disponible.',404);
  $posts=query('SELECT p.*,u.name AS owner_name FROM roomie_posts p JOIN users u ON u.id=p.user_id WHERE p.user_id=? AND p.status="active" ORDER BY p.created DESC LIMIT 20',[$p['user_id']])->fetchAll();
  $homes=query('SELECT id,title,city,state,price,operation FROM properties WHERE user_id=? AND status="active" ORDER BY created DESC LIMIT 20',[$p['user_id']])->fetchAll();foreach($homes as &$h){$h['photo']=query('SELECT id FROM photos WHERE property_id=? ORDER BY position LIMIT 1',[$h['id']])->fetchColumn()?:null;}unset($h);
  reply(['profile'=>socialPublic($p),'posts'=>array_map(fn($r)=>roomieData($r),$posts),'properties'=>$homes]);
 }
 $u=requireUser();$uid=(int)$u['id'];
 if($action==='community-unread'&&$method==='GET')reply(socialUnread($uid));
 if($action==='community-read'&&$method==='POST'){
  $t=socialThread(strvalInput($input,'thread',24,24),$u);$upTo=integerInput($input,'up_to',1,PHP_INT_MAX);
  if(!query('SELECT 1 FROM community_messages WHERE id=? AND thread_id=?',[$upTo,$t['id']])->fetchColumn())fail('Mensaje no disponible.',404);
  query('INSERT INTO community_reads(user_id,thread_id,last_read_id) VALUES(?,?,?) ON CONFLICT(user_id,thread_id) DO UPDATE SET last_read_id=MAX(last_read_id,excluded.last_read_id)',[$uid,$t['id'],$upTo]);reply(socialUnread($uid));
 }
 if(str_starts_with($action,'admin-community')){
  if(!isSuperadmin($u))fail('Acceso reservado al superadmin.',403);
  if($action==='admin-community-reports'&&$method==='GET'){
   $resolved=($_GET['status']??'open')==='resolved';$items=query('SELECT r.*,a.name AS reporter_name,b.name AS target_name,b.email AS target_email,b.status AS target_status FROM community_reports r JOIN users a ON a.id=r.reporter JOIN users b ON b.id=r.target WHERE r.resolved_at IS '.($resolved?'NOT NULL':'NULL').' ORDER BY r.created DESC LIMIT 100')->fetchAll();foreach($items as &$r){$r['messages']=json_decode($r['context'],true)?:[];unset($r['context']);}unset($r);reply(['items'=>$items]);
  }
  if($action==='admin-community-resolve'&&$method==='POST'){$id=integerInput($input,'id',1,PHP_INT_MAX);query('UPDATE community_reports SET resolved_at=? WHERE id=?',[time(),$id]);auditAdmin($u,'community_report_resolved',(string)$id);reply(['ok'=>true]);}
  fail('Operación no disponible.',405);
 }
 if($action==='community-mine'&&$method==='GET'){$p=query('SELECT p.*,u.name FROM community_profiles p JOIN users u ON u.id=p.user_id WHERE user_id=?',[$uid])->fetch();reply(['profile'=>$p?array_merge(socialPublic($p),['visible'=>(bool)$p['visible']]):null]);}
 if($action==='community-inbox'&&$method==='GET'){
  $rows=query('SELECT t.*,u.name AS other_name,u.status AS other_status,p.id AS profile_id,p.visible,(SELECT body FROM community_messages WHERE thread_id=t.id ORDER BY id DESC LIMIT 1) AS last_message,(SELECT MAX(created) FROM community_messages WHERE thread_id=t.id) AS last_at FROM community_threads t JOIN users u ON u.id=CASE WHEN t.a=? THEN t.b ELSE t.a END LEFT JOIN community_profiles p ON p.user_id=u.id WHERE t.a=? OR t.b=? ORDER BY COALESCE(last_at,t.created) DESC LIMIT 100',[$uid,$uid,$uid])->fetchAll();$unreads=query('SELECT m.thread_id,COUNT(*) AS total FROM community_messages m JOIN users sender ON sender.id=m.sender LEFT JOIN community_reads r ON r.thread_id=m.thread_id AND r.user_id=? WHERE m.thread_id IN (SELECT id FROM community_threads WHERE a=? OR b=?) AND m.sender<>? AND m.id>COALESCE(r.last_read_id,0) AND sender.status="active" GROUP BY m.thread_id',[$uid,$uid,$uid,$uid])->fetchAll(PDO::FETCH_KEY_PAIR);$items=[];foreach($rows as $r){$other=(int)$r['a']===$uid?(int)$r['b']:(int)$r['a'];$items[]=['id'=>$r['id'],'name'=>$r['other_name'],'profile_id'=>(int)$r['visible']===1&&$r['other_status']==='active'?$r['profile_id']:null,'last_message'=>$r['last_message'],'last_at'=>$r['last_at'],'unread'=>!socialBlocked($uid,$other)&&$r['other_status']==='active'?(int)($unreads[$r['id']]??0):0,'blocked'=>socialBlocked($uid,$other),'blocked_by_me'=>(bool)query('SELECT 1 FROM community_blocks WHERE user_id=? AND target=?',[$uid,$other])->fetchColumn()];}reply(['items'=>$items]);
 }
 if($action==='community-messages'&&$method==='GET'){$t=socialThread((string)($_GET['id']??''),$u);$before=max(0,(int)($_GET['before']??0));$rows=query('SELECT id,sender,body,created FROM community_messages WHERE thread_id=?'.($before?' AND id<?':'').' ORDER BY id DESC LIMIT 51',$before?[$t['id'],$before]:[$t['id']])->fetchAll();$more=count($rows)>50;if($more)array_pop($rows);foreach($rows as &$r){$r['mine']=(int)$r['sender']===$uid;unset($r['sender']);}unset($r);$other=query('SELECT name,status FROM users WHERE id=?',[$t['other']])->fetch();reply(['items'=>array_reverse($rows),'more'=>$more,'name'=>$other['name'],'blocked'=>socialBlocked($uid,$t['other']),'blocked_by_me'=>(bool)query('SELECT 1 FROM community_blocks WHERE user_id=? AND target=?',[$uid,$t['other']])->fetchColumn(),'available'=>$other['status']==='active']);}
 if($method!=='POST')fail('Operación no disponible.',405);
 if($action==='community-save'){
  requireVerified($u);roomieSafety($input);limitRate('community-profile-'.$uid,15,3600);if(($input['public_consent']??false)!==true)fail('Autoriza mostrar la información que eliges publicar.');
  $bio=strvalInput($input,'bio',20,800);$intent=choice($input,'intent',['Busco espacio','Ofrezco espacio','Busco roomie']);$state=strvalInput($input,'state',2,70);$city=strvalInput($input,'city',2,100);$budget=integerInput($input,'budget',1,1000000);$visible=($input['visible']??false)===true?1:0;
  global $db;$db->beginTransaction();try{query('INSERT INTO community_profiles(user_id,id,bio,intent,state,city,budget,visible,updated) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(user_id) DO UPDATE SET bio=excluded.bio,intent=excluded.intent,state=excluded.state,city=excluded.city,budget=excluded.budget,visible=excluded.visible,updated=excluded.updated',[$uid,bin2hex(random_bytes(12)),$bio,$intent,$state,$city,$budget,$visible,time()]);recordTerms($uid,'community-profile');$db->commit();}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}reply(['ok'=>true]);
 }
 if($action==='community-hide'){query('UPDATE community_profiles SET visible=0,updated=? WHERE user_id=?',[time(),$uid]);reply(['ok'=>true]);}
 if($action==='community-delete-profile'){if(($input['confirm']??false)!==true)fail('Confirma que deseas eliminar el perfil.');query('DELETE FROM community_profiles WHERE user_id=?',[$uid]);reply(['ok'=>true]);}
 if($action==='community-start'){
  requireVerified($u);roomieSafety($input);limitRate('community-start-'.$uid,20,3600);$p=socialProfile(strvalInput($input,'id',24,24));if(!socialAvailable($p)||socialBlocked($uid,(int)$p['user_id']))fail('No puedes iniciar esta conversación.',404);if((int)$p['user_id']===$uid)fail('Este es tu propio perfil.');$a=min($uid,(int)$p['user_id']);$b=max($uid,(int)$p['user_id']);query('INSERT INTO community_threads(id,a,b,created) VALUES(?,?,?,?) ON CONFLICT(a,b) DO NOTHING',[bin2hex(random_bytes(12)),$a,$b,time()]);recordTerms($uid,'community-chat');reply(['id'=>query('SELECT id FROM community_threads WHERE a=? AND b=?',[$a,$b])->fetchColumn()]);
 }
 if(in_array($action,['community-send','community-block','community-report'],true)){
  requireVerified($u);$thread=null;$target=0;
  if(($input['thread']??'')!==''){$thread=socialThread(strvalInput($input,'thread',24,24),$u);$target=$thread['other'];}
  elseif($action==='community-report'){$p=socialProfile(strvalInput($input,'profile',24,24));if(!socialAvailable($p))fail('Perfil no disponible.',404);$target=(int)$p['user_id'];}
  else fail('Elige una conversación.');
  if($target===$uid)fail('Elige otro perfil.');
  if($action==='community-send'){
   limitRate('community-message-minute-'.$uid,15,60);limitRate('community-message-day-'.$uid,250,86400);$body=strvalInput($input,'body',1,2000);if(socialBlocked($uid,$target)||query('SELECT status FROM users WHERE id=?',[$target])->fetchColumn()!=='active')fail('Esta conversación no admite mensajes.',403);
   query('INSERT INTO community_messages(thread_id,sender,body,created) VALUES(?,?,?,?)',[$thread['id'],$uid,$body,time()]);reply(['ok'=>true],201);
  }
  if($action==='community-block'){if(($input['confirm']??false)!==true)fail('Confirma el cambio de bloqueo.');if(($input['block']??true)===false)query('DELETE FROM community_blocks WHERE user_id=? AND target=?',[$uid,$target]);else query('INSERT OR IGNORE INTO community_blocks(user_id,target,created) VALUES(?,?,?)',[$uid,$target,time()]);reply(['ok'=>true]);}
  limitRate('community-report-'.$uid,10,3600);$reason=strvalInput($input,'reason',10,1000);if($thread&&($input['share_messages']??false)!==true)fail('Autoriza compartir el contexto de esta conversación para revisar el reporte.');$context=$thread?query('SELECT m.body,m.created,u.name AS sender_name FROM community_messages m JOIN users u ON u.id=m.sender WHERE thread_id=? ORDER BY m.id DESC LIMIT 20',[$thread['id']])->fetchAll():[];query('INSERT INTO community_reports(reporter,target,reason,thread_id,created,context) VALUES(?,?,?,?,?,?)',[$uid,$target,$reason,$thread['id']??null,time(),json_encode($context,JSON_UNESCAPED_UNICODE)]);reply(['ok'=>true]);
 }
 fail('Operación no disponible.',404);
}
