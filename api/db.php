<?php
/**
 * Conexão SQLite + criação das tabelas.
 * O banco fica em /data/shalom.db (protegido por .htaccess).
 */
declare(strict_types=1);

const DB_DIR   = __DIR__ . '/../data';
const DB_FILE  = DB_DIR . '/shalom.db';

/* usuário admin criado automaticamente na primeira execução */
const ADMIN_USER = 'shalomadmin';
const ADMIN_PASS = 'jesusteama';

function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  if (!is_dir(DB_DIR)) @mkdir(DB_DIR, 0775, true);

  $pdo = new PDO('sqlite:' . DB_FILE);
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

  /* cria o admin apenas na primeira vez */
  $total = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();
  if ($total === 0) {
    $st = $pdo->prepare('INSERT INTO usuarios (usuario, senha_hash) VALUES (?, ?)');
    $st->execute([ADMIN_USER, password_hash(ADMIN_PASS, PASSWORD_DEFAULT)]);
  }

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

function require_auth(): void {
  start_session();
  if (empty($_SESSION['uid'])) json_out(['erro' => 'Não autenticado'], 401);
}
