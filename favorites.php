<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS property_favorites(user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,property_id TEXT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,created INTEGER NOT NULL,PRIMARY KEY(user_id,property_id));CREATE INDEX IF NOT EXISTS favorites_owner_date ON property_favorites(user_id,created);');
function handleFavorites(string $action,string $method,array $input): void {
 if(!in_array($action,['favorites-ids','favorites-list','favorites-save','favorites-remove'],true))return;
 global $db;$u=requireUser();$uid=(int)$u['id'];
 if($action==='favorites-ids'&&$method==='GET')reply(['ids'=>query('SELECT property_id FROM property_favorites WHERE user_id=? ORDER BY created DESC,property_id',[$uid])->fetchAll(PDO::FETCH_COLUMN)]);
 if($action==='favorites-list'&&$method==='GET'){
  $page=max(1,min(25,(int)($_GET['page']??1)));$total=(int)query('SELECT COUNT(*) FROM property_favorites WHERE user_id=?',[$uid])->fetchColumn();
  $rows=query('SELECT p.*,u.name AS owner_name,u.status AS owner_status FROM property_favorites f JOIN properties p ON p.id=f.property_id JOIN users u ON u.id=p.user_id WHERE f.user_id=? ORDER BY f.created DESC,f.property_id LIMIT 12 OFFSET '.(($page-1)*12),[$uid])->fetchAll();
  $items=[];foreach($rows as $p){if($p['status']==='active'&&$p['owner_status']==='active'){$items[]=['available'=>true,'property'=>propertyData($p)];}else{$items[]=['available'=>false,'id'=>$p['id'],'status'=>$p['owner_status']==='active'&&in_array($p['status'],['rented','sold'],true)?$p['status']:'unavailable'];}}
  reply(['items'=>$items,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/12)]);
 }
 if($method!=='POST')fail('Método no permitido.',405);
 $id=strvalInput($input,'id',24,24);
 if($action==='favorites-remove'){query('DELETE FROM property_favorites WHERE user_id=? AND property_id=?',[$uid,$id]);reply(['saved'=>false]);}
 if($action!=='favorites-save')fail('Operación no disponible.',405);
 $db->exec('BEGIN IMMEDIATE');
 try{
  $p=selectProperty($id);if(!$p||$p['status']!=='active'||$p['owner_status']!=='active'){$db->exec('ROLLBACK');fail('Esta propiedad ya no está disponible para guardar.',404);}
  $exists=query('SELECT 1 FROM property_favorites WHERE user_id=? AND property_id=?',[$uid,$id])->fetchColumn();
  if(!$exists&&(int)query('SELECT COUNT(*) FROM property_favorites WHERE user_id=?',[$uid])->fetchColumn()>=300){$db->exec('ROLLBACK');fail('Puedes guardar hasta 300 propiedades. Quita alguna para agregar otra.');}
  query('INSERT OR IGNORE INTO property_favorites(user_id,property_id,created) VALUES(?,?,?)',[$uid,$id,time()]);$db->exec('COMMIT');
 }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');throw $e;}
 reply(['saved'=>true]);
}
