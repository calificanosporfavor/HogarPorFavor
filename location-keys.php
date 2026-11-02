<?php
declare(strict_types=1);
function location_key(?string $value): string {
 $value=mb_strtolower(trim($value??''),'UTF-8');
 $value=strtr($value,['á'=>'a','à'=>'a','ä'=>'a','â'=>'a','é'=>'e','è'=>'e','ë'=>'e','ê'=>'e','í'=>'i','ì'=>'i','ï'=>'i','î'=>'i','ó'=>'o','ò'=>'o','ö'=>'o','ô'=>'o','ú'=>'u','ù'=>'u','ü'=>'u','û'=>'u','ñ'=>'n']);
 return preg_replace('/\s+/u',' ',$value);
}
function state_key(?string $value): string {
 $key=location_key($value);return ['estado de mexico'=>'mexico','cdmx'=>'ciudad de mexico','distrito federal'=>'ciudad de mexico','coahuila'=>'coahuila de zaragoza','michoacan'=>'michoacan de ocampo','veracruz'=>'veracruz de ignacio de la llave'][$key]??$key;
}
