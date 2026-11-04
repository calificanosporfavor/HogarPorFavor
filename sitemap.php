<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=300, stale-while-revalidate=900');
header('X-Content-Type-Options: nosniff');

function sitemapEscape(string $value): string {
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}
$urls = [['loc' => 'https://hogarporfavor.com/', 'lastmod' => null, 'priority' => '1.0'], ['loc' => 'https://hogarporfavor.com/planes.html', 'lastmod' => null, 'priority' => '0.8']];
$documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__), '/\\');
$dataDir = PHP_SAPI === 'cli-server' ? dirname(__DIR__) . '/hogar-test-data' : dirname($documentRoot) . '/.hogar-private';
$realData = realpath($dataDir);
$realRoot = realpath($documentRoot);
if ($realData && !($realRoot && str_starts_with($realData . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR))) {
    $database = $realData . '/hogar.sqlite';
    if (is_file($database)) {
        try {
            $db = new PDO('sqlite:' . $database, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $rows = $db->query('SELECT p.id,p.updated FROM properties p JOIN users u ON u.id=p.user_id WHERE p.status="active" AND u.status="active" AND u.email_verified_at IS NOT NULL ORDER BY p.updated DESC,p.id LIMIT 50000')->fetchAll();
            foreach ($rows as $row) {
                $urls[] = ['loc' => 'https://hogarporfavor.com/prop/' . rawurlencode((string)$row['id']), 'lastmod' => gmdate('Y-m-d', (int)$row['updated']), 'priority' => '0.8'];
            }
        } catch (Throwable $error) {
            error_log('Hogar sitemap: ' . $error->getMessage());
        }
    }
}
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $url) {
    echo '<url><loc>' . sitemapEscape($url['loc']) . '</loc>';
    if ($url['lastmod'] !== null) echo '<lastmod>' . sitemapEscape($url['lastmod']) . '</lastmod>';
    echo '<changefreq>weekly</changefreq><priority>' . $url['priority'] . '</priority></url>' . "\n";
}
echo '</urlset>' . "\n";
