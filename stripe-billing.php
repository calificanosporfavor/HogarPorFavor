<?php
declare(strict_types=1);
if(!defined('HOGAR_INTERNAL')){http_response_code(404);exit;}

const HOGAR_BILLING_PLANS = [
 'basic'=>['name'=>'Gratis','limit'=>3,'amount'=>0],
 'advisor'=>['name'=>'Asesor','limit'=>10,'amount'=>99],
 'advisor_plus'=>['name'=>'Asesor Plus','limit'=>25,'amount'=>200],
 'advisor_unlimited'=>['name'=>'Ilimitado','limit'=>PHP_INT_MAX,'amount'=>300,'features_per_month'=>4],
];
$billingColumns=['billing_plan TEXT NOT NULL DEFAULT "basic"','billing_status TEXT NOT NULL DEFAULT "none"','billing_customer_id TEXT','billing_subscription_id TEXT','billing_current_period_end INTEGER NOT NULL DEFAULT 0','billing_cancel_at_period_end INTEGER NOT NULL DEFAULT 0','billing_event_created INTEGER NOT NULL DEFAULT 0'];
foreach($billingColumns as $column){try{$db->exec('ALTER TABLE users ADD COLUMN '.$column);}catch(PDOException $e){if(!str_contains(strtolower($e->getMessage()),'duplicate column'))throw $e;}}

$db->exec('CREATE UNIQUE INDEX IF NOT EXISTS users_billing_customer ON users(billing_customer_id) WHERE billing_customer_id IS NOT NULL; CREATE UNIQUE INDEX IF NOT EXISTS users_billing_subscription ON users(billing_subscription_id) WHERE billing_subscription_id IS NOT NULL;');
$db->exec('CREATE TABLE IF NOT EXISTS billing_acceptances(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,plan TEXT NOT NULL,version TEXT NOT NULL,document_sha256 TEXT NOT NULL,accepted_at INTEGER NOT NULL);CREATE TABLE IF NOT EXISTS billing_feature_claims(id INTEGER PRIMARY KEY,user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,property_id TEXT NOT NULL REFERENCES properties(id) ON DELETE CASCADE,month_key TEXT NOT NULL,starts_at INTEGER NOT NULL,expires_at INTEGER NOT NULL,UNIQUE(user_id,property_id,month_key));CREATE INDEX IF NOT EXISTS billing_feature_month ON billing_feature_claims(user_id,month_key);CREATE INDEX IF NOT EXISTS billing_feature_active ON billing_feature_claims(property_id,expires_at);');
const HOGAR_BILLING_TERMS_VERSION='2026-11-04.2';
function recordBillingTerms(int $userId,string $plan): void {$hash=hash_file('sha256',__DIR__.'/suscripciones.html');if(!$hash)throw new RuntimeException('Subscription terms unavailable');query('INSERT INTO billing_acceptances(user_id,plan,version,document_sha256,accepted_at) VALUES(?,?,?,?,?)',[$userId,$plan,HOGAR_BILLING_TERMS_VERSION,$hash,time()]);}

