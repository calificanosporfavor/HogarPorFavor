<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS search_mail_history(id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,search_id TEXT NOT NULL REFERENCES saved_searches(id) ON DELETE CASCADE,sent_at INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS search_history_owner_date ON search_mail_history(search_id,sent_at,id);
CREATE TABLE IF NOT EXISTS search_mail_history_items(history_id INTEGER NOT NULL REFERENCES search_mail_history(id) ON DELETE CASCADE,property_id TEXT REFERENCES properties(id) ON DELETE SET NULL,property_ref TEXT NOT NULL,position INTEGER NOT NULL,PRIMARY KEY(history_id,property_ref));');
function recordSearchHistory(int $uid,int $sentAt,array $eligible,array $deliveredIds): void {
 global $db;$selected=array_fill_keys($deliveredIds,true);$groups=[];foreach($eligible as $p)if(isset($selected[$p['property_id']]))$groups[$p['search_id']][$p['property_id']]=true;
 foreach($groups as $searchId=>$properties){query('INSERT INTO search_mail_history(user_id,search_id,sent_at) VALUES(?,?,?)',[$uid,$searchId,$sentAt]);$historyId=(int)$db->lastInsertId();$position=0;foreach(array_keys($properties) as $id)query('INSERT INTO search_mail_history_items(history_id,property_id,property_ref,position) VALUES(?,?,?,?)',[$historyId,$id,$id,$position++]);}
}
function handleSearchHistory(string $method): void {
 $u=requireUser();if($method!=='GET')fail('Método no permitido.',405);$id=$_GET['id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{24}$/D',$id))fail('Búsqueda no disponible.',404);
 $search=query('SELECT id,name FROM saved_searches WHERE id=? AND user_id=?',[$id,$u['id']])->fetch();if(!$search)fail('Búsqueda no disponible.',404);
 $filters=[];foreach(['from','to','q'] as $key){$value=$_GET[$key]??'';if(!is_string($value))fail('Filtros no válidos.',422);$filters[$key]=trim($value);}
 if(mb_strlen($filters['q'])>100)fail('La búsqueda admite hasta 100 caracteres.',422);
 $where='h.search_id=? AND h.user_id=?';$params=[$id,$u['id']];$tz=new DateTimeZone('America/Mexico_City');
 foreach(['from','to'] as $key)if($filters[$key]!==''){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$filters[$key],$tz);if(!$date||$date->format('Y-m-d')!==$filters[$key])fail('Escribe una fecha válida.',422);$where.=$key==='from'?' AND h.sent_at>=?':' AND h.sent_at<?';$params[]=$key==='from'?$date->getTimestamp():$date->modify('+1 day')->getTimestamp();}
 if($filters['from']!==''&&$filters['to']!==''&&$filters['from']>$filters['to'])fail('La fecha inicial debe ser anterior o igual a la final.',422);
 if($filters['q']!==''){global $db;$db->sqliteCreateFunction('history_key','location_key',1);$where.=" AND EXISTS(SELECT 1 FROM search_mail_history_items x LEFT JOIN properties p ON p.id=x.property_id LEFT JOIN users o ON o.id=p.user_id WHERE x.history_id=h.id AND (instr(substr(x.property_ref,1,8),?)>0 OR (p.status='active' AND o.status='active' AND instr(history_key(p.title||' '||p.neighborhood||' '||p.city||' '||p.state),?)>0)))";$params[]=location_key($filters['q']);$params[]=location_key($filters['q']);}
 $page=max(1,min(10000,(int)(filter_var($_GET['page']??1,FILTER_VALIDATE_INT)?:1)));$total=(int)query('SELECT COUNT(*) FROM search_mail_history h WHERE '.$where,$params)->fetchColumn();
 $rows=query('SELECT h.id,h.sent_at FROM search_mail_history h WHERE '.$where.' ORDER BY h.sent_at DESC,h.id DESC LIMIT 10 OFFSET '.(($page-1)*10),$params)->fetchAll();$items=[];
 foreach($rows as $row){$properties=[];foreach(query('SELECT hi.property_ref,p.id,p.title,p.operation,p.state,p.city,p.neighborhood,p.status,u.status AS owner_status FROM search_mail_history_items hi LEFT JOIN properties p ON p.id=hi.property_id LEFT JOIN users u ON u.id=p.user_id WHERE hi.history_id=? ORDER BY hi.position',[$row['id']])->fetchAll() as $p){$available=$p['id']!==null&&$p['status']==='active'&&$p['owner_status']==='active';$item=['reference'=>substr($p['property_ref'],0,8),'available'=>$available];if($available)$item+=['id'=>$p['id'],'title'=>$p['title'],'operation'=>$p['operation'],'location'=>implode(', ',array_filter([$p['neighborhood'],$p['city'],$p['state']]))];else $item['status']=$p['owner_status']==='active'&&in_array($p['status'],['rented','sold'],true)?$p['status']:'unavailable';$properties[]=$item;}$items[]=['id'=>(int)$row['id'],'sent_at'=>(int)$row['sent_at'],'properties'=>$properties];}
 reply(['search'=>$search,'items'=>$items,'total'=>$total,'page'=>$page,'pages'=>(int)ceil($total/10)]);
}
