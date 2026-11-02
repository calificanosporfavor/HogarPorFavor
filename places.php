<?php
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
function placeReply(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
try {
 if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')placeReply(['error'=>'Método no permitido.'],405);
 $state=$_GET['state']??'';$city=$_GET['city']??'';
 if(!is_string($state)||!is_string($city)||($state!==''&&!preg_match('/^[0-9]{2}$/D',$state))||($city!==''&&!preg_match('/^[0-9]{3}$/D',$city)))placeReply(['error'=>'Ubicación inválida.'],400);
 $raw=gzdecode(file_get_contents(__DIR__.'/places-mx.json.gz'));$catalog=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
 if($state==='')placeReply(['level'=>'state','items'=>array_map(fn($s)=>['id'=>$s['id'],'name'=>$s['name']],$catalog['states']),'source'=>$catalog['source']]);
 $selected=null;foreach($catalog['states'] as $s)if($s['id']===$state){$selected=$s;break;}
 if(!$selected)placeReply(['error'=>'Estado no encontrado.'],404);
 if($city==='')placeReply(['level'=>'city','items'=>array_map(fn($c)=>['id'=>$c['id'],'name'=>$c['name']],$selected['cities'])]);
 foreach($selected['cities'] as $c)if($c['id']===$city)placeReply(['level'=>'neighborhood','items'=>array_map(fn($name)=>['name'=>$name],$c['colonies'])]);
 placeReply(['error'=>'Municipio no encontrado.'],404);
}catch(Throwable $e){header('Cache-Control: no-store');error_log('Hogar places: '.$e->getMessage());placeReply(['error'=>'No pudimos cargar las sugerencias. Puedes escribir la ubicación.'],503);}
