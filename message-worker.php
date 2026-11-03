<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
ini_set('display_errors','0');umask(0077);
try{
 $options=getopt('',['root:','test']);$root=rtrim((string)($options['root']??''),'/\\');
 if(!$root||realpath($root)!==realpath(__DIR__))throw new RuntimeException('Invalid website root');
 $local=array_key_exists('test',$options);if(!$local&&!function_exists('mail'))throw new RuntimeException('Mail service unavailable');$dataDir=$local?dirname($root).'/hogar-test-data':dirname($root).'/.hogar-private';
 $realRoot=realpath($root);$realData=realpath($dataDir);
 if(!$realData||str_starts_with($realData.DIRECTORY_SEPARATOR,$realRoot.DIRECTORY_SEPARATOR)||!is_file($dataDir.'/hogar.sqlite'))throw new RuntimeException('Private database unavailable');
 $lock=fopen($dataDir.'/message-worker.lock','c');if(!$lock)throw new RuntimeException('Worker lock unavailable');if(!flock($lock,LOCK_EX|LOCK_NB)){echo "Already running\n";exit(0);}
 $db=new PDO('sqlite:'.$dataDir.'/hogar.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec('PRAGMA foreign_keys=ON;PRAGMA busy_timeout=5000;');
 function query(string $sql,array $params=[]): PDOStatement {global $db;$s=$db->prepare($sql);$s->execute($params);return $s;}
 define('HOGAR_INTERNAL',true);require __DIR__.'/auth-mail.php';require __DIR__.'/security-mail.php';require __DIR__.'/message-mail.php';require __DIR__.'/property-availability.php';
 flushSecurityNotices();$stats=flushCommunityMail();$stats['properties']=flushPropertyReminders();echo 'Message worker OK: '.json_encode($stats)."\n";flock($lock,LOCK_UN);fclose($lock);
}catch(Throwable $e){error_log('Hogar message worker: '.$e->getMessage());fwrite(STDERR,"Message worker failed\n");exit(1);}
