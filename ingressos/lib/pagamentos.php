<?php
/**
 * Pagamentos: cálculo de taxas e "motoristas" de cada banco/intermediador.
 *
 * Cada evento escolhe uma conta para PIX e outra para cartão (podem ser a mesma).
 * O comprador vê uma única tela; o roteamento acontece só aqui no servidor.
 *
 * Provedores:
 *   sandbox      -> simulador interno, para testar tudo sem dinheiro de verdade
 *   mercadopago  -> PIX e cartão (checkout transparente, cartão tokenizado no navegador)
 * Novos bancos entram criando outra classe que implementa Provedor.
 */
declare(strict_types=1);

require_once __DIR__ . '/boot.php';

const PROVEDORES = [
  'sandbox'     => ['nome' => 'Simulador (testes)', 'pix' => true, 'cartao' => true],
  'mercadopago' => ['nome' => 'Mercado Pago',       'pix' => true, 'cartao' => true],
];

/* ---------------- taxas ---------------- */

/** percentual efetivo do cartão para N parcelas */
function pct_cartao(array $conta, int $parcelas): float {
  return (float)$conta['taxa_cartao_pct'] + (float)$conta['taxa_parcela_pct'] * max(0, $parcelas - 1);
}

/**
 * Valor final de uma cobrança.
 * repassar: o comprador paga a taxa (o valor sobe para que, depois da taxa do banco, a igreja receba "base").
 * absorver: o comprador paga só "base" e a igreja arca com a taxa.
 * Retorna [valor_cobrado, taxa_para_o_cliente, custo_estimado_do_banco].
 */
function calcular_cobranca(int $base, float $pct, int $fixa, string $modo): array {
  if ($base <= 0) return [0, 0, 0];
  if ($modo === 'repassar') {
    $valor = (int)ceil(($base + $fixa) / (1 - min($pct, 50) / 100));
    $custo = (int)round($valor * $pct / 100) + $fixa;
    return [$valor, $valor - $base, $custo];
  }
  $custo = (int)round($base * $pct / 100) + $fixa;
  return [$base, 0, $custo];
}

function conta(int $id): ?array {
  $st = db()->prepare('SELECT * FROM contas WHERE id = ? AND ativo = 1');
  $st->execute([$id]);
  $c = $st->fetch();
  if (!$c) return null;
  $c['credenciais'] = json_decode($c['credenciais'] ?: '{}', true) ?: [];
  return $c;
}

function provedor(string $nome): Provedor {
  return match ($nome) {
    'sandbox'     => new ProvedorSandbox(),
    'mercadopago' => new ProvedorMercadoPago(),
    default       => throw new RuntimeException('Provedor de pagamento desconhecido: ' . $nome),
  };
}

/** dados públicos que o navegador precisa para tokenizar o cartão (sem segredos) */
function config_publica_cartao(?array $conta): array {
  if (!$conta) return ['tipo' => 'nenhum'];
  return match ($conta['provedor']) {
    'mercadopago' => ['tipo' => 'mercadopago', 'chave' => (string)($conta['credenciais']['public_key'] ?? '')],
    default       => ['tipo' => 'sandbox'],
  };
}

interface Provedor {
  /** @return array{ref:string, copia_cola:string, qr_img:string, status:string} */
  public function pix(array $conta, array $pedido, int $valor, int $minutos): array;
  /** @return array{ref:string, status:string, mensagem:string, final:string, bandeira:string} */
  public function cartao(array $conta, array $pedido, int $valor, int $parcelas, array $cartao): array;
  public function estornar(array $conta, array $pagamento): bool;
  /** status atual no banco: pendente|aprovado|recusado|estornado|expirado ou null se não souber */
  public function consultar(array $conta, array $pagamento): ?string;
  /** lê o aviso do banco e devolve a lista de [ref, status] para atualizar */
  public function webhook(array $conta, string $corpo, array $query): array;
}

/* ================= SIMULADOR (sandbox) ================= */

final class ProvedorSandbox implements Provedor {
  public function pix(array $conta, array $pedido, int $valor, int $minutos): array {
    $ref = 'SBX-PIX-' . codigo_aleatorio(12);
    return ['ref' => $ref, 'copia_cola' => brcode_teste($valor, $ref), 'qr_img' => '', 'status' => 'pendente'];
  }

