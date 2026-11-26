<?php
require __DIR__ . '/../app/bootstrap.php';
requiere_admin();

$f = $_GET['f'] ?? '';
$ruta = __DIR__ . '/../data/fotos/' . $f;
if (!preg_match('/^[a-f0-9]{32}\.jpg$/', $f) || !is_file($ruta)) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($ruta);
