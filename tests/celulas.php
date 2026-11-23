<?php
/**
 * Testes do painel antigo de células (admin.html + api/): primeiro acesso, login,
 * troca de senha e bloqueio da senha antiga que ficou pública.
 * Uso: SHALOM_TESTE=1 SHALOM_DB=/tmp/s.db php -S 127.0.0.1:8099 tests/router.php &   e depois   php tests/celulas.php
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
  private string $jar;
  public function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'cj'); }
  public function req(string $metodo, string $caminho, ?array $corpo = null): array {
    $ch = curl_init(BASE . $caminho);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $metodo,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 20]);
    if ($corpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo));
    $res = (string)curl_exec($ch);
    $cod = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($res, true);
    return [$cod, is_array($j) ? $j : ['_bruto' => $res]];
  }
  public function get(string $c) { return $this->req('GET', $c); }
  public function post(string $c, array $b = []) { return $this->req('POST', $c, $b); }
}

const AUTH = '/api/auth.php?acao=';
const LISTA_ADMIN = '/api/celulas.php?todas=1';
$celula = ['nome' => 'Célula Teste', 'tipo' => 'vox', 'lider' => 'Fulano', 'dia' => 'Quarta', 'horario' => '20:00', 'lat' => -23.76, 'lng' => -53.32, 'ativo' => true];

echo "\n== Código: nenhuma senha fixa ==\n";
$fonte = file_get_contents(__DIR__ . '/../api/db.php');
ok(!preg_match('/ADMIN_(USER|PASS)\b/', $fonte), 'api/db.php não define usuário/senha fixos');
ok(!preg_match('/INSERT INTO usuarios/i', $fonte), 'api/db.php não cria usuário sozinho');

echo "\n== Primeiro acesso ==\n";
$a = new Cliente();
[$c, $r] = $a->get(AUTH . 'me');
ok($c === 200 && $r['autenticado'] === false && $r['instalado'] === false, 'banco novo: sem usuário, painel pede instalação', $r);
[$c] = $a->get(LISTA_ADMIN);
ok($c === 401, 'lista de admin exige login', $c);
[$c] = $a->get('/api/celulas.php');
ok($c === 200, 'lista pública continua aberta', $c);
[$c, $r] = $a->post(AUTH . 'instalar', ['codigo' => 'errado', 'usuario' => 'admin', 'senha' => 'senha-forte-123']);
ok($c === 403, 'código de instalação errado é recusado', $r);
[$c, $r] = $a->post(AUTH . 'instalar', ['codigo' => 'TESTE-CODIGO', 'usuario' => 'admin', 'senha' => 'curta']);
ok($c === 400, 'senha curta é recusada', $r);
[$c, $r] = $a->post(AUTH . 'instalar', ['codigo' => 'TESTE-CODIGO', 'usuario' => 'admin', 'senha' => 'senha-forte-123']);
ok($c === 200 && ($r['ok'] ?? false), 'instala com o código certo', $r);
[$c, $r] = $a->get(AUTH . 'me');
ok(($r['autenticado'] ?? false) && $r['usuario'] === 'admin' && !$r['trocar_senha'], 'já entra logado depois de instalar', $r);
[$c, $r] = $a->post('/api/celulas.php', $celula);
ok($c === 201, 'logado: cadastra célula', $r);
[$c, $r] = (new Cliente())->post(AUTH . 'instalar', ['codigo' => 'TESTE-CODIGO', 'usuario' => 'outro', 'senha' => 'senha-forte-123']);
ok($c === 403, 'não instala de novo quando já há usuário', $r);

echo "\n== Login e troca de senha ==\n";
$b = new Cliente();
[$c] = $b->post(AUTH . 'login', ['usuario' => 'admin', 'senha' => 'errada-errada']);
ok($c === 401, 'senha errada não entra', $c);
[$c, $r] = $b->post(AUTH . 'login', ['usuario' => 'admin', 'senha' => 'senha-forte-123']);
ok($c === 200 && $r['trocar_senha'] === false, 'login normal', $r);
[$c] = $a->post(AUTH . 'senha', ['atual' => 'nao-e-essa-123', 'nova' => 'outra-senha-456']);
ok($c === 400, 'troca exige a senha atual correta', $c);
[$c] = $a->post(AUTH . 'senha', ['atual' => 'senha-forte-123', 'nova' => 'curta']);
ok($c === 400, 'troca recusa senha nova curta', $c);
[$c, $r] = $a->post(AUTH . 'senha', ['atual' => 'senha-forte-123', 'nova' => 'outra-senha-456']);
ok($c === 200, 'troca a senha', $r);
[$c] = $a->get(LISTA_ADMIN);
ok($c === 200, 'quem trocou continua logado', $c);
[$c] = $b->get(LISTA_ADMIN);
ok($c === 401, 'a outra sessão aberta cai depois da troca', $c);
[$c] = (new Cliente())->post(AUTH . 'login', ['usuario' => 'admin', 'senha' => 'senha-forte-123']);
ok($c === 401, 'senha anterior não entra mais', $c);
[$c] = (new Cliente())->post(AUTH . 'login', ['usuario' => 'admin', 'senha' => 'outra-senha-456']);
ok($c === 200, 'senha nova entra', $c);
[$c] = (new Cliente())->post(AUTH . 'senha', ['atual' => 'outra-senha-456', 'nova' => 'mais-uma-789']);
ok($c === 401, 'trocar senha exige login', $c);

echo "\n== Banco antigo com a senha que ficou pública ==\n";
$vazada = 'jesus' . 'teama';
$pdo = new PDO('sqlite:' . getenv('SHALOM_DB'));
$pdo->prepare('UPDATE usuarios SET usuario = ?, senha_hash = ?')->execute(['shalomadmin', password_hash($vazada, PASSWORD_DEFAULT)]);
$v = new Cliente();
[$c, $r] = $v->post(AUTH . 'login', ['usuario' => 'shalomadmin', 'senha' => $vazada]);
ok($c === 200 && $r['trocar_senha'] === true, 'entra, mas marcado para trocar a senha', $r);
[$c, $r] = $v->get(LISTA_ADMIN);
ok($c === 403 && ($r['trocar_senha'] ?? false), 'não vê a lista de admin antes de trocar', $r);
[$c] = $v->post('/api/celulas.php', $celula);
ok($c === 403, 'não cadastra célula antes de trocar', $c);
[$c] = $v->post('/api/sede.php', ['nome' => 'X', 'endereco' => 'Y', 'lat' => -23, 'lng' => -53]);
ok($c === 403, 'não altera a sede antes de trocar', $c);
[$c, $r] = $v->post(AUTH . 'senha', ['atual' => $vazada, 'nova' => 'senha-nova-segura']);
ok($c === 403 && ($r['precisa_codigo'] ?? false), 'troca da senha pública exige o código de instalação', $r);
[$c] = $v->post(AUTH . 'senha', ['atual' => $vazada, 'nova' => 'senha-nova-segura', 'codigo' => 'errado']);
ok($c === 403, 'código errado não troca', $c);
[$c] = $v->post(AUTH . 'senha', ['atual' => $vazada, 'nova' => $vazada, 'codigo' => 'TESTE-CODIGO']);
ok($c === 400, 'não aceita a senha pública como senha nova', $c);
[$c, $r] = $v->post(AUTH . 'senha', ['atual' => $vazada, 'nova' => 'senha-nova-segura', 'codigo' => 'TESTE-CODIGO']);
ok($c === 200, 'troca com o código certo', $r);
[$c, $r] = $v->get(AUTH . 'me');
ok(($r['trocar_senha'] ?? true) === false, 'marca de troca obrigatória some', $r);
[$c, $r] = $v->get(LISTA_ADMIN);
ok($c === 200 && count($r) === 1, 'painel liberado e a célula cadastrada continua lá', $r);
[$c] = (new Cliente())->post(AUTH . 'login', ['usuario' => 'shalomadmin', 'senha' => $vazada]);
ok($c === 401, 'senha pública não entra mais', $c);

echo "\n== Proteções ==\n";
[$c] = (new Cliente())->get('/data/shalom.db');
ok($c === 403, 'pasta data/ bloqueada', $c);
[$c, $r] = $v->post(AUTH . 'logout');
[$c] = $v->get(LISTA_ADMIN);
ok($c === 401, 'logout encerra a sessão', $c);
$x = new Cliente();
for ($i = 0; $i < 10; $i++) $x->post(AUTH . 'login', ['usuario' => 'shalomadmin', 'senha' => 'chute-' . $i]);
[$c] = $x->post(AUTH . 'login', ['usuario' => 'shalomadmin', 'senha' => 'senha-nova-segura']);
ok($c === 429, 'bloqueia depois de muitas tentativas erradas', $c);

/* deixa o banco como o do servidor hoje (senha pública), para o teste visual da troca obrigatória */
$pdo->prepare('UPDATE usuarios SET senha_hash = ?')->execute([password_hash($vazada, PASSWORD_DEFAULT)]);
$pdo->exec('DELETE FROM tentativas');

echo "\n$total verificações, " . ($total - $falhas) . " ok, $falhas falha(s).\n";
exit($falhas ? 1 : 0);
