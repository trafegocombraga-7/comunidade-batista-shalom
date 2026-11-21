<?php
/* Página de compra de um evento: /e/{slug} */
declare(strict_types=1);
require __DIR__ . '/lib/pedidos.php';

$slug = preg_replace('/[^a-z0-9-]/', '', strtolower((string)($_GET['evento'] ?? ''))) ?? '';
$ev = $slug ? evento_por_slug($slug) : null;
if (!$ev || ($ev['status'] === 'rascunho' && !usuario_atual())) {
  http_response_code(404);
  echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Evento não encontrado</title>'
     . '<body style="font-family:system-ui;display:grid;place-items:center;min-height:90vh;text-align:center;color:#333"><div><h1>Evento não encontrado</h1><p><a href="/">Voltar para o site</a></p></div>';
  exit;
}
$cor = preg_match('/^#[0-9a-f]{6}$/i', $ev['cor']) ? $ev['cor'] : '#055a85';
$img = $ev['imagem'] ?: '';
$quando = $ev['inicio'] ? strtotime($ev['inicio']) : null;
$meses = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ingressos · <?= h($ev['nome']) ?></title>
<meta name="description" content="Garanta seu ingresso para <?= h($ev['nome']) ?>.">
<meta property="og:title" content="Ingressos · <?= h($ev['nome']) ?>">
<?php if ($img): ?><meta property="og:image" content="<?= h(str_starts_with($img, 'http') ? $img : url_base() . $img) ?>"><?php endif; ?>
<?= $ev['status'] !== 'publicado' ? '<meta name="robots" content="noindex">' : '' ?>
<link rel="icon" type="image/png" href="/assets/favicon-branco.png?v=1">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<link rel="stylesheet" href="/ingressos/assets/loja.css?v=1">
<style>:root{--cor:<?= h($cor) ?>}</style>
</head>
<body>

<header class="topo">
  <a class="topo__marca" href="/"><img src="/assets/logo.png" alt=""><span><?= h(cfg('nome_organizacao', 'Comunidade Batista Shalom')) ?></span></a>
  <span class="topo__seguro"><i class="fa-solid fa-lock"></i> Compra segura</span>
</header>

<section class="capa"<?= $img ? ' style="--img:url(' . h($img) . ')"' : '' ?>>
  <div class="capa__fundo"></div>
  <div class="capa__conteudo">
    <?php if ($quando): ?>
    <div class="capa__data"><b><?= date('d', $quando) ?></b><span><?= $meses[(int)date('n', $quando) - 1] ?></span></div>
    <?php endif; ?>
    <div>
      <h1><?= h($ev['nome']) ?></h1>
      <p class="capa__info">
        <?php if ($quando): ?><span><i class="fa-regular fa-calendar"></i> <?= date('d/m/Y', $quando) ?><?= $ev['fim'] && date('Y-m-d', strtotime($ev['fim'])) !== date('Y-m-d', $quando) ? ' a ' . date('d/m/Y', strtotime($ev['fim'])) : '' ?> · <?= date('H\hi', $quando) ?></span><?php endif; ?>
        <?php if ($ev['local_nome'] || $ev['endereco']): ?><span><i class="fa-solid fa-location-dot"></i> <?= h(trim($ev['local_nome'] . ($ev['endereco'] ? ' · ' . $ev['endereco'] : ''))) ?></span><?php endif; ?>
      </p>
    </div>
  </div>
</section>

<?php if ($ev['status'] === 'rascunho'): ?>
<div class="aviso aviso--rascunho"><i class="fa-solid fa-eye"></i> Pré-visualização: este evento está em rascunho e só a equipe logada consegue ver.</div>
<?php endif; ?>
<div class="aviso aviso--teste" id="avisoTeste" hidden><i class="fa-solid fa-flask"></i> Ambiente de testes: nenhum valor é cobrado de verdade.</div>

<main class="loja" id="loja" data-slug="<?= h($ev['slug']) ?>">
  <div class="loja__principal">
    <section class="bloco" id="blocoIngressos">
      <h2><span>1</span> Ingressos</h2>
      <div id="tipos" class="tipos"><div class="carregando"></div></div>
      <div class="cupom" id="cupomBox">
        <button type="button" class="link" id="abrirCupom"><i class="fa-solid fa-tag"></i> Tenho um cupom</button>
        <div class="cupom__campo" hidden><input id="cupom" placeholder="CÓDIGO" autocomplete="off"><button type="button" id="aplicarCupom" class="btn btn--sec">Aplicar</button></div>
        <p class="cupom__msg" id="cupomMsg"></p>
      </div>
    </section>

    <section class="bloco" id="blocoDados">
      <h2><span>2</span> Seus dados</h2>
      <div class="campos">
        <label class="campo campo--2">Nome completo<input id="nome" autocomplete="name" required></label>
        <label class="campo">E-mail<input id="email" type="email" autocomplete="email" inputmode="email" required></label>
        <label class="campo">WhatsApp<input id="telefone" autocomplete="tel" inputmode="tel" placeholder="(44) 99999-9999" required></label>
        <label class="campo" id="campoCpf">CPF<input id="cpf" inputmode="numeric" placeholder="000.000.000-00" autocomplete="off"></label>
      </div>
      <p class="nota">Os ingressos chegam neste e-mail. Confira antes de pagar.</p>
    </section>

    <section class="bloco" id="blocoPagamento">
      <h2><span>3</span> Pagamento</h2>
      <div class="metodos" id="metodos" role="radiogroup"></div>
      <div id="painelPix" class="metodo-painel" hidden>
        <p><i class="fa-brands fa-pix"></i> Ao finalizar, mostramos o QR Code e o código "copia e cola". A confirmação é automática, em segundos.</p>
      </div>
      <div id="painelCartao" class="metodo-painel" hidden></div>
    </section>
  </div>

  <aside class="resumo" id="resumo">
    <h3>Resumo</h3>
    <ul class="resumo__itens" id="resumoItens"><li class="vazio">Nenhum ingresso escolhido.</li></ul>
    <dl class="resumo__contas" id="resumoContas"></dl>
    <div class="resumo__total"><span>Total</span><b id="total">R$ 0,00</b></div>
    <p class="resumo__parc" id="resumoParc"></p>
    <button class="btn btn--pagar" id="pagar" type="button" disabled>Escolha seus ingressos</button>
    <p class="erro" id="erro" role="alert"></p>
    <p class="resumo__seg"><i class="fa-solid fa-shield-halved"></i> Seus dados de cartão são protegidos e não ficam guardados conosco.</p>
  </aside>
</main>

<footer class="rodape">
  <p><?= h(cfg('nome_organizacao', 'Comunidade Batista Shalom')) ?> · Dúvidas? Fale com a nossa equipe.</p>
</footer>

<script src="/ingressos/assets/checkout.js?v=1"></script>
</body>
</html>
