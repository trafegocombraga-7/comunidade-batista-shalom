<?php
/**
 * API pública (checkout).
 *   GET  ?acao=evento&slug=koinonia
 *   POST ?acao=cotar     {slug, itens:{tipo_id:qtd}, cupom, metodo, cartoes:[{parcelas, base}]}
 *   POST ?acao=comprar   {slug, itens, cupom, metodo, nome, email, telefone, cpf, cartoes:[{token, parcelas, base, final, bandeira}]}
 *   GET  ?acao=status&c=CODIGO&a=ACESSO
 *   POST ?acao=simular_pix {c, a}   (só funciona com conta de testes)
 */
declare(strict_types=1);
require __DIR__ . '/../lib/pedidos.php';

$acao = $_GET['acao'] ?? '';

try {
  if ($acao === 'evento') {
    $ev = evento_por_slug((string)($_GET['slug'] ?? ''));
    if (!$ev || $ev['status'] === 'rascunho' && !usuario_atual()) erro('Evento não encontrado.', 404);
    json_out(evento_publico($ev));
  }

  if ($acao === 'cotar') {
    $b = corpo();
    $ev = evento_por_slug((string)($b['slug'] ?? ''));
    if (!$ev) erro('Evento não encontrado.', 404);
    [$itens, $subtotal, $desconto] = montar_itens($ev, (array)($b['itens'] ?? []), (string)($b['cupom'] ?? ''));
    $base = $subtotal - $desconto;
    $plano = plano_cobranca($ev, $base, $base > 0 ? (string)($b['metodo'] ?? 'pix') : 'pix', (array)($b['cartoes'] ?? []));
    /* opções de parcelamento de cada cartão (o comprador vê só valores, nunca o banco) */
    $opcoes = [];
    foreach ($plano['cobrancas'] as $c) {
      if ($c['metodo'] !== 'cartao') continue;
      $lista = [];
      for ($p = 1; $p <= max(1, (int)$ev['max_parcelas']); $p++) {
        [$v] = calcular_cobranca($c['base'], pct_cartao($c['conta'], $p), (int)$c['conta']['taxa_cartao_fixa'], $ev['taxa_modo']);
        $lista[] = ['parcelas' => $p, 'total' => $v, 'parcela' => (int)ceil($v / $p)];
      }
      $opcoes[] = $lista;
    }
    json_out(['subtotal' => $subtotal, 'desconto' => $desconto, 'base' => $base, 'opcoes' => $opcoes] + plano_publico($plano));
  }

  if ($acao === 'comprar') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') erro('Método inválido.', 405);
    $st = db()->prepare("SELECT COUNT(*) FROM pedidos WHERE ip = ? AND criado_em > datetime('now','localtime','-10 minutes')");
    $st->execute([ip_cliente()]);
    if ((int)$st->fetchColumn() >= 15 && !getenv('INGRESSOS_TESTE')) erro('Muitas tentativas seguidas. Aguarde alguns minutos.', 429);
    $b = corpo();
    $ev = evento_por_slug((string)($b['slug'] ?? ''));
    if (!$ev) erro('Evento não encontrado.', 404);
    json_out(criar_pedido($ev, $b));
  }

  if ($acao === 'status') {
    liberar_expirados();
    $p = pedido_por_acesso((string)($_GET['c'] ?? ''), (string)($_GET['a'] ?? ''));
    if (!$p) erro('Pedido não encontrado.', 404);
    json_out(resposta_pedido($p));
  }

  if ($acao === 'simular_pix') {
    $b = corpo();
    $p = pedido_por_acesso((string)($b['c'] ?? ''), (string)($b['a'] ?? ''));
    if (!$p) erro('Pedido não encontrado.', 404);
    $st = db()->prepare("SELECT g.id FROM pagamentos g JOIN contas c ON c.id = g.conta_id
                         WHERE g.pedido_id = ? AND g.metodo = 'pix' AND g.status = 'pendente' AND c.provedor = 'sandbox'");
    $st->execute([$p['id']]);
    $pag = $st->fetchColumn();
    if (!$pag) erro('Simulação disponível só para PIX de teste pendente.');
    confirmar_pagamento((int)$pag, 'aprovado');
    json_out(resposta_pedido(pedido_por_id((int)$p['id'])));
  }

  erro('Ação inválida.', 404);
} catch (DomainException $e) {
  erro($e->getMessage(), 422);
} catch (Throwable $e) {
  error_log('[ingressos] ' . $e->getMessage());
  erro('Algo deu errado. Tente novamente.', 500);
}
