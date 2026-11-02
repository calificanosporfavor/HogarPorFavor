<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}
const HOGAR_TERMS_VERSION='2026-11-01.1';
$db->exec('CREATE TABLE IF NOT EXISTS terms_acceptances(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id),purpose TEXT NOT NULL,property_id TEXT,version TEXT NOT NULL,document_sha256 TEXT NOT NULL,accepted_at INTEGER NOT NULL)');
function requireTerms(array $input): void {
 if(!in_array($input['terms']??null,[true,'1'],true))fail('Lee y acepta los términos y condiciones para continuar.');
 if(($input['terms_version']??null)!==HOGAR_TERMS_VERSION)fail('Los términos cambiaron. Actualiza la página, léelos y acepta la versión vigente.',409);
}
function recordTerms(int $userId,string $purpose,?string $propertyId=null): void {
 $hash=hash_file('sha256',__DIR__.'/terminos-2026-11-01.html');if(!$hash)throw new RuntimeException('Terms document unavailable');
 query('INSERT INTO terms_acceptances(user_id,purpose,property_id,version,document_sha256,accepted_at) VALUES(?,?,?,?,?,?)',[$userId,$purpose,$propertyId,HOGAR_TERMS_VERSION,$hash,time()]);
}
