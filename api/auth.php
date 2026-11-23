<?php
/**
 * Login / logout / sessão do painel admin de células.
 *   GET  ?acao=me
 *   POST ?acao=instalar { codigo, usuario, senha }   (só enquanto não existe nenhum usuário)
 *   POST ?acao=login    { usuario, senha }
 *   POST ?acao=senha    { atual, nova, codigo? }       (o código de instalação é exigido
 *                                                       quando a senha atual é a que ficou pública)
 *   POST ?acao=logout
 */
declare(strict_types=1);
require __DIR__ . '/db.php';

$acao = $_GET['acao'] ?? '';

if ($acao === 'me') {
  if (!logado()) json_out(['autenticado' => false, 'instalado' => tem_usuarios()]);
  json_out([
    'autenticado'  => true,
    'instalado'    => true,
    'usuario'      => $_SESSION['usuario'] ?? '',
    'trocar_senha' => !empty($_SESSION['trocar_senha']),
  ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['erro' => 'Método inválido'], 405);

function entrar(array $u, bool $trocar): void {
  start_session();
  session_regenerate_id(true);
  $_SESSION = [
    'uid'          => (int)$u['id'],
    'usuario'      => $u['usuario'],
    'v'            => SESSAO_VERSAO,
    'sh'           => marca_senha($u['senha_hash']),
    'trocar_senha' => $trocar,
  ];
}

if ($acao === 'instalar') {
  if (tem_usuarios()) json_out(['erro' => 'O painel já foi instalado.'], 403);
  limitar_tentativas();
  $b = body_json();
  if (!codigo_instalacao_ok((string)($b['codigo'] ?? ''))) {
    registrar_tentativa();
    json_out(['erro' => 'Código de instalação incorreto.'], 403);
  }
  $usuario = trim((string)($b['usuario'] ?? ''));
  $senha   = (string)($b['senha'] ?? '');
  if (!preg_match('/^[A-Za-z0-9._@-]{3,60}$/', $usuario)) {
    json_out(['erro' => 'Usuário: de 3 a 60 letras, números ou . _ @ -'], 400);
  }
  if ($p = problema_senha($senha)) json_out(['erro' => $p], 400);

  $hash = password_hash($senha, PASSWORD_DEFAULT);
  db()->prepare('INSERT INTO usuarios (usuario, senha_hash) VALUES (?, ?)')->execute([$usuario, $hash]);
  entrar(['id' => db()->lastInsertId(), 'usuario' => $usuario, 'senha_hash' => $hash], false);
  json_out(['ok' => true, 'usuario' => $usuario]);
}

if ($acao === 'login') {
  limitar_tentativas();
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
    registrar_tentativa();
    json_out(['erro' => 'Usuário ou senha incorretos.'], 401);
  }

  $trocar = senha_vazada($senha);
  entrar($u, $trocar);
  json_out(['ok' => true, 'usuario' => $u['usuario'], 'trocar_senha' => $trocar]);
}

if ($acao === 'senha') {
  if (!logado()) json_out(['erro' => 'Não autenticado'], 401);
  limitar_tentativas();
  $b     = body_json();
  $atual = (string)($b['atual'] ?? '');
  $nova  = (string)($b['nova'] ?? '');

  $st = db()->prepare('SELECT * FROM usuarios WHERE id = ?');
  $st->execute([$_SESSION['uid']]);
  $u = $st->fetch();
  if (!$u) json_out(['erro' => 'Usuário não encontrado.'], 401);

  if (!password_verify($atual, $u['senha_hash'])) {
    registrar_tentativa();
    json_out(['erro' => 'A senha atual está incorreta.'], 400);
  }
  /* a senha antiga é pública: só quem tem o código de instalação pode trocá-la */
  if (senha_vazada($atual) && !codigo_instalacao_ok((string)($b['codigo'] ?? ''))) {
    registrar_tentativa();
    json_out(['erro' => 'Informe o código de instalação correto para trocar esta senha.', 'precisa_codigo' => true], 403);
  }
  if ($p = problema_senha($nova)) json_out(['erro' => $p], 400);
  if (hash_equals($atual, $nova)) json_out(['erro' => 'A senha nova precisa ser diferente da atual.'], 400);

  $u['senha_hash'] = password_hash($nova, PASSWORD_DEFAULT);
  db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([$u['senha_hash'], $u['id']]);
  entrar($u, false);  /* nova sessão; as demais (de qualquer lugar) deixam de valer */
  json_out(['ok' => true]);
}

if ($acao === 'logout') {
  start_session();
  $_SESSION = [];
  session_destroy();
  json_out(['ok' => true]);
}

json_out(['erro' => 'Ação inválida'], 400);
