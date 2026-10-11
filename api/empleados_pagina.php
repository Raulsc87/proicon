<?php
require_once __DIR__.'/autorizacion.php';
header('Cache-Control: no-store');
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/empleados.html';
$base = preg_replace('~/(?:api/)?empleados(?:_pagina\.php|\.html)$~i', '', $ruta);
if (!autorizacion_usuario()) { header('Location: '.$base.'/index.html'); exit; }
if (!usuario_tiene_permiso('VER_EMPLEADOS')) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    $prefijo = htmlspecialchars($base, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>PROICON - Acceso no autorizado</title><link rel="stylesheet" href="'.$prefijo.'/assets/css/estilos.css"><link rel="stylesheet" href="'.$prefijo.'/assets/css/corporativo.css"></head><body class="body-dashboard"><main class="contenido"><section class="panel-mantenimiento"><h1>Acceso no autorizado</h1><p>No tienes permiso para administrar empleados.</p><a href="'.$prefijo.'/principal.html">Volver al Panel de Control</a></section></main></body></html>'; exit;
}
if (str_ends_with($ruta, '/api/empleados_pagina.php')) { header('Location: '.$base.'/empleados.html'); exit; }
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__.'/../empleados.html');
