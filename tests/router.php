<?php
/* Roteador para "php -S" nos testes: imita as regras do .htaccess da Hostinger. */
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raiz = dirname(__DIR__);
if (preg_match('#^/e/([a-z0-9-]+)/?$#', $uri, $m)) { $_GET['evento'] = $m[1]; chdir($raiz . '/ingressos'); require $raiz . '/ingressos/checkout.php'; return true; }
if (preg_match('#^/painel/?$#', $uri)) { readfile($raiz . '/ingressos/painel/index.html'); return true; }
if (preg_match('#^/checkin/?$#', $uri)) { readfile($raiz . '/ingressos/checkin/index.html'); return true; }
if (preg_match('#^/ingressos/lib/#', $uri) || preg_match('#^/data/#', $uri)) { http_response_code(403); echo 'proibido'; return true; }
if (preg_match('#^/(koinonia|quem-somos|ofertas)$#', $uri, $m)) { readfile($raiz . '/' . $m[1] . '.html'); return true; }
return false;