  /**
   * No simulador o navegador nunca manda o número do cartão: manda um token "sbx_..." com
   * os 4 últimos dígitos, a bandeira e o resultado esperado (aprovado/recusado), como um banco real faria.
   */
  public function cartao(array $conta, array $pedido, int $valor, int $parcelas, array $cartao): array {
    $tok = (string)($cartao['token'] ?? '');
    if (!str_starts_with($tok, 'sbx_')) return ['ref' => '', 'status' => 'recusado', 'mensagem' => 'Cartão inválido.', 'final' => '', 'bandeira' => ''];
    $d = json_decode((string)base64_decode(strtr(substr($tok, 4), '-_', '+/')), true) ?: [];
    $ok = ($d['resultado'] ?? '') === 'aprovado';
    return [
      'ref' => 'SBX-CC-' . codigo_aleatorio(12),
      'status' => $ok ? 'aprovado' : 'recusado',
      'mensagem' => $ok ? 'Pagamento aprovado.' : 'Pagamento recusado pelo banco emissor (simulação).',
      'final' => substr(so_digitos((string)($d['final'] ?? '')), -4),
      'bandeira' => substr(preg_replace('/[^a-z]/', '', strtolower((string)($d['bandeira'] ?? ''))), 0, 20),
    ];
  }

  public function estornar(array $conta, array $pagamento): bool { return true; }
  public function consultar(array $conta, array $pagamento): ?string { return null; }
  public function webhook(array $conta, string $corpo, array $query): array {
    $d = json_decode($corpo, true) ?: [];
    return isset($d['ref'], $d['status']) ? [[(string)$d['ref'], (string)$d['status']]] : [];
  }
}

/** gera um "copia e cola" PIX no formato oficial (BR Code), marcado como teste */
function brcode_teste(int $valor, string $ref): string {
  $campo = fn(string $id, string $v) => $id . str_pad((string)strlen($v), 2, '0', STR_PAD_LEFT) . $v;
  $conta = $campo('00', 'br.gov.bcb.pix') . $campo('01', 'teste@sandbox.invalid');
  $txid = substr(preg_replace('/[^A-Za-z0-9]/', '', $ref), 0, 25);
  $p = $campo('00', '01') . $campo('26', $conta) . $campo('52', '0000') . $campo('53', '986')
     . $campo('54', number_format($valor / 100, 2, '.', '')) . $campo('58', 'BR')
     . $campo('59', 'SANDBOX SHALOM') . $campo('60', 'UMUARAMA') . $campo('62', $campo('05', $txid)) . '6304';
  return $p . crc16($p);
}

function crc16(string $s): string {
  /* CRC16-CCITT (polinômio 0x1021, início 0xFFFF), exigido pelo padrão PIX do Banco Central */
  $crc = 0xFFFF;
  $n = strlen($s);
  for ($i = 0; $i < $n; $i++) {
    $crc = ($crc ^ (ord($s[$i]) << 8)) & 0xFFFF;
    for ($b = 0; $b < 8; $b++) {
      $crc = ($crc & 0x8000) !== 0 ? ((($crc << 1) & 0xFFFF) ^ 0x1021) : (($crc << 1) & 0xFFFF);
    }
  }
  return sprintf('%04X', $crc);
}

/* ================= MERCADO PAGO ================= */
/* Credenciais (no painel): access_token (secreto, só servidor) e public_key (usada no navegador para tokenizar o cartão).
   Use as credenciais "de teste" do Mercado Pago para testar e as "de produção" quando for vender. */

