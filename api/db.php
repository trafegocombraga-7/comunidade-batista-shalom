<?php
/**
 * Conexão SQLite + criação das tabelas.
 * O banco fica em /data/shalom.db (protegido por .htaccess).
 */
declare(strict_types=1);

const DB_DIR   = __DIR__ . '/../data';
const DB_FILE  = DB_DIR . '/shalom.db';

/*
 * Não há usuário nem senha no código (o repositório é público).
 * O primeiro usuário é criado pelo próprio painel, com o código de instalação.
 * Aqui fica só o sha256 desse código; o código foi entregue ao responsável, fora do repositório.
 */
const ADMIN_INSTALACAO_HASH = '025df3f724a978ba351d6f307e898cecd0c771e85c785efee76b397005918fb9';

/* sha256 da senha antiga que ficou publicada no repositório: quem entrar com ela é obrigado a trocá-la */
const ADMIN_SENHA_VAZADA_SHA256 = '5a369569d548d2bd0eb904b2a9938fc9c4d3b67558d65b0c83c3fd24954f9982';

/* versão da sessão: sessões abertas antes desta mudança deixam de valer */
const SESSAO_VERSAO = 2;

const SENHA_MIN = 10;

function arquivo_db(): string {
  $env = getenv('SHALOM_DB');
  return $env ?: DB_FILE;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  $arq = arquivo_db();
  if (!is_dir(dirname($arq))) @mkdir(dirname($arq), 0775, true);

  $pdo = new PDO('sqlite:' . $arq);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  $pdo->exec('PRAGMA journal_mode = WAL');

  migrate($pdo);
  return $pdo;
}

function migrate(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS usuarios (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    usuario    TEXT NOT NULL UNIQUE,
    senha_hash TEXT NOT NULL,
    criado_em  TEXT NOT NULL DEFAULT (datetime('now'))
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS celulas (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    nome          TEXT NOT NULL,
    tipo          TEXT NOT NULL,
    lider         TEXT NOT NULL,
    telefone      TEXT,
    bairro        TEXT,
    endereco      TEXT,
    dia           TEXT NOT NULL,
    horario       TEXT NOT NULL,
    idade_media   TEXT,
    lat           REAL NOT NULL,
    lng           REAL NOT NULL,
    ativo         INTEGER NOT NULL DEFAULT 1,
    criado_em     TEXT NOT NULL DEFAULT (datetime('now')),
    atualizado_em TEXT
  )");

  $pdo->exec("CREATE TABLE IF NOT EXISTS sede (
    id            INTEGER PRIMARY KEY CHECK (id = 1),
    nome          TEXT NOT NULL,
    endereco      TEXT NOT NULL,
    lat           REAL NOT NULL,
    lng           REAL NOT NULL,
    atualizado_em TEXT
  )");

  /* tentativas de login / código (limite contra força bruta) */
  $pdo->exec("CREATE TABLE IF NOT EXISTS tentativas (
    ip TEXT NOT NULL,
    em INTEGER NOT NULL
  )");

  /* o admin NÃO é mais criado aqui: ver ?acao=instalar em api/auth.php */

  /* endereço padrão da sede, editável depois pelo painel */
  $totalSede = (int) $pdo->query('SELECT COUNT(*) FROM sede')->fetchColumn();
  if ($totalSede === 0) {
    $st = $pdo->prepare('INSERT INTO sede (id, nome, endereco, lat, lng) VALUES (1, ?, ?, ?, ?)');
    $st->execute(['Comunidade Batista Shalom', 'Av. Paraná, 3103, Zona I, Umuarama/PR', -23.7644, -53.3256]);
  }
}

function json_out($data, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function body_json(): array {
  $raw = file_get_contents('php://input');
  $d   = json_decode($raw ?: '[]', true);
  return is_array($d) ? $d : [];
}

function start_session(): void {
  if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
  }
}

/* marca da senha guardada na sessão: quando a senha muda, as outras sessões caem */
function marca_senha(string $senha_hash): string {
  return hash('sha256', $senha_hash);
}

function logado(): bool {
  start_session();
  if (empty($_SESSION['uid']) || ($_SESSION['v'] ?? 0) !== SESSAO_VERSAO) return false;
  $st = db()->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
  $st->execute([(int)$_SESSION['uid']]);
  $h = $st->fetchColumn();
  return is_string($h) && hash_equals(marca_senha($h), (string)($_SESSION['sh'] ?? ''));
}

function require_auth(): void {
  if (!logado()) json_out(['erro' => 'Não autenticado'], 401);
  if (!empty($_SESSION['trocar_senha'])) {
    json_out(['erro' => 'Troque a senha antes de continuar.', 'trocar_senha' => true], 403);
  }
}

function tem_usuarios(): bool {
  return (int) db()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
}

function codigo_instalacao_ok(string $codigo): bool {
  $hash = getenv('SHALOM_TESTE') ? hash('sha256', 'TESTE-CODIGO') : ADMIN_INSTALACAO_HASH;
  return hash_equals($hash, hash('sha256', trim($codigo)));
}

function senha_vazada(string $senha): bool {
  return hash_equals(ADMIN_SENHA_VAZADA_SHA256, hash('sha256', $senha));
}

/* devolve a mensagem de erro, ou null se a senha nova serve */
function problema_senha(string $nova): ?string {
  if (mb_strlen($nova) < SENHA_MIN) return 'A senha precisa ter pelo menos ' . SENHA_MIN . ' caracteres.';
  if (senha_vazada($nova)) return 'Essa senha ficou pública e não pode mais ser usada.';
  return null;
}

function ip_cliente(): string {
  return (string)($_SERVER['REMOTE_ADDR'] ?? '?');
}

/* no máximo 10 erros por IP a cada 15 minutos */
function limitar_tentativas(): void {
  $st = db()->prepare('SELECT COUNT(*) FROM tentativas WHERE ip = ? AND em > ?');
  $st->execute([ip_cliente(), time() - 900]);
  if ((int)$st->fetchColumn() >= 10) json_out(['erro' => 'Muitas tentativas. Espere 15 minutos.'], 429);
}

function registrar_tentativa(): void {
  db()->prepare('DELETE FROM tentativas WHERE em < ?')->execute([time() - 86400]);
  db()->prepare('INSERT INTO tentativas (ip, em) VALUES (?, ?)')->execute([ip_cliente(), time()]);
  usleep(400000);
}
