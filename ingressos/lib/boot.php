<?php
/**
 * Sistema de ingressos da Comunidade Batista Shalom.
 * Núcleo: banco de dados, sessão, login, CSRF e utilitários.
 *
 * O repositório é público: NADA secreto fica no código.
 * Chaves de bancos, senhas e o segredo dos QR Codes ficam no banco (data/ingressos.db),
 * que é bloqueado para acesso web pelo data/.htaccess e não é enviado pelo deploy.
 */
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

const ING_VERSAO = '1.0.0';
/* sha256 do código de instalação (o código em si foi entregue ao responsável, fora do repositório) */
const ING_INSTALACAO_HASH = 'da0d43dd4d1363fff67dbeffad2efc48786a7e9a7dcfb4dc19400eed8d580677';

function ing_db_arquivo(): string {
  $env = getenv('INGRESSOS_DB');
  return $env ?: __DIR__ . '/../../data/ingressos.db';
}

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;
  $arq = ing_db_arquivo();
  if (!is_dir(dirname($arq))) @mkdir(dirname($arq), 0775, true);
  $pdo = new PDO('sqlite:' . $arq);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec('PRAGMA journal_mode = WAL');
  $pdo->exec('PRAGMA foreign_keys = ON');
  $pdo->exec('PRAGMA busy_timeout = 5000');
  ing_migrar($pdo);
  return $pdo;
}

