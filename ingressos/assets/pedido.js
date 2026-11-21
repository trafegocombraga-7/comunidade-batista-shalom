/* Página do pedido: mostra o PIX (e acompanha o pagamento) ou os ingressos com QR Code. */
(function () {
  'use strict';
  const P = window.PEDIDO;
  const box = document.getElementById('pedido');
  const brl = c => (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  const params = new URLSearchParams(location.search);

  function qrSvg(texto, celula = 4) {
    if (!window.qrcode) return `<p style="font-size:12px;word-break:break-all">${esc(texto)}</p>`;
    const q = window.qrcode(0, 'M');
    q.addData(texto); q.make();
    return q.createSvgTag({ cellSize: celula, margin: 2, scalable: true });
  }

  function quando() {
    if (!P.evento.inicio) return '';
    const d = new Date(P.evento.inicio.replace(' ', 'T'));
    return d.toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long' }) + ' às ' + d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  }

  function desenhar() {
    if (P.status === 'pago') return pago();
    if (P.status === 'pendente' && P.pix) return pix();
    if (P.status === 'pendente') return analise();
    const txt = { expirado: ['O prazo do PIX acabou', 'Nenhum valor foi cobrado. Você pode fazer um novo pedido.'],
      cancelado: ['Pedido cancelado', 'Nenhum valor foi cobrado.'],
      recusado: ['Pagamento não aprovado', 'Nenhum valor foi cobrado. Tente novamente com outro cartão ou com PIX.'],
      estornado: ['Pedido estornado', 'O valor foi devolvido e os ingressos foram cancelados.'] }[P.status] || ['Pedido', ''];
    box.innerHTML = `<section class="bloco"><div class="status-ico status-ico--ruim"><i class="fa-solid fa-xmark"></i></div>
      <h1>${txt[0]}</h1><p class="sub">${txt[1]}</p>
      <div class="acoes"><a class="btn" href="/e/${esc(P.evento.slug)}">Fazer novo pedido</a></div></section>`;
  }

  let relogio;
  function pix() {
    box.innerHTML = `<section class="bloco">
      <div class="status-ico"><i class="fa-brands fa-pix"></i></div>
      <h1>Pague com PIX para garantir</h1>
      <p class="sub">Abra o app do seu banco, escolha PIX e escaneie o código ou use o "copia e cola".</p>
      <div class="pix-qr">${P.pix.qr_img ? `<img src="${esc(P.pix.qr_img)}" alt="QR Code PIX">` : qrSvg(P.pix.copia_cola, 5)}</div>
      <div class="pix-valor">${brl(P.pix.valor)}</div>
      <div class="copia"><input id="cc" readonly value="${esc(P.pix.copia_cola)}"><button class="btn" id="copiar" type="button"><i class="fa-regular fa-copy"></i> Copiar</button></div>
      <p class="tempo" id="tempo"></p>
      <div class="aguarde">Aguardando o pagamento…</div>
      <ol class="passos"><li>Assim que o pagamento cair, esta página atualiza sozinha.</li><li>Os ingressos também chegam no e-mail <b>${esc(P.email)}</b>.</li></ol>
      ${P.simulador ? `<div class="simular"><b>Ambiente de testes.</b> Este PIX é de mentira. Clique para simular que ele foi pago.<br><button class="btn" id="simular" type="button">Simular pagamento</button></div>` : ''}
    </section>`;
    document.getElementById('copiar').onclick = async () => {
      const inp = document.getElementById('cc');
      try { await navigator.clipboard.writeText(inp.value); } catch (e) { inp.select(); document.execCommand('copy'); }
      document.getElementById('copiar').innerHTML = '<i class="fa-solid fa-check"></i> Copiado';
    };
    const sim = document.getElementById('simular');
    if (sim) sim.onclick = async () => {
      sim.disabled = true;
      const r = await fetch('/ingressos/api/publico.php?acao=simular_pix', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ c: params.get('c'), a: params.get('a') }) });
      if (r.ok) location.reload(); else sim.disabled = false;
    };
    const fim = P.expira_em ? new Date(P.expira_em.replace(' ', 'T')).getTime() : 0;
    clearInterval(relogio);
    relogio = setInterval(() => {
      const s = Math.max(0, Math.floor((fim - Date.now()) / 1000));
      document.getElementById('tempo').innerHTML = fim ? `O código vale por <b>${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}</b>` : '';
      if (fim && s === 0) { clearInterval(relogio); checar(); }
    }, 1000);
    acompanhar();
  }

  function analise() {
    box.innerHTML = `<section class="bloco"><div class="status-ico"><i class="fa-solid fa-hourglass-half"></i></div>
      <h1>Pagamento em análise</h1><p class="sub">O banco do cartão está confirmando a compra. Pode levar alguns minutos; avisamos por e-mail.</p>
      <div class="aguarde">Aguardando confirmação…</div></section>`;
    acompanhar();
  }

  let espera;
  async function checar() {
    try {
      const r = await fetch(`/ingressos/api/publico.php?acao=status&c=${encodeURIComponent(params.get('c'))}&a=${encodeURIComponent(params.get('a'))}`, { cache: 'no-store' });
      if (!r.ok) return;
      const j = await r.json();
      if (j.status !== P.status) location.reload();
    } catch (e) { /* sem conexão: tenta de novo */ }
  }
  function acompanhar() { clearInterval(espera); espera = setInterval(checar, 4000); }

  function pago() {
    const validos = P.ingressos.filter(g => g.status === 'valido').length;
    box.innerHTML = `<section class="bloco">
      <div class="status-ico status-ico--ok"><i class="fa-solid fa-check"></i></div>
      <h1>Tudo certo, ${esc(P.comprador)}!</h1>
      <p class="sub">${validos === 1 ? 'Seu ingresso está garantido' : `Seus ${validos} ingressos estão garantidos`} para <b>${esc(P.evento.nome)}</b>. Enviamos também para ${esc(P.email)}.</p>
      ${P.evento.inicio ? `<p class="sub" style="margin-top:10px"><i class="fa-regular fa-calendar"></i> ${esc(quando())}${P.evento.local ? `<br><i class="fa-solid fa-location-dot"></i> ${esc(P.evento.local)}` : ''}</p>` : ''}
      <div class="ingressos">${P.ingressos.map(g => `
        <article class="ingresso${g.status !== 'valido' ? ' is-cancelado' : ''}">
          <div class="ingresso__qr">${g.status === 'valido' ? qrSvg(g.codigo) : '<i class="fa-solid fa-ban" style="font-size:40px;color:#bbb"></i>'}</div>
          <div class="ingresso__info">
            <span class="ingresso__evento">${esc(P.evento.nome)}</span>
            <span class="ingresso__tipo">${esc(g.tipo)}</span>
            <span class="ingresso__nome">${esc(g.titular)}</span>
            <span class="ingresso__cod">${esc(g.codigo)}</span>
            ${g.status !== 'valido' ? '<span class="ingresso__usado" style="background:#fdeaea;color:#c0392b">Cancelado</span>' : g.checkin_em ? '<span class="ingresso__usado">Entrada registrada</span>' : ''}
          </div>
        </article>`).join('')}</div>
      <div class="acoes"><button class="btn" type="button" onclick="print()"><i class="fa-solid fa-print"></i> Imprimir / salvar PDF</button><a class="btn btn--sec" href="/">Voltar ao site</a></div>
      <p class="sub" style="margin-top:18px;font-size:13px">Na entrada, mostre o QR Code de cada ingresso (pode ser na tela do celular).</p>
    </section>`;
  }

  desenhar();
})();
