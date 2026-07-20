<?php
/**
 * Login / logout / sessão do painel admin.
 *   POST ?acao=login   { usuario, senha }
 *   POST ?acao=logout
 *   GET  ?acao=me
 */
declare(strict_types=1);
require __DIR__ . '/db.php';

$acao = $_GET['acao'] ?? '';

if ($acao === 'login') {
  $b       = body_json();
  $usuario = trim((string)($b['usuario'] ?? ''));
  $senha   = (string)($b['senha'] ?? '');

  if ($usuario === '' || $senha === '') {
    json_out(['erro' => 'Informe usuário e senha.'], 400);
  }

  $st = db()->prepare('SELECT * FROM usuarios WHERE usuario = ?');
  $st->execute([$usuario]);
  $u = $st->fetch();

  if (!$u || !password_verify($senha, $u['senha_hash'])) {
    usleep(400000); // atrasa tentativa inválida
    json_out(['erro' => 'Usuário ou senha incorretos.'], 401);
  }

  start_session();
  session_regenerate_id(true);
  $_SESSION['uid']     = (int)$u['id'];
  $_SESSION['usuario'] = $u['usuario'];
  json_out(['ok' => true, 'usuario' => $u['usuario']]);
}

if ($acao === 'logout') {
  start_session();
  $_SESSION = [];
  session_destroy();
  json_out(['ok' => true]);
}

if ($acao === 'me') {
  start_session();
  if (empty($_SESSION['uid'])) json_out(['autenticado' => false], 200);
  json_out(['autenticado' => true, 'usuario' => $_SESSION['usuario'] ?? '']);
}

json_out(['erro' => 'Ação inválida'], 400);