function ing_migrar(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS config (chave TEXT PRIMARY KEY, valor TEXT)");
  $versao = (int)($pdo->query("SELECT valor FROM config WHERE chave='schema'")->fetchColumn() ?: 0);
  if ($versao >= 1) return;

  $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    senha_hash TEXT NOT NULL,
    papel TEXT NOT NULL CHECK (papel IN ('admin','checkin')),
    ativo INTEGER NOT NULL DEFAULT 1,
    ultimo_login TEXT,
    criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS tentativas_login (
    ip TEXT NOT NULL, em INTEGER NOT NULL
  )");

  /* contas de recebimento (bancos/intermediadores). O comprador nunca vê qual é usada. */
  $pdo->exec("CREATE TABLE IF NOT EXISTS contas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    provedor TEXT NOT NULL,
    ambiente TEXT NOT NULL DEFAULT 'sandbox' CHECK (ambiente IN ('sandbox','producao')),
    aceita_pix INTEGER NOT NULL DEFAULT 0,
    aceita_cartao INTEGER NOT NULL DEFAULT 0,
    credenciais TEXT NOT NULL DEFAULT '{}',
    taxa_pix_pct REAL NOT NULL DEFAULT 0,
    taxa_pix_fixa INTEGER NOT NULL DEFAULT 0,
    taxa_cartao_pct REAL NOT NULL DEFAULT 0,
    taxa_cartao_fixa INTEGER NOT NULL DEFAULT 0,
    taxa_parcela_pct REAL NOT NULL DEFAULT 0,
    ativo INTEGER NOT NULL DEFAULT 1,
    criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS eventos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    nome TEXT NOT NULL,
    descricao TEXT NOT NULL DEFAULT '',
    local_nome TEXT NOT NULL DEFAULT '',
    endereco TEXT NOT NULL DEFAULT '',
    inicio TEXT,
    fim TEXT,
    imagem TEXT NOT NULL DEFAULT '',
    cor TEXT NOT NULL DEFAULT '#055a85',
    status TEXT NOT NULL DEFAULT 'rascunho' CHECK (status IN ('rascunho','publicado','encerrado')),
    lotacao INTEGER NOT NULL DEFAULT 0,
    limite_por_pedido INTEGER NOT NULL DEFAULT 10,
    vendas_inicio TEXT,
    vendas_fim TEXT,
    taxa_modo TEXT NOT NULL DEFAULT 'repassar' CHECK (taxa_modo IN ('repassar','absorver')),
    aceita_pix INTEGER NOT NULL DEFAULT 1,
    aceita_cartao INTEGER NOT NULL DEFAULT 1,
    dois_cartoes INTEGER NOT NULL DEFAULT 1,
    max_parcelas INTEGER NOT NULL DEFAULT 1,
    pix_conta_id INTEGER REFERENCES contas(id),
    cartao_conta_id INTEGER REFERENCES contas(id),
    pix_minutos INTEGER NOT NULL DEFAULT 30,
    pedir_cpf INTEGER NOT NULL DEFAULT 1,
    criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS tipos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    nome TEXT NOT NULL,
    descricao TEXT NOT NULL DEFAULT '',
    preco INTEGER NOT NULL DEFAULT 0,
    quantidade INTEGER NOT NULL DEFAULT 0,
    pessoas INTEGER NOT NULL DEFAULT 1,
    max_por_pedido INTEGER NOT NULL DEFAULT 10,
    vendas_inicio TEXT,
    vendas_fim TEXT,
    ordem INTEGER NOT NULL DEFAULT 0,
    ativo INTEGER NOT NULL DEFAULT 1
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS cupons (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    evento_id INTEGER NOT NULL REFERENCES eventos(id) ON DELETE CASCADE,
    codigo TEXT NOT NULL COLLATE NOCASE,
    tipo TEXT NOT NULL CHECK (tipo IN ('pct','valor')),
    valor INTEGER NOT NULL,
    usos_max INTEGER NOT NULL DEFAULT 0,
    ativo INTEGER NOT NULL DEFAULT 1,
    UNIQUE (evento_id, codigo)
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS pedidos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo TEXT NOT NULL UNIQUE,
    acesso TEXT NOT NULL,
    evento_id INTEGER NOT NULL REFERENCES eventos(id),
    nome TEXT NOT NULL,
    email TEXT NOT NULL,
    telefone TEXT NOT NULL DEFAULT '',
    cpf TEXT NOT NULL DEFAULT '',
    subtotal INTEGER NOT NULL,
    desconto INTEGER NOT NULL DEFAULT 0,
    taxa_cliente INTEGER NOT NULL DEFAULT 0,
    total INTEGER NOT NULL,
    custo_taxas INTEGER NOT NULL DEFAULT 0,
    metodo TEXT NOT NULL CHECK (metodo IN ('pix','cartao','dois_cartoes','cortesia','manual')),
    status TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente','pago','expirado','cancelado','estornado','recusado')),
    cupom_id INTEGER REFERENCES cupons(id),
    expira_em TEXT,
    pago_em TEXT,
    obs TEXT NOT NULL DEFAULT '',
    ip TEXT NOT NULL DEFAULT '',
    criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pedidos_evento ON pedidos(evento_id, status)");

  $pdo->exec("CREATE TABLE IF NOT EXISTS pedido_itens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pedido_id INTEGER NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
    tipo_id INTEGER NOT NULL REFERENCES tipos(id),
    qtd INTEGER NOT NULL,
    preco INTEGER NOT NULL
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS pagamentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pedido_id INTEGER NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
    conta_id INTEGER REFERENCES contas(id),
    provedor TEXT NOT NULL,
    metodo TEXT NOT NULL CHECK (metodo IN ('pix','cartao')),
    valor INTEGER NOT NULL,
    custo INTEGER NOT NULL DEFAULT 0,
    parcelas INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'pendente' CHECK (status IN ('pendente','aprovado','recusado','estornado','expirado')),
    ref TEXT NOT NULL DEFAULT '',
    pix_copia_cola TEXT NOT NULL DEFAULT '',
    pix_qr_img TEXT NOT NULL DEFAULT '',
    cartao_final TEXT NOT NULL DEFAULT '',
    cartao_bandeira TEXT NOT NULL DEFAULT '',
    mensagem TEXT NOT NULL DEFAULT '',
    criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime')),
    atualizado_em TEXT
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pag_ref ON pagamentos(provedor, ref)");

  $pdo->exec("CREATE TABLE IF NOT EXISTS ingressos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    codigo TEXT NOT NULL UNIQUE,
    pedido_id INTEGER NOT NULL REFERENCES pedidos(id) ON DELETE CASCADE,
    evento_id INTEGER NOT NULL REFERENCES eventos(id),
    tipo_id INTEGER NOT NULL REFERENCES tipos(id),
    titular TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'valido' CHECK (status IN ('valido','cancelado')),
    checkin_em TEXT,
    checkin_por INTEGER REFERENCES usuarios(id),
    criado_em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
  )");
  $pdo->exec("CREATE INDEX IF NOT EXISTS idx_ing_evento ON ingressos(evento_id)");

  $pdo->exec("CREATE TABLE IF NOT EXISTS checkins (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ingresso_id INTEGER REFERENCES ingressos(id),
    codigo_lido TEXT NOT NULL,
    usuario_id INTEGER REFERENCES usuarios(id),
    evento_id INTEGER,
    resultado TEXT NOT NULL,
    em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS log_webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    conta_id INTEGER, corpo TEXT, resultado TEXT,
    em TEXT NOT NULL DEFAULT (datetime('now','localtime'))
  )");

  /* segredo usado para assinar links de ingresso: gerado uma vez, só no servidor */
  $st = $pdo->prepare("INSERT OR IGNORE INTO config (chave, valor) VALUES (?, ?)");
  $st->execute(['segredo', bin2hex(random_bytes(32))]);
  $st->execute(['email_remetente', 'ingressos@comunidadebatistashalom.com.br']);
  $st->execute(['nome_organizacao', 'Comunidade Batista Shalom']);

  /* conta de testes já pronta, para testar tudo sem banco real */
  if (!(int)$pdo->query("SELECT COUNT(*) FROM contas")->fetchColumn()) {
    $pdo->exec("INSERT INTO contas (nome, provedor, ambiente, aceita_pix, aceita_cartao, taxa_pix_pct, taxa_pix_fixa, taxa_cartao_pct, taxa_cartao_fixa, taxa_parcela_pct)
                VALUES ('Conta de testes (sandbox)', 'sandbox', 'sandbox', 1, 1, 0, 0, 3.99, 0, 1.5)");
  }

  /* evento Koinonia já cadastrado como rascunho */
  if (!(int)$pdo->query("SELECT COUNT(*) FROM eventos")->fetchColumn()) {
    $pdo->exec("INSERT INTO eventos (slug, nome, descricao, local_nome, endereco, inicio, fim, imagem, cor, status, lotacao, max_parcelas, pix_conta_id, cartao_conta_id)
      VALUES ('koinonia', 'Koinonia', 'Um encontro de comunhão, mesa e fogo.', 'Comunidade Batista Shalom', 'Av. Paraná, 3103, Zona I, Umuarama/PR',
              '2027-02-06 19:00', '2027-02-09 22:00', '/assets/koinonia-capa.webp', '#fb5e01', 'rascunho', 0, 3, 1, 1)");
    $ev = (int)$pdo->lastInsertId();
    $t = $pdo->prepare("INSERT INTO tipos (evento_id, nome, descricao, preco, quantidade, pessoas, ordem) VALUES (?,?,?,?,?,?,?)");
    $t->execute([$ev, 'Individual', 'Acesso a todas as sessões', 0, 0, 1, 1]);
    $t->execute([$ev, 'Dupla', '2 ingressos individuais', 0, 0, 2, 2]);
    $t->execute([$ev, 'Célula', 'Pacote para 5 pessoas', 0, 0, 5, 3]);
  }

  $pdo->exec("INSERT OR REPLACE INTO config (chave, valor) VALUES ('schema', '1')");
}

/* ---------------- utilitários ---------------- */

function cfg(string $chave, string $padrao = ''): string {
  $st = db()->prepare('SELECT valor FROM config WHERE chave = ?');
  $st->execute([$chave]);
  $v = $st->fetchColumn();
  return $v === false ? $padrao : (string)$v;
}

function cfg_set(string $chave, string $valor): void {
  db()->prepare('INSERT OR REPLACE INTO config (chave, valor) VALUES (?, ?)')->execute([$chave, $valor]);
}

function agora(): string { return date('Y-m-d H:i:s'); }

function json_out($dados, int $codigo = 200): void {
  http_response_code($codigo);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function erro(string $msg, int $codigo = 400): void { json_out(['erro' => $msg], $codigo); }

function corpo(): array {
  $raw = file_get_contents('php://input');
  $d = json_decode($raw ?: '[]', true);
  return is_array($d) ? $d : [];
}

function codigo_aleatorio(int $tam = 10): string {
  $alf = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sem 0/O/1/I para não confundir
  $s = '';
  for ($i = 0; $i < $tam; $i++) $s .= $alf[random_int(0, strlen($alf) - 1)];
  return $s;
}

function ip_cliente(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64); }

function url_base(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $host = $_SERVER['HTTP_HOST'] ?? 'comunidadebatistashalom.com.br';
  return ($https ? 'https' : 'http') . '://' . $host;
}

function so_digitos(string $s): string { return preg_replace('/\D+/', '', $s) ?? ''; }

function cpf_valido(string $cpf): bool {
  $c = so_digitos($cpf);
  if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) return false;
  for ($t = 9; $t < 11; $t++) {
    $s = 0;
    for ($i = 0; $i < $t; $i++) $s += (int)$c[$i] * (($t + 1) - $i);
    $d = ((10 * $s) % 11) % 10;
    if ((int)$c[$t] !== $d) return false;
  }
  return true;
}

function reais(int $centavos): string { return 'R$ ' . number_format($centavos / 100, 2, ',', '.'); }

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------------- sessão, login e CSRF ---------------- */

function sessao(): void {
  if (session_status() === PHP_SESSION_NONE) {
    session_name('shalom_ing');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => url_base() !== '' && str_starts_with(url_base(), 'https'), 'path' => '/']);
    session_start();
  }
}

function usuario_atual(): ?array {
  sessao();
  if (empty($_SESSION['ing_uid'])) return null;
  $st = db()->prepare('SELECT id, nome, email, papel FROM usuarios WHERE id = ? AND ativo = 1');
  $st->execute([(int)$_SESSION['ing_uid']]);
  $u = $st->fetch();
  return $u ?: null;
}

function exigir_login(array $papeis = ['admin', 'checkin']): array {
  $u = usuario_atual();
  if (!$u) erro('Faça login novamente.', 401);
  if (!in_array($u['papel'], $papeis, true)) erro('Seu usuário não tem permissão para isso.', 403);
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!hash_equals((string)($_SESSION['ing_csrf'] ?? ''), (string)$tok)) erro('Sessão expirada. Recarregue a página.', 419);
  }
  return $u;
}

function csrf_token(): string {
  sessao();
  if (empty($_SESSION['ing_csrf'])) $_SESSION['ing_csrf'] = bin2hex(random_bytes(24));
  return $_SESSION['ing_csrf'];
}

function limitar_tentativas(): void {
  $pdo = db();
  $pdo->prepare('DELETE FROM tentativas_login WHERE em < ?')->execute([time() - 900]);
  $st = $pdo->prepare('SELECT COUNT(*) FROM tentativas_login WHERE ip = ?');
  $st->execute([ip_cliente()]);
  if ((int)$st->fetchColumn() >= 8) erro('Muitas tentativas. Aguarde 15 minutos e tente de novo.', 429);
}

function registrar_tentativa(): void {
  db()->prepare('INSERT INTO tentativas_login (ip, em) VALUES (?, ?)')->execute([ip_cliente(), time()]);
}
