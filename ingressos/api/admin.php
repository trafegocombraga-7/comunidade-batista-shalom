<?php
/**
 * API do painel (somente usuários "admin").
 * Todas as ações: ?acao=NOME. POST exige o cabeçalho X-CSRF.
 */
declare(strict_types=1);
require __DIR__ . '/../lib/pedidos.php';

$acao = $_GET['acao'] ?? '';
$eu = exigir_login(['admin']);
$pdo = db();

function int_ou_null($v): ?int { return ($v === '' || $v === null) ? null : (int)$v; }
function data_ou_null($v): ?string {
  $v = trim((string)$v);
  if ($v === '') return null;
  $t = strtotime(str_replace('T', ' ', $v));
  return $t ? date('Y-m-d H:i', $t) : null;
}
function slugificar(string $s): string {
  $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
  $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s) ?? '', '-'));
  return $s !== '' ? substr($s, 0, 60) : 'evento';
}
function centavos($v): int {
  if (is_int($v)) return $v;
  $s = str_replace(['R$', ' '], '', (string)$v);
  if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);
  return (int)round((float)$s * 100);
}
/** aceita "3,49" ou "3.49" */
function decimal($v): float { return (float)str_replace(',', '.', str_replace(['%', ' '], '', (string)$v)); }
function conta_publica(array $c): array {
  $cred = json_decode($c['credenciais'] ?: '{}', true) ?: [];
  $c['credenciais'] = array_map(fn($v) => $v === '' ? '' : '••••••' . substr((string)$v, -4), $cred);
  $c['tem_credenciais'] = (bool)array_filter($cred);
  unset($c['credenciais']['public_key']);
  $c['public_key'] = (string)($cred['public_key'] ?? '');
  return $c;
}

