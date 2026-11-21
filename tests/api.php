<?php
/**
 * Testes de ponta a ponta do sistema de ingressos (rodam no GitHub Actions).
 * Uso: INGRESSOS_TESTE=1 INGRESSOS_DB=/tmp/t.db php -S 127.0.0.1:8099 tests/router.php &   e depois   php tests/api.php
 */
declare(strict_types=1);

const BASE = 'http://127.0.0.1:8099';
$falhas = 0;
$total = 0;

function ok(bool $cond, string $nome, $extra = null): void {
  global $falhas, $total;
  $total++;
  if ($cond) { echo "  ✔ $nome\n"; return; }
  $falhas++;
  echo "  ✘ $nome" . ($extra !== null ? ' → ' . (is_string($extra) ? $extra : json_encode($extra, JSON_UNESCAPED_UNICODE)) : '') . "\n";
}

final class Cliente {
  public ?string $csrf = null;
  private string $jar;
  public function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'cj'); }
  public function req(string $metodo, string $caminho, ?array $corpo = null, bool $csrf = true): array {
    $ch = curl_init(BASE . $caminho);
    $h = ['Content-Type: application/json'];
    if ($csrf && $this->csrf) $h[] = 'X-CSRF: ' . $this->csrf;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_HTTPHEADER => $h,
      CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo));
    $res = (string)curl_exec($ch);
    $cod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($res, true);
    return [$cod, is_array($j) ? $j : ['_bruto' => $res]];
  }
  public function get(string $c) { return $this->req('GET', $c); }
  public function post(string $c, array $b = [], bool $csrf = true) { return $this->req('POST', $c, $b, $csrf); }
}

function tokenSbx(string $numero): string {
  $d = ['final' => substr($numero, -4), 'bandeira' => 'visa', 'resultado' => str_ends_with($numero, '0002') ? 'recusado' : 'aprovado'];
  return 'sbx_' . rtrim(strtr(base64_encode(json_encode($d)), '+/', '-_'), '=');
}
function crc_ok(string $pix): bool {
  $corpo = substr($pix, 0, -4);
  $crc = 0xFFFF;
  for ($i = 0; $i < strlen($corpo); $i++) { $crc ^= ord($corpo[$i]) << 8; for ($b = 0; $b < 8; $b++) $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1); $crc &= 0xFFFF; }
  return strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT)) === substr($pix, -4);
}

$adm = new Cliente();
$pub = new Cliente();
$chk = new Cliente();
$comprador = ['nome' => 'Maria Teste da Silva', 'email' => 'maria@exemplo.com', 'telefone' => '(44) 99999-1234', 'cpf' => '529.982.247-25'];

echo "\n== Instalação e login ==\n";
[$c, $r] = $adm->get('/ingressos/api/auth.php?acao=me');
ok($c === 200 && $r['instalado'] === false, 'sistema começa sem usuários', $r);
[$c] = $adm->post('/ingressos/api/auth.php?acao=instalar', ['codigo' => 'ERRADO', 'nome' => 'X', 'email' => 'x@x.com', 'senha' => '12345678']);
ok($c === 403, 'código de instalação errado é recusado', $c);
[$c, $r] = $adm->post('/ingressos/api/auth.php?acao=instalar', ['codigo' => 'TESTE-CODIGO', 'nome' => 'Admin Teste', 'email' => 'admin@teste.com', 'senha' => 'senhaforte1']);
ok($c === 200, 'instala com o código certo', $r);
[$c] = $adm->post('/ingressos/api/auth.php?acao=instalar', ['codigo' => 'TESTE-CODIGO', 'nome' => 'Outro', 'email' => 'o@o.com', 'senha' => 'senhaforte1']);
ok($c === 403, 'não instala duas vezes', $c);
[$c] = $adm->post('/ingressos/api/auth.php?acao=login', ['email' => 'admin@teste.com', 'senha' => 'errada']);
ok($c === 401, 'senha errada não entra', $c);
[$c, $r] = $adm->post('/ingressos/api/auth.php?acao=login', ['email' => 'admin@teste.com', 'senha' => 'senhaforte1']);
ok($c === 200 && !empty($r['csrf']), 'login do admin', $r);
$adm->csrf = $r['csrf'] ?? null;
[$c] = $adm->post('/ingressos/api/admin.php?acao=evento_excluir', ['id' => 999], false);
ok($c === 419, 'POST sem token CSRF é bloqueado', $c);
[$c] = $pub->get('/ingressos/api/admin.php?acao=resumo');
ok($c === 401, 'painel exige login', $c);

