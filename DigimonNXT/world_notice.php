<?php
declare(strict_types=1);
require_once __DIR__.'/includes/bootstrap.php';
require_once __DIR__.'/includes/world_notice.php';
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET'); http_response_code(405);
    echo json_encode(['ok'=>false,'error'=>'method']); exit;
}
if (!pv_is_logged_in()) {
    http_response_code(401); echo json_encode(['ok'=>false,'error'=>'session']); exit;
}
echo json_encode(['ok'=>true,'notice'=>dn_world_public_notice()], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
