<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
const HOGAR_SUPERADMIN='cueva@hogarporfavor.com';
function migrateAdmin(): void {
 global $db;if((int)$db->query('PRAGMA user_version')->fetchColumn()>=5)return;
 $db->exec('BEGIN IMMEDIATE');
 try{
  $columns=$db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN,1);
  if(!in_array('status',$columns,true))$db->exec('ALTER TABLE users ADD COLUMN status TEXT NOT NULL DEFAULT "active"');
  if(!in_array('revoked_at',$columns,true))$db->exec('ALTER TABLE users ADD COLUMN revoked_at REAL NOT NULL DEFAULT 0');
  $columns=$db->query('PRAGMA table_info(reports)')->fetchAll(PDO::FETCH_COLUMN,1);
  if(!in_array('resolved_at',$columns,true))$db->exec('ALTER TABLE reports ADD COLUMN resolved_at INTEGER DEFAULT NULL');
  $db->exec('CREATE TABLE IF NOT EXISTS admin_audit(id INTEGER PRIMARY KEY,actor_id INTEGER NOT NULL,action TEXT NOT NULL,target TEXT NOT NULL,created INTEGER NOT NULL);PRAGMA user_version=5;COMMIT;');
 }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function isSuperadmin(?array $u=null): bool {$u??=user();return $u!==null && strtolower($u['email'])===HOGAR_SUPERADMIN && $u['email_verified']===true;}
