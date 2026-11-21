<?php
/**
 * Avisos automáticos dos bancos (PIX pago, cartão aprovado/estornado...).
 * Cada conta tem a sua URL: /ingressos/api/webhook.php?conta=ID
 * O aviso nunca é aceito "de olhos fechados": cada provedor confirma o status direto na API do banco.
 */
declare(strict_types=1);
require __DIR__ . '/../lib/pedidos.php';

$conta = conta((int)($_GET['conta'] ?? 0));
$corpo = (string)file_get_contents('php://input');
if (!$conta) { http_response_code(404); exit; }
if ($conta['provedor'] === 'sandbox' && !getenv('INGRESSOS_TESTE')) { http_response_code(204); exit; } // simulador não recebe avisos de fora

$res = 'ok';
try {
  foreach (provedor($conta['provedor'])->webhook($conta, $corpo, $_GET) as [$ref, $status]) {
    $st = db()->prepare('SELECT id, status FROM pagamentos WHERE conta_id = ? AND ref = ?');
    $st->execute([$conta['id'], $ref]);
    $pag = $st->fetch();
    if (!$pag) { $res = 'pagamento desconhecido ' . $ref; continue; }
    if ($pag['status'] !== $status && in_array($status, ['aprovado', 'recusado', 'estornado', 'expirado'], true)) {
      confirmar_pagamento((int)$pag['id'], $status);
      if ($status === 'recusado') {
        $p = db()->prepare("UPDATE pedidos SET status = 'recusado' WHERE id = (SELECT pedido_id FROM pagamentos WHERE id = ?) AND status = 'pendente'");
        $p->execute([$pag['id']]);
      }
    }
  }
} catch (Throwable $e) {
  $res = 'erro: ' . $e->getMessage();
  http_response_code(500);
}
db()->prepare('INSERT INTO log_webhooks (conta_id, corpo, resultado) VALUES (?,?,?)')->execute([$conta['id'], substr($corpo, 0, 4000), $res]);
if (http_response_code() !== 500) http_response_code(200);
echo 'ok';