function stripeBillingConfig(): array {
 global $dataDir;
 $path=$dataDir.'/stripe-config.php';
 $file=is_file($path)?require $path:[];
 if(!is_array($file))$file=[];
 $mode=(string)($file['mode']??'test');
 $priceIds=$mode==='live'?($file['price_ids_live']??[]):($file['price_ids_test']??$file['price_ids']??[]);
 return [
  'secret_key'=>(string)($file['secret_key']??getenv('HOGAR_STRIPE_SECRET_KEY')?:''),
  'webhook_secret'=>(string)($file['webhook_secret']??getenv('HOGAR_STRIPE_WEBHOOK_SECRET')?:''),
  'mode'=>$mode,
  'price_ids'=>is_array($priceIds)?$priceIds:[],
  'billing_enabled'=>($file['billing_enabled']??false)===true,
 ];
}
function stripeModeMatchesKey(array $config): bool {
 $mode=(string)($config['mode']??'');$key=(string)($config['secret_key']??'');
 return ($mode==='test'&&preg_match('/^sk_test_[A-Za-z0-9_]+$/',$key)===1)||($mode==='live'&&preg_match('/^sk_live_[A-Za-z0-9_]+$/',$key)===1);
}
function billingEffectivePlan(?array $u): string {
 if(!$u)return 'basic';
 if(isSuperadmin($u))return 'superadmin';
 $row=query('SELECT billing_plan,billing_status,billing_current_period_end FROM users WHERE id=?',[(int)$u['id']])->fetch();
 if(!$row||!in_array($row['billing_plan'],['advisor','advisor_plus','advisor_unlimited'],true))return 'basic';
 $status=(string)$row['billing_status'];$end=(int)$row['billing_current_period_end'];
 $valid=in_array($status,['active','trialing'],true)&&$end>time();
 if($status==='past_due'&&$end+7*86400>time())$valid=true;
 return $valid?$row['billing_plan']:'basic';
}
function billingPropertyLimit(?array $u): int {
 $plan=billingEffectivePlan($u);
 if($plan==='superadmin')return HOGAR_BILLING_PLANS['basic']['limit'];
 return HOGAR_BILLING_PLANS[$plan]['limit']??3;
}
function billingLimitMessage(?array $u): string {
 $plan=billingEffectivePlan($u);$limit=billingPropertyLimit($u);
 if(in_array($plan,['basic','superadmin'],true))return 'Tu cuenta gratis incluye hasta 3 publicaciones. Puedes ampliar a 10 por $99 al mes, hasta 25 con estadísticas por $200 o publicar sin límite por $300 al mes con 4 espacios destacados de 7 días cada mes.';
 return 'Tu plan incluye hasta '.$limit.' propiedades activas, pausadas o en borrador. Libera un espacio marcando una como rentada o vendida, o revisa las opciones de tu plan en Mi cuenta.';
}
function billingHasAnalytics(?array $u): bool { return in_array(billingEffectivePlan($u),['advisor_plus','advisor_unlimited','superadmin'],true); }
function billingFeatureMonth(): string {return (new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City')))->format('Y-m');}
function billingHttpRequest(string $url,array $form,string $secret,string $method='POST'): array {
 if($method==='GET'&&$form)$url.='?'.http_build_query($form);
 $headers="Authorization: Basic ".base64_encode($secret.':')."\r\nAccept: application/json\r\n";if($method!=='GET')$headers.="Content-Type: application/x-www-form-urlencoded\r\n";
 $context=stream_context_create(['http'=>['method'=>$method,'header'=>$headers,'content'=>$method==='GET'?'':http_build_query($form),'timeout'=>15,'ignore_errors'=>true]]);
 $body=@file_get_contents($url,false,$context);$status=0;foreach($http_response_header??[] as $header)if(preg_match('/^HTTP\/\S+\s+(\d{3})/',$header,$m))$status=(int)$m[1];
 $data=is_string($body)?json_decode($body,true):null;if($status<200||$status>=300||!is_array($data))throw new RuntimeException('Stripe no pudo completar el paso de facturación (HTTP '.$status.').');return $data;
}
function handleStripeBilling(string $action,string $method,array $input): void {
 if($action==='billing-status'&&$method==='GET'){
  $u=requireUser();$plan=billingEffectivePlan($u);$row=query('SELECT billing_status,billing_current_period_end,billing_cancel_at_period_end,billing_customer_id FROM users WHERE id=?',[$u['id']])->fetch();
  $count=(int)query('SELECT COUNT(*) FROM properties WHERE user_id=? AND status IN ("active","paused","draft")',[$u['id']])->fetchColumn();$featureUsed=(int)query('SELECT COUNT(*) FROM billing_feature_claims WHERE user_id=? AND month_key=?',[$u['id'],billingFeatureMonth()])->fetchColumn();$config=stripeBillingConfig();$pricesReady=true;foreach(['advisor','advisor_plus','advisor_unlimited'] as $pricePlan)$pricesReady=$pricesReady&&preg_match('/^price_[A-Za-z0-9]+$/',(string)($config['price_ids'][$pricePlan]??''))===1;
  $webhookReady=preg_match('/^whsec_[A-Za-z0-9_]+$/',(string)($config['webhook_secret']??''))===1;
  reply(['plan'=>$plan,'name'=>$plan==='superadmin'?'Superadmin':(HOGAR_BILLING_PLANS[$plan]['name']??'Gratis'),'limit'=>$plan==='advisor_unlimited'?null:billingPropertyLimit($u),'occupied'=>$count,'feature_used'=>$featureUsed,'feature_remaining'=>$plan==='advisor_unlimited'?max(0,4-$featureUsed):0,'status'=>$row['billing_status']??'none','period_end'=>(int)($row['billing_current_period_end']??0),'cancel_at_period_end'=>(bool)($row['billing_cancel_at_period_end']??false),'manage_available'=>!empty($row['billing_customer_id'])&&$config['billing_enabled']&&stripeModeMatchesKey($config)&&$webhookReady,'checkout_available'=>$config['billing_enabled']&&stripeModeMatchesKey($config)&&$webhookReady&&$pricesReady&&$u['email_verified']]);
 }
 if($action==='billing-checkout'&&$method==='POST'){
  $u=requireUser();requireVerified($u);if(!hash_equals($_SESSION['csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))fail('Actualiza la página e inténtalo de nuevo.',403);
  $plan=choice($input,'plan',['advisor','advisor_plus','advisor_unlimited']);$config=stripeBillingConfig();
  if(($input['billing_terms']??null)!==true||($input['billing_terms_version']??'')!==HOGAR_BILLING_TERMS_VERSION)fail('Lee y acepta las condiciones del plan para continuar.',422);
  if(!$config['billing_enabled']||!stripeModeMatchesKey($config)||!preg_match('/^whsec_[A-Za-z0-9_]+$/',(string)$config['webhook_secret']))fail('Los planes de pago todavía están en preparación. Tu cuenta y publicaciones gratis no cambian.',503);
  $price=(string)($config['price_ids'][$plan]??'');if(!preg_match('/^price_[A-Za-z0-9]+$/',$price))fail('Este plan aún no está listo para contratarse. Puedes seguir publicando gratis.',503);
  $stripePrice=billingHttpRequest('https://api.stripe.com/v1/prices/'.rawurlencode($price),[],$config['secret_key'],'GET');$expectedAmount=HOGAR_BILLING_PLANS[$plan]['amount']*100;
  if(empty($stripePrice['active'])||strtolower((string)($stripePrice['currency']??''))!=='mxn'||(int)($stripePrice['unit_amount']??0)!==$expectedAmount||($stripePrice['recurring']['interval']??'')!=='month'||(int)($stripePrice['recurring']['interval_count']??0)!==1)fail('La configuración de precio de este plan necesita revisión. No se realizó ningún cobro.',503);
  $existing=query('SELECT billing_customer_id,billing_subscription_id,billing_status,billing_current_period_end FROM users WHERE id=?',[$u['id']])->fetch();
  if(in_array($existing['billing_status'],['active','trialing','past_due'],true)&&(int)$existing['billing_current_period_end']>time())fail('Ya tienes un plan activo. Adminístralo desde Mi cuenta.',409);
  limitRate('billing-checkout',5,3600);$base='https://hogarporfavor.com';
  $form=['mode'=>'subscription','success_url'=>$base.'/?billing=success&session_id={CHECKOUT_SESSION_ID}','cancel_url'=>$base.'/?billing=cancel','client_reference_id'=>(string)$u['id'],'line_items[0][price]'=>$price,'line_items[0][quantity]'=>'1','metadata[user_id]'=>(string)$u['id'],'metadata[plan]'=>$plan,'subscription_data[metadata][user_id]'=>(string)$u['id'],'subscription_data[metadata][plan]'=>$plan,'allow_promotion_codes'=>'false'];
  if(!empty($existing['billing_customer_id']))$form['customer']=$existing['billing_customer_id'];else $form['customer_email']=$u['email'];
  $session=billingHttpRequest('https://api.stripe.com/v1/checkout/sessions',$form,$config['secret_key']);
  if(empty($session['url'])||!str_starts_with((string)$session['url'],'https://checkout.stripe.com/'))fail('Stripe no devolvió una página segura de pago.',502);
  recordBillingTerms((int)$u['id'],$plan);
  reply(['url'=>$session['url']]);
 }
 if($action==='billing-feature'&&$method==='POST'){
  $u=requireUser();requireVerified($u);if(!hash_equals($_SESSION['csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))fail('Actualiza la página e inténtalo de nuevo.',403);if(billingEffectivePlan($u)!=='advisor_unlimited')fail('Las publicaciones destacadas están incluidas en el plan Ilimitado.',403);
  $id=strvalInput($input,'id',24,24);if(!preg_match('/^[a-f0-9]{24}$/D',$id))fail('Anuncio no disponible.',404);limitRate('billing-feature',20,3600);$db->exec('BEGIN IMMEDIATE');
  try{$p=query('SELECT id FROM properties WHERE id=? AND user_id=? AND status="active"',[$id,$u['id']])->fetch();if(!$p){$db->rollBack();fail('Solo puedes destacar una propiedad activa que te pertenezca.',409);}$now=time();if((int)query('SELECT COALESCE(MAX(expires_at),0) FROM billing_feature_claims WHERE property_id=?',[$id])->fetchColumn()>$now){$db->rollBack();fail('Esta propiedad ya está destacada.',409);}$month=billingFeatureMonth();$used=(int)query('SELECT COUNT(*) FROM billing_feature_claims WHERE user_id=? AND month_key=?',[$u['id'],$month])->fetchColumn();if($used>=4){$db->rollBack();fail('Ya utilizaste los 4 destacados de este mes. Se renuevan el próximo mes.',409);}query('INSERT INTO billing_feature_claims(user_id,property_id,month_key,starts_at,expires_at) VALUES(?,?,?,?,?)',[$u['id'],$id,$month,$now,$now+7*86400]);$db->commit();reply(['ok'=>true,'expires_at'=>$now+7*86400,'feature_remaining'=>3-$used]);}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
 }
 if($action==='billing-portal'&&$method==='POST'){
  $u=requireUser();if(!hash_equals($_SESSION['csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??'')))fail('Actualiza la página e inténtalo de nuevo.',403);$config=stripeBillingConfig();$customer=(string)query('SELECT billing_customer_id FROM users WHERE id=?',[$u['id']])->fetchColumn();
  if(!$config['billing_enabled']||!stripeModeMatchesKey($config)||$customer==='')fail('La administración de pagos todavía no está configurada.',503);
  $session=billingHttpRequest('https://api.stripe.com/v1/billing_portal/sessions',['customer'=>$customer,'return_url'=>'https://hogarporfavor.com/'],$config['secret_key']);if(empty($session['url'])||!str_starts_with((string)$session['url'],'https://billing.stripe.com/'))fail('Stripe no devolvió una página segura de facturación.',502);reply(['url'=>$session['url']]);
 }
}
