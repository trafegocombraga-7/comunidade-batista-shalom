<?php
/**
 * Login da equipe (painel e check-in).
 *   GET  ?acao=me
 *   POST ?acao=login     {email, senha}
 *   POST ?acao=logout
 *   POST ?acao=instalar  {codigo, nome, email, senha}   (só quando ainda não existe nenhum usuário)
 *   POST ?acao=senha     {atual, nova}                   (troca a própria senha)
 */
declare(strict_types=1);
require __DIR__ . '/../lib/boot.php';

$acao = $_GET['acao'] ?? '';
$tem_usuarios = (int)db()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;

if ($acao === 'me') {
  $u = usuario_atual();
  json_out(['instalado' => $tem_usuarios, 'usuario' => $u, 'csrf' => $u ? csrf_token() : null]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') erro('Método inválido.', 405);

if ($acao === 'instalar') {
  if ($tem_usuarios) erro('O sistema já foi instalado.', 403);
  limitar_tentativas();
  $b = corpo();
  $hash = getenv('INGRESSOS_TESTE') ? hash('sha256', 'TESTE-CODIGO') : ING_INSTALACAO_HASH;
  if (!hash_equals($hash, hash('sha256', trim((string)($b['codigo'] ?? ''))))) { registrar_tentativa(); erro('Código de instalação incorreto.', 403); }
  $nome = trim((string)($b['nome'] ?? ''));
  $email = mb_strtolower(trim((string)($b['email'] ?? '')));
  $senha = (string)($b['senha'] ?? '');
  if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Informe nome e e-mail válidos.');
  if (strlen($senha) < 8) erro('A senha precisa ter pelo menos 8 caracteres.');
  db()->prepare("INSERT INTO usuarios (nome, email, senha_hash, papel) VALUES (?,?,?,'admin')")
      ->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT)]);
  json_out(['ok' => true]);
}

if ($acao === 'login') {
  limitar_tentativas();
  $b = corpo();
  $st = db()->prepare('SELECT * FROM usuarios WHERE email = ? AND ativo = 1');
  $st->execute([mb_strtolower(trim((string)($b['email'] ?? '')))]);
  $u = $st->fetch();
  if (!$u || !password_verify((string)($b['senha'] ?? ''), $u['senha_hash'])) {
    registrar_tentativa();
    usleep(400000);
    erro('E-mail ou senha incorretos.', 401);
  }
  sessao();
  session_regenerate_id(true);
  $_SESSION['ing_uid'] = (int)$u['id'];
  unset($_SESSION['ing_csrf']);
  db()->prepare('UPDATE usuarios SET ultimo_login = ? WHERE id = ?')->execute([agora(), $u['id']]);
  json_out(['ok' => true, 'usuario' => ['id' => (int)$u['id'], 'nome' => $u['nome'], 'email' => $u['email'], 'papel' => $u['papel']], 'csrf' => csrf_token()]);
}

if ($acao === 'logout') {
  sessao();
  $_SESSION = [];
  session_destroy();
  json_out(['ok' => true]);
}

if ($acao === 'senha') {
  $u = exigir_login();
  $b = corpo();
  $st = db()->prepare('SELECT senha_hash FROM usuarios WHERE id = ?');
  $st->execute([$u['id']]);
  if (!password_verify((string)($b['atual'] ?? ''), (string)$st->fetchColumn())) erro('Senha atual incorreta.', 403);
  if (strlen((string)($b['nova'] ?? '')) < 8) erro('A nova senha precisa ter pelo menos 8 caracteres.');
  db()->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash((string)$b['nova'], PASSWORD_DEFAULT), $u['id']]);
  json_out(['ok' => true]);
}

erro('Ação inválida.', 404);
