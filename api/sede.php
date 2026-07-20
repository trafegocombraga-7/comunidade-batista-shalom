<?php
/**
 * Localização da sede (igreja).
 *   GET  -> retorna nome, endereço e coordenadas (público)
 *   POST -> atualiza (admin)
 */
declare(strict_types=1);
require __DIR__ . '/db.php';

function saida_sede(array $s): array {
  return [
    'nome'     => $s['nome'],
    'endereco' => $s['endereco'],
    'lat'      => (float)$s['lat'],
    'lng'      => (float)$s['lng'],
  ];
}

$metodo = $_SERVER['REQUEST_METHOD'];

if ($metodo === 'GET') {
  $row = db()->query('SELECT * FROM sede WHERE id = 1')->fetch();
  json_out($row ? saida_sede($row) : ['erro' => 'Sede não configurada'], $row ? 200 : 404);
}

if ($metodo !== 'POST') json_out(['erro' => 'Método não permitido'], 405);

require_auth();

$b = body_json();
$nome     = trim((string)($b['nome'] ?? ''));
$endereco = trim((string)($b['endereco'] ?? ''));
$lat      = $b['lat'] ?? null;
$lng      = $b['lng'] ?? null;

$erros = [];
if ($nome === '')     $erros[] = 'Informe o nome.';
if ($endereco === '') $erros[] = 'Informe o endereço.';
if (!is_numeric($lat) || !is_numeric($lng)) {
  $erros[] = 'Defina a localização no mapa.';
} else {
  $lat = (float)$lat; $lng = (float)$lng;
  if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    $erros[] = 'Coordenadas fora do intervalo válido.';
  }
}
if ($erros) json_out(['erro' => implode(' ', $erros)], 422);

$st = db()->prepare('UPDATE sede SET nome=:nome, endereco=:endereco, lat=:lat, lng=:lng, atualizado_em=datetime("now") WHERE id=1');
$st->execute(['nome' => $nome, 'endereco' => $endereco, 'lat' => $lat, 'lng' => $lng]);

$row = db()->query('SELECT * FROM sede WHERE id = 1')->fetch();
json_out(saida_sede($row));
