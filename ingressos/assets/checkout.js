/* Checkout de ingressos: escolha, dados, pagamento (PIX, cartão ou dois cartões). */
(function () {
  'use strict';
  const $ = s => document.querySelector(s);
  const API = '/ingressos/api/publico.php';
  const slug = $('#loja').dataset.slug;
  const brl = c => (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));

  const st = { ev: null, qtd: {}, cupom: '', metodo: null, cot: null, parcelas: [1, 1], base1: null, cotando: 0 };

  async function api(acao, dados, metodo = 'POST') {
    const r = await fetch(`${API}?acao=${acao}` + (metodo === 'GET' ? '&' + new URLSearchParams(dados) : ''), {
      method: metodo, headers: { 'Content-Type': 'application/json' }, credentials: 'same-origin',
      body: metodo === 'POST' ? JSON.stringify(dados) : undefined,
    });
    const j = await r.json().catch(() => ({ erro: 'Falha de conexão. Tente de novo.' }));
    if (!r.ok) throw new Error(j.erro || 'Erro inesperado.');
    return j;
  }

  /* ---------------- máscaras ---------------- */
  const mascara = (el, fn) => el.addEventListener('input', () => { const p = el.selectionStart, a = el.value.length; el.value = fn(el.value); const d = el.value.length - a; try { el.setSelectionRange(p + d, p + d); } catch (e) {} });
  const dig = s => s.replace(/\D/g, '');
  mascara($('#telefone'), v => { const d = dig(v).slice(0, 11); return d.length > 10 ? `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}` : d.length > 6 ? `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}` : d.length > 2 ? `(${d.slice(0, 2)}) ${d.slice(2)}` : d; });
  mascara($('#cpf'), v => { const d = dig(v).slice(0, 11); return d.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2'); });

  /* ---------------- carregar evento ---------------- */
  api('evento', { slug }, 'GET').then(ev => {
    st.ev = ev;
    if (ev.pagamento.teste) $('#avisoTeste').hidden = false;
    if (!ev.pedir_cpf) $('#campoCpf').hidden = true;
    const preTipo = new URLSearchParams(location.search).get('tipo');
    ev.tipos.forEach(t => { st.qtd[t.id] = 0; });
    if (preTipo && st.qtd[preTipo] !== undefined) { const t = ev.tipos.find(x => String(x.id) === preTipo); if (t && !t.esgotado && t.disponivel) st.qtd[preTipo] = 1; }
    desenharTipos();
    desenharMetodos();
    if (ev.vendas !== 'abertas') {
      $('#pagar').textContent = ev.vendas === 'em_breve' ? 'Vendas em breve' : 'Vendas encerradas';
      document.querySelectorAll('#blocoDados input, #blocoPagamento input').forEach(i => i.disabled = true);
    }
    cotar();
  }).catch(e => { $('#tipos').innerHTML = `<p class="erro">${esc(e.message)}</p>`; });

  function desenharTipos() {
    const fechado = st.ev.vendas !== 'abertas';
    $('#tipos').innerHTML = st.ev.tipos.map(t => {
      const off = fechado || t.esgotado || !t.disponivel;
      const selo = t.esgotado ? '<span class="tipo__selo tipo__selo--off">Esgotado</span>' : !t.disponivel ? '<span class="tipo__selo tipo__selo--off">Indisponível</span>' : t.poucos ? '<span class="tipo__selo tipo__selo--poucos">Últimos</span>' : '';
      return `<div class="tipo${off ? ' is-off' : ''}" data-id="${t.id}">
        <div class="tipo__info"><div class="tipo__nome">${esc(t.nome)}${selo}</div>
          ${t.descricao ? `<div class="tipo__desc">${esc(t.descricao)}</div>` : ''}
          <div class="tipo__preco">${t.preco > 0 ? brl(t.preco) : 'Gratuito'}${t.pessoas > 1 ? ` <small style="color:var(--suave);font-weight:600">· ${t.pessoas} pessoas</small>` : ''}</div></div>
        <div class="qtd"><button type="button" data-d="-1" aria-label="Menos" ${off || !st.qtd[t.id] ? 'disabled' : ''}>−</button><b>${st.qtd[t.id]}</b><button type="button" data-d="1" aria-label="Mais" ${off || st.qtd[t.id] >= t.max ? 'disabled' : ''}>+</button></div>
      </div>`;
    }).join('') || '<p class="nota">Nenhum ingresso cadastrado ainda.</p>';
  }
  $('#tipos').addEventListener('click', e => {
    const b = e.target.closest('button[data-d]'); if (!b) return;
    const id = b.closest('.tipo').dataset.id;
    const t = st.ev.tipos.find(x => String(x.id) === id);
    const total = Object.values(st.qtd).reduce((a, b) => a + b, 0);
    const d = +b.dataset.d;
    if (d > 0 && total >= st.ev.limite_por_pedido) return mostrarErro(`Máximo de ${st.ev.limite_por_pedido} ingressos por pedido.`);
    st.qtd[id] = Math.max(0, Math.min(t.max, st.qtd[id] + d));
    st.base1 = null;
    mostrarErro('');
    desenharTipos(); cotar();
  });

  /* ---------------- cupom ---------------- */
  $('#abrirCupom').addEventListener('click', () => { $('.cupom__campo').hidden = false; $('#abrirCupom').hidden = true; $('#cupom').focus(); });
  $('#aplicarCupom').addEventListener('click', () => { st.cupom = $('#cupom').value.trim(); cotar(true); });
  $('#cupom').addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('#aplicarCupom').click(); } });

  /* ---------------- métodos de pagamento ---------------- */
  function desenharMetodos() {
    const p = st.ev.pagamento;
    const ops = [];
    if (p.pix) ops.push(['pix', 'fa-brands fa-pix', 'PIX', 'Aprovação na hora']);
    if (p.cartao) ops.push(['cartao', 'fa-regular fa-credit-card', 'Cartão de crédito', p.max_parcelas > 1 ? `Em até ${p.max_parcelas}x` : 'À vista']);
    if (p.cartao && p.dois_cartoes) ops.push(['dois_cartoes', 'fa-solid fa-clone', 'Dois cartões', 'Divida o valor']);
    if (!ops.length) { $('#metodos').innerHTML = '<p class="nota">Pagamento ainda não configurado para este evento.</p>'; return; }
    st.metodo = st.metodo || ops[0][0];
    $('#metodos').innerHTML = ops.map(([v, ic, t, s]) => `<label class="metodo${st.metodo === v ? ' is-on' : ''}"><input type="radio" name="metodo" value="${v}" ${st.metodo === v ? 'checked' : ''}><i class="${ic}"></i><b>${t}</b><small>${s}</small></label>`).join('');
    painelMetodo();
  }
  $('#metodos').addEventListener('change', e => {
    st.metodo = e.target.value; st.parcelas = [1, 1];
    document.querySelectorAll('.metodo').forEach(m => m.classList.toggle('is-on', m.querySelector('input').checked));
    painelMetodo(); cotar();
  });

  function formCartao(i, dois) {
    return `<div class="cartao" data-i="${i}">
      <div class="cartao__topo"><b>${dois ? `${i + 1}º cartão` : 'Dados do cartão'}</b><i class="cartao__band fa-regular fa-credit-card" data-band></i></div>
      <div class="campos">
        <label class="campo campo--2">Número do cartão<input data-c="numero" inputmode="numeric" autocomplete="cc-number" placeholder="0000 0000 0000 0000"></label>
        <label class="campo campo--2">Nome impresso no cartão<input data-c="nome" autocomplete="cc-name"></label>
        <label class="campo">Validade<input data-c="validade" inputmode="numeric" autocomplete="cc-exp" placeholder="MM/AA"></label>
        <label class="campo">CVV<input data-c="cvv" inputmode="numeric" autocomplete="cc-csc" placeholder="123"></label>
        <label class="campo campo--2">Parcelas<select data-c="parcelas"><option value="1">1x</option></select></label>
      </div>
    </div>`;
  }

  function painelMetodo() {
    $('#painelPix').hidden = st.metodo !== 'pix';
    const pc = $('#painelCartao');
    pc.hidden = st.metodo === 'pix';
    if (st.metodo === 'pix') return;
    const dois = st.metodo === 'dois_cartoes';
    pc.innerHTML = formCartao(0, dois)
      + (dois ? `<div class="dividir"><label>Quanto vai no 1º cartão</label><div class="dividir__linha"><input type="range" id="divisor" min="100" step="100"><span class="dividir__val" id="divisorVal"></span></div></div>` + formCartao(1, true) : '')
      + (st.ev.pagamento.tokenizacao.tipo === 'sandbox' ? `<p class="teste-dica"><b>Cartões de teste:</b> 4111 1111 1111 1111 (aprovado) · 4000 0000 0000 0002 (recusado). Qualquer validade futura e CVV.</p>` : '');
    pc.querySelectorAll('[data-c="numero"]').forEach(el => mascara(el, v => dig(v).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 ')));
    pc.querySelectorAll('[data-c="validade"]').forEach(el => mascara(el, v => { const d = dig(v).slice(0, 4); return d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d; }));
    pc.querySelectorAll('[data-c="cvv"]').forEach(el => mascara(el, v => dig(v).slice(0, 4)));
    pc.querySelectorAll('[data-c="numero"]').forEach(el => el.addEventListener('input', () => {
      const b = bandeira(dig(el.value));
      const ic = el.closest('.cartao').querySelector('[data-band]');
      ic.className = 'cartao__band ' + ({ visa: 'fa-brands fa-cc-visa', master: 'fa-brands fa-cc-mastercard', amex: 'fa-brands fa-cc-amex', diners: 'fa-brands fa-cc-diners-club', discover: 'fa-brands fa-cc-discover' }[b] || 'fa-regular fa-credit-card');
    }));
    pc.querySelectorAll('[data-c="parcelas"]').forEach(el => el.addEventListener('change', () => { st.parcelas[+el.closest('.cartao').dataset.i] = +el.value; cotar(); }));
    const div = $('#divisor');
    if (div) {
      let t;
      div.addEventListener('input', () => { st.base1 = +div.value; $('#divisorVal').textContent = brl(st.base1); clearTimeout(t); t = setTimeout(cotar, 250); });
    }
  }

  function bandeira(n) {
    if (/^4/.test(n)) return 'visa';
    if (/^(5[1-5]|2[2-7])/.test(n)) return 'master';
    if (/^3[47]/.test(n)) return 'amex';
    if (/^(4011|4312|4389|4514|4576|5041|5066|5067|509|6277|6362|6363|650|6516|6550)/.test(n)) return 'elo';
    if (/^(606282|3841)/.test(n)) return 'hipercard';
    if (/^3(0[0-5]|[68])/.test(n)) return 'diners';
    if (/^6(011|5)/.test(n)) return 'discover';
    return '';
  }

  /* ---------------- cotação ---------------- */
  const itens = () => Object.fromEntries(Object.entries(st.qtd).filter(([, q]) => q > 0));
  async function cotar(doCupom) {
    const it = itens();
    if (!Object.keys(it).length || !st.ev || st.ev.vendas !== 'abertas') { st.cot = null; resumo(); return; }
    const n = ++st.cotando;
    const cartoes = [{ parcelas: st.parcelas[0], base: st.base1 ?? undefined }, { parcelas: st.parcelas[1] }];
    try {
      const c = await api('cotar', { slug, itens: it, cupom: st.cupom, metodo: st.metodo || 'pix', cartoes });
      if (n !== st.cotando) return;
      st.cot = c;
      if (doCupom) msgCupom(c.desconto > 0 ? `Cupom aplicado: −${brl(c.desconto)}` : 'Cupom aplicado.', true);
      mostrarErro('');
    } catch (e) {
      if (n !== st.cotando) return;
      if (doCupom || /cupom/i.test(e.message)) { msgCupom(e.message, false); st.cupom = ''; return cotar(); }
      st.cot = null; mostrarErro(e.message);
    }
    resumo();
  }
  function msgCupom(t, ok) { const m = $('#cupomMsg'); m.textContent = t; m.className = 'cupom__msg ' + (ok ? 'ok' : 'ruim'); }

  function resumo() {
    const c = st.cot;
    const linhas = st.ev ? st.ev.tipos.filter(t => st.qtd[t.id] > 0).map(t => `<li><span>${st.qtd[t.id]}× ${esc(t.nome)}</span><span>${brl(t.preco * st.qtd[t.id])}</span></li>`) : [];
    $('#resumoItens').innerHTML = linhas.join('') || '<li class="vazio">Nenhum ingresso escolhido.</li>';
    let contas = '';
    if (c) {
      if (c.desconto) contas += `<dt>Desconto</dt><dd>−${brl(c.desconto)}</dd>`;
      if (c.taxa) contas += `<dt>Taxa de serviço</dt><dd>${brl(c.taxa)}</dd>`;
      if (c.cobrancas.length === 2) c.cobrancas.forEach((x, i) => { contas += `<dt>${i + 1}º cartão</dt><dd>${brl(x.valor)}</dd>`; });
    }
    $('#resumoContas').innerHTML = contas;
    $('#total').textContent = brl(c ? c.total : 0);
    $('#resumoParc').textContent = c && c.cobrancas.length === 1 && c.cobrancas[0].parcelas > 1 ? `${c.cobrancas[0].parcelas}x de ${brl(c.cobrancas[0].valor_parcela)}` : '';
    // parcelas e divisor
    if (c && c.opcoes) {
      document.querySelectorAll('#painelCartao .cartao').forEach(el => {
        const i = +el.dataset.i, ops = c.opcoes[i] || [], sel = el.querySelector('[data-c="parcelas"]');
        sel.innerHTML = ops.map(o => `<option value="${o.parcelas}" ${o.parcelas === st.parcelas[i] ? 'selected' : ''}>${o.parcelas}x de ${brl(o.parcela)}${o.parcelas > 1 ? ` (${brl(o.total)})` : ' à vista'}</option>`).join('');
      });
      const div = $('#divisor');
      if (div && c.cobrancas.length === 2) {
        div.max = String(c.base - 100);
        const b1 = c.cobrancas[0].base;
        if (document.activeElement !== div) div.value = String(b1);
        $('#divisorVal').textContent = brl(b1);
      }
    }
    const fechado = !st.ev || st.ev.vendas !== 'abertas';
    const btn = $('#pagar');
    if (fechado) { btn.disabled = true; return; }
    btn.disabled = !c;
    btn.textContent = !c ? 'Escolha seus ingressos' : c.total === 0 ? 'Confirmar inscrição' : st.metodo === 'pix' ? `Pagar ${brl(c.total)} com PIX` : `Pagar ${brl(c.total)}`;
  }

  function mostrarErro(t) { $('#erro').textContent = t; }

  /* ---------------- cartão: tokenização ---------------- */
  function luhn(n) { let s = 0, alt = false; for (let i = n.length - 1; i >= 0; i--) { let d = +n[i]; if (alt) { d *= 2; if (d > 9) d -= 9; } s += d; alt = !alt; } return n.length >= 13 && s % 10 === 0; }

  function lerCartao(el, n) {
    const g = k => el.querySelector(`[data-c="${k}"]`);
    const numero = dig(g('numero').value), nome = g('nome').value.trim(), [mm, aa] = g('validade').value.split('/'), cvv = dig(g('cvv').value);
    const qual = n > 1 ? ` do ${+el.dataset.i + 1}º cartão` : '';
    const marca = (k, m) => { g(k).classList.add('is-ruim'); g(k).focus(); throw new Error(m + qual + '.'); };
    el.querySelectorAll('input').forEach(i => i.classList.remove('is-ruim'));
    if (!luhn(numero)) marca('numero', 'Número inválido');
    if (nome.length < 3) marca('nome', 'Informe o nome impresso');
    const m = +mm, a = 2000 + +aa, hoje = new Date();
    if (!(m >= 1 && m <= 12) || !aa || aa.length !== 2 || a < hoje.getFullYear() || (a === hoje.getFullYear() && m < hoje.getMonth() + 1)) marca('validade', 'Validade inválida');
    if (cvv.length < 3) marca('cvv', 'CVV inválido');
    return { numero, nome, mm: String(m).padStart(2, '0'), aa: String(a), cvv, parcelas: +g('parcelas').value || 1 };
  }

  let mpSdk = null;
  async function tokenizar(c) {
    const tk = st.ev.pagamento.tokenizacao;
    if (tk.tipo === 'sandbox') {
      const dados = { final: c.numero.slice(-4), bandeira: bandeira(c.numero) || 'cartao', resultado: c.numero.endsWith('0002') ? 'recusado' : 'aprovado' };
      return { token: 'sbx_' + btoa(JSON.stringify(dados)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''), final: dados.final, bandeira: dados.bandeira };
    }
    if (tk.tipo === 'mercadopago') {
      if (!mpSdk) {
        await new Promise((ok, erro) => { const s = document.createElement('script'); s.src = 'https://sdk.mercadopago.com/js/v2'; s.onload = ok; s.onerror = () => erro(new Error('Não foi possível carregar o pagamento por cartão.')); document.head.appendChild(s); });
        mpSdk = new window.MercadoPago(tk.chave, { locale: 'pt-BR' });
      }
      const cpf = dig($('#cpf').value);
      const t = await mpSdk.createCardToken({ cardNumber: c.numero, cardholderName: c.nome, cardExpirationMonth: c.mm, cardExpirationYear: c.aa, securityCode: c.cvv, identificationType: 'CPF', identificationNumber: cpf });
      const pm = await mpSdk.getPaymentMethods({ bin: c.numero.slice(0, 8) });
      return { token: t.id, final: c.numero.slice(-4), bandeira: (pm.results && pm.results[0] && pm.results[0].id) || bandeira(c.numero) };
    }
    throw new Error('Pagamento com cartão indisponível no momento.');
  }

  /* ---------------- finalizar ---------------- */
  function validarDados() {
    const marca = (id, m) => { const el = $(id); el.classList.add('is-ruim'); el.focus(); throw new Error(m); };
    document.querySelectorAll('#blocoDados input').forEach(i => i.classList.remove('is-ruim'));
    const nome = $('#nome').value.trim();
    if (nome.split(/\s+/).length < 2) marca('#nome', 'Informe nome e sobrenome.');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test($('#email').value.trim())) marca('#email', 'E-mail inválido.');
    if (dig($('#telefone').value).length < 10) marca('#telefone', 'Informe o WhatsApp com DDD.');
    if (st.ev.pedir_cpf && !cpfOk(dig($('#cpf').value))) marca('#cpf', 'CPF inválido.');
  }
  function cpfOk(c) {
    if (c.length !== 11 || /^(\d)\1+$/.test(c)) return false;
    for (let t = 9; t < 11; t++) { let s = 0; for (let i = 0; i < t; i++) s += +c[i] * (t + 1 - i); if (+c[t] !== ((10 * s) % 11) % 10) return false; }
    return true;
  }

  $('#pagar').addEventListener('click', async () => {
    const btn = $('#pagar');
    mostrarErro('');
    try {
      validarDados();
      const corpo = { slug, itens: itens(), cupom: st.cupom, metodo: st.metodo, nome: $('#nome').value.trim(), email: $('#email').value.trim(), telefone: $('#telefone').value, cpf: $('#cpf').value };
      btn.classList.add('is-carregando'); btn.disabled = true;
      if (st.cot && st.cot.total > 0 && st.metodo !== 'pix') {
        const els = [...document.querySelectorAll('#painelCartao .cartao')];
        const lidos = els.map(el => lerCartao(el, els.length));
        corpo.cartoes = [];
        for (let i = 0; i < lidos.length; i++) {
          const t = await tokenizar(lidos[i]);
          corpo.cartoes.push({ ...t, parcelas: lidos[i].parcelas, base: i === 0 && els.length === 2 ? st.cot.cobrancas[0].base : undefined });
        }
      }
      const r = await api('comprar', corpo);
      location.href = r.link;
    } catch (e) {
      mostrarErro(e.message || 'Não foi possível concluir. Tente novamente.');
      btn.classList.remove('is-carregando'); btn.disabled = false;
      if (/esgot|disponível|Quantidade/.test(e.message)) api('evento', { slug }, 'GET').then(ev => { st.ev = ev; desenharTipos(); cotar(); });
    }
  });
})();
