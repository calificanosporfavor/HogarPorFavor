<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
$db->exec('CREATE TABLE IF NOT EXISTS hero_config(id INTEGER PRIMARY KEY CHECK(id=1),revision INTEGER NOT NULL DEFAULT 0);INSERT OR IGNORE INTO hero_config(id,revision) VALUES(1,0);CREATE TABLE IF NOT EXISTS hero_photos(id TEXT PRIMARY KEY,alt TEXT NOT NULL,position INTEGER NOT NULL)');

foreach(['hero_config'=>['content'=>'TEXT'],'hero_photos'=>['title'=>"TEXT NOT NULL DEFAULT ''",'url'=>"TEXT NOT NULL DEFAULT ''"]] as $table=>$columns){$known=array_column(query('PRAGMA table_info('.$table.')')->fetchAll(),'name');foreach($columns as $name=>$type)if(!in_array($name,$known,true))$db->exec('ALTER TABLE '.$table.' ADD COLUMN '.$name.' '.$type);}
function heroDefaults(): array {return ['eyebrow'=>'BIENVENIDO A TU PRÓXIMO COMIENZO','heading_line1'=>'Un lugar para vivir.','heading_line2'=>'Un hogar para','heading_highlight'=>'ser tú.','description'=>'Encuentra propiedades en renta y venta, habla directamente con quien las ofrece y publica tu espacio sin costo.','cta_label'=>'Encuentra tu lugar','cta_url'=>'#explorar','note_line1'=>'Tu búsqueda, a tu ritmo.','note_line2'=>'Tu próximo hogar, más cerca.','image_label'=>'AQUÍ EMPIEZA ALGO BUENO','image_note'=>'Más que cuatro paredes.','image_title'=>'Tu siguiente capítulo.'];}
function heroContent(): array {$raw=query('SELECT content FROM hero_config WHERE id=1')->fetchColumn();$values=is_string($raw)?json_decode($raw,true):[];return array_replace(heroDefaults(),is_array($values)?array_intersect_key($values,heroDefaults()):[]);}
function heroSafeUrl(string $url): bool {
 if($url==='')return true;
 if(preg_match('/[\x00-\x20\x7f]/',$url)||str_contains($url,chr(92)))return false;
 if(str_starts_with($url,'#'))return strlen($url)>1;
 if(str_starts_with($url,'/'))return !str_starts_with($url,'//');
 $parts=parse_url($url);return filter_var($url,FILTER_VALIDATE_URL)!==false&&is_array($parts)&&strtolower($parts['scheme']??'')==='https'&&!empty($parts['host'])&&!isset($parts['user'])&&!isset($parts['pass']);
}
function heroValidateContent(array $input): array {
 $defaults=heroDefaults();$out=[];foreach($defaults as $key=>$default){$value=$input[$key]??null;$max=$key==='description'?400:($key==='cta_url'?1000:($key==='heading_line1'||$key==='heading_line2'||$key==='heading_highlight'?65:120));if(!is_string($value)||mb_strlen(trim($value))<1||mb_strlen($value)>$max)throw new InvalidArgumentException('Revisa el texto de portada: '.$key.'.');$out[$key]=trim($value);}
 if(!heroSafeUrl($out['cta_url']))throw new InvalidArgumentException('Usa un enlace HTTPS, una ruta que empiece con / o una sección como #explorar.');return $out;
}
function heroItems(): array {return query('SELECT id,alt,title,url FROM hero_photos ORDER BY position,id')->fetchAll();}
function handleHero(string $action,string $method,array $input): void {
 if(!in_array($action,['hero','hero-photo','admin-hero','admin-hero-save'],true))return;
 global $db,$dataDir;
 if($action==='hero'&&$method==='GET')reply(['items'=>heroItems(),'content'=>heroContent()]);
 if($action==='hero-photo'&&$method==='GET'){
  $id=$_GET['id']??'';if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!query('SELECT 1 FROM hero_photos WHERE id=?',[$id])->fetchColumn())fail('Imagen no encontrada.',404);
  $path=$dataDir.'/hero/'.$id.'.jpg';if(!is_file($path))fail('Imagen no encontrada.',404);
  header('Content-Type: image/jpeg');header('Cache-Control: public, max-age=300');header('Content-Length: '.filesize($path));readfile($path);exit;
 }
 $actor=requireUser();if(!isSuperadmin($actor))fail('Acceso reservado al superadmin.',403);
 if($action==='admin-hero'&&$method==='GET')reply(['items'=>heroItems(),'content'=>heroContent(),'revision'=>(int)query('SELECT revision FROM hero_config WHERE id=1')->fetchColumn()]);
 if($action!=='admin-hero-save'||$method!=='POST')fail('Operación no disponible.',405);
 limitRate('admin-hero-save',20,900);
 $revision=filter_var($input['revision']??null,FILTER_VALIDATE_INT);$manifest=json_decode(is_string($input['manifest']??null)?$input['manifest']:'',true);
 if($revision===false||!is_array($manifest)||!array_is_list($manifest)||count($manifest)>6)fail('Elige hasta 6 imágenes para tu portada.');
 $dir=$dataDir.'/hero';if(!is_dir($dir)&&!mkdir($dir,0700,true))throw new RuntimeException('Hero storage unavailable');
 $uploads=$_FILES['images']??null;$new=[];$items=[];$used=[];$old=[];
 try{
  $content=heroContent();if(array_key_exists('content',$input)){$raw=is_string($input['content'])?json_decode($input['content'],true):null;if(!is_array($raw))throw new InvalidArgumentException('Revisa los textos de la portada.');$content=heroValidateContent($raw);}
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
   $prior=isset($item['id'])?query('SELECT title,url FROM hero_photos WHERE id=?',[$id])->fetch():[];
   $title=$item['title']??($prior['title']??'');$url=$item['url']??($prior['url']??'');if(!is_string($title)||mb_strlen($title)>100||!is_string($url)||strlen($url)>1000||!heroSafeUrl(trim($url)))throw new InvalidArgumentException('Revisa el título y el enlace de cada imagen. Solo se permiten enlaces HTTPS o del sitio.');
   $items[]=['id'=>$id,'alt'=>trim($alt),'title'=>trim($title),'url'=>trim($url)];
  }
  $db->exec('BEGIN IMMEDIATE');
  if((int)query('SELECT revision FROM hero_config WHERE id=1')->fetchColumn()!==$revision)throw new InvalidArgumentException('La portada cambió en otra ventana. Actualiza antes de guardar.');
  $old=query('SELECT id FROM hero_photos')->fetchAll(PDO::FETCH_COLUMN);query('DELETE FROM hero_photos');foreach($items as $pos=>$item)query('INSERT INTO hero_photos(id,alt,title,url,position) VALUES(?,?,?,?,?)',[$item['id'],$item['alt'],$item['title'],$item['url'],$pos]);query('UPDATE hero_config SET revision=revision+1,content=? WHERE id=1',[json_encode($content,JSON_UNESCAPED_UNICODE)]);auditAdmin($actor,'hero_updated','portada');$db->exec('COMMIT');
 }catch(Throwable $e){if($db->inTransaction())$db->exec('ROLLBACK');foreach($new as $id)@unlink($dir.'/'.$id.'.jpg');if($e instanceof InvalidArgumentException)fail($e->getMessage(),409);throw $e;}
 $retained=array_column($items,'id');foreach(array_diff($old,$retained) as $id)@unlink($dir.'/'.$id.'.jpg');
 // Retry removal of unreferenced files left by an interrupted upload, outside the public directory.
 foreach(glob($dir.'/*.jpg')?:[] as $file){$id=basename($file,'.jpg');if(preg_match('/^[a-f0-9]{32}$/D',$id)&&filemtime($file)<time()-3600&&!query('SELECT 1 FROM hero_photos WHERE id=?',[$id])->fetchColumn())@unlink($file);}
 reply(['items'=>$items,'content'=>$content,'revision'=>$revision+1,'message'=>'Portada actualizada.']);
}
