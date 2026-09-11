<?php
// Router exclusivo del servidor de desarrollo: php -S localhost:8000 servidor_local.php
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$ruta = str_replace('\\', '/', rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/'));
if (preg_match('~(?:^|/)(?:uploads|config|\.git|\.codex|\.agents|facturas|pagos)(?:/|$)~i', $ruta) || str_contains($ruta, '..')) {
    http_response_code(404); exit;
}
return false;