try {
  switch ($acao) {

    /* ================= painel inicial ================= */
    case 'resumo': {
      liberar_expirados();
      $eventos = $pdo->query("SELECT id, nome, slug, status, inicio, lotacao FROM eventos ORDER BY COALESCE(inicio, criado_em) DESC")->fetchAll();
      foreach ($eventos as &$e) {
        $st = $pdo->prepare("SELECT COUNT(*) AS pedidos, COALESCE(SUM(total),0) AS bruto, COALESCE(SUM(custo_taxas),0) AS taxas,
                             COALESCE(SUM(taxa_cliente),0) AS taxas_cliente FROM pedidos WHERE evento_id = ? AND status = 'pago'");
        $st->execute([$e['id']]);
        $e += $st->fetch();
        $st = $pdo->prepare("SELECT COUNT(*) AS ingressos, SUM(checkin_em IS NOT NULL) AS presentes FROM ingressos WHERE evento_id = ? AND status = 'valido'");
        $st->execute([$e['id']]);
        $e += $st->fetch();
        $st = $pdo->prepare("SELECT COUNT(*) FROM pedidos WHERE evento_id = ? AND status = 'pendente'");
        $st->execute([$e['id']]);
        $e['pendentes'] = (int)$st->fetchColumn();
        $e['liquido'] = (int)$e['bruto'] - (int)$e['taxas'];
      }
      json_out(['eventos' => $eventos, 'link_base' => url_base() . '/e/']);
    }

    case 'grafico': {
      $id = (int)($_GET['evento'] ?? 0);
      $st = $pdo->prepare("SELECT substr(pago_em,1,10) AS dia, COUNT(*) AS pedidos, SUM(total) AS valor FROM pedidos
                           WHERE evento_id = ? AND status = 'pago' AND pago_em >= date('now','-30 days') GROUP BY dia ORDER BY dia");
      $st->execute([$id]);
      $dias = $st->fetchAll();
      $st = $pdo->prepare("SELECT t.nome, COUNT(g.id) AS ingressos FROM tipos t LEFT JOIN ingressos g ON g.tipo_id = t.id AND g.status = 'valido'
                           WHERE t.evento_id = ? GROUP BY t.id ORDER BY t.ordem");
      $st->execute([$id]);
      $tipos = $st->fetchAll();
      $st = $pdo->prepare("SELECT metodo, COUNT(*) AS pedidos, SUM(total) AS valor FROM pedidos WHERE evento_id = ? AND status = 'pago' GROUP BY metodo");
      $st->execute([$id]);
      json_out(['dias' => $dias, 'tipos' => $tipos, 'metodos' => $st->fetchAll()]);
    }

    /* ================= eventos ================= */
    case 'eventos': {
      json_out($pdo->query("SELECT id, nome, slug, status, inicio FROM eventos ORDER BY COALESCE(inicio, criado_em) DESC")->fetchAll());
    }

    case 'evento': {
      $ev = evento_por_id((int)($_GET['id'] ?? 0));
      if (!$ev) erro('Evento não encontrado.', 404);
      $ev['tipos'] = tipos_do_evento((int)$ev['id'], false);
      $oc = ocupacao((int)$ev['id']);
      foreach ($ev['tipos'] as &$t) $t['vendidos'] = $oc['tipos'][(int)$t['id']] ?? 0;
      $st = $pdo->prepare('SELECT * FROM cupons WHERE evento_id = ? ORDER BY id');
      $st->execute([$ev['id']]);
      $ev['cupons'] = $st->fetchAll();
      $ev['link'] = url_base() . '/e/' . $ev['slug'];
      json_out($ev);
    }

    case 'evento_salvar': {
      $b = corpo();
      $id = (int)($b['id'] ?? 0);
      $nome = trim((string)($b['nome'] ?? ''));
      if ($nome === '') erro('Dê um nome ao evento.');
      $slug = slugificar((string)($b['slug'] ?? '') ?: $nome);
      $st = $pdo->prepare('SELECT id FROM eventos WHERE slug = ? AND id <> ?');
      $st->execute([$slug, $id]);
      if ($st->fetchColumn()) erro('Já existe um evento com o link /e/' . $slug . '. Escolha outro.');
      $cor = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($b['cor'] ?? '')) ? $b['cor'] : '#055a85';
      $status = in_array($b['status'] ?? '', ['rascunho', 'publicado', 'encerrado'], true) ? $b['status'] : 'rascunho';
      $valores = [
        $slug, $nome, trim((string)($b['descricao'] ?? '')), trim((string)($b['local_nome'] ?? '')), trim((string)($b['endereco'] ?? '')),
        data_ou_null($b['inicio'] ?? ''), data_ou_null($b['fim'] ?? ''), trim((string)($b['imagem'] ?? '')), $cor, $status,
        max(0, (int)($b['lotacao'] ?? 0)), max(1, min(50, (int)($b['limite_por_pedido'] ?? 10))),
        data_ou_null($b['vendas_inicio'] ?? ''), data_ou_null($b['vendas_fim'] ?? ''),
        ($b['taxa_modo'] ?? '') === 'absorver' ? 'absorver' : 'repassar',
        empty($b['aceita_pix']) ? 0 : 1, empty($b['aceita_cartao']) ? 0 : 1, empty($b['dois_cartoes']) ? 0 : 1,
        max(1, min(12, (int)($b['max_parcelas'] ?? 1))),
        int_ou_null($b['pix_conta_id'] ?? null), int_ou_null($b['cartao_conta_id'] ?? null),
        max(5, min(1440, (int)($b['pix_minutos'] ?? 30))), empty($b['pedir_cpf']) ? 0 : 1,
      ];
      $campos = 'slug, nome, descricao, local_nome, endereco, inicio, fim, imagem, cor, status, lotacao, limite_por_pedido, vendas_inicio, vendas_fim,
                 taxa_modo, aceita_pix, aceita_cartao, dois_cartoes, max_parcelas, pix_conta_id, cartao_conta_id, pix_minutos, pedir_cpf';
      $pdo->exec('BEGIN');
      if ($id) {
        $sets = implode(', ', array_map(fn($c) => trim($c) . ' = ?', explode(',', $campos)));
        $pdo->prepare("UPDATE eventos SET $sets WHERE id = ?")->execute([...$valores, $id]);
      } else {
        $pdo->prepare("INSERT INTO eventos ($campos) VALUES (" . implode(',', array_fill(0, count($valores), '?')) . ')')->execute($valores);
        $id = (int)$pdo->lastInsertId();
      }
      /* tipos de ingresso: atualiza os existentes, cria os novos, desativa/remove os retirados */
      $manter = [];
      foreach ((array)($b['tipos'] ?? []) as $i => $t) {
        $tn = trim((string)($t['nome'] ?? ''));
        if ($tn === '') continue;
        $v = [$tn, trim((string)($t['descricao'] ?? '')), max(0, centavos($t['preco'] ?? 0)), max(0, (int)($t['quantidade'] ?? 0)),
              max(1, (int)($t['pessoas'] ?? 1)), max(1, (int)($t['max_por_pedido'] ?? 10)), data_ou_null($t['vendas_inicio'] ?? ''),
              data_ou_null($t['vendas_fim'] ?? ''), $i, empty($t['ativo']) && isset($t['ativo']) ? 0 : 1];
        $tid = (int)($t['id'] ?? 0);
        if ($tid) {
          $pdo->prepare('UPDATE tipos SET nome=?, descricao=?, preco=?, quantidade=?, pessoas=?, max_por_pedido=?, vendas_inicio=?, vendas_fim=?, ordem=?, ativo=? WHERE id=? AND evento_id=?')
              ->execute([...$v, $tid, $id]);
        } else {
          $pdo->prepare('INSERT INTO tipos (nome, descricao, preco, quantidade, pessoas, max_por_pedido, vendas_inicio, vendas_fim, ordem, ativo, evento_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
              ->execute([...$v, $id]);
          $tid = (int)$pdo->lastInsertId();
        }
        $manter[] = $tid;
      }
      $st = $pdo->prepare('SELECT id FROM tipos WHERE evento_id = ?');
      $st->execute([$id]);
      foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $tid) {
        if (in_array((int)$tid, $manter, true)) continue;
        $u = $pdo->prepare('SELECT COUNT(*) FROM pedido_itens WHERE tipo_id = ?');
        $u->execute([$tid]);
        if ((int)$u->fetchColumn() > 0) $pdo->prepare('UPDATE tipos SET ativo = 0 WHERE id = ?')->execute([$tid]);
        else $pdo->prepare('DELETE FROM tipos WHERE id = ?')->execute([$tid]);
      }
      /* cupons */
      $manter = [];
      foreach ((array)($b['cupons'] ?? []) as $c) {
        $cod = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($c['codigo'] ?? '')) ?? '');
        if ($cod === '') continue;
        $tipo = ($c['tipo'] ?? '') === 'valor' ? 'valor' : 'pct';
        $val = $tipo === 'valor' ? max(0, centavos($c['valor'] ?? 0)) : max(0, min(100, (int)($c['valor'] ?? 0)));
        $v = [$cod, $tipo, $val, max(0, (int)($c['usos_max'] ?? 0)), empty($c['ativo']) && isset($c['ativo']) ? 0 : 1];
        $cid = (int)($c['id'] ?? 0);
        if ($cid) $pdo->prepare('UPDATE cupons SET codigo=?, tipo=?, valor=?, usos_max=?, ativo=? WHERE id=? AND evento_id=?')->execute([...$v, $cid, $id]);
        else { $pdo->prepare('INSERT INTO cupons (codigo, tipo, valor, usos_max, ativo, evento_id) VALUES (?,?,?,?,?,?)')->execute([...$v, $id]); $cid = (int)$pdo->lastInsertId(); }
        $manter[] = $cid;
      }
      $st = $pdo->prepare('SELECT id FROM cupons WHERE evento_id = ?');
      $st->execute([$id]);
      foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        if (in_array((int)$cid, $manter, true)) continue;
        $u = $pdo->prepare('SELECT COUNT(*) FROM pedidos WHERE cupom_id = ?');
        $u->execute([$cid]);
        if ((int)$u->fetchColumn() > 0) $pdo->prepare('UPDATE cupons SET ativo = 0 WHERE id = ?')->execute([$cid]);
        else $pdo->prepare('DELETE FROM cupons WHERE id = ?')->execute([$cid]);
      }
      $pdo->exec('COMMIT');
      json_out(['ok' => true, 'id' => $id, 'link' => url_base() . '/e/' . $slug]);
    }

    case 'evento_duplicar': {
      $ev = evento_por_id((int)(corpo()['id'] ?? 0));
      if (!$ev) erro('Evento não encontrado.', 404);
      $slug = slugificar($ev['slug'] . '-copia');
      for ($n = 2; evento_por_slug($slug); $n++) $slug = slugificar($ev['slug'] . '-copia-' . $n);
      $pdo->exec('BEGIN');
      $cols = 'nome, descricao, local_nome, endereco, inicio, fim, imagem, cor, lotacao, limite_por_pedido, vendas_inicio, vendas_fim, taxa_modo,
               aceita_pix, aceita_cartao, dois_cartoes, max_parcelas, pix_conta_id, cartao_conta_id, pix_minutos, pedir_cpf';
      $pdo->prepare("INSERT INTO eventos (slug, status, $cols) SELECT ?, 'rascunho', $cols FROM eventos WHERE id = ?")->execute([$slug, $ev['id']]);
      $novo = (int)$pdo->lastInsertId();
      $pdo->prepare("UPDATE eventos SET nome = nome || ' (cópia)' WHERE id = ?")->execute([$novo]);
      $pdo->prepare('INSERT INTO tipos (evento_id, nome, descricao, preco, quantidade, pessoas, max_por_pedido, vendas_inicio, vendas_fim, ordem, ativo)
                     SELECT ?, nome, descricao, preco, quantidade, pessoas, max_por_pedido, vendas_inicio, vendas_fim, ordem, ativo FROM tipos WHERE evento_id = ? AND ativo = 1')
          ->execute([$novo, $ev['id']]);
      $pdo->exec('COMMIT');
      json_out(['ok' => true, 'id' => $novo]);
    }

    case 'evento_excluir': {
      $id = (int)(corpo()['id'] ?? 0);
      $st = $pdo->prepare('SELECT COUNT(*) FROM pedidos WHERE evento_id = ?');
      $st->execute([$id]);
      if ((int)$st->fetchColumn() > 0) erro('Este evento já tem pedidos. Em vez de excluir, mude o status para "Encerrado".');
      $pdo->prepare('DELETE FROM eventos WHERE id = ?')->execute([$id]);
      json_out(['ok' => true]);
    }

    case 'imagem': {
      if (empty($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) erro('Envie uma imagem.');
      $f = $_FILES['arquivo'];
      if ($f['size'] > 5 * 1024 * 1024) erro('Imagem muito grande (máximo 5 MB).');
      $tipo = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
      $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$tipo] ?? null;
      if (!$ext) erro('Use JPG, PNG ou WEBP.');
      $dir = __DIR__ . '/../../uploads/eventos';
      if (!is_dir($dir)) mkdir($dir, 0775, true);
      if (!is_file("$dir/.htaccess")) file_put_contents("$dir/.htaccess", "<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi)$\">\nRequire all denied\n</FilesMatch>\n");
      $nome = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $ext;
      if (!move_uploaded_file($f['tmp_name'], "$dir/$nome")) erro('Não foi possível salvar a imagem.', 500);
      json_out(['url' => '/uploads/eventos/' . $nome]);
    }

    /* ================= pedidos ================= */
    case 'pedidos': {
      liberar_expirados();
      $where = ['1=1'];
      $par = [];
      if (!empty($_GET['evento'])) { $where[] = 'p.evento_id = ?'; $par[] = (int)$_GET['evento']; }
      if (!empty($_GET['status'])) { $where[] = 'p.status = ?'; $par[] = (string)$_GET['status']; }
      if (!empty($_GET['q'])) {
        $q = '%' . trim((string)$_GET['q']) . '%';
        $where[] = '(p.nome LIKE ? OR p.email LIKE ? OR p.codigo LIKE ? OR p.telefone LIKE ? OR p.cpf LIKE ?)';
        array_push($par, $q, $q, $q, $q, $q);
      }
      $pag = max(1, (int)($_GET['pagina'] ?? 1));
      $st = $pdo->prepare('SELECT COUNT(*) FROM pedidos p WHERE ' . implode(' AND ', $where));
      $st->execute($par);
      $total = (int)$st->fetchColumn();
      $st = $pdo->prepare('SELECT p.id, p.codigo, p.nome, p.email, p.telefone, p.total, p.metodo, p.status, p.criado_em, p.pago_em, e.nome AS evento,
                           (SELECT COUNT(*) FROM ingressos g WHERE g.pedido_id = p.id) AS ingressos
                           FROM pedidos p JOIN eventos e ON e.id = p.evento_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.id DESC LIMIT 50 OFFSET ' . (($pag - 1) * 50));
      $st->execute($par);
      json_out(['total' => $total, 'pedidos' => $st->fetchAll()]);
    }

    case 'pedido': {
      $p = pedido_por_id((int)($_GET['id'] ?? 0));
      if (!$p) erro('Pedido não encontrado.', 404);
      $ev = evento_por_id((int)$p['evento_id']);
      $st = $pdo->prepare('SELECT i.*, t.nome AS tipo FROM pedido_itens i JOIN tipos t ON t.id = i.tipo_id WHERE i.pedido_id = ?');
      $st->execute([$p['id']]);
      $p['itens'] = $st->fetchAll();
      $st = $pdo->prepare('SELECT g.id, g.metodo, g.valor, g.custo, g.parcelas, g.status, g.ref, g.cartao_final, g.cartao_bandeira, g.mensagem, g.criado_em, c.nome AS conta
                           FROM pagamentos g LEFT JOIN contas c ON c.id = g.conta_id WHERE g.pedido_id = ? ORDER BY g.id');
      $st->execute([$p['id']]);
      $p['pagamentos'] = $st->fetchAll();
      $st = $pdo->prepare('SELECT g.id, g.codigo, g.titular, g.status, g.checkin_em, t.nome AS tipo FROM ingressos g JOIN tipos t ON t.id = g.tipo_id WHERE g.pedido_id = ? ORDER BY g.id');
      $st->execute([$p['id']]);
      $p['lista_ingressos'] = $st->fetchAll();
      $p['evento'] = $ev['nome'] ?? '';
      $p['link'] = link_pedido($p);
      unset($p['acesso']);
      json_out($p);
    }

    case 'pedido_acao': {
      $b = corpo();
      $p = pedido_por_id((int)($b['id'] ?? 0));
      if (!$p) erro('Pedido não encontrado.', 404);
      $o = (string)($b['o'] ?? '');
      if ($o === 'marcar_pago') {
        if (!in_array($p['status'], ['pendente', 'expirado'], true)) erro('Só pedidos pendentes ou expirados podem ser marcados como pagos.');
        $pdo->prepare("UPDATE pagamentos SET status='aprovado', mensagem='Confirmado manualmente', atualizado_em=? WHERE pedido_id=? AND status IN ('pendente','expirado')")->execute([agora(), $p['id']]);
        $pdo->prepare("UPDATE pedidos SET obs = trim(obs || ?) WHERE id = ?")->execute([' Pago confirmado manualmente por ' . $eu['nome'] . '.', $p['id']]);
        marcar_pago((int)$p['id']);
      } elseif ($o === 'cancelar') {
        if ($p['status'] !== 'pendente') erro('Só pedidos pendentes podem ser cancelados. Para pedidos pagos use "Estornar".');
        $pdo->prepare("UPDATE pagamentos SET status='expirado', atualizado_em=? WHERE pedido_id=? AND status='pendente'")->execute([agora(), $p['id']]);
        atualizar_status_pedido((int)$p['id'], 'cancelado');
      } elseif ($o === 'estornar') {
        if ($p['status'] !== 'pago') erro('Só pedidos pagos podem ser estornados.');
        $st = $pdo->prepare("SELECT id FROM pagamentos WHERE pedido_id = ? AND status = 'aprovado'");
        $st->execute([$p['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $gid) estornar_pagamento((int)$gid);
        $pdo->prepare("UPDATE pedidos SET status='estornado', obs = trim(obs || ?) WHERE id = ?")->execute([' Estornado por ' . $eu['nome'] . ' em ' . date('d/m/Y H:i') . '.', $p['id']]);
        $pdo->prepare("UPDATE ingressos SET status='cancelado' WHERE pedido_id = ?")->execute([$p['id']]);
      } elseif ($o === 'reenviar') {
        if ($p['status'] !== 'pago') erro('Só é possível reenviar ingressos de pedidos pagos.');
        if (!enviar_email_pedido((int)$p['id'])) erro('O servidor não conseguiu enviar o e-mail agora.', 500);
      } elseif ($o === 'titular') {
        $nome = trim((string)($b['titular'] ?? ''));
        if ($nome === '') erro('Informe o nome.');
        $pdo->prepare('UPDATE ingressos SET titular = ? WHERE id = ? AND pedido_id = ?')->execute([$nome, (int)($b['ingresso_id'] ?? 0), $p['id']]);
      } elseif ($o === 'desfazer_checkin') {
        $pdo->prepare('UPDATE ingressos SET checkin_em = NULL, checkin_por = NULL WHERE id = ? AND pedido_id = ?')->execute([(int)($b['ingresso_id'] ?? 0), $p['id']]);
      } else {
        erro('Ação inválida.');
      }
      json_out(['ok' => true]);
    }

    case 'cortesia': {
      $b = corpo();
      $ev = evento_por_id((int)($b['evento_id'] ?? 0));
      if (!$ev) erro('Evento não encontrado.', 404);
      $st = $pdo->prepare('SELECT * FROM tipos WHERE id = ? AND evento_id = ?');
      $st->execute([(int)($b['tipo_id'] ?? 0), $ev['id']]);
      $t = $st->fetch();
      if (!$t) erro('Escolha o tipo de ingresso.');
      $qtd = max(1, min(100, (int)($b['qtd'] ?? 1)));
      $nome = trim((string)($b['nome'] ?? ''));
      $email = mb_strtolower(trim((string)($b['email'] ?? '')));
      if ($nome === '') erro('Informe o nome de quem recebe.');
      if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) erro('E-mail inválido.');
      $metodo = ($b['tipo'] ?? '') === 'manual' ? 'manual' : 'cortesia';
      $valor = $metodo === 'manual' ? max(0, centavos($b['valor'] ?? 0)) : 0;
      $pdo->prepare("INSERT INTO pedidos (codigo, acesso, evento_id, nome, email, telefone, subtotal, total, metodo, status, obs)
                     VALUES (?,?,?,?,?,?,?,?,?,'pendente',?)")
          ->execute([codigo_aleatorio(8), bin2hex(random_bytes(16)), $ev['id'], $nome, $email ?: 'sem-email@cortesia.local', so_digitos((string)($b['telefone'] ?? '')),
                     $valor, $valor, $metodo, trim(($metodo === 'manual' ? 'Venda manual' : 'Cortesia') . ' emitida por ' . $eu['nome'] . '. ' . (string)($b['obs'] ?? ''))]);
      $pid = (int)$pdo->lastInsertId();
      $pdo->prepare('INSERT INTO pedido_itens (pedido_id, tipo_id, qtd, preco) VALUES (?,?,?,?)')->execute([$pid, $t['id'], $qtd, $metodo === 'manual' ? intdiv($valor, $qtd) : 0]);
      if ($email === '') {   // sem e-mail: só emite, não tenta enviar
        $pdo->prepare("UPDATE pedidos SET status='pago', pago_em=? WHERE id=?")->execute([agora(), $pid]);
        emitir_ingressos($pid);
      } else {
        marcar_pago($pid);
      }
      $p = pedido_por_id($pid);
      json_out(['ok' => true, 'id' => $pid, 'link' => link_pedido($p)]);
    }

    case 'exportar': {
      $id = (int)($_GET['evento'] ?? 0);
      $ev = evento_por_id($id);
      if (!$ev) erro('Evento não encontrado.', 404);
      $st = $pdo->prepare("SELECT g.codigo, g.titular, t.nome AS tipo, p.codigo AS pedido, p.nome AS comprador, p.email, p.telefone, p.cpf, p.metodo,
                           p.total, p.pago_em, g.status, g.checkin_em FROM ingressos g JOIN pedidos p ON p.id = g.pedido_id JOIN tipos t ON t.id = g.tipo_id
                           WHERE g.evento_id = ? ORDER BY p.pago_em, g.id");
      $st->execute([$id]);
      header('Content-Type: text/csv; charset=utf-8');
      header('Content-Disposition: attachment; filename="participantes-' . $ev['slug'] . '.csv"');
      $out = fopen('php://output', 'w');
      fwrite($out, "\xEF\xBB\xBF");
      fputcsv($out, ['Ingresso', 'Titular', 'Tipo', 'Pedido', 'Comprador', 'E-mail', 'WhatsApp', 'CPF', 'Pagamento', 'Total do pedido', 'Pago em', 'Situação', 'Check-in'], ';');
      foreach ($st as $r) {
        $r['total'] = number_format($r['total'] / 100, 2, ',', '.');
        fputcsv($out, array_map(fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v, array_values($r)), ';');
      }
      exit;
    }

    /* ================= contas de recebimento ================= */
    case 'contas': {
      $rows = $pdo->query('SELECT * FROM contas ORDER BY id')->fetchAll();
      json_out(['contas' => array_map('conta_publica', $rows), 'provedores' => PROVEDORES]);
    }

    case 'conta_salvar': {
      $b = corpo();
      $id = (int)($b['id'] ?? 0);
      $prov = (string)($b['provedor'] ?? '');
      if (!isset(PROVEDORES[$prov])) erro('Escolha o banco/intermediador.');
      $nome = trim((string)($b['nome'] ?? '')) ?: PROVEDORES[$prov]['nome'];
      $cred_ant = [];
      if ($id) {
        $st = $pdo->prepare('SELECT credenciais FROM contas WHERE id = ?');
        $st->execute([$id]);
        $cred_ant = json_decode((string)$st->fetchColumn() ?: '{}', true) ?: [];
      }
      $cred = $cred_ant;
      foreach ((array)($b['credenciais'] ?? []) as $k => $v) {   // campo vazio = manter o que já estava salvo
        $k = preg_replace('/[^a-z_]/', '', (string)$k);
        if ($k !== '' && trim((string)$v) !== '' && !str_starts_with((string)$v, '••')) $cred[$k] = trim((string)$v);
      }
      $v = [$nome, $prov, ($b['ambiente'] ?? '') === 'producao' ? 'producao' : 'sandbox',
            empty($b['aceita_pix']) ? 0 : 1, empty($b['aceita_cartao']) ? 0 : 1, json_encode($cred),
            decimal($b['taxa_pix_pct'] ?? 0), centavos($b['taxa_pix_fixa'] ?? 0), decimal($b['taxa_cartao_pct'] ?? 0),
            centavos($b['taxa_cartao_fixa'] ?? 0), decimal($b['taxa_parcela_pct'] ?? 0), empty($b['ativo']) && isset($b['ativo']) ? 0 : 1];
      if ($v[3] && !PROVEDORES[$prov]['pix']) erro('Este provedor não recebe PIX.');
      if ($v[4] && !PROVEDORES[$prov]['cartao']) erro('Este provedor não recebe cartão.');
      if ($id) $pdo->prepare('UPDATE contas SET nome=?, provedor=?, ambiente=?, aceita_pix=?, aceita_cartao=?, credenciais=?, taxa_pix_pct=?, taxa_pix_fixa=?, taxa_cartao_pct=?, taxa_cartao_fixa=?, taxa_parcela_pct=?, ativo=? WHERE id=?')->execute([...$v, $id]);
      else { $pdo->prepare('INSERT INTO contas (nome, provedor, ambiente, aceita_pix, aceita_cartao, credenciais, taxa_pix_pct, taxa_pix_fixa, taxa_cartao_pct, taxa_cartao_fixa, taxa_parcela_pct, ativo) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute($v); $id = (int)$pdo->lastInsertId(); }
      json_out(['ok' => true, 'id' => $id, 'webhook' => url_base() . '/ingressos/api/webhook.php?conta=' . $id]);
    }

    case 'conta_excluir': {
      $id = (int)(corpo()['id'] ?? 0);
      $st = $pdo->prepare('SELECT (SELECT COUNT(*) FROM pagamentos WHERE conta_id = ?) + (SELECT COUNT(*) FROM eventos WHERE pix_conta_id = ? OR cartao_conta_id = ?)');
      $st->execute([$id, $id, $id]);
      if ((int)$st->fetchColumn() > 0) erro('Esta conta está em uso por eventos ou pagamentos. Desative em vez de excluir.');
      $pdo->prepare('DELETE FROM contas WHERE id = ?')->execute([$id]);
      json_out(['ok' => true]);
    }

    /* ================= usuários ================= */
    case 'usuarios': {
      json_out($pdo->query('SELECT id, nome, email, papel, ativo, ultimo_login, criado_em FROM usuarios ORDER BY papel, nome')->fetchAll());
    }

    case 'usuario_salvar': {
      $b = corpo();
      $id = (int)($b['id'] ?? 0);
      $nome = trim((string)($b['nome'] ?? ''));
      $email = mb_strtolower(trim((string)($b['email'] ?? '')));
      $papel = ($b['papel'] ?? '') === 'admin' ? 'admin' : 'checkin';
      $ativo = empty($b['ativo']) && isset($b['ativo']) ? 0 : 1;
      $senha = (string)($b['senha'] ?? '');
      if ($nome === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) erro('Informe nome e e-mail válidos.');
      if ($id === (int)$eu['id'] && ($papel !== 'admin' || !$ativo)) erro('Você não pode tirar o seu próprio acesso de administrador.');
      $st = $pdo->prepare('SELECT id FROM usuarios WHERE email = ? AND id <> ?');
      $st->execute([$email, $id]);
      if ($st->fetchColumn()) erro('Já existe um usuário com esse e-mail.');
      if ($id) {
        $pdo->prepare('UPDATE usuarios SET nome=?, email=?, papel=?, ativo=? WHERE id=?')->execute([$nome, $email, $papel, $ativo, $id]);
        if ($senha !== '') {
          if (strlen($senha) < 8) erro('A senha precisa ter pelo menos 8 caracteres.');
          $pdo->prepare('UPDATE usuarios SET senha_hash=? WHERE id=?')->execute([password_hash($senha, PASSWORD_DEFAULT), $id]);
        }
      } else {
        if (strlen($senha) < 8) erro('Defina uma senha com pelo menos 8 caracteres.');
        $pdo->prepare('INSERT INTO usuarios (nome, email, senha_hash, papel, ativo) VALUES (?,?,?,?,?)')->execute([$nome, $email, password_hash($senha, PASSWORD_DEFAULT), $papel, $ativo]);
        $id = (int)$pdo->lastInsertId();
      }
      json_out(['ok' => true, 'id' => $id]);
    }

    case 'usuario_excluir': {
      $id = (int)(corpo()['id'] ?? 0);
      if ($id === (int)$eu['id']) erro('Você não pode excluir o seu próprio usuário.');
      $st = $pdo->prepare('SELECT COUNT(*) FROM checkins WHERE usuario_id = ?');
      $st->execute([$id]);
      if ((int)$st->fetchColumn() > 0) $pdo->prepare('UPDATE usuarios SET ativo = 0 WHERE id = ?')->execute([$id]);
      else $pdo->prepare('DELETE FROM usuarios WHERE id = ?')->execute([$id]);
      json_out(['ok' => true]);
    }

    /* ================= configurações gerais ================= */
    case 'config': {
      if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $b = corpo();
        if (isset($b['nome_organizacao'])) cfg_set('nome_organizacao', trim((string)$b['nome_organizacao']));
        if (isset($b['email_remetente'])) {
          if (!filter_var($b['email_remetente'], FILTER_VALIDATE_EMAIL)) erro('E-mail remetente inválido.');
          cfg_set('email_remetente', trim((string)$b['email_remetente']));
        }
      }
      json_out(['nome_organizacao' => cfg('nome_organizacao'), 'email_remetente' => cfg('email_remetente'), 'versao' => ING_VERSAO]);
    }

    default:
      erro('Ação inválida.', 404);
  }
} catch (DomainException $e) {
  if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
  erro($e->getMessage(), 422);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->exec('ROLLBACK');
  error_log('[ingressos-admin] ' . $e->getMessage());
  erro('Erro interno: ' . $e->getMessage(), 500);
}
