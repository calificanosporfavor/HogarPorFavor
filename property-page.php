<?php
declare(strict_types=1);

// Public, server-rendered property pages let search engines and link previews
// read the same listing details visitors see in the application.
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');

function propertyPageNotFound(): never {
    http_response_code(404);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="es-MX"><meta charset="utf-8"><meta name="robots" content="noindex"><title>Publicación no disponible | Hogar Por Favor</title><main><h1>Esta publicación ya no está disponible.</h1><p><a href="/">Volver a Hogar Por Favor</a></p></main></html>';
    exit;
}
function propertyPageEscape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function propertyPageMetaText(string $value, int $length = 180): string {
    $value = trim((string)preg_replace('/\s+/u', ' ', strip_tags($value)));
    return mb_substr($value, 0, $length, 'UTF-8');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
$id = $_GET['id'] ?? '';
if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D', $id)) propertyPageNotFound();

$local = PHP_SAPI === 'cli-server';
$documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/\\');
$dataDir = $local ? dirname(__DIR__) . '/hogar-test-data' : dirname($documentRoot) . '/.hogar-private';
$realData = realpath($dataDir);
$realRoot = realpath($documentRoot);
if (!$realData || ($realRoot && str_starts_with($realData . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR))) propertyPageNotFound();
$database = $realData . '/hogar.sqlite';
if (!is_file($database)) propertyPageNotFound();

try {
    $db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $statement = $db->prepare('SELECT p.id,p.title,p.operation,p.type,p.price,p.state,p.city,p.neighborhood,p.description,p.contact_type,p.contact,p.bedrooms,p.bathrooms,p.area,p.updated,u.name AS owner_name FROM properties p JOIN users u ON u.id=p.user_id WHERE p.id=? AND p.status="active" AND u.status="active" AND u.email_verified_at IS NOT NULL');
    $statement->execute([$id]);
    $property = $statement->fetch();
    if (!$property) propertyPageNotFound();
    $photos = $db->prepare('SELECT id FROM photos WHERE property_id=? ORDER BY position LIMIT 8');
    $photos->execute([$id]);
    $photos = $photos->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $error) {
    error_log('Hogar property page: ' . $error->getMessage());
    propertyPageNotFound();
}

$locationParts = array_filter([$property['neighborhood'], $property['city'], $property['state']], static fn($value): bool => is_string($value) && trim($value) !== '');
$location = implode(', ', $locationParts);
$price = '$' . number_format((int)$property['price'], 0, '.', ',') . ' MXN' . ($property['operation'] === 'Renta' ? ' al mes' : '');
$title = propertyPageMetaText((string)$property['title'], 120);
$canonical = 'https://hogarporfavor.com/prop/' . rawurlencode($id);
$metaTitle = $title . ' | ' . $price . ' | Hogar Por Favor';
$metaDescription = propertyPageMetaText((string)$property['description'], 180);
if ($metaDescription === '') $metaDescription = propertyPageMetaText($property['type'] . ' en ' . $location . ' · ' . $price, 180);
$image = $photos ? 'https://hogarporfavor.com/api.php?action=photo&id=' . rawurlencode((string)$photos[0]) : 'https://hogarporfavor.com/hogar.jpg';
$contactType = (string)$property['contact_type'];
$contact = (string)$property['contact'];
$contactDigits = preg_replace('/\D+/', '', $contact) ?? '';
$contactHref = match ($contactType) {
    'WhatsApp' => $contactDigits !== '' ? 'https://wa.me/' . $contactDigits : '#',
    'Teléfono' => $contactDigits !== '' ? 'tel:+' . $contactDigits : '#',
    'Correo' => filter_var($contact, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $contact : '#',
    default => '#',
};
$contactLabel = $contactType === 'Correo' ? 'por correo' : 'por ' . strtolower($contactType);
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Offer',
    'name' => $title,
    'description' => $metaDescription,
    'url' => $canonical,
    'price' => (string)(int)$property['price'],
    'priceCurrency' => 'MXN',
    'availability' => 'https://schema.org/InStock',
    'businessFunction' => $property['operation'] === 'Renta' ? 'http://purl.org/goodrelations/v1#LeaseOut' : 'http://purl.org/goodrelations/v1#Sell',
    'itemOffered' => ['@type' => 'Place', 'name' => $title, 'address' => ['@type' => 'PostalAddress', 'addressLocality' => (string)$property['city'], 'addressRegion' => (string)$property['state'], 'addressCountry' => 'MX']],
];
if ($photos) $jsonLd['image'] = array_map(static fn($photo): string => 'https://hogarporfavor.com/api.php?action=photo&id=' . rawurlencode((string)$photo), $photos);
$extraSpecs = [];
if ((int)$property['bedrooms'] > 0) $extraSpecs[] = (int)$property['bedrooms'] . ' recámaras';
if ((int)$property['bathrooms'] > 0) $extraSpecs[] = (int)$property['bathrooms'] . ' baños';
if ((int)$property['area'] > 0) $extraSpecs[] = (int)$property['area'] . ' m²';
$photosMarkup = '';
foreach ($photos as $index => $photo) {
    $photoUrl = '/api.php?action=photo&amp;id=' . rawurlencode((string)$photo);
    $photosMarkup .= '<img src="' . $photoUrl . '" alt="Foto ' . ($index + 1) . ' de ' . propertyPageEscape($title) . '"' . ($index === 0 ? ' fetchpriority="high"' : ' loading="lazy"') . '>';
}
$jsonLdText = json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="es-MX">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= propertyPageEscape($metaTitle) ?></title>
  <meta name="description" content="<?= propertyPageEscape($metaDescription) ?>">
  <meta name="robots" content="index,follow,max-image-preview:large">
  <meta name="theme-color" content="#8DDA07">
  <link rel="icon" type="image/png" sizes="96x96" href="/favicon.png?v=3">
  <link rel="canonical" href="<?= propertyPageEscape($canonical) ?>">
  <meta property="og:type" content="article"><meta property="og:site_name" content="Hogar Por Favor">
  <meta property="og:title" content="<?= propertyPageEscape($metaTitle) ?>"><meta property="og:description" content="<?= propertyPageEscape($metaDescription) ?>">
  <meta property="og:url" content="<?= propertyPageEscape($canonical) ?>"><meta property="og:image" content="<?= propertyPageEscape($image) ?>"><meta property="og:image:alt" content="<?= propertyPageEscape('Propiedad: ' . $title) ?>">
  <meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="<?= propertyPageEscape($metaTitle) ?>"><meta name="twitter:description" content="<?= propertyPageEscape($metaDescription) ?>"><meta name="twitter:image" content="<?= propertyPageEscape($image) ?>">
  <link rel="stylesheet" href="/style.css?v=32">
  <style>
    .listing-page{width:min(1080px,calc(100% - 40px));margin:0 auto;padding:32px 0 72px}.listing-crumb{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:28px}.listing-crumb img{width:min(220px,45vw);height:auto}.listing-layout{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(280px,.75fr);gap:40px;align-items:start}.listing-gallery{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.listing-gallery img{width:100%;height:260px;object-fit:cover;border-radius:18px;background:#f5f5f2}.listing-gallery img:first-child{grid-column:1/-1;height:410px}.listing-info{position:sticky;top:24px}.listing-info h1{font-size:clamp(2rem,5vw,3.1rem);line-height:1.05;margin:14px 0}.listing-price{font-weight:800;font-size:1.65rem}.listing-location{color:#585b55;margin:10px 0 20px}.listing-specs{display:flex;flex-wrap:wrap;gap:8px;margin:20px 0}.listing-specs span{background:#f4f5ef;border-radius:99px;padding:9px 13px}.listing-description{white-space:pre-line;line-height:1.8;margin:26px 0}.listing-contact{margin-top:26px}.listing-contact small{display:block;margin-top:12px;overflow-wrap:anywhere;color:#575a54}.listing-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:18px}.listing-safety{margin-top:32px}.listing-footer{border-top:1px solid #e6e7e1;margin-top:56px;padding-top:22px;color:#5c5e58}.listing-empty{min-height:55vh;display:grid;align-content:center;gap:12px}@media(max-width:740px){.listing-page{width:min(100% - 28px,620px);padding-top:18px}.listing-layout{grid-template-columns:1fr;gap:22px}.listing-info{position:static}.listing-gallery{gap:8px}.listing-gallery img{height:150px;border-radius:13px}.listing-gallery img:first-child{height:min(68vw,400px)}}
  </style>
  <script type="application/ld+json"><?= $jsonLdText ?></script>
</head>
<body>
<main class="listing-page">
  <div class="listing-crumb"><a href="/" aria-label="Hogar Por Favor, inicio"><img src="/logo.png?v=2" width="220" height="50" alt="Hogar Por Favor"></a><a class="plain" href="/?prop=<?= propertyPageEscape($id) ?>">Explorar más propiedades</a></div>
  <article class="listing-layout">
    <div>
      <?php if ($photosMarkup !== ''): ?><div class="listing-gallery" aria-label="Fotos de la propiedad"><?= $photosMarkup ?></div><?php else: ?><img src="/hogar.jpg" alt="Imagen ilustrativa de Hogar Por Favor" style="width:100%;border-radius:18px"><?php endif; ?>
      <section class="listing-description"><h2>Acerca de esta propiedad</h2><p><?= nl2br(propertyPageEscape((string)$property['description'])) ?></p></section>
      <aside class="care-note listing-safety"><strong>Revisa todo antes de decidir.</strong><p>Habla con quien publica, visita el lugar y comprueba su identidad y autorización para ofrecerlo. Revisa precio, condiciones y documentos antes de entregar dinero, compartir información sensible o firmar un contrato.</p><p class="care-caption">Hogar Por Favor facilita el contacto; no verifica este anuncio ni interviene en pagos o contratos. Te corresponde validar la información y los acuerdos, conforme a las responsabilidades que establece la ley.</p></aside>
    </div>
    <section class="listing-info" aria-labelledby="property-title">
      <span class="eyebrow"><?= propertyPageEscape((string)$property['type']) ?> · <?= propertyPageEscape((string)$property['operation']) ?></span>
      <h1 id="property-title"><?= propertyPageEscape($title) ?></h1>
      <p class="listing-price"><?= propertyPageEscape($price) ?></p>
      <p class="listing-location"><?= propertyPageEscape($location) ?></p>
      <?php if ($extraSpecs): ?><div class="listing-specs"><?php foreach ($extraSpecs as $spec): ?><span><?= propertyPageEscape($spec) ?></span><?php endforeach; ?></div><?php endif; ?>
      <p>Publicado por <?= propertyPageEscape((string)$property['owner_name']) ?></p>
      <div class="listing-contact"><a class="button full" href="<?= propertyPageEscape($contactHref) ?>"<?= $contactType === 'WhatsApp' ? ' target="_blank" rel="noopener noreferrer"' : '' ?>>Contactar <?= propertyPageEscape($contactLabel) ?></a><small>Contacto compartido por quien publicó: <?= propertyPageEscape($contact) ?></small></div>
      <div class="listing-actions"><a class="plain" href="/?prop=<?= propertyPageEscape($id) ?>">Ver ficha y opciones de la comunidad</a><a class="plain" href="/">Buscar otra propiedad</a></div>
    </section>
  </article>
  <footer class="listing-footer">Hogar Por Favor · Imperio Porfavor · <a href="/terminos.html">Términos</a> · <a href="/privacidad.html">Privacidad</a></footer>
</main>
</body>
</html>