echo "\n== Evento Koinonia (rascunho → publicado) ==\n";
[$c, $ev] = $adm->get('/ingressos/api/admin.php?acao=evento&id=1');
ok($c === 200 && $ev['slug'] === 'koinonia' && $ev['status'] === 'rascunho', 'Koinonia já vem cadastrado como rascunho', $ev);
[$c] = $pub->get('/ingressos/api/publico.php?acao=evento&slug=koinonia');
ok($c === 404, 'rascunho não aparece para o público', $c);
$tipos = $ev['tipos'];
$salvar = [
  'id' => 1, 'nome' => 'Koinonia', 'slug' => 'koinonia', 'status' => 'publicado', 'descricao' => 'Teste', 'local_nome' => 'Shalom', 'endereco' => 'Umuarama',
  'inicio' => '2027-02-06T19:00', 'fim' => '2027-02-09T22:00', 'imagem' => '/assets/koinonia-capa.webp', 'cor' => '#fb5e01', 'lotacao' => 12, 'limite_por_pedido' => 10,
  'taxa_modo' => 'repassar', 'aceita_pix' => true, 'aceita_cartao' => true, 'dois_cartoes' => true, 'max_parcelas' => 3, 'pix_conta_id' => 1, 'cartao_conta_id' => 1,
  'pix_minutos' => 30, 'pedir_cpf' => true,
  'tipos' => [
    ['id' => $tipos[0]['id'], 'nome' => 'Individual', 'preco' => '120,00', 'quantidade' => 3, 'pessoas' => 1, 'max_por_pedido' => 10, 'ativo' => true],
    ['id' => $tipos[1]['id'], 'nome' => 'Dupla', 'preco' => '220,00', 'quantidade' => 2, 'pessoas' => 2, 'max_por_pedido' => 10, 'ativo' => true],
    ['id' => $tipos[2]['id'], 'nome' => 'Célula', 'preco' => '500,00', 'quantidade' => 0, 'pessoas' => 5, 'max_por_pedido' => 10, 'ativo' => true],
  ],
  'cupons' => [['codigo' => 'promo10', 'tipo' => 'pct', 'valor' => 10, 'usos_max' => 1, 'ativo' => true]],
];
[$c, $r] = $adm->post('/ingressos/api/admin.php?acao=evento_salvar', $salvar);
ok($c === 200 && str_ends_with($r['link'] ?? '', '/e/koinonia'), 'salva e publica o evento, gera o link /e/koinonia', $r);
[$c, $ev] = $adm->get('/ingressos/api/admin.php?acao=evento&id=1');
$T = array_column($ev['tipos'], 'id', 'nome');
ok((int)$ev['tipos'][0]['preco'] === 12000 && count($ev['cupons']) === 1 && $ev['cupons'][0]['codigo'] === 'PROMO10', 'preços em centavos e cupom salvos', $ev['tipos'][0]);

[$c, $pe] = $pub->get('/ingressos/api/publico.php?acao=evento&slug=koinonia');
ok($c === 200 && $pe['vendas'] === 'abertas' && count($pe['tipos']) === 3, 'público vê o evento com vendas abertas', $pe);
ok($pe['pagamento']['pix'] && $pe['pagamento']['cartao'] && $pe['pagamento']['teste'], 'PIX e cartão disponíveis (modo teste)', $pe['pagamento']);
ok(!str_contains(json_encode($pe), 'sandbox') || $pe['pagamento']['tokenizacao']['tipo'] === 'sandbox', 'resposta pública não expõe dados da conta além do tipo de tokenização');
ok(!isset($pe['pagamento']['conta']) && !str_contains(json_encode($pe), 'taxa_cartao'), 'comprador não vê qual banco nem as taxas internas');