final class ProvedorMercadoPago implements Provedor {
  private function req(array $conta, string $metodo, string $caminho, ?array $corpo = null, ?string $idem = null): array {
    $token = (string)($conta['credenciais']['access_token'] ?? '');
    if ($token === '') throw new RuntimeException('Conta Mercado Pago sem access_token.');
    $h = ['Authorization: Bearer ' . $token, 'Content-Type: application/json'];
    if ($idem) $h[] = 'X-Idempotency-Key: ' . $idem;
    $ch = curl_init('https://api.mercadopago.com' . $caminho);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 30]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    $res = curl_exec($ch);
    $cod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $d = json_decode((string)$res, true) ?: [];
    if ($cod >= 400) throw new RuntimeException('Mercado Pago: ' . ($d['message'] ?? ('erro HTTP ' . $cod)));
    return $d;
  }

  private static function status(string $s): string {
    return match ($s) {
      'approved', 'authorized' => 'aprovado',
      'rejected' => 'recusado',
      'refunded', 'charged_back' => 'estornado',
      'cancelled' => 'expirado',
      default => 'pendente',
    };
  }

  private function pagador(array $pedido): array {
    $p = ['email' => $pedido['email'], 'first_name' => explode(' ', trim($pedido['nome']))[0]];
    if ($pedido['cpf'] !== '') $p['identification'] = ['type' => 'CPF', 'number' => so_digitos($pedido['cpf'])];
    return $p;
  }

  public function pix(array $conta, array $pedido, int $valor, int $minutos): array {
    $d = $this->req($conta, 'POST', '/v1/payments', [
      'transaction_amount' => round($valor / 100, 2),
      'description' => 'Ingressos pedido ' . $pedido['codigo'],
      'payment_method_id' => 'pix',
      'payer' => $this->pagador($pedido),
      'external_reference' => $pedido['codigo'],
      'date_of_expiration' => date('Y-m-d\TH:i:s.000P', time() + $minutos * 60),
      'notification_url' => url_base() . '/ingressos/api/webhook.php?conta=' . $conta['id'],
    ], 'pix-' . $pedido['codigo']);
    $t = $d['point_of_interaction']['transaction_data'] ?? [];
    return [
      'ref' => (string)($d['id'] ?? ''),
      'copia_cola' => (string)($t['qr_code'] ?? ''),
      'qr_img' => isset($t['qr_code_base64']) ? 'data:image/png;base64,' . $t['qr_code_base64'] : '',
      'status' => self::status((string)($d['status'] ?? '')),
    ];
  }

  public function cartao(array $conta, array $pedido, int $valor, int $parcelas, array $cartao): array {
    $d = $this->req($conta, 'POST', '/v1/payments', [
      'transaction_amount' => round($valor / 100, 2),
      'token' => (string)($cartao['token'] ?? ''),
      'installments' => $parcelas,
      'payment_method_id' => (string)($cartao['bandeira'] ?? ''),
      'description' => 'Ingressos pedido ' . $pedido['codigo'],
      'payer' => ['email' => $pedido['email']] + ($pedido['cpf'] !== '' ? ['identification' => ['type' => 'CPF', 'number' => so_digitos($pedido['cpf'])]] : []),
      'external_reference' => $pedido['codigo'],
      'statement_descriptor' => 'SHALOM INGRESSOS',
      'notification_url' => url_base() . '/ingressos/api/webhook.php?conta=' . $conta['id'],
    ], 'cc-' . $pedido['codigo'] . '-' . substr(hash('sha256', (string)($cartao['token'] ?? '')), 0, 12));
    $st = self::status((string)($d['status'] ?? ''));
    return [
      'ref' => (string)($d['id'] ?? ''),
      'status' => $st,
      'mensagem' => $st === 'aprovado' ? 'Pagamento aprovado.' : ($st === 'recusado' ? 'Pagamento recusado pelo banco do cartão.' : 'Pagamento em análise.'),
      'final' => (string)($d['card']['last_four_digits'] ?? ($cartao['final'] ?? '')),
      'bandeira' => (string)($d['payment_method_id'] ?? ($cartao['bandeira'] ?? '')),
    ];
  }

  public function estornar(array $conta, array $pagamento): bool {
    $this->req($conta, 'POST', '/v1/payments/' . rawurlencode($pagamento['ref']) . '/refunds', [], 'refund-' . $pagamento['ref']);
    return true;
  }

  public function consultar(array $conta, array $pagamento): ?string {
    $d = $this->req($conta, 'GET', '/v1/payments/' . rawurlencode($pagamento['ref']));
    return self::status((string)($d['status'] ?? ''));
  }

  public function webhook(array $conta, string $corpo, array $query): array {
    $d = json_decode($corpo, true) ?: [];
    $id = (string)($d['data']['id'] ?? ($query['data_id'] ?? ($query['id'] ?? '')));
    if ($id === '' || !preg_match('/^\d+$/', $id)) return [];
    /* não confia no aviso: confirma o status direto na API do Mercado Pago */
    $p = $this->req($conta, 'GET', '/v1/payments/' . $id);
    return [[$id, self::status((string)($p['status'] ?? ''))]];
  }
}
