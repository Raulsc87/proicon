<?php
// Router exclusivo del servidor de desarrollo: php -S localhost:8000 scripts/servidor_local.php
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$ruta = str_replace('\\', '/', rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/'));
if (preg_match('~(?:^|/)(?:uploads|config|\.git|\.codex|\.agents|facturas|pagos|fotos)(?:/|$)~i', $ruta) || str_contains($ruta, '..')) {
    http_response_code(404); exit;
}
$archivo = realpath(__DIR__.'/..'.$ruta);
if ($archivo !== false && strcasecmp($archivo, realpath(__DIR__.'/../usuarios.html')) === 0) { require __DIR__.'/../api/usuarios_pagina.php'; return true; }
if ($archivo !== false && strcasecmp($archivo, realpath(__DIR__.'/../empleados.html')) === 0) { require __DIR__.'/../api/empleados_pagina.php'; return true; }
return false;