echo "\n== Cotação e taxas ==\n";
[$c, $q] = $pub->post('/ingressos/api/publico.php?acao=cotar', ['slug' => 'koinonia', 'itens' => [$T['Individual'] => 1], 'metodo' => 'pix']);
ok($c === 200 && $q['total'] === 12000 && $q['taxa'] === 0, 'PIX sem taxa: total R$ 120,00', $q);
[$c, $q] = $pub->post('/ingressos/api/publico.php?acao=cotar', ['slug' => 'koinonia', 'itens' => [$T['Individual'] => 1], 'metodo' => 'cartao', 'cartoes' => [['parcelas' => 1]]]);
ok($c === 200 && $q['total'] === 12499 && $q['taxa'] === 499, 'cartão 1x repassa 3,99%: R$ 124,99', $q);
ok(count($q['opcoes'][0]) === 3 && $q['opcoes'][0][2]['total'] === 12902, 'opções de parcelamento até 3x (3x = R$ 129,02)', $q['opcoes'] ?? null);
[$c, $q] = $pub->post('/ingressos/api/publico.php?acao=cotar', ['slug' => 'koinonia', 'itens' => [$T['Individual'] => 1], 'cupom' => 'PROMO10', 'metodo' => 'pix']);
ok($c === 200 && $q['desconto'] === 1200 && $q['total'] === 10800, 'cupom PROMO10 dá 10%', $q);
[$c, $q] = $pub->post('/ingressos/api/publico.php?acao=cotar', ['slug' => 'koinonia', 'itens' => [$T['Individual'] => 1], 'cupom' => 'NAOEXISTE', 'metodo' => 'pix']);
ok($c === 422, 'cupom inválido é recusado', $q);
[$c, $q] = $pub->post('/ingressos/api/publico.php?acao=cotar', ['slug' => 'koinonia', 'itens' => [$T['Dupla'] => 1], 'metodo' => 'dois_cartoes', 'cartoes' => [['parcelas' => 1, 'base' => 10000], ['parcelas' => 2]]]);
ok($c === 200 && count($q['cobrancas']) === 2 && $q['cobrancas'][0]['base'] === 10000 && $q['cobrancas'][1]['base'] === 12000, 'dois cartões dividem R$ 220 em 100 + 120', $q);

echo "\n== Compra com PIX ==\n";
[$c, $r] = $pub->post('/ingressos/api/publico.php?acao=comprar', ['slug' => 'koinonia', 'itens' => [$T['Individual'] => 1], 'metodo' => 'pix'] + $comprador);
ok($c === 200 && $r['status'] === 'pendente' && str_starts_with($r['pix']['copia_cola'] ?? '', '000201'), 'gera PIX pendente com copia e cola', $r);
ok(crc_ok($r['pix']['copia_cola'] ?? '0000'), 'copia e cola com CRC válido');
$pix1 = $r;
[$c, $r] = $pub->post('/ingressos/api/publico.php?acao=comprar', ['slug' => 'koinonia', 'itens' => [$T['Individual'] => 1], 'metodo' => 'pix'] + array_merge($comprador, ['cpf' => '111.111.111-11']));
ok($c === 422 && str_contains($r['erro'] ?? '', 'CPF'), 'CPF inválido é recusado', $r);
[$c, $r] = $pub->post('/ingressos/api/publico.php?acao=simular_pix', ['c' => $pix1['codigo'], 'a' => $pix1['acesso']]);
ok($c === 200 && $r['status'] === 'pago', 'pagamento do PIX confirmado (simulação)', $r);
[$c, $r] = $pub->get('/ingressos/api/publico.php?acao=status&c=' . $pix1['codigo'] . '&a=' . $pix1['acesso']);
ok($c === 200 && $r['status'] === 'pago', 'status do pedido fica "pago"', $r);
[$c] = $pub->get('/ingressos/api/publico.php?acao=status&c=' . $pix1['codigo'] . '&a=errado');
ok($c === 404, 'link do pedido com chave errada não abre', $c);

echo "\n== Cartão (um e dois) ==\n";
[$c, $r] = $pub->post('/ingressos/api/publico.php?acao=comprar', ['slug' => 'koinonia', 'itens' => [$T['Individual'] => 1], 'metodo' => 'cartao',
  'cartoes' => [['token' => tokenSbx('4000000000000002'), 'parcelas' => 1, 'final' => '0002', 'bandeira' => 'visa']]] + $comprador);
