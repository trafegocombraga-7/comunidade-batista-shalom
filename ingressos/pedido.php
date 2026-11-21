<?php
/* Página do pedido: PIX aguardando pagamento ou ingressos com QR Code. Acesso pelo link secreto do pedido. */
declare(strict_types=1);
require __DIR__ . '/lib/pedidos.php';

liberar_expirados();
$p = pedido_por_acesso((string)($_GET['c'] ?? ''), (string)($_GET['a'] ?? ''));
if (!$p) {
  http_response_code(404);
  echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pedido não encontrado</title>'
     . '<body style="font-family:system-ui;display:grid;place-items:center;min-height:90vh;text-align:center;color:#333"><div><h1>Pedido não encontrado</h1><p>Confira o link recebido por e-mail.</p></div>';
  exit;
}
$ev = evento_por_id((int)$p['evento_id']);
$cor = preg_match('/^#[0-9a-f]{6}$/i', $ev['cor']) ? $ev['cor'] : '#055a85';
$dados = resposta_pedido($p);
$st = db()->prepare("SELECT c.provedor FROM pagamentos g JOIN contas c ON c.id = g.conta_id WHERE g.pedido_id = ? AND g.metodo = 'pix' LIMIT 1");
$st->execute([$p['id']]);
$dados['simulador'] = $st->fetchColumn() === 'sandbox';
$st = db()->prepare("SELECT g.codigo, g.titular, g.status, g.checkin_em, t.nome AS tipo FROM ingressos g JOIN tipos t ON t.id = g.tipo_id WHERE g.pedido_id = ? ORDER BY g.id");
$st->execute([$p['id']]);
$dados['ingressos'] = $st->fetchAll();
$dados['evento'] = ['nome' => $ev['nome'], 'slug' => $ev['slug'], 'inicio' => $ev['inicio'], 'local' => trim($ev['local_nome'] . ($ev['endereco'] ? ' · ' . $ev['endereco'] : ''))];
$dados['comprador'] = explode(' ', $p['nome'])[0];
$dados['email'] = $p['email'];
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Pedido <?= h($p['codigo']) ?> · <?= h($ev['nome']) ?></title>
<link rel="icon" type="image/png" href="/assets/favicon-branco.png?v=1">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="/ingressos/assets/loja.css?v=1">
<style>:root{--cor:<?= h($cor) ?>}</style>
</head>
<body>
<header class="topo">
  <a class="topo__marca" href="/"><img src="/assets/logo.png" alt=""><span><?= h(cfg('nome_organizacao', 'Comunidade Batista Shalom')) ?></span></a>
  <span class="topo__seguro">Pedido <?= h($p['codigo']) ?></span>
</header>
<main class="pedido" id="pedido"></main>
<footer class="rodape"><p>Guarde este link: é por ele que você acessa seus ingressos.</p></footer>
<script>window.PEDIDO = <?= json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
<script src="/ingressos/assets/pedido.js?v=1"></script>
</body>
</html>
