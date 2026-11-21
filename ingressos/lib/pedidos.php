<?php
/**
 * Regras de venda: estoque, cupom, cotação, criação do pedido, confirmação de pagamento,
 * emissão dos ingressos, e-mail e check-in.
 */
declare(strict_types=1);

require_once __DIR__ . '/pagamentos.php';

const MINUTOS_CARTAO = 15;   // reserva enquanto o cartão é processado

/* ---------------- leitura ---------------- */

function evento_por_slug(string $slug): ?array {
  $st = db()->prepare('SELECT * FROM eventos WHERE slug = ?');
  $st->execute([$slug]);
  return $st->fetch() ?: null;
}

function evento_por_id(int $id): ?array {
  $st = db()->prepare('SELECT * FROM eventos WHERE id = ?');
  $st->execute([$id]);
  return $st->fetch() ?: null;
}

/** pedidos PIX/cartão que passaram do prazo deixam de segurar ingressos */
function liberar_expirados(): void {
  $pdo = db();
  $agora = agora();
  $pdo->prepare("UPDATE pagamentos SET status='expirado', atualizado_em=? WHERE status='pendente' AND pedido_id IN
                 (SELECT id FROM pedidos WHERE status='pendente' AND expira_em IS NOT NULL AND expira_em < ?)")->execute([$agora, $agora]);
  $pdo->prepare("UPDATE pedidos SET status='expirado' WHERE status='pendente' AND expira_em IS NOT NULL AND expira_em < ?")->execute([$agora]);
}

/** unidades já vendidas/reservadas por tipo e pessoas por evento */
function ocupacao(int $evento_id): array {
  $st = db()->prepare("SELECT i.tipo_id, SUM(i.qtd) AS unidades, SUM(i.qtd * t.pessoas) AS pessoas
    FROM pedido_itens i JOIN pedidos p ON p.id = i.pedido_id JOIN tipos t ON t.id = i.tipo_id
    WHERE p.evento_id = ? AND (p.status = 'pago' OR (p.status = 'pendente' AND (p.expira_em IS NULL OR p.expira_em >= ?)))
    GROUP BY i.tipo_id");
  $st->execute([$evento_id, agora()]);
  $por_tipo = [];
  $pessoas = 0;
  foreach ($st as $r) { $por_tipo[(int)$r['tipo_id']] = (int)$r['unidades']; $pessoas += (int)$r['pessoas']; }
  return ['tipos' => $por_tipo, 'pessoas' => $pessoas];
}

function tipos_do_evento(int $evento_id, bool $so_ativos = true): array {
  $st = db()->prepare('SELECT * FROM tipos WHERE evento_id = ?' . ($so_ativos ? ' AND ativo = 1' : '') . ' ORDER BY ordem, id');
  $st->execute([$evento_id]);
  return $st->fetchAll();
}

function dentro_periodo(?string $ini, ?string $fim): bool {
  $a = agora();
  if ($ini && $a < $ini) return false;
  if ($fim && $a > $fim) return false;
  return true;
}

function situacao_vendas(array $ev): string {
  if ($ev['status'] === 'encerrado') return 'encerradas';
  if ($ev['status'] !== 'publicado') return 'em_breve';
  if ($ev['vendas_inicio'] && agora() < $ev['vendas_inicio']) return 'em_breve';
  if ($ev['vendas_fim'] && agora() > $ev['vendas_fim']) return 'encerradas';
  return 'abertas';
}

/** o que o comprador pode ver do evento */
function evento_publico(array $ev): array {
  liberar_expirados();
  $oc = ocupacao((int)$ev['id']);
  $vagas_evento = (int)$ev['lotacao'] > 0 ? max(0, (int)$ev['lotacao'] - $oc['pessoas']) : PHP_INT_MAX;
  $tipos = [];
  foreach (tipos_do_evento((int)$ev['id']) as $t) {
    $restam = (int)$t['quantidade'] > 0 ? max(0, (int)$t['quantidade'] - ($oc['tipos'][(int)$t['id']] ?? 0)) : PHP_INT_MAX;
    $restam = min($restam, intdiv($vagas_evento === PHP_INT_MAX ? PHP_INT_MAX : $vagas_evento, max(1, (int)$t['pessoas'])));
    $tipos[] = [
      'id' => (int)$t['id'], 'nome' => $t['nome'], 'descricao' => $t['descricao'], 'preco' => (int)$t['preco'],
      'pessoas' => (int)$t['pessoas'],
      'max' => (int)min((int)$t['max_por_pedido'], (int)$ev['limite_por_pedido'], $restam),
      'esgotado' => $restam <= 0,
      'poucos' => $restam !== PHP_INT_MAX && $restam > 0 && $restam <= 10,
      'disponivel' => dentro_periodo($t['vendas_inicio'], $t['vendas_fim']),
    ];
  }
  $pix = $ev['pix_conta_id'] ? conta((int)$ev['pix_conta_id']) : null;
  $cc = $ev['cartao_conta_id'] ? conta((int)$ev['cartao_conta_id']) : null;
  return [
    'slug' => $ev['slug'], 'nome' => $ev['nome'], 'descricao' => $ev['descricao'],
    'local' => $ev['local_nome'], 'endereco' => $ev['endereco'], 'inicio' => $ev['inicio'], 'fim' => $ev['fim'],
    'imagem' => $ev['imagem'], 'cor' => $ev['cor'],
    'vendas' => situacao_vendas($ev),
    'tipos' => $tipos,
    'pedir_cpf' => (bool)$ev['pedir_cpf'],
    'pagamento' => [
      'pix' => (bool)$ev['aceita_pix'] && $pix && $pix['aceita_pix'],
      'cartao' => (bool)$ev['aceita_cartao'] && $cc && $cc['aceita_cartao'],
      'dois_cartoes' => (bool)$ev['dois_cartoes'],
      'max_parcelas' => max(1, (int)$ev['max_parcelas']),
      'tokenizacao' => config_publica_cartao($cc && $cc['aceita_cartao'] ? $cc : null),
      'teste' => ($pix && $pix['ambiente'] === 'sandbox') || ($cc && $cc['ambiente'] === 'sandbox'),
    ],
    'limite_por_pedido' => (int)$ev['limite_por_pedido'],
    'taxa_modo' => $ev['taxa_modo'],
  ];
}

/* ---------------- montagem do pedido ---------------- */

/** valida itens e cupom; devolve [itens_validos, subtotal, desconto, cupom] ou lança erro */
function montar_itens(array $ev, array $itens_req, string $cupom_cod): array {
  $pub = evento_publico($ev);
  if ($pub['vendas'] !== 'abertas') throw new DomainException($pub['vendas'] === 'em_breve' ? 'As vendas ainda não começaram.' : 'As vendas estão encerradas.');
  $mapa = [];
  foreach ($pub['tipos'] as $t) $mapa[$t['id']] = $t;
  $precos = [];
  foreach (tipos_do_evento((int)$ev['id']) as $t) $precos[(int)$t['id']] = (int)$t['preco'];

  $itens = [];
  $subtotal = 0;
  $unidades = 0;
  foreach ($itens_req as $tid => $qtd) {
    $tid = (int)$tid; $qtd = (int)$qtd;
    if ($qtd <= 0) continue;
    if (!isset($mapa[$tid])) throw new DomainException('Tipo de ingresso inválido.');
    $t = $mapa[$tid];
    if (!$t['disponivel']) throw new DomainException('"' . $t['nome'] . '" não está à venda neste momento.');
    if ($t['esgotado']) throw new DomainException('"' . $t['nome'] . '" esgotou.');
    if ($qtd > $t['max']) throw new DomainException('Quantidade de "' . $t['nome'] . '" maior que o disponível (' . $t['max'] . ').');
    $itens[] = ['tipo_id' => $tid, 'qtd' => $qtd, 'preco' => $precos[$tid]];
    $subtotal += $qtd * $precos[$tid];
    $unidades += $qtd;
  }
  if (!$itens) throw new DomainException('Escolha pelo menos um ingresso.');
  if ($unidades > (int)$ev['limite_por_pedido']) throw new DomainException('Máximo de ' . $ev['limite_por_pedido'] . ' ingressos por pedido.');

  $desconto = 0;
  $cupom = null;
  if (trim($cupom_cod) !== '') {
    $cupom = cupom_valido((int)$ev['id'], $cupom_cod);
    if (!$cupom) throw new DomainException('Cupom inválido ou esgotado.');
    $desconto = $cupom['tipo'] === 'pct' ? (int)round($subtotal * min(100, (int)$cupom['valor']) / 100) : min($subtotal, (int)$cupom['valor']);
  }
  return [$itens, $subtotal, $desconto, $cupom];
}

function cupom_valido(int $evento_id, string $codigo): ?array {
  $st = db()->prepare('SELECT * FROM cupons WHERE evento_id = ? AND codigo = ? AND ativo = 1');
  $st->execute([$evento_id, trim($codigo)]);
  $c = $st->fetch();
  if (!$c) return null;
  if ((int)$c['usos_max'] > 0) {
    $u = db()->prepare("SELECT COUNT(*) FROM pedidos WHERE cupom_id = ? AND (status = 'pago' OR (status = 'pendente' AND (expira_em IS NULL OR expira_em >= ?)))");
    $u->execute([$c['id'], agora()]);
    if ((int)$u->fetchColumn() >= (int)$c['usos_max']) return null;
  }
  return $c;
}

/**
 * Plano de cobrança. $cartoes = [['parcelas'=>N, 'base'=>centavos?], ...] (até 2).
 * No modo 2 cartões, "base" do primeiro define quanto vai nele; o segundo leva o resto.
 */
function plano_cobranca(array $ev, int $base, string $metodo, array $cartoes): array {
  if ($base <= 0) return ['cobrancas' => [], 'taxa_cliente' => 0, 'custo' => 0, 'total' => 0];
  $modo = $ev['taxa_modo'];
  $cob = [];
  if ($metodo === 'pix') {
    $c = $ev['pix_conta_id'] ? conta((int)$ev['pix_conta_id']) : null;
    if (!$ev['aceita_pix'] || !$c || !$c['aceita_pix']) throw new DomainException('PIX indisponível para este evento.');
    [$v, $tx, $custo] = calcular_cobranca($base, (float)$c['taxa_pix_pct'], (int)$c['taxa_pix_fixa'], $modo);
    $cob[] = ['metodo' => 'pix', 'conta' => $c, 'base' => $base, 'valor' => $v, 'taxa' => $tx, 'custo' => $custo, 'parcelas' => 1];
  } else {
    $c = $ev['cartao_conta_id'] ? conta((int)$ev['cartao_conta_id']) : null;
    if (!$ev['aceita_cartao'] || !$c || !$c['aceita_cartao']) throw new DomainException('Cartão indisponível para este evento.');
    $qtd = $metodo === 'dois_cartoes' ? 2 : 1;
    if ($qtd === 2 && !$ev['dois_cartoes']) throw new DomainException('Pagamento com dois cartões não está liberado.');
    $bases = [$base];
    if ($qtd === 2) {
      $b1 = (int)($cartoes[0]['base'] ?? intdiv($base, 2));
      if ($b1 < 100 || $base - $b1 < 100) throw new DomainException('Cada cartão precisa ter pelo menos R$ 1,00.');
      $bases = [$b1, $base - $b1];
    }
    foreach ($bases as $i => $b) {
      $p = max(1, min((int)$ev['max_parcelas'], (int)($cartoes[$i]['parcelas'] ?? 1)));
      [$v, $tx, $custo] = calcular_cobranca($b, pct_cartao($c, $p), (int)$c['taxa_cartao_fixa'], $modo);
      $cob[] = ['metodo' => 'cartao', 'conta' => $c, 'base' => $b, 'valor' => $v, 'taxa' => $tx, 'custo' => $custo, 'parcelas' => $p];
    }
  }
  return [
    'cobrancas' => $cob,
    'taxa_cliente' => array_sum(array_column($cob, 'taxa')),
    'custo' => array_sum(array_column($cob, 'custo')),
    'total' => array_sum(array_column($cob, 'valor')),
  ];
}

/** versão para mostrar ao comprador (sem dizer qual banco) */
function plano_publico(array $plano): array {
  return [
    'total' => $plano['total'], 'taxa' => $plano['taxa_cliente'],
    'cobrancas' => array_map(fn($c) => ['metodo' => $c['metodo'], 'valor' => $c['valor'], 'parcelas' => $c['parcelas'],
      'valor_parcela' => (int)ceil($c['valor'] / max(1, $c['parcelas'])), 'base' => $c['base']], $plano['cobrancas']),
  ];
}

/* ---------------- criação ---------------- */

function criar_pedido(array $ev, array $req): array {
  $nome = trim((string)($req['nome'] ?? ''));
  $email = trim((string)($req['email'] ?? ''));
  $tel = so_digitos((string)($req['telefone'] ?? ''));
  $cpf = so_digitos((string)($req['cpf'] ?? ''));
  $metodo = (string)($req['metodo'] ?? 'pix');
  if (mb_strlen($nome) < 3 || !str_contains($nome, ' ')) throw new DomainException('Informe nome e sobrenome.');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new DomainException('E-mail inválido.');
  if (strlen($tel) < 10) throw new DomainException('Informe um WhatsApp com DDD.');
  if ($ev['pedir_cpf'] && !cpf_valido($cpf)) throw new DomainException('CPF inválido.');
  if (!in_array($metodo, ['pix', 'cartao', 'dois_cartoes'], true)) throw new DomainException('Forma de pagamento inválida.');

  $pdo = db();
  $pdo->exec('BEGIN IMMEDIATE'); // trava a venda: dois compradores não pegam o último ingresso ao mesmo tempo
  try {
    liberar_expirados();
    [$itens, $subtotal, $desconto, $cupom] = montar_itens($ev, (array)($req['itens'] ?? []), (string)($req['cupom'] ?? ''));
    $base = $subtotal - $desconto;
    $cartoes = (array)($req['cartoes'] ?? []);
    $plano = plano_cobranca($ev, $base, $base > 0 ? $metodo : 'pix', $cartoes);
    $codigo = codigo_aleatorio(8);
    $minutos = $metodo === 'pix' ? max(5, (int)$ev['pix_minutos']) : MINUTOS_CARTAO;
    $pdo->prepare('INSERT INTO pedidos (codigo, acesso, evento_id, nome, email, telefone, cpf, subtotal, desconto, taxa_cliente, total, custo_taxas, metodo, status, cupom_id, expira_em, ip)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$codigo, bin2hex(random_bytes(16)), $ev['id'], $nome, mb_strtolower($email), $tel, $cpf, $subtotal, $desconto,
                   $plano['taxa_cliente'], $plano['total'], $plano['custo'], $base > 0 ? $metodo : 'cortesia', 'pendente',
                   $cupom['id'] ?? null, date('Y-m-d H:i:s', time() + $minutos * 60), ip_cliente()]);
    $pid = (int)$pdo->lastInsertId();
    $ins = $pdo->prepare('INSERT INTO pedido_itens (pedido_id, tipo_id, qtd, preco) VALUES (?,?,?,?)');
    foreach ($itens as $i) $ins->execute([$pid, $i['tipo_id'], $i['qtd'], $i['preco']]);
    $pdo->exec('COMMIT');
  } catch (Throwable $e) {
    $pdo->exec('ROLLBACK');
    throw $e;
  }

  $pedido = pedido_por_id($pid);
  if ($base <= 0) { /* ingresso gratuito ou 100% de desconto */
    marcar_pago($pid);
    return resposta_pedido(pedido_por_id($pid));
  }

  if ($metodo === 'pix') {
    $c = $plano['cobrancas'][0];
    try {
      $r = provedor($c['conta']['provedor'])->pix($c['conta'], $pedido, $c['valor'], $minutos);
    } catch (Throwable $e) {
      atualizar_status_pedido($pid, 'cancelado');
      throw new DomainException('Não foi possível gerar o PIX agora. Tente novamente em instantes.');
    }
    $pdo->prepare('INSERT INTO pagamentos (pedido_id, conta_id, provedor, metodo, valor, custo, parcelas, status, ref, pix_copia_cola, pix_qr_img)
                   VALUES (?,?,?,?,?,?,1,?,?,?,?)')
        ->execute([$pid, $c['conta']['id'], $c['conta']['provedor'], 'pix', $c['valor'], $c['custo'], 'pendente', $r['ref'], $r['copia_cola'], $r['qr_img']]);
    if ($r['status'] === 'aprovado') confirmar_pagamento((int)$pdo->lastInsertId(), 'aprovado');
    return resposta_pedido(pedido_por_id($pid));
  }

  /* cartão (um ou dois): cobra em sequência; se algum for recusado, devolve o que já passou */
  $aprovados = [];
  foreach ($plano['cobrancas'] as $i => $c) {
    $dados = (array)($cartoes[$i] ?? []);
    try {
      $r = provedor($c['conta']['provedor'])->cartao($c['conta'], $pedido, $c['valor'], $c['parcelas'], $dados);
    } catch (Throwable $e) {
      $r = ['ref' => '', 'status' => 'recusado', 'mensagem' => 'Não foi possível processar o cartão.', 'final' => '', 'bandeira' => ''];
    }
    $pdo->prepare('INSERT INTO pagamentos (pedido_id, conta_id, provedor, metodo, valor, custo, parcelas, status, ref, cartao_final, cartao_bandeira, mensagem)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$pid, $c['conta']['id'], $c['conta']['provedor'], 'cartao', $c['valor'], $c['custo'], $c['parcelas'],
                   $r['status'], $r['ref'], $r['final'], $r['bandeira'], $r['mensagem']]);
    $pag_id = (int)$pdo->lastInsertId();
    if ($r['status'] === 'recusado') {
      foreach ($aprovados as $ap) estornar_pagamento($ap);
      atualizar_status_pedido($pid, 'recusado');
      $qual = count($plano['cobrancas']) > 1 ? ' (' . ($i + 1) . 'º cartão)' : '';
      throw new DomainException(($r['mensagem'] ?: 'Pagamento recusado.') . $qual . ' Nenhum valor foi cobrado. Confira os dados ou use outro cartão.');
    }
    if ($r['status'] === 'aprovado') $aprovados[] = $pag_id;
  }
  verificar_pedido_pago($pid);
  $p = pedido_por_id($pid);
  if ($p['status'] === 'pendente') {   // cartão em análise: segura a vaga por 2 dias aguardando o banco
    $pdo->prepare('UPDATE pedidos SET expira_em = ? WHERE id = ?')->execute([date('Y-m-d H:i:s', time() + 172800), $pid]);
  }
  return resposta_pedido(pedido_por_id($pid));
}

/* ---------------- confirmação ---------------- */

function pedido_por_id(int $id): array {
  $st = db()->prepare('SELECT * FROM pedidos WHERE id = ?');
  $st->execute([$id]);
  return $st->fetch() ?: [];
}

function atualizar_status_pedido(int $id, string $status): void {
  db()->prepare('UPDATE pedidos SET status = ? WHERE id = ?')->execute([$status, $id]);
}

function confirmar_pagamento(int $pag_id, string $status): void {
  $pdo = db();
  $pdo->prepare('UPDATE pagamentos SET status = ?, atualizado_em = ? WHERE id = ?')->execute([$status, agora(), $pag_id]);
  $st = $pdo->prepare('SELECT pedido_id FROM pagamentos WHERE id = ?');
  $st->execute([$pag_id]);
  $pid = (int)$st->fetchColumn();
  if ($pid) {
    if ($status === 'estornado') {
      $pdo->prepare("UPDATE pedidos SET status='estornado' WHERE id = ? AND status = 'pago'")->execute([$pid]);
      $pdo->prepare("UPDATE ingressos SET status='cancelado' WHERE pedido_id = ?")->execute([$pid]);
    } else {
      verificar_pedido_pago($pid);
    }
  }
}

/** pedido fica pago quando todas as cobranças foram aprovadas */
function verificar_pedido_pago(int $pid): void {
  $st = db()->prepare("SELECT COUNT(*) AS n, SUM(status = 'aprovado') AS ok FROM pagamentos WHERE pedido_id = ?");
  $st->execute([$pid]);
  $r = $st->fetch();
  if ((int)$r['n'] > 0 && (int)$r['n'] === (int)$r['ok']) {
    $p = pedido_por_id($pid);
    if (in_array($p['status'], ['pendente', 'expirado'], true)) {
      if ($p['status'] === 'expirado') {   // PIX pago depois do prazo: confirma mesmo assim e avisa no pedido
        db()->prepare("UPDATE pedidos SET obs = trim(obs || ' Pago após o prazo do PIX; conferir lotação.') WHERE id = ?")->execute([$pid]);
      }
      marcar_pago($pid);
    }
  }
}

function marcar_pago(int $pid): void {
  $pdo = db();
  $pdo->prepare("UPDATE pedidos SET status = 'pago', pago_em = ?, expira_em = NULL WHERE id = ?")->execute([agora(), $pid]);
  emitir_ingressos($pid);
  enviar_email_pedido($pid);
}

function emitir_ingressos(int $pid): void {
  $pdo = db();
  $ja = $pdo->prepare('SELECT COUNT(*) FROM ingressos WHERE pedido_id = ?');
  $ja->execute([$pid]);
  if ((int)$ja->fetchColumn() > 0) return;
  $p = pedido_por_id($pid);
  $st = $pdo->prepare('SELECT i.*, t.pessoas FROM pedido_itens i JOIN tipos t ON t.id = i.tipo_id WHERE i.pedido_id = ?');
  $st->execute([$pid]);
  $ins = $pdo->prepare('INSERT INTO ingressos (codigo, pedido_id, evento_id, tipo_id, titular) VALUES (?,?,?,?,?)');
  foreach ($st->fetchAll() as $it) {
    $n = (int)$it['qtd'] * max(1, (int)$it['pessoas']);
    for ($k = 0; $k < $n; $k++) {
      for ($tent = 0; $tent < 5; $tent++) {
        try { $ins->execute([codigo_aleatorio(12), $pid, $p['evento_id'], $it['tipo_id'], $p['nome']]); break; }
        catch (PDOException $e) { if ($tent === 4) throw $e; }
      }
    }
  }
}

function estornar_pagamento(int $pag_id): bool {
  $st = db()->prepare('SELECT * FROM pagamentos WHERE id = ?');
  $st->execute([$pag_id]);
  $pag = $st->fetch();
  if (!$pag || $pag['status'] !== 'aprovado') return false;
  $c = conta((int)$pag['conta_id']);
  if ($c) provedor($pag['provedor'])->estornar($c, $pag);
  db()->prepare("UPDATE pagamentos SET status = 'estornado', atualizado_em = ? WHERE id = ?")->execute([agora(), $pag_id]);
  return true;
}

/* ---------------- respostas e links ---------------- */

function link_pedido(array $p): string {
  return url_base() . '/ingressos/pedido.php?c=' . $p['codigo'] . '&a=' . $p['acesso'];
}

function resposta_pedido(array $p): array {
  $st = db()->prepare('SELECT metodo, valor, parcelas, status, pix_copia_cola, pix_qr_img, cartao_final, cartao_bandeira FROM pagamentos WHERE pedido_id = ? ORDER BY id');
  $st->execute([$p['id']]);
  $pags = $st->fetchAll();
  $pix = null;
  foreach ($pags as $g) if ($g['metodo'] === 'pix') $pix = ['copia_cola' => $g['pix_copia_cola'], 'qr_img' => $g['pix_qr_img'], 'valor' => (int)$g['valor']];
  return [
    'codigo' => $p['codigo'], 'acesso' => $p['acesso'], 'status' => $p['status'], 'total' => (int)$p['total'],
    'expira_em' => $p['expira_em'], 'metodo' => $p['metodo'], 'pix' => $pix,
    'link' => link_pedido($p),
    'cartoes' => array_values(array_map(fn($g) => ['final' => $g['cartao_final'], 'bandeira' => $g['cartao_bandeira'], 'valor' => (int)$g['valor'], 'parcelas' => (int)$g['parcelas'], 'status' => $g['status']],
                 array_filter($pags, fn($g) => $g['metodo'] === 'cartao'))),
  ];
}

function pedido_por_acesso(string $codigo, string $acesso): ?array {
  $st = db()->prepare('SELECT * FROM pedidos WHERE codigo = ?');
  $st->execute([$codigo]);
  $p = $st->fetch();
  if (!$p || !hash_equals($p['acesso'], $acesso)) return null;
  return $p;
}

/* ---------------- e-mail ---------------- */

function enviar_email_pedido(int $pid): bool {
  if (getenv('INGRESSOS_TESTE')) return true;
  $p = pedido_por_id($pid);
  $ev = evento_por_id((int)$p['evento_id']);
  if (!$p || !$ev) return false;
  $n = db()->prepare('SELECT COUNT(*) FROM ingressos WHERE pedido_id = ?');
  $n->execute([$pid]);
  $qtd = (int)$n->fetchColumn();
  $link = link_pedido($p);
  $org = cfg('nome_organizacao', 'Comunidade Batista Shalom');
  $cor = preg_match('/^#[0-9a-f]{6}$/i', $ev['cor']) ? $ev['cor'] : '#055a85';
  $quando = $ev['inicio'] ? date('d/m/Y \à\s H\hi', strtotime($ev['inicio'])) : '';
  $html = '<div style="font-family:Arial,sans-serif;max-width:560px;margin:auto;color:#222">'
    . '<div style="background:' . $cor . ';color:#fff;padding:26px;border-radius:14px 14px 0 0"><h1 style="margin:0;font-size:26px">' . h($ev['nome']) . '</h1>'
    . '<p style="margin:6px 0 0">Seu pedido ' . h($p['codigo']) . ' está confirmado!</p></div>'
    . '<div style="border:1px solid #eee;border-top:0;padding:26px;border-radius:0 0 14px 14px">'
    . '<p>Olá, ' . h(explode(' ', $p['nome'])[0]) . '! Seus <b>' . $qtd . ' ingresso(s)</b> estão prontos.</p>'
    . ($quando ? '<p><b>Quando:</b> ' . h($quando) . '<br><b>Onde:</b> ' . h($ev['local_nome']) . ($ev['endereco'] ? ' · ' . h($ev['endereco']) : '') . '</p>' : '')
    . '<p style="text-align:center;margin:28px 0"><a href="' . h($link) . '" style="background:' . $cor . ';color:#fff;padding:14px 26px;border-radius:999px;text-decoration:none;font-weight:bold">Ver meus ingressos (QR Code)</a></p>'
    . '<p style="font-size:13px;color:#666">Apresente o QR Code de cada ingresso na entrada. Guarde este e-mail.</p></div>'
    . '<p style="font-size:12px;color:#999;text-align:center">' . h($org) . '</p></div>';
  $rem = cfg('email_remetente', 'ingressos@comunidadebatistashalom.com.br');
  $cab = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . mb_encode_mimeheader($org, 'UTF-8') . " <$rem>\r\nReply-To: $rem\r\n";
  return @mail($p['email'], mb_encode_mimeheader('Seus ingressos: ' . $ev['nome'], 'UTF-8'), $html, $cab);
}

/* ---------------- check-in ---------------- */

function fazer_checkin(string $lido, int $evento_id, int $usuario_id): array {
  $codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', basename(trim($lido))) ?? '');
  $pdo = db();
  $st = $pdo->prepare('SELECT g.*, t.nome AS tipo_nome, p.status AS pedido_status, p.codigo AS pedido_codigo
                       FROM ingressos g JOIN tipos t ON t.id = g.tipo_id JOIN pedidos p ON p.id = g.pedido_id WHERE g.codigo = ?');
  $st->execute([$codigo]);
  $g = $st->fetch();
  $res = function (string $r, string $msg, ?array $g = null) use ($pdo, $codigo, $usuario_id, $evento_id) {
    $pdo->prepare('INSERT INTO checkins (ingresso_id, codigo_lido, usuario_id, evento_id, resultado) VALUES (?,?,?,?,?)')
        ->execute([$g['id'] ?? null, $codigo, $usuario_id, $evento_id, $r]);
    return ['resultado' => $r, 'mensagem' => $msg, 'ingresso' => $g ? [
      'codigo' => $g['codigo'], 'titular' => $g['titular'], 'tipo' => $g['tipo_nome'], 'pedido' => $g['pedido_codigo'], 'checkin_em' => $g['checkin_em'],
    ] : null];
  };
  if (!$g) return $res('invalido', 'Ingresso não encontrado.');
  if ((int)$g['evento_id'] !== $evento_id) return $res('outro_evento', 'Este ingresso é de outro evento.', $g);
  if ($g['status'] !== 'valido' || $g['pedido_status'] !== 'pago') return $res('cancelado', 'Ingresso cancelado ou estornado.', $g);
  $up = $pdo->prepare('UPDATE ingressos SET checkin_em = ?, checkin_por = ? WHERE id = ? AND checkin_em IS NULL');
  $up->execute([agora(), $usuario_id, $g['id']]);
  if ($up->rowCount() === 0) return $res('ja_usado', 'Este ingresso já entrou às ' . date('H:i \d\e d/m', strtotime((string)$g['checkin_em'])) . '.', $g);
  $g['checkin_em'] = agora();
  return $res('ok', 'Entrada liberada!', $g);
}
