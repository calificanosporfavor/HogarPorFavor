<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS hero_config(id INTEGER PRIMARY KEY CHECK(id=1),revision INTEGER NOT NULL DEFAULT 0);INSERT OR IGNORE INTO hero_config(id,revision) VALUES(1,0);CREATE TABLE IF NOT EXISTS hero_photos(id TEXT PRIMARY KEY,alt TEXT NOT NULL,position INTEGER NOT NULL)');
function heroItems(): array {return query('SELECT id,alt FROM hero_photos ORDER BY position,id')->fetchAll();}
function handleHero(string $action,string $method,array $input): void {
 if(!in_array($action,['hero','hero-photo','admin-hero','admin-hero-save'],true))return;
 global $db,$dataDir;
 if($action==='hero'&&$method==='GET')reply(['items'=>heroItems()]);
 if($action==='hero-photo'&&$method==='GET'){
  $id=$_GET['id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!query('SELECT 1 FROM hero_photos WHERE id=?',[$id])->fetchColumn())fail('Imagen no encontrada.',404);
  $path=$dataDir.'/hero/'.$id.'.jpg';if(!is_file($path))fail('Imagen no encontrada.',404);
  header('Content-Type: image/jpeg');header('Cache-Control: public, max-age=300');header('Content-Length: '.filesize($path));readfile($path);exit;
 }
 $actor=requireUser();if(!isSuperadmin($actor))fail('Acceso reservado al superadmin.',403);
 if($action==='admin-hero'&&$method==='GET')reply(['items'=>heroItems(),'revision'=>(int)query('SELECT revision FROM hero_config WHERE id=1')->fetchColumn()]);
 if($action!=='admin-hero-save'||$method!=='POST')fail('Operación no disponible.',405);
 limitRate('admin-hero-save',20,900);
 $revision=filter_var($input['revision']??null,FILTER_VALIDATE_INT);$manifest=json_decode(is_string($input['manifest']??null)?$input['manifest']:'',true);
 if($revision===false||!is_array($manifest)||!array_is_list($manifest)||count($manifest)>6)fail('Elige hasta 6 imágenes para tu portada.');
 $dir=$dataDir.'/hero';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Hero storage unavailable');
 $uploads=$_FILES['images']??null;$new=[];$items=[];$used=[];$old=[];
 try{
  foreach($manifest as $item){
   if(!is_array($item))throw new InvalidArgumentException('Revisa las imágenes seleccionadas.');
   $alt=$item['alt']??'';if(!is_string($alt)||mb_strlen(trim($alt))<3||mb_strlen($alt)>160)throw new InvalidArgumentException('Describe cada imagen en entre 3 y 160 caracteres.');
   if(isset($item['id'])){
    $id=$item['id'];if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||isset($used['id-'.$id])||!query('SELECT 1 FROM hero_photos WHERE id=?',[$id])->fetchColumn())throw new InvalidArgumentException('Una imagen cambió. Actualiza la portada.');$used['id-'.$id]=true;
   }else{
    $i=$item['upload']??null;if(!is_int($i)||$i<0||$i>5||isset($used['upload-'.$i])||!is_array($uploads['error']??null)||($uploads['error'][$i]??-1)!==UPLOAD_ERR_OK||($uploads['size'][$i]??PHP_INT_MAX)>6*1024*1024)throw new InvalidArgumentException('Cada imagen debe pesar hasta 6 MB.');$used['upload-'.$i]=true;
    $tmp=$uploads['tmp_name'][$i]??'';if(!is_string($tmp)||!is_uploaded_file($tmp))throw new InvalidArgumentException('Carga de imagen inválida.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);$size=@getimagesize($tmp);if(!in_array($mime,['image/jpeg','image/png','image/webp'],true)||!$size||$size[0]<320||$size[1]<200||$size[0]*$size[1]>16000000)throw new InvalidArgumentException('Usa JPG, PNG o WebP de al menos 320 × 200 px y hasta 16 megapíxeles.');
    $source=@imagecreatefromstring(file_get_contents($tmp));if(!$source)throw new InvalidArgumentException('No pudimos leer una imagen.');$scale=min(1,1920/max($size[0],$size[1]));$w=max(1,(int)round($size[0]*$scale));$h=max(1,(int)round($size[1]*$scale));$img=imagecreatetruecolor($w,$h);imagefill($img,0,0,imagecolorallocate($img,255,255,255));imagecopyresampled($img,$source,0,0,0,0,$w,$h,$size[0],$size[1]);imagedestroy($source);
    $id=bin2hex(random_bytes(16));$new[]=$id;if(!imagejpeg($img,$dir.'/'.$id.'.jpg',86))throw new RuntimeException('Cannot save hero image');imagedestroy($img);
   }
   $items[]=['id'=>$id,'alt'=>trim($alt)];
  }
  $db->exec('BEGIN IMMEDIATE');
  if((int)query('SELECT revision FROM hero_config WHERE id=1')->fetchColumn()!==$revision)throw new InvalidArgumentException('La portada cambió en otra ventana. Actualiza antes de guardar.');
  $old=query('SELECT id FROM hero_photos')->fetchAll(PDO::FETCH_COLUMN);query('DELETE FROM hero_photos');foreach($items as $pos=>$item)query('INSERT INTO hero_photos(id,alt,position) VALUES(?,?,?)',[$item['id'],$item['alt'],$pos]);query('UPDATE hero_config SET revision=revision+1 WHERE id=1');auditAdmin($actor,'hero_updated','portada');$db->exec('COMMIT');
 }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');foreach($new as $id)@unlink($dir.'/'.$id.'.jpg');if($e instanceof InvalidArgumentException)fail($e->getMessage(),409);throw $e;}
 $retained=array_column($items,'id');foreach(array_diff($old,$retained) as $id)@unlink($dir.'/'.$id.'.jpg');
 // Retry removal of unreferenced files left by an interrupted upload, outside the public directory.
 foreach(glob($dir.'/*.jpg')?:[] as $file){$id=basename($file,'.jpg');if(preg_match('/^[a-f0-9]{32}$/D',$id)&&filemtime($file)<time()-3600&&!query('SELECT 1 FROM hero_photos WHERE id=?',[$id])->fetchColumn())@unlink($file);}
 reply(['items'=>$items,'revision'=>$revision+1,'message'=>'Portada actualizada.']);
}
