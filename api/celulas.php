<?php
/**
 * CRUD das células.
 *   GET                  -> lista (público, só ativas)  |  ?todas=1 (admin, inclui inativas)
 *   POST                 -> cria      (admin)
 *   POST ?id=N&_m=PUT    -> atualiza  (admin)
 *   POST ?id=N&_m=DELETE -> remove    (admin)
 */
declare(strict_types=1);
require __DIR__ . '/db.php';

const TIPOS = ['rise', 'flow', 'vox', 'eklektos', 'familia', 'todas', 'casal'];
const DIAS  = ['Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado', 'Domingo'];

const TIPO_LABEL = [
  'rise'     => '12 a 14 anos',
  'flow'     => '15 a 18 anos',
  'vox'      => '18 a 29 anos',
  'eklektos' => '30+ anos',
  'familia'  => 'Famílias',
  'todas'    => 'Todas as idades',
  'casal'    => 'Casal',
];

function saida(array $c): array {
  return [
    'id'          => 'c' . $c['id'],
    'dbId'        => (int)$c['id'],
    'nome'        => $c['nome'],
    'tipo'        => $c['tipo'],
    'tipoLabel'   => TIPO_LABEL[$c['tipo']] ?? $c['tipo'],
    'lider'       => $c['lider'],
    'telefone'    => $c['telefone'],
    'bairro'      => $c['bairro'],
    'endereco'    => $c['endereco'],
    'dia'         => $c['dia'],
    'horario'     => $c['horario'],
    'idadeMedia'  => $c['idade_media'],
    'lat'         => (float)$c['lat'],
    'lng'         => (float)$c['lng'],
    'ativo'       => (int)$c['ativo'] === 1,
  ];
}

/** valida e normaliza os campos vindos do formulário */
function validar(array $b): array {
  $erros = [];

  $nome    = trim((string)($b['nome'] ?? ''));
  $tipo    = trim((string)($b['tipo'] ?? ''));
  $lider   = trim((string)($b['lider'] ?? ''));
  $dia     = trim((string)($b['dia'] ?? ''));
  $horario = trim((string)($b['horario'] ?? ''));
  $lat     = $b['lat'] ?? null;
  $lng     = $b['lng'] ?? null;

  if ($nome === '')                    $erros[] = 'Informe o nome da célula.';
  if (!in_array($tipo, TIPOS, true))   $erros[] = 'Tipo de célula inválido.';
  if ($lider === '')                   $erros[] = 'Informe o líder.';
  if (!in_array($dia, DIAS, true))     $erros[] = 'Dia da semana inválido.';
  if ($horario === '')                 $erros[] = 'Informe o horário.';

  if (!is_numeric($lat) || !is_numeric($lng)) {
    $erros[] = 'Defina a localização no mapa.';
  } else {
    $lat = (float)$lat; $lng = (float)$lng;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
      $erros[] = 'Coordenadas fora do intervalo válido.';
    }
  }

  if ($erros) json_out(['erro' => implode(' ', $erros)], 422);

  return [
    'nome'        => $nome,
    'tipo'        => $tipo,
    'lider'       => $lider,
    'telefone'    => trim((string)($b['telefone'] ?? '')),
    'bairro'      => trim((string)($b['bairro'] ?? '')),
    'endereco'    => trim((string)($b['endereco'] ?? '')),
    'dia'         => $dia,
    'horario'     => $horario,
    'idade_media' => trim((string)($b['idadeMedia'] ?? '')),
    'lat'         => $lat,
    'lng'         => $lng,
    'ativo'       => !empty($b['ativo']) ? 1 : 0,
  ];
}

$metodo = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$m      = strtoupper((string)($_GET['_m'] ?? ''));

/* ---------- LISTAR (público) ---------- */
if ($metodo === 'GET') {
  $todas = !empty($_GET['todas']);
  if ($todas) require_auth();

  $sql  = 'SELECT * FROM celulas' . ($todas ? '' : ' WHERE ativo = 1') . ' ORDER BY nome COLLATE NOCASE';
  $rows = db()->query($sql)->fetchAll();
  json_out(array_map('saida', $rows));
}

if ($metodo !== 'POST') json_out(['erro' => 'Método não permitido'], 405);

require_auth();

/* ---------- REMOVER ---------- */
if ($m === 'DELETE') {
  if ($id <= 0) json_out(['erro' => 'ID inválido'], 400);
  $st = db()->prepare('DELETE FROM celulas WHERE id = ?');
  $st->execute([$id]);
  json_out(['ok' => true, 'removidos' => $st->rowCount()]);
}

$d = validar(body_json());

/* ---------- ATUALIZAR ---------- */
if ($m === 'PUT') {
  if ($id <= 0) json_out(['erro' => 'ID inválido'], 400);
  $st = db()->prepare('UPDATE celulas SET
      nome=:nome, tipo=:tipo, lider=:lider, telefone=:telefone, bairro=:bairro,
      endereco=:endereco, dia=:dia, horario=:horario, idade_media=:idade_media,
      lat=:lat, lng=:lng, ativo=:ativo, atualizado_em=datetime("now")
    WHERE id=:id');
  $d['id'] = $id;
  $st->execute($d);

  $row = db()->prepare('SELECT * FROM celulas WHERE id = ?');
  $row->execute([$id]);
  $c = $row->fetch();
  if (!$c) json_out(['erro' => 'Célula não encontrada'], 404);
  json_out(saida($c));
}

/* ---------- CRIAR ---------- */
$st = db()->prepare('INSERT INTO celulas
  (nome, tipo, lider, telefone, bairro, endereco, dia, horario, idade_media, lat, lng, ativo)
  VALUES (:nome,:tipo,:lider,:telefone,:bairro,:endereco,:dia,:horario,:idade_media,:lat,:lng,:ativo)');
$st->execute($d);
$novo = (int) db()->lastInsertId();

$row = db()->prepare('SELECT * FROM celulas WHERE id = ?');
$row->execute([$novo]);
json_out(saida($row->fetch()), 201);