ok($c === 422 && str_contains($r['erro'] ?? '', 'recusado'), 'cartão recusado mostra erro e não cobra', $r);
[$c, $r] = $pub->post('/ingressos/api/publico.php?acao=comprar', ['slug' => 'koinonia', 'itens' => [$T['Individual'] => 1], 'metodo' => 'cartao',
  'cartoes' => [['token' => tokenSbx('4111111111111111'), 'parcelas' => 3, 'final' => '1111', 'bandeira' => 'visa']]] + $comprador);
ok($c === 200 && $r['status'] === 'pago' && $r['total'] === 12902 && $r['cartoes'][0]['parcelas'] === 3, 'cartão aprovado em 3x (R$ 129,02)', $r);
[$c, $r] = $pub->post('/ingressos/api/publico.php?acao=comprar', ['slug' => 'koinonia', 'itens' => [$T['Dupla'] => 1], 'metodo' => 'dois_cartoes',
  'cartoes' => [['token' => tokenSbx('4111111111111111'), 'parcelas' => 1, 'base' => 10000], ['token' => tokenSbx('5555555555554444'), 'parcelas' => 2]]] + $comprador);
ok($c === 200 && $r['status'] === 'pago' && count($r['cartoes']) === 2, 'Dupla paga com dois cartões', $r);
$dupla = $r;
[$c, $r] = $pub->post('/ingressos/api/publico.php?acao=comprar', ['slug' => 'koinonia', 'itens' => [$T['Dupla'] => 1], 'metodo' => 'dois_cartoes',
  'cartoes' => [['token' => tokenSbx('4111111111111111'), 'parcelas' => 1, 'base' => 10000], ['token' => tokenSbx('4000000000000002'), 'parcelas' => 1]]] + $comprador);
ok($c === 422 && str_contains($r['erro'] ?? '', '2º cartão'), 'se o 2º cartão falha, avisa qual foi', $r);
[$c, $lista] = $adm->get('/ingressos/api/admin.php?acao=pedidos&status=recusado');
$ultimo = $lista['pedidos'][0] ?? null;
[$c, $det] = $adm->get('/ingressos/api/admin.php?acao=pedido&id=' . ($ultimo['id'] ?? 0));
ok(($det['pagamentos'][0]['status'] ?? '') === 'estornado' && ($det['pagamentos'][1]['status'] ?? '') === 'recusado', '1º cartão é estornado automaticamente', $det['pagamentos'] ?? null);

echo "\n== Estoque e lotação ==\n";
[$c, $pe] = $pub->get('/ingressos/api/publico.php?acao=evento&slug=koinonia');
$ind = array_values(array_filter($pe['tipos'], fn($t) => $t['nome'] === 'Individual'))[0];
ok($ind['max'] === 1, 'Individual: restou 1 de 3 (recusados não ocupam vaga)', $ind);
[$c, $r] = $pub->post('/ingressos/api/publico.php?acao=comprar', ['slug' => 'koinonia', 'itens' => [$T['Individual'] => 2], 'metodo' => 'pix'] + $comprador);
ok($c === 422, 'não vende mais do que o disponível', $r);
[$c, $res] = $pub->post('/ingressos/api/publico.php?acao=comprar', ['slug' => 'koinonia', 'itens' => [$T['Individual'] => 1], 'metodo' => 'pix'] + $comprador);
ok($c === 200 && $res['status'] === 'pendente', 'reserva o último Individual com PIX pendente', $res);
[$c, $r] = $pub->post('/ingressos/api/publico.php?acao=comprar', ['slug' => 'koinonia', 'itens' => [$T['Individual'] => 1], 'metodo' => 'pix'] + $comprador);
ok($c === 422 && str_contains($r['erro'] ?? '', 'esgotou'), 'PIX pendente segura a vaga: esgotado', $r);
/* expira o PIX reservado direto no banco e confere que a vaga volta */
$pdo = new PDO('sqlite:' . getenv('INGRESSOS_DB'));
$pdo->prepare("UPDATE pedidos SET expira_em = '2000-01-01 00:00:00' WHERE codigo = ?")->execute([$res['codigo']]);
[$c, $pe] = $pub->get('/ingressos/api/publico.php?acao=evento&slug=koinonia');
$ind = array_values(array_filter($pe['tipos'], fn($t) => $t['nome'] === 'Individual'))[0];
ok($ind['max'] === 1 && !$ind['esgotado'], 'PIX vencido libera a vaga de volta', $ind);
[$c, $r] = $pub->get('/ingressos/api/publico.php?acao=status&c=' . $res['codigo'] . '&a=' . $res['acesso']);
ok($r['status'] === 'expirado', 'pedido vencido fica "expirado"', $r);
/* lotação 12 pessoas: já há 1+1+2 = 4; Célula (5 pessoas) cabe 1, não 2 */
$cel = array_values(array_filter($pe['tipos'], fn($t) => $t['nome'] === 'Célula'))[0];
ok($cel['max'] === 1, 'lotação total limita a Célula a 1 pacote (5 pessoas)', $cel);