function auditAdmin(array $actor,string $action,string $target): void {query('INSERT INTO admin_audit(actor_id,action,target,created) VALUES(?,?,?,?)',[$actor['id'],$action,$target,time()]);}
function adminSearch(): string {$q=$_GET['q']??'';if(!is_string($q)||mb_strlen($q)>100)fail('Búsqueda inválida.');return trim($q);}
function handleAdminAction(string $action,string $method,array $input): void {
 if(!str_starts_with($action,'admin-'))return;
 $actor=requireUser();if(!isSuperadmin($actor))fail('Este panel está reservado para la cuenta superadmin con correo confirmado.',403);
 if($action==='admin-summary'&&$method==='GET'){
  reply(['users'=>(int)query('SELECT COUNT(*) FROM users')->fetchColumn(),'verified'=>(int)query('SELECT COUNT(*) FROM users WHERE email_verified_at IS NOT NULL')->fetchColumn(),'suspended'=>(int)query('SELECT COUNT(*) FROM users WHERE status="suspended"')->fetchColumn(),'properties'=>(int)query('SELECT COUNT(*) FROM properties')->fetchColumn(),'active'=>(int)query('SELECT COUNT(*) FROM properties p JOIN users u ON u.id=p.user_id WHERE p.status="active" AND u.status="active"')->fetchColumn(),'reports'=>(int)query('SELECT COUNT(*) FROM reports WHERE resolved_at IS NULL')->fetchColumn()]);
 }
 if($action==='admin-users'&&$method==='GET'){
  $q=adminSearch();$page=max(1,(int)($_GET['page']??1));$where='1=1';$args=[];if($q!==''){$where='(instr(lower(name),lower(?))>0 OR instr(lower(email),lower(?))>0)';$args=[$q,$q];}
  $total=(int)query('SELECT COUNT(*) FROM users WHERE '.$where,$args)->fetchColumn();$offset=min(1000000,($page-1)*20);
  $rows=query('SELECT id,name,email,created,status,email_verified_at,(SELECT COUNT(*) FROM properties p WHERE p.user_id=users.id) AS property_count FROM users WHERE '.$where.' ORDER BY created DESC,id DESC LIMIT 20 OFFSET '.$offset,$args)->fetchAll();
  foreach($rows as &$r){$r['protected']=strtolower($r['email'])===HOGAR_SUPERADMIN;}$rows=array_values($rows);
  reply(['items'=>$rows,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/20)]);
 }
 if($action==='admin-properties'&&$method==='GET'){
  $q=adminSearch();$status=$_GET['status']??'';if(!is_string($status)||!in_array($status,['','active','paused','archived'],true))fail('Estado inválido.');$where=['1=1'];$args=[];
  if($status!==''){$where[]='p.status=?';$args[]=$status;}if($q!==''){$where[]='(instr(lower(p.title),lower(?))>0 OR instr(lower(p.city),lower(?))>0 OR instr(lower(u.email),lower(?))>0)';array_push($args,$q,$q,$q);}
  $page=max(1,(int)($_GET['page']??1));$sql=implode(' AND ',$where);$total=(int)query('SELECT COUNT(*) FROM properties p JOIN users u ON u.id=p.user_id WHERE '.$sql,$args)->fetchColumn();$offset=min(1000000,($page-1)*20);
  $rows=query('SELECT p.*,u.name AS owner_name,u.email AS owner_email,u.status AS owner_status,u.email_verified_at AS owner_verified_at FROM properties p JOIN users u ON u.id=p.user_id WHERE '.$sql.' ORDER BY p.created DESC LIMIT 20 OFFSET '.$offset,$args)->fetchAll();
  reply(['items'=>array_map(fn($p)=>propertyData($p,true),$rows),'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/20)]);
 }
 if($action==='admin-reports'&&$method==='GET'){
  $page=max(1,(int)($_GET['page']??1));$offset=min(1000000,($page-1)*20);$status=$_GET['status']??'open';$where=$status==='resolved'?'r.resolved_at IS NOT NULL':'r.resolved_at IS NULL';
  $total=(int)query('SELECT COUNT(*) FROM reports r WHERE '.$where)->fetchColumn();$rows=query('SELECT r.*,p.title,p.status AS property_status,u.name AS reporter_name,u.email AS reporter_email FROM reports r JOIN properties p ON p.id=r.property_id JOIN users u ON u.id=r.user_id WHERE '.$where.' ORDER BY r.created DESC LIMIT 20 OFFSET '.$offset)->fetchAll();reply(['items'=>$rows,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/20)]);
 }
 if($action==='admin-audit'&&$method==='GET')reply(['items'=>query('SELECT a.*,u.email AS actor_email FROM admin_audit a LEFT JOIN users u ON u.id=a.actor_id ORDER BY a.id DESC LIMIT 100')->fetchAll()]);
 if($method!=='POST')fail('Operación no disponible.',404);
 if($action==='admin-user-status'){
  $id=integerInput($input,'id',1,PHP_INT_MAX);$status=choice($input,'status',['active','suspended']);$target=query('SELECT id,email FROM users WHERE id=?',[$id])->fetch();if(!$target)fail('Usuario no encontrado.',404);if(strtolower($target['email'])===HOGAR_SUPERADMIN)fail('La cuenta superadmin no se puede suspender.');
  if($status==='suspended')query('UPDATE users SET status=?,revoked_at=? WHERE id=?',[$status,microtime(true),$id]);else query('UPDATE users SET status=? WHERE id=?',[$status,$id]);auditAdmin($actor,'user_'.$status,(string)$id);reply(['ok'=>true]);
 }
 if($action==='admin-property-status'){
  $id=strvalInput($input,'id',24,24);$status=choice($input,'status',['active','paused','archived']);$p=query('SELECT p.id,u.status AS owner_status,u.email_verified_at FROM properties p JOIN users u ON u.id=p.user_id WHERE p.id=?',[$id])->fetch();if(!$p)fail('Publicación no encontrada.',404);if($status==='active'&&($p['owner_status']!=='active'||$p['email_verified_at']===null))fail('Para publicar, la cuenta propietaria debe estar activa y tener el correo confirmado.');query('UPDATE properties SET status=?,updated=? WHERE id=?',[$status,time(),$id]);auditAdmin($actor,'property_'.$status,$id);reply(['ok'=>true]);
 }
 if($action==='admin-property-update'){
  $id=strvalInput($input,'id',24,24);if(!query('SELECT id FROM properties WHERE id=?',[$id])->fetch())fail('Publicación no encontrada.',404);
  $title=strvalInput($input,'title',8,100);$price=integerInput($input,'price',1,1000000000);$state=strvalInput($input,'state',2,70);$city=strvalInput($input,'city',2,100);$zone=strvalInput($input,'neighborhood',0,100);$description=strvalInput($input,'description',30,5000);
  query('UPDATE properties SET title=?,price=?,state=?,city=?,neighborhood=?,description=?,updated=? WHERE id=?',[$title,$price,$state,$city,$zone,$description,time(),$id]);auditAdmin($actor,'property_edited',$id);reply(['ok'=>true]);
 }
 if($action==='admin-report-status'){
  $id=integerInput($input,'id',1,PHP_INT_MAX);$status=choice($input,'status',['open','resolved']);if(!query('SELECT id FROM reports WHERE id=?',[$id])->fetch())fail('Reporte no encontrado.',404);query('UPDATE reports SET resolved_at=? WHERE id=?',[$status==='resolved'?time():null,$id]);auditAdmin($actor,'report_'.$status,(string)$id);reply(['ok'=>true]);
 }
 fail('Operación no disponible.',404);
}
