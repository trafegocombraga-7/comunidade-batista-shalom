<?php
/**
 * API do check-in (usuários "checkin" e "admin").
 *   GET  ?acao=eventos
 *   GET  ?acao=resumo&evento=ID
 *   GET  ?acao=buscar&evento=ID&q=nome
 *   POST ?acao=validar {evento_id, codigo}
 */
declare(strict_types=1);
require __DIR__ . '/../lib/pedidos.php';

$acao = $_GET['acao'] ?? '';
$eu = exigir_login(['admin', 'checkin']);
$pdo = db();

if ($acao === 'eventos') {
  json_out($pdo->query("SELECT id, nome, inicio, cor FROM eventos WHERE status <> 'rascunho' OR id IN (SELECT DISTINCT evento_id FROM ingressos)
                        ORDER BY COALESCE(inicio, criado_em) DESC")->fetchAll());
}

if ($acao === 'resumo') {
  $st = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(checkin_em IS NOT NULL),0) AS presentes FROM ingressos WHERE evento_id = ? AND status = 'valido'");
  $st->execute([(int)($_GET['evento'] ?? 0)]);
  json_out($st->fetch());
}

if ($acao === 'buscar') {
  $q = trim((string)($_GET['q'] ?? ''));
  if (mb_strlen($q) < 3) json_out([]);
  $like = '%' . $q . '%';
  $st = $pdo->prepare("SELECT g.codigo, g.titular, g.checkin_em, g.status, t.nome AS tipo, p.nome AS comprador, p.codigo AS pedido
                       FROM ingressos g JOIN pedidos p ON p.id = g.pedido_id JOIN tipos t ON t.id = g.tipo_id
                       WHERE g.evento_id = ? AND p.status = 'pago' AND (g.titular LIKE ? OR p.nome LIKE ? OR p.email LIKE ? OR p.codigo LIKE ? OR g.codigo LIKE ? OR p.cpf LIKE ?)
                       ORDER BY g.titular LIMIT 30");
  $cpf = so_digitos($q);
  $st->execute([(int)($_GET['evento'] ?? 0), $like, $like, $like, $like, $like, $cpf !== '' ? '%' . $cpf . '%' : '__nenhum__']);
  json_out($st->fetchAll());
}

if ($acao === 'validar') {
  $b = corpo();
  json_out(fazer_checkin((string)($b['codigo'] ?? ''), (int)($b['evento_id'] ?? 0), (int)$eu['id']));
}

erro('Ação inválida.', 404);