echo "\n== Aviso do banco (webhook) com PIX pago depois do prazo ==\n";
$st = $pdo->prepare("SELECT g.ref FROM pagamentos g JOIN pedidos p ON p.id = g.pedido_id WHERE p.codigo = ?");
$st->execute([$res['codigo']]);
$ref = $st->fetchColumn();
[$c] = $pub->post('/ingressos/api/webhook.php?conta=1', ['ref' => $ref, 'status' => 'aprovado'], false);
[$c, $r] = $pub->get('/ingressos/api/publico.php?acao=status&c=' . $res['codigo'] . '&a=' . $res['acesso']);
ok($r['status'] === 'pago', 'webhook confirma pagamento mesmo após vencer', $r);

echo "\n== Cupom com limite de uso ==\n";
[$c, $r] = $pub->post('/ingressos/api/publico.php?acao=comprar', ['slug' => 'koinonia', 'itens' => [$T['Célula'] => 1], 'cupom' => 'promo10', 'metodo' => 'pix'] + $comprador);
ok($c === 200 && $r['total'] === 45000, 'Célula com cupom: R$ 450,00', $r);
[$c, $q] = $pub->post('/ingressos/api/publico.php?acao=cotar', ['slug' => 'koinonia', 'itens' => [$T['Dupla'] => 1], 'cupom' => 'PROMO10', 'metodo' => 'pix']);
ok($c === 422, 'cupom de uso único não vale de novo', $q);

echo "\n== Equipe e check-in ==\n";
[$c, $r] = $adm->post('/ingressos/api/admin.php?acao=usuario_salvar', ['nome' => 'Porta 1', 'email' => 'porta1@teste.com', 'papel' => 'checkin', 'senha' => 'portaporta', 'ativo' => true]);
ok($c === 200, 'cria usuário de check-in', $r);
[$c, $r] = $chk->post('/ingressos/api/auth.php?acao=login', ['email' => 'porta1@teste.com', 'senha' => 'portaporta']);
ok($c === 200, 'usuário de check-in faz login', $r);
$chk->csrf = $r['csrf'] ?? null;
[$c] = $chk->get('/ingressos/api/admin.php?acao=resumo');
ok($c === 403, 'check-in não acessa o painel administrativo', $c);
[$c, $evs] = $chk->get('/ingressos/api/checkin.php?acao=eventos');
ok($c === 200 && count($evs) >= 1, 'check-in lista eventos', $evs);
$codigo = $pdo->query("SELECT g.codigo FROM ingressos g JOIN pedidos p ON p.id = g.pedido_id WHERE p.codigo = '" . $pix1['codigo'] . "'")->fetchColumn();
[$c, $r] = $chk->post('/ingressos/api/checkin.php?acao=validar', ['evento_id' => 1, 'codigo' => $codigo]);
ok($r['resultado'] === 'ok', 'QR válido libera a entrada', $r);
[$c, $r] = $chk->post('/ingressos/api/checkin.php?acao=validar', ['evento_id' => 1, 'codigo' => strtolower($codigo)]);
ok($r['resultado'] === 'ja_usado', 'mesmo QR de novo: "já utilizado"', $r);
[$c, $r] = $chk->post('/ingressos/api/checkin.php?acao=validar', ['evento_id' => 1, 'codigo' => 'NAOEXISTE123']);
ok($r['resultado'] === 'invalido', 'código inexistente é recusado', $r);
[$c, $r] = $chk->get('/ingressos/api/checkin.php?acao=buscar&evento=1&q=Maria');
ok($c === 200 && count($r) >= 3, 'busca por nome encontra os ingressos', count($r));
[$c, $r] = $chk->get('/ingressos/api/checkin.php?acao=resumo&evento=1');
ok((int)$r['presentes'] === 1, 'contador de presentes', $r);

echo "\n== Ações do painel ==\n";
$dupla_id = (int)$pdo->query("SELECT id FROM pedidos WHERE codigo = '" . $dupla['codigo'] . "'")->fetchColumn();
[$c, $r] = $adm->post('/ingressos/api/admin.php?acao=pedido_acao', ['id' => $dupla_id, 'o' => 'estornar']);
ok($c === 200, 'estorna o pedido da Dupla', $r);
$cod_dupla = $pdo->query("SELECT codigo FROM ingressos WHERE pedido_id = $dupla_id")->fetchColumn();
[$c, $r] = $chk->post('/ingressos/api/checkin.php?acao=validar', ['evento_id' => 1, 'codigo' => $cod_dupla]);
ok($r['resultado'] === 'cancelado', 'ingresso estornado não entra', $r);
[$c, $r] = $adm->post('/ingressos/api/admin.php?acao=cortesia', ['evento_id' => 1, 'tipo_id' => $T['Individual'], 'qtd' => 2, 'nome' => 'Preletor Convidado', 'email' => '', 'tipo' => 'cortesia']);
ok($c === 200 && str_contains($r['link'] ?? '', '/ingressos/pedido.php'), 'emite cortesia com link', $r);
[$c, $r] = $adm->get('/ingressos/api/admin.php?acao=resumo');
$k = $r['eventos'][0] ?? [];
ok($c === 200 && (int)$k['ingressos'] > 0 && (int)$k['bruto'] > 0, 'resumo com vendas e ingressos', $k);
[$c, $r] = $adm->get('/ingressos/api/admin.php?acao=exportar&evento=1');
ok($c === 200 && str_contains($r['_bruto'] ?? '', 'Ingresso;Titular'), 'exporta participantes em CSV');
[$c, $r] = $adm->post('/ingressos/api/admin.php?acao=evento_duplicar', ['id' => 1]);
ok($c === 200 && $r['id'] > 1, 'duplica o evento', $r);
[$c, $nv] = $adm->get('/ingressos/api/admin.php?acao=evento&id=' . ($r['id'] ?? 0));
ok($nv['status'] === 'rascunho' && count($nv['tipos']) === 3 && $nv['slug'] === 'koinonia-copia', 'cópia vem em rascunho com os mesmos ingressos', $nv);
[$c, $r] = $adm->post('/ingressos/api/admin.php?acao=evento_excluir', ['id' => 1]);
ok($c === 400, 'não exclui evento com pedidos', $r);
[$c, $r] = $adm->post('/ingressos/api/admin.php?acao=conta_salvar', ['nome' => 'MP', 'provedor' => 'mercadopago', 'ambiente' => 'sandbox', 'aceita_pix' => false, 'aceita_cartao' => true,
  'taxa_cartao_pct' => '3,49', 'credenciais' => ['public_key' => 'TEST-pub', 'access_token' => 'TEST-segredo-123456']]);
ok($c === 200, 'cadastra conta Mercado Pago', $r);
[$c, $r] = $adm->get('/ingressos/api/admin.php?acao=contas');
$mp = array_values(array_filter($r['contas'], fn($x) => $x['provedor'] === 'mercadopago'))[0] ?? [];
ok(!str_contains(json_encode($mp), 'TEST-segredo') && str_ends_with($mp['credenciais']['access_token'] ?? '', '3456'), 'access token nunca volta inteiro para a tela', $mp);

echo "\n== Páginas ==\n";
foreach (['/e/koinonia' => 'Koinonia', '/painel' => 'Painel de ingressos', '/checkin' => 'Check-in', '/ingressos/pedido.php?c=' . $pix1['codigo'] . '&a=' . $pix1['acesso'] => 'window.PEDIDO'] as $u => $txt) {
  [$c, $r] = $pub->get($u);
  ok($c === 200 && str_contains($r['_bruto'] ?? '', $txt), "abre $u", $c);
}
[$c] = $pub->get('/e/nao-existe');
ok($c === 404, 'evento inexistente: 404', $c);

echo "\n$total verificações, " . ($total - $falhas) . " ok, $falhas falha(s).\n";
exit($falhas ? 1 : 0);
