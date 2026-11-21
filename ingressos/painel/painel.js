/* Painel de ingressos: eventos, vendas, cortesias, contas de recebimento e equipe. */
(function () {
  'use strict';
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  const brl = c => ((+c || 0) / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const reais = c => ((+c || 0) / 100).toFixed(2).replace('.', ',');
  const dataBR = s => s ? new Date(s.replace(' ', 'T')).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—';
  const paraInput = s => s ? s.replace(' ', 'T').slice(0, 16) : '';
  const STATUS = { pago: 'Pago', pendente: 'Pendente', expirado: 'Expirado', cancelado: 'Cancelado', estornado: 'Estornado', recusado: 'Recusado', publicado: 'Publicado', rascunho: 'Rascunho', encerrado: 'Encerrado', aprovado: 'Aprovado', valido: 'Válido' };
  const METODO = { pix: 'PIX', cartao: 'Cartão', dois_cartoes: '2 cartões', cortesia: 'Cortesia', manual: 'Venda manual' };
  const selo = s => `<span class="selo s-${esc(s)}">${esc(STATUS[s] || s)}</span>`;

  let csrf = null, eu = null;

  async function api(arq, acao, dados, metodo = 'GET', form) {
    const url = `/ingressos/api/${arq}.php?acao=${acao}` + (metodo === 'GET' && dados ? '&' + new URLSearchParams(dados) : '');
    const opt = { method: metodo, credentials: 'same-origin', headers: { 'X-CSRF': csrf || '' } };
    if (form) opt.body = form;
    else if (metodo === 'POST') { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(dados || {}); }
    const r = await fetch(url, opt);
    const j = await r.json().catch(() => ({ erro: 'O servidor não respondeu como esperado.' }));
    if (r.status === 401) { entrar(); throw new Error(j.erro); }
    if (!r.ok) throw new Error(j.erro || 'Erro.');
    return j;
  }
  const adm = (acao, dados, metodo) => api('admin', acao, dados, metodo);

  function toast(msg, ruim) {
    const t = $('#toast'); t.textContent = msg; t.className = 'toast' + (ruim ? ' ruim' : ''); t.hidden = false;
    clearTimeout(t._t); t._t = setTimeout(() => { t.hidden = true; }, 3200);
  }
  function modal(html) { $('#modalCaixa').innerHTML = html; $('#modal').hidden = false; }
  function fecharModal() { $('#modal').hidden = true; $('#modalCaixa').innerHTML = ''; }
  $('#modal').addEventListener('click', e => { if (e.target.id === 'modal' || e.target.closest('.fechar')) fecharModal(); });
  async function copiar(t) { try { await navigator.clipboard.writeText(t); toast('Link copiado!'); } catch (e) { prompt('Copie o link:', t); } }
  function dadosForm(f) {
    const d = {};
    $$('[name]', f).forEach(el => { d[el.name] = el.type === 'checkbox' ? el.checked : el.value; });
    return d;
  }

  /* ================= entrada ================= */
  async function entrar() {
    const me = await api('auth', 'me');
    if (me.usuario && me.usuario.papel === 'admin') {
      csrf = me.csrf; eu = me.usuario;
      $('#telaEntrada').hidden = true; $('#app').hidden = false;
      $('#euNome').textContent = eu.nome + ' · ' + eu.email;
      return rota();
    }
    $('#app').hidden = true; $('#telaEntrada').hidden = false;
    $('#formInstalar').hidden = me.instalado;
    $('#formLogin').hidden = !me.instalado;
    if (me.usuario && me.usuario.papel !== 'admin') $('#formLogin .erro').textContent = 'Seu usuário é só de check-in. Use o endereço /checkin.';
  }
  $('#formLogin').addEventListener('submit', async e => {
    e.preventDefault(); const f = e.target; $('.erro', f).textContent = '';
    try { await api('auth', 'login', dadosForm(f), 'POST'); await entrar(); } catch (er) { $('.erro', f).textContent = er.message; }
  });
  $('#formInstalar').addEventListener('submit', async e => {
    e.preventDefault(); const f = e.target; $('.erro', f).textContent = '';
    try {
      const d = dadosForm(f);
      await api('auth', 'instalar', d, 'POST');
      await api('auth', 'login', { email: d.email, senha: d.senha }, 'POST');
      await entrar();
      toast('Bem-vindo! O sistema está pronto.');
    } catch (er) { $('.erro', f).textContent = er.message; }
  });
  $('#sair').addEventListener('click', async () => { await api('auth', 'logout', {}, 'POST').catch(() => {}); csrf = null; entrar(); });

  /* ================= rotas ================= */
  const C = () => $('#conteudo');
  window.addEventListener('hashchange', rota);
  function rota() {
    const [r, id] = (location.hash.slice(1) || 'inicio').split('/');
    $$('.menu a[data-r]').forEach(a => a.classList.toggle('is-on', a.dataset.r === r || (r === 'evento' && a.dataset.r === 'eventos')));
    C().innerHTML = '<div class="vazio">Carregando…</div>';
    const f = { inicio, eventos, evento: () => editarEvento(id), vendas, cortesias, contas, equipe, conta: minhaConta }[r] || inicio;
    Promise.resolve(f()).catch(e => { C().innerHTML = `<div class="card erro">${esc(e.message)}</div>`; });
    window.scrollTo(0, 0);
  }

  /* ================= início ================= */
  async function inicio() {
    const r = await adm('resumo');
    const evs = r.eventos;
    if (!evs.length) { C().innerHTML = `<div class="cab"><h1>Início</h1></div><div class="card vazio">Nenhum evento ainda. <a href="#evento/novo">Criar o primeiro</a></div>`; return; }
    const tot = evs.reduce((a, e) => ({ bruto: a.bruto + +e.bruto, liquido: a.liquido + +e.liquido, ingressos: a.ingressos + +e.ingressos, pedidos: a.pedidos + +e.pedidos }), { bruto: 0, liquido: 0, ingressos: 0, pedidos: 0 });
    C().innerHTML = `<div class="cab"><h1>Olá, ${esc(eu.nome.split(' ')[0])}</h1><div class="cab__acoes"><a class="btn btn--pri" href="#evento/novo"><i class="fa-solid fa-plus"></i> Novo evento</a></div></div>
      <div class="nums">
        <div class="num"><span>Vendido (bruto)</span><b>${brl(tot.bruto)}</b><small>todos os eventos</small></div>
        <div class="num"><span>Líquido estimado</span><b>${brl(tot.liquido)}</b><small>depois das taxas dos bancos</small></div>
        <div class="num"><span>Ingressos emitidos</span><b>${tot.ingressos}</b><small>${tot.pedidos} pedidos pagos</small></div>
      </div>
      ${evs.map(e => {
        const pct = +e.lotacao > 0 ? Math.min(100, Math.round(+e.ingressos / +e.lotacao * 100)) : 0;
        return `<div class="card">
          <div class="cab" style="margin-bottom:12px"><h2 style="margin:0">${esc(e.nome)} ${selo(e.status)}</h2>
            <div class="cab__acoes"><button class="btn btn--peq" data-copiar="${esc(r.link_base + e.slug)}"><i class="fa-regular fa-copy"></i> Link de venda</button><a class="btn btn--peq" href="#vendas/${e.id}">Vendas</a><a class="btn btn--peq" href="#evento/${e.id}">Editar</a></div></div>
          <div class="nums" style="margin:0">
            <div class="num"><span>Bruto</span><b>${brl(e.bruto)}</b><small>${e.pedidos} pedidos pagos · ${e.pendentes} aguardando</small></div>
            <div class="num"><span>Taxas</span><b>${brl(e.taxas)}</b><small>${brl(e.taxas_cliente)} pagas pelos compradores</small></div>
            <div class="num"><span>Líquido</span><b>${brl(e.liquido)}</b><small>estimado</small></div>
            <div class="num"><span>Ingressos</span><b>${e.ingressos}${+e.lotacao ? ` / ${e.lotacao}` : ''}</b>${+e.lotacao ? `<div class="barra-prog"><i style="width:${pct}%"></i></div>` : '<small>sem limite de lotação</small>'}</div>
            <div class="num"><span>Check-in</span><b>${+e.presentes || 0}</b><small>de ${e.ingressos} ingressos</small></div>
          </div>
          <div class="grafico" data-grafico="${e.id}"></div>
        </div>`;
      }).join('')}`;
    $$('[data-copiar]').forEach(b => b.onclick = () => copiar(b.dataset.copiar));
    for (const el of $$('[data-grafico]')) {
      const g = await adm('grafico', { evento: el.dataset.grafico });
      if (!g.dias.length) { el.outerHTML = '<p class="ajuda" style="margin-top:12px">As vendas dos últimos 30 dias aparecerão aqui.</p>'; continue; }
      const max = Math.max(...g.dias.map(d => +d.valor));
      el.innerHTML = g.dias.map(d => `<div title="${d.pedidos} pedidos · ${brl(d.valor)}"><i style="height:${Math.max(2, Math.round(+d.valor / max * 130))}px"></i><small>${d.dia.slice(8, 10)}/${d.dia.slice(5, 7)}</small></div>`).join('');
    }
  }

  /* ================= eventos ================= */
  async function eventos() {
    const r = await adm('resumo');
    C().innerHTML = `<div class="cab"><h1>Eventos</h1><div class="cab__acoes"><a class="btn btn--pri" href="#evento/novo"><i class="fa-solid fa-plus"></i> Novo evento</a></div></div>
      <div class="ev-lista">${r.eventos.map(e => `<div class="ev" data-id="${e.id}"><div class="ev__img"></div><div class="ev__info"><b>${esc(e.nome)}</b><small>${e.inicio ? dataBR(e.inicio) : 'Sem data'} · ${e.ingressos} ingressos</small><div style="margin-top:8px">${selo(e.status)}</div></div></div>`).join('') || '<div class="card vazio">Nenhum evento.</div>'}</div>`;
    const lista = await adm('eventos');
    for (const e of lista) {
      const d = await adm('evento', { id: e.id });
      const img = $(`.ev[data-id="${e.id}"] .ev__img`);
      if (img && d.imagem) img.style.backgroundImage = `url("${d.imagem.replace(/"/g, '')}")`;
    }
    $$('.ev').forEach(el => el.onclick = () => { location.hash = 'evento/' + el.dataset.id; });
  }

  async function editarEvento(id) {
    const [ev, cs] = await Promise.all([id && id !== 'novo' ? adm('evento', { id }) : null, adm('contas')]);
    const e = ev || { nome: '', slug: '', descricao: '', local_nome: 'Comunidade Batista Shalom', endereco: '', inicio: '', fim: '', imagem: '', cor: '#055a85', status: 'rascunho', lotacao: 0, limite_por_pedido: 10, vendas_inicio: '', vendas_fim: '', taxa_modo: 'repassar', aceita_pix: 1, aceita_cartao: 1, dois_cartoes: 1, max_parcelas: 3, pix_conta_id: '', cartao_conta_id: '', pix_minutos: 30, pedir_cpf: 1, tipos: [{ nome: 'Ingresso', descricao: '', preco: 0, quantidade: 0, pessoas: 1, max_por_pedido: 10 }], cupons: [] };
    const contasPix = cs.contas.filter(c => +c.aceita_pix && +c.ativo), contasCC = cs.contas.filter(c => +c.aceita_cartao && +c.ativo);
    if (!ev) { e.pix_conta_id = contasPix[0]?.id || ''; e.cartao_conta_id = contasCC[0]?.id || ''; }
    const opcContas = (lista, sel) => '<option value="">— nenhuma —</option>' + lista.map(c => `<option value="${c.id}" ${String(c.id) === String(sel) ? 'selected' : ''}>${esc(c.nome)}${c.ambiente === 'sandbox' ? ' (testes)' : ''}</option>`).join('');
    const ck = v => +v ? 'checked' : '';

    C().innerHTML = `<div class="cab"><h1>${ev ? esc(e.nome) : 'Novo evento'}</h1><div class="cab__acoes">
        ${ev ? `<a class="btn" href="/e/${esc(e.slug)}" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square"></i> Ver página</a>
        <button class="btn" id="duplicar" type="button"><i class="fa-regular fa-clone"></i> Duplicar</button>
        <a class="btn" href="/ingressos/api/admin.php?acao=exportar&evento=${e.id}"><i class="fa-solid fa-file-csv"></i> Participantes</a>` : ''}
      </div></div>
      ${ev ? `<div class="link-box"><i class="fa-solid fa-link"></i><code>${esc(e.link)}</code><button class="btn btn--peq" type="button" data-copiar="${esc(e.link)}">Copiar link do checkout</button></div>` : ''}
      <form id="formEvento" autocomplete="off">
        <div class="abas"><button type="button" data-aba="info" class="is-on">Informações</button><button type="button" data-aba="ing">Ingressos</button><button type="button" data-aba="pag">Pagamento</button><button type="button" data-aba="cup">Cupons</button></div>

        <section data-painel="info">
          <div class="card">
            <div class="grade">
              <label>Nome do evento<input name="nome" value="${esc(e.nome)}" required></label>
              <label>Link (/e/…)<input name="slug" value="${esc(e.slug)}" placeholder="gerado a partir do nome"><span class="ajuda">Ex.: koinonia → /e/koinonia</span></label>
              <label>Status<select name="status">${['rascunho', 'publicado', 'encerrado'].map(s => `<option value="${s}" ${e.status === s ? 'selected' : ''}>${STATUS[s]}</option>`).join('')}</select><span class="ajuda">Rascunho: só a equipe vê. Publicado: vendas abertas.</span></label>
            </div>
            <label>Descrição<textarea name="descricao">${esc(e.descricao)}</textarea></label>
            <div class="grade">
              <label>Início<input type="datetime-local" name="inicio" value="${paraInput(e.inicio)}"></label>
              <label>Término<input type="datetime-local" name="fim" value="${paraInput(e.fim)}"></label>
              <label>Local<input name="local_nome" value="${esc(e.local_nome)}"></label>
              <label>Endereço<input name="endereco" value="${esc(e.endereco)}"></label>
            </div>
          </div>
          <div class="card"><h2>Aparência</h2>
            <div class="grade">
              <div><div class="img-prev" id="imgPrev" style="background-image:url('${esc(e.imagem)}')"></div>
                <label>Imagem de capa<input type="file" id="imgArquivo" accept="image/jpeg,image/png,image/webp"><span class="ajuda">JPG, PNG ou WEBP até 5 MB. Ideal: horizontal 1600×900.</span></label>
                <input type="hidden" name="imagem" value="${esc(e.imagem)}"></div>
              <label>Cor principal<input type="color" name="cor" value="${esc(e.cor)}"><span class="ajuda">Usada nos botões e destaques do checkout.</span></label>
            </div>
          </div>
          <div class="card"><h2>Vendas</h2>
            <div class="grade">
              <label>Lotação total (pessoas)<input type="number" min="0" name="lotacao" value="${e.lotacao}"><span class="ajuda">0 = sem limite geral</span></label>
              <label>Máximo de ingressos por pedido<input type="number" min="1" max="50" name="limite_por_pedido" value="${e.limite_por_pedido}"></label>
              <label>Vendas começam em<input type="datetime-local" name="vendas_inicio" value="${paraInput(e.vendas_inicio)}"><span class="ajuda">vazio = assim que publicar</span></label>
              <label>Vendas terminam em<input type="datetime-local" name="vendas_fim" value="${paraInput(e.vendas_fim)}"></label>
            </div>
            <label class="check"><input type="checkbox" name="pedir_cpf" ${ck(e.pedir_cpf)}> Pedir CPF do comprador</label>
          </div>
        </section>

        <section data-painel="ing" hidden>
          <div class="info-box">Cada linha é um tipo de ingresso (lote). <b>Quantidade 0 = ilimitada</b> (respeitando a lotação total). "Pessoas" é quantos ingressos com QR Code cada compra gera — ex.: Dupla = 2.</div>
          <div id="tipos"></div>
          <button class="btn" type="button" id="addTipo"><i class="fa-solid fa-plus"></i> Adicionar tipo de ingresso</button>
        </section>

        <section data-painel="pag" hidden>
          <div class="card"><h2>Onde cada pagamento cai</h2>
            <div class="info-box">O comprador vê uma tela só. Aqui você escolhe, por trás, qual conta recebe o PIX e qual recebe o cartão. Cadastre as contas em <a href="#contas">Recebimentos</a>.</div>
            <div class="grade">
              <label>Conta para PIX<select name="pix_conta_id">${opcContas(contasPix, e.pix_conta_id)}</select></label>
              <label>Conta para cartão<select name="cartao_conta_id">${opcContas(contasCC, e.cartao_conta_id)}</select></label>
            </div>
            <label class="check"><input type="checkbox" name="aceita_pix" ${ck(e.aceita_pix)}> Aceitar PIX</label>
            <label class="check"><input type="checkbox" name="aceita_cartao" ${ck(e.aceita_cartao)}> Aceitar cartão de crédito</label>
            <label class="check"><input type="checkbox" name="dois_cartoes" ${ck(e.dois_cartoes)}> Permitir pagar com dois cartões</label>
            <div class="grade" style="margin-top:10px">
              <label>Parcelas no cartão (máximo)<select name="max_parcelas">${Array.from({ length: 12 }, (_, i) => `<option value="${i + 1}" ${+e.max_parcelas === i + 1 ? 'selected' : ''}>${i + 1}x</option>`).join('')}</select></label>
              <label>Prazo para pagar o PIX (minutos)<input type="number" min="5" max="1440" name="pix_minutos" value="${e.pix_minutos}"></label>
            </div>
          </div>
          <div class="card"><h2>Taxas</h2>
            <label class="check"><input type="radio" name="taxa_modo_r" value="repassar" ${e.taxa_modo === 'repassar' ? 'checked' : ''}> <span><b>Repassar ao comprador</b> — aparece "taxa de serviço" no checkout e a igreja recebe o valor cheio do ingresso.</span></label>
            <label class="check"><input type="radio" name="taxa_modo_r" value="absorver" ${e.taxa_modo === 'absorver' ? 'checked' : ''}> <span><b>Absorver</b> — o comprador paga só o preço do ingresso e a taxa sai do valor recebido.</span></label>
            <input type="hidden" name="taxa_modo" value="${esc(e.taxa_modo)}">
          </div>
        </section>

        <section data-painel="cup" hidden>
          <div class="info-box">Cupons de desconto deste evento. Uso máximo 0 = ilimitado.</div>
          <div id="cupons"></div>
          <button class="btn" type="button" id="addCupom"><i class="fa-solid fa-plus"></i> Adicionar cupom</button>
        </section>

        <div class="rodape-form">
          ${ev ? '<button class="btn btn--perigo" type="button" id="excluir">Excluir</button>' : ''}
          <button class="btn btn--pri" type="submit"><i class="fa-solid fa-check"></i> Salvar evento</button>
        </div>
        <p class="erro" id="erroEvento"></p>
      </form>`;

    const f = $('#formEvento');
    $$('[data-copiar]').forEach(b => b.onclick = () => copiar(b.dataset.copiar));
    $$('.abas button').forEach(b => b.onclick = () => {
      $$('.abas button').forEach(x => x.classList.toggle('is-on', x === b));
      $$('[data-painel]').forEach(p => { p.hidden = p.dataset.painel !== b.dataset.aba; });
    });
    $$('[name="taxa_modo_r"]').forEach(r => r.onchange = () => { f.taxa_modo.value = r.value; });

    const linhaTipo = t => `<div class="linha-tipo" data-id="${t.id || ''}">
      <label>Nome<input data-k="nome" value="${esc(t.nome)}" placeholder="Ex.: Individual · 1º lote"></label>
      <label>Preço (R$)<input data-k="preco" inputmode="decimal" value="${reais(t.preco)}"></label>
      <label>Quantidade<input data-k="quantidade" type="number" min="0" value="${t.quantidade}"></label>
      <label>Pessoas<input data-k="pessoas" type="number" min="1" value="${t.pessoas}"></label>
      <label>Máx./pedido<input data-k="max_por_pedido" type="number" min="1" value="${t.max_por_pedido}"></label>
      <button class="btn btn--perigo btn--peq" type="button" data-rem title="Remover"><i class="fa-solid fa-trash"></i></button>
      <div class="det">
        <label>Descrição curta<input data-k="descricao" value="${esc(t.descricao)}" placeholder="Ex.: Acesso a todas as sessões"></label>
        <label>Vende a partir de<input data-k="vendas_inicio" type="datetime-local" value="${paraInput(t.vendas_inicio)}"></label>
        <label>Vende até<input data-k="vendas_fim" type="datetime-local" value="${paraInput(t.vendas_fim)}"></label>
        <label class="check" style="margin-top:22px"><input data-k="ativo" type="checkbox" ${t.ativo === undefined || +t.ativo ? 'checked' : ''}> Ativo</label>
      </div>
      ${t.id ? `<span class="vend">${t.vendidos || 0} vendidos/reservados</span>` : ''}
    </div>`;
    const linhaCupom = c => `<div class="linha-cupom" data-id="${c.id || ''}">
      <label>Código<input data-k="codigo" value="${esc(c.codigo)}" style="text-transform:uppercase"></label>
      <label>Tipo<select data-k="tipo"><option value="pct" ${c.tipo !== 'valor' ? 'selected' : ''}>% de desconto</option><option value="valor" ${c.tipo === 'valor' ? 'selected' : ''}>R$ de desconto</option></select></label>
      <label>Valor<input data-k="valor" inputmode="decimal" value="${c.tipo === 'valor' ? reais(c.valor) : (c.valor || '')}"></label>
      <label>Uso máximo<input data-k="usos_max" type="number" min="0" value="${c.usos_max || 0}"></label>
      <label class="check" style="margin-top:22px"><input data-k="ativo" type="checkbox" ${c.ativo === undefined || +c.ativo ? 'checked' : ''}> Ativo</label>
      <button class="btn btn--perigo btn--peq" type="button" data-rem><i class="fa-solid fa-trash"></i></button>
    </div>`;
    $('#tipos').innerHTML = e.tipos.map(linhaTipo).join('');
    $('#cupons').innerHTML = (e.cupons || []).map(linhaCupom).join('') || '';
    $('#addTipo').onclick = () => $('#tipos').insertAdjacentHTML('beforeend', linhaTipo({ nome: '', descricao: '', preco: 0, quantidade: 0, pessoas: 1, max_por_pedido: 10 }));
    $('#addCupom').onclick = () => $('#cupons').insertAdjacentHTML('beforeend', linhaCupom({ codigo: '', tipo: 'pct', valor: 10, usos_max: 0 }));
    f.addEventListener('click', ev2 => { const b = ev2.target.closest('[data-rem]'); if (b && confirm('Remover esta linha?')) b.parentElement.remove(); });

    $('#imgArquivo').onchange = async () => {
      const arq = $('#imgArquivo').files[0]; if (!arq) return;
      const fd = new FormData(); fd.append('arquivo', arq);
      try { const r = await api('admin', 'imagem', null, 'POST', fd); f.imagem.value = r.url; $('#imgPrev').style.backgroundImage = `url('${r.url}')`; toast('Imagem enviada.'); }
      catch (er) { toast(er.message, true); }
    };

    const lerLinhas = (sel) => $$(sel).map(l => {
      const o = { id: l.dataset.id || undefined };
      $$('[data-k]', l).forEach(i => { o[i.dataset.k] = i.type === 'checkbox' ? i.checked : i.value; });
      return o;
    });
    f.addEventListener('submit', async ev2 => {
      ev2.preventDefault(); $('#erroEvento').textContent = '';
      const d = dadosForm(f); delete d.taxa_modo_r;
      d.id = ev ? ev.id : 0;
      d.tipos = lerLinhas('#tipos .linha-tipo');
      d.cupons = lerLinhas('#cupons .linha-cupom');
      try { const r = await adm('evento_salvar', d, 'POST'); toast('Evento salvo!'); if (!ev) location.hash = 'evento/' + r.id; else editarEvento(r.id); }
      catch (er) { $('#erroEvento').textContent = er.message; toast(er.message, true); }
    });
    if (ev) {
      $('#duplicar').onclick = async () => { if (!confirm('Criar uma cópia deste evento (com os mesmos ingressos e configurações) como rascunho?')) return; const r = await adm('evento_duplicar', { id: ev.id }, 'POST'); toast('Cópia criada.'); location.hash = 'evento/' + r.id; };
      $('#excluir').onclick = async () => { if (!confirm('Excluir este evento? Só é possível se ele não tiver nenhum pedido.')) return; try { await adm('evento_excluir', { id: ev.id }, 'POST'); toast('Evento excluído.'); location.hash = 'eventos'; } catch (er) { toast(er.message, true); } };
    }
  }

  /* ================= vendas ================= */
  async function vendas() {
    const evId = location.hash.split('/')[1] || '';
    const evs = await adm('eventos');
    C().innerHTML = `<div class="cab"><h1>Vendas</h1><div class="cab__acoes" id="expBox"></div></div>
      <div class="filtros">
        <select id="fEvento"><option value="">Todos os eventos</option>${evs.map(e => `<option value="${e.id}" ${String(e.id) === evId ? 'selected' : ''}>${esc(e.nome)}</option>`).join('')}</select>
        <select id="fStatus"><option value="">Todas as situações</option>${['pago', 'pendente', 'expirado', 'recusado', 'cancelado', 'estornado'].map(s => `<option value="${s}">${STATUS[s]}</option>`).join('')}</select>
        <input id="fBusca" placeholder="Buscar nome, e-mail, código, CPF…">
      </div>
      <div class="card" style="padding:0"><div class="rolar"><table class="tabela"><thead><tr><th>Pedido</th><th>Comprador</th><th>Evento</th><th>Total</th><th>Forma</th><th>Situação</th><th>Data</th></tr></thead><tbody id="linhas"></tbody></table></div></div>
      <div id="paginas" style="display:flex;gap:8px;justify-content:center"></div>`;
    let pagina = 1, t;
    async function carregar() {
      const fe = $('#fEvento').value;
      $('#expBox').innerHTML = fe ? `<a class="btn" href="/ingressos/api/admin.php?acao=exportar&evento=${fe}"><i class="fa-solid fa-file-csv"></i> Exportar participantes</a>` : '';
      const r = await adm('pedidos', { evento: fe, status: $('#fStatus').value, q: $('#fBusca').value, pagina });
      $('#linhas').innerHTML = r.pedidos.map(p => `<tr class="clicavel" data-id="${p.id}"><td><b>${esc(p.codigo)}</b><br><small class="ajuda">${p.ingressos} ingresso(s)</small></td><td>${esc(p.nome)}<br><small class="ajuda">${esc(p.email)}</small></td><td>${esc(p.evento)}</td><td>${brl(p.total)}</td><td>${METODO[p.metodo] || p.metodo}</td><td>${selo(p.status)}</td><td>${dataBR(p.criado_em)}</td></tr>`).join('') || '<tr><td colspan="7" class="vazio">Nenhum pedido encontrado.</td></tr>';
      const pags = Math.ceil(r.total / 50);
      $('#paginas').innerHTML = pags > 1 ? Array.from({ length: pags }, (_, i) => `<button class="btn btn--peq ${i + 1 === pagina ? 'btn--pri' : ''}" data-p="${i + 1}">${i + 1}</button>`).join('') : `<p class="ajuda">${r.total} pedido(s)</p>`;
    }
    $('#fEvento').onchange = $('#fStatus').onchange = () => { pagina = 1; carregar(); };
    $('#fBusca').oninput = () => { clearTimeout(t); t = setTimeout(() => { pagina = 1; carregar(); }, 300); };
    $('#paginas').onclick = e => { const b = e.target.closest('[data-p]'); if (b) { pagina = +b.dataset.p; carregar(); } };
    $('#linhas').onclick = e => { const tr = e.target.closest('tr[data-id]'); if (tr) verPedido(tr.dataset.id, carregar); };
    await carregar();
  }

  async function verPedido(id, depois) {
    const p = await adm('pedido', { id });
    const acoes = [];
    if (['pendente', 'expirado'].includes(p.status)) acoes.push(['marcar_pago', 'Marcar como pago', 'Confirma que recebeu este pagamento por fora (ex.: PIX conferido no extrato)? Os ingressos serão emitidos e enviados.']);
    if (p.status === 'pendente') acoes.push(['cancelar', 'Cancelar pedido', 'Cancelar este pedido pendente? As vagas voltam para venda.']);
    if (p.status === 'pago') acoes.push(['reenviar', 'Reenviar e-mail', null], ['estornar', 'Estornar e cancelar ingressos', 'Devolver o dinheiro ao comprador e cancelar os ingressos? Esta ação não pode ser desfeita.']);
    modal(`<div class="modal__cab"><div><h2>Pedido ${esc(p.codigo)} ${selo(p.status)}</h2><p class="ajuda">${esc(p.evento)}</p></div><button class="fechar" type="button">✕</button></div>
      <dl class="dl">
        <dt>Comprador</dt><dd>${esc(p.nome)}</dd><dt>E-mail</dt><dd>${esc(p.email)}</dd><dt>WhatsApp</dt><dd>${esc(p.telefone) || '—'} ${p.telefone ? `<a href="https://wa.me/55${esc(p.telefone)}" target="_blank" rel="noopener">abrir</a>` : ''}</dd>
        <dt>CPF</dt><dd>${esc(p.cpf) || '—'}</dd><dt>Forma</dt><dd>${METODO[p.metodo] || esc(p.metodo)}</dd>
        <dt>Itens</dt><dd>${p.itens.map(i => `${i.qtd}× ${esc(i.tipo)} (${brl(i.preco)})`).join('<br>')}</dd>
        <dt>Subtotal</dt><dd>${brl(p.subtotal)}</dd>${+p.desconto ? `<dt>Desconto</dt><dd>−${brl(p.desconto)}</dd>` : ''}${+p.taxa_cliente ? `<dt>Taxa paga pelo cliente</dt><dd>${brl(p.taxa_cliente)}</dd>` : ''}
        <dt>Total</dt><dd><b>${brl(p.total)}</b></dd><dt>Custo das taxas</dt><dd>${brl(p.custo_taxas)} (estimado)</dd>
        <dt>Criado</dt><dd>${dataBR(p.criado_em)}</dd><dt>Pago</dt><dd>${dataBR(p.pago_em)}</dd>
        ${p.obs ? `<dt>Observações</dt><dd>${esc(p.obs)}</dd>` : ''}
        <dt>Link do comprador</dt><dd><a href="${esc(p.link)}" target="_blank" rel="noopener">abrir página do pedido</a></dd>
      </dl>
      ${p.pagamentos.length ? `<h3 style="font-size:15px;margin:10px 0">Pagamentos</h3><div class="rolar"><table class="tabela"><thead><tr><th>Forma</th><th>Conta</th><th>Valor</th><th>Situação</th><th>Detalhe</th></tr></thead><tbody>
        ${p.pagamentos.map(g => `<tr><td>${g.metodo === 'pix' ? 'PIX' : `Cartão ${esc(g.cartao_bandeira)} •••• ${esc(g.cartao_final)} · ${g.parcelas}x`}</td><td>${esc(g.conta)}</td><td>${brl(g.valor)}</td><td>${selo(g.status)}</td><td><small class="ajuda">${esc(g.mensagem || g.ref)}</small></td></tr>`).join('')}</tbody></table></div>` : ''}
      ${p.lista_ingressos.length ? `<h3 style="font-size:15px;margin:16px 0 10px">Ingressos</h3><div class="rolar"><table class="tabela"><thead><tr><th>Código</th><th>Tipo</th><th>Titular</th><th>Check-in</th><th></th></tr></thead><tbody>
        ${p.lista_ingressos.map(g => `<tr><td><code>${esc(g.codigo)}</code></td><td>${esc(g.tipo)}</td><td>${esc(g.titular)}</td><td>${g.status !== 'valido' ? selo('cancelado') : g.checkin_em ? dataBR(g.checkin_em) : '—'}</td>
          <td style="white-space:nowrap"><button class="btn btn--peq" data-tit="${g.id}" title="Trocar titular"><i class="fa-solid fa-pen"></i></button>${g.checkin_em ? ` <button class="btn btn--peq" data-desf="${g.id}" title="Desfazer check-in"><i class="fa-solid fa-rotate-left"></i></button>` : ''}</td></tr>`).join('')}</tbody></table></div>` : ''}
      <div class="cab__acoes" style="margin-top:18px">${acoes.map(([o, t]) => `<button class="btn ${o === 'estornar' || o === 'cancelar' ? 'btn--perigo' : ''}" data-o="${o}">${t}</button>`).join('')}</div>`);
    const recarregar = async () => { await verPedido(id, depois); depois && depois(); };
    $$('#modalCaixa [data-o]').forEach(b => b.onclick = async () => {
      const a = acoes.find(x => x[0] === b.dataset.o);
      if (a[2] && !confirm(a[2])) return;
      b.disabled = true;
      try { await adm('pedido_acao', { id, o: a[0] }, 'POST'); toast('Feito!'); recarregar(); } catch (e) { toast(e.message, true); b.disabled = false; }
    });
    $$('#modalCaixa [data-tit]').forEach(b => b.onclick = async () => {
      const nome = prompt('Nome do novo titular deste ingresso:'); if (!nome) return;
      try { await adm('pedido_acao', { id, o: 'titular', ingresso_id: b.dataset.tit, titular: nome }, 'POST'); recarregar(); } catch (e) { toast(e.message, true); }
    });
    $$('#modalCaixa [data-desf]').forEach(b => b.onclick = async () => {
      if (!confirm('Desfazer o check-in deste ingresso? Ele poderá entrar de novo.')) return;
      try { await adm('pedido_acao', { id, o: 'desfazer_checkin', ingresso_id: b.dataset.desf }, 'POST'); recarregar(); } catch (e) { toast(e.message, true); }
    });
  }

  /* ================= cortesias ================= */
  async function cortesias() {
    const evs = await adm('eventos');
    C().innerHTML = `<div class="cab"><h1>Cortesias e vendas manuais</h1></div>
      <div class="card" style="max-width:720px">
        <p class="sub">Emita ingressos sem passar pelo checkout: convidados, equipe, preletores, ou quem pagou direto na secretaria.</p>
        <form id="formCort">
          <div class="grade">
            <label>Evento<select name="evento_id" id="cEv">${evs.map(e => `<option value="${e.id}">${esc(e.nome)}</option>`).join('')}</select></label>
            <label>Tipo de ingresso<select name="tipo_id" id="cTipo"></select></label>
            <label>Quantidade<input type="number" name="qtd" min="1" max="100" value="1"></label>
            <label>Tipo de emissão<select name="tipo" id="cModo"><option value="cortesia">Cortesia (gratuito)</option><option value="manual">Venda manual (pago por fora)</option></select></label>
            <label id="cValor" hidden>Valor recebido (R$)<input name="valor" inputmode="decimal" placeholder="0,00"></label>
          </div>
          <div class="grade">
            <label>Nome de quem recebe<input name="nome" required></label>
            <label>E-mail<input name="email" type="email"><span class="ajuda">Com e-mail, os ingressos são enviados automaticamente.</span></label>
            <label>WhatsApp<input name="telefone"></label>
          </div>
          <label>Observação<input name="obs" placeholder="Ex.: preletor convidado"></label>
          <button class="btn btn--pri" type="submit"><i class="fa-solid fa-gift"></i> Emitir ingressos</button>
          <p class="erro"></p>
        </form>
      </div>`;
    const carregarTipos = async () => { if (!$('#cEv').value) return; const e = await adm('evento', { id: $('#cEv').value }); $('#cTipo').innerHTML = e.tipos.filter(t => +t.ativo).map(t => `<option value="${t.id}">${esc(t.nome)}${+t.pessoas > 1 ? ` (${t.pessoas} pessoas)` : ''}</option>`).join(''); };
    $('#cEv').onchange = carregarTipos; await carregarTipos();
    $('#cModo').onchange = () => { $('#cValor').hidden = $('#cModo').value !== 'manual'; };
    $('#formCort').onsubmit = async e => {
      e.preventDefault(); const f = e.target; $('.erro', f).textContent = '';
      try {
        const r = await adm('cortesia', dadosForm(f), 'POST');
        modal(`<div class="modal__cab"><h2>Ingressos emitidos!</h2><button class="fechar" type="button">✕</button></div><p class="sub">Envie este link para a pessoa (ele mostra os QR Codes):</p><div class="link-box"><code>${esc(r.link)}</code><button class="btn btn--peq" data-copiar="${esc(r.link)}">Copiar</button></div>`);
        $$('#modalCaixa [data-copiar]').forEach(b => b.onclick = () => copiar(b.dataset.copiar));
        f.nome.value = f.email.value = f.telefone.value = f.obs.value = '';
      } catch (er) { $('.erro', f).textContent = er.message; }
    };
  }

  /* ================= contas de recebimento ================= */
  async function contas() {
    const r = await adm('contas');
    C().innerHTML = `<div class="cab"><h1>Recebimentos</h1><div class="cab__acoes"><button class="btn btn--pri" id="novaConta"><i class="fa-solid fa-plus"></i> Nova conta</button></div></div>
      <div class="info-box">Cadastre aqui os bancos/intermediadores (ex.: um banco só para PIX e outro para cartão). Em cada evento você escolhe qual conta recebe cada forma de pagamento. O comprador não vê essa divisão.</div>
      <div class="card" style="padding:0"><div class="rolar"><table class="tabela"><thead><tr><th>Conta</th><th>Provedor</th><th>Ambiente</th><th>PIX</th><th>Cartão</th><th>Situação</th></tr></thead><tbody>
      ${r.contas.map(c => `<tr class="clicavel" data-id="${c.id}"><td><b>${esc(c.nome)}</b></td><td>${esc(r.provedores[c.provedor]?.nome || c.provedor)}</td><td>${c.ambiente === 'sandbox' ? selo('rascunho').replace('Rascunho', 'Testes') : selo('publicado').replace('Publicado', 'Produção')}</td>
        <td>${+c.aceita_pix ? `${c.taxa_pix_pct}%${+c.taxa_pix_fixa ? ' + ' + brl(c.taxa_pix_fixa) : ''}` : '—'}</td><td>${+c.aceita_cartao ? `${c.taxa_cartao_pct}%${+c.taxa_parcela_pct ? ` (+${c.taxa_parcela_pct}%/parcela)` : ''}` : '—'}</td><td>${+c.ativo ? selo('aprovado').replace('Aprovado', 'Ativa') : selo('cancelado').replace('Cancelado', 'Inativa')}</td></tr>`).join('')}
      </tbody></table></div></div>`;
    $('#novaConta').onclick = () => formConta(null, r.provedores);
    $$('tr[data-id]').forEach(tr => tr.onclick = () => formConta(r.contas.find(c => String(c.id) === tr.dataset.id), r.provedores));
  }

  function formConta(c, provs) {
    const x = c || { nome: '', provedor: 'mercadopago', ambiente: 'sandbox', aceita_pix: 1, aceita_cartao: 1, taxa_pix_pct: 0, taxa_pix_fixa: 0, taxa_cartao_pct: 0, taxa_cartao_fixa: 0, taxa_parcela_pct: 0, ativo: 1, credenciais: {}, public_key: '' };
    const ck = v => +v ? 'checked' : '';
    modal(`<div class="modal__cab"><h2>${c ? 'Editar conta' : 'Nova conta de recebimento'}</h2><button class="fechar" type="button">✕</button></div>
      <form id="formConta">
        <div class="grade">
          <label>Nome interno<input name="nome" value="${esc(x.nome)}" placeholder="Ex.: Banco X (PIX)"></label>
          <label>Provedor<select name="provedor" id="provSel">${Object.entries(provs).map(([k, p]) => `<option value="${k}" ${x.provedor === k ? 'selected' : ''}>${esc(p.nome)}</option>`).join('')}</select></label>
          <label>Ambiente<select name="ambiente"><option value="sandbox" ${x.ambiente === 'sandbox' ? 'selected' : ''}>Testes (sandbox)</option><option value="producao" ${x.ambiente === 'producao' ? 'selected' : ''}>Produção (dinheiro real)</option></select></label>
        </div>
        <label class="check"><input type="checkbox" name="aceita_pix" ${ck(x.aceita_pix)}> Recebe PIX</label>
        <label class="check"><input type="checkbox" name="aceita_cartao" ${ck(x.aceita_cartao)}> Recebe cartão</label>
        <label class="check"><input type="checkbox" name="ativo" ${ck(x.ativo)}> Conta ativa</label>
        <h3 style="font-size:15px;margin:16px 0 8px">Taxas cobradas por esta conta</h3>
        <div class="grade">
          <label>PIX (%)<input name="taxa_pix_pct" inputmode="decimal" value="${x.taxa_pix_pct}"></label>
          <label>PIX fixa (R$)<input name="taxa_pix_fixa" inputmode="decimal" value="${reais(x.taxa_pix_fixa)}"></label>
          <label>Cartão à vista (%)<input name="taxa_cartao_pct" inputmode="decimal" value="${x.taxa_cartao_pct}"></label>
          <label>Cartão fixa (R$)<input name="taxa_cartao_fixa" inputmode="decimal" value="${reais(x.taxa_cartao_fixa)}"></label>
          <label>Acréscimo por parcela (%)<input name="taxa_parcela_pct" inputmode="decimal" value="${x.taxa_parcela_pct}"><span class="ajuda">Somado para cada parcela além da 1ª.</span></label>
        </div>
        <div id="credBox"></div>
        ${c ? `<div class="info-box">URL de notificação (webhook) para cadastrar no provedor:<br><code>${esc(location.origin)}/ingressos/api/webhook.php?conta=${c.id}</code></div>` : ''}
        <div class="cab__acoes">${c ? '<button class="btn btn--perigo" type="button" id="excConta">Excluir</button>' : ''}<button class="btn btn--pri" type="submit">Salvar conta</button></div>
        <p class="erro"></p>
      </form>`);
    const credBox = () => {
      const p = $('#provSel').value;
      $('#credBox').innerHTML = p === 'sandbox'
        ? '<div class="info-box amarelo">Simulador: não precisa de credenciais. Nada é cobrado de verdade.</div>'
        : p === 'mercadopago'
          ? `<h3 style="font-size:15px;margin:16px 0 8px">Credenciais do Mercado Pago</h3><div class="info-box">No Mercado Pago: Seu negócio → Configurações → Credenciais. Use as <b>de teste</b> no ambiente de testes e as <b>de produção</b> para vender. Deixe em branco para manter as já salvas.</div>
             <label>Public Key<input name="cred_public_key" value="${esc(x.public_key || '')}" placeholder="APP_USR-… ou TEST-…"></label>
             <label>Access Token (secreto)<input name="cred_access_token" type="password" placeholder="${x.credenciais && x.credenciais.access_token ? esc(x.credenciais.access_token) : 'APP_USR-… ou TEST-…'}" autocomplete="off"></label>` : '';
    };
    $('#provSel').onchange = credBox; credBox();
    $('#formConta').onsubmit = async e => {
      e.preventDefault(); const f = e.target; $('.erro', f).textContent = '';
      const d = dadosForm(f); d.id = c ? c.id : 0; d.credenciais = {};
      Object.keys(d).filter(k => k.startsWith('cred_')).forEach(k => { d.credenciais[k.slice(5)] = d[k]; delete d[k]; });
      try { await adm('conta_salvar', d, 'POST'); fecharModal(); toast('Conta salva.'); contas(); } catch (er) { $('.erro', f).textContent = er.message; }
    };
    if (c) $('#excConta').onclick = async () => { if (!confirm('Excluir esta conta?')) return; try { await adm('conta_excluir', { id: c.id }, 'POST'); fecharModal(); toast('Conta excluída.'); contas(); } catch (er) { toast(er.message, true); } };
  }

  /* ================= equipe ================= */
  async function equipe() {
    const us = await adm('usuarios');
    C().innerHTML = `<div class="cab"><h1>Equipe</h1><div class="cab__acoes"><button class="btn btn--pri" id="novoUsu"><i class="fa-solid fa-user-plus"></i> Novo usuário</button></div></div>
      <div class="info-box"><b>Administrador</b>: acesso total ao painel. <b>Check-in</b>: só entra no leitor de QR Code (<a href="/checkin" target="_blank">/checkin</a>), cada um com seu login e senha.</div>
      <div class="card" style="padding:0"><div class="rolar"><table class="tabela"><thead><tr><th>Nome</th><th>E-mail</th><th>Acesso</th><th>Último login</th><th>Situação</th></tr></thead><tbody>
      ${us.map(u => `<tr class="clicavel" data-id="${u.id}"><td><b>${esc(u.nome)}</b></td><td>${esc(u.email)}</td><td>${u.papel === 'admin' ? 'Administrador' : 'Check-in'}</td><td>${dataBR(u.ultimo_login)}</td><td>${+u.ativo ? selo('aprovado').replace('Aprovado', 'Ativo') : selo('cancelado').replace('Cancelado', 'Inativo')}</td></tr>`).join('')}
      </tbody></table></div></div>`;
    const form = u => {
      const x = u || { nome: '', email: '', papel: 'checkin', ativo: 1 };
      modal(`<div class="modal__cab"><h2>${u ? 'Editar usuário' : 'Novo usuário'}</h2><button class="fechar" type="button">✕</button></div>
        <form id="formUsu"><div class="grade"><label>Nome<input name="nome" value="${esc(x.nome)}" required></label><label>E-mail (login)<input name="email" type="email" value="${esc(x.email)}" required></label>
          <label>Acesso<select name="papel"><option value="checkin" ${x.papel === 'checkin' ? 'selected' : ''}>Check-in</option><option value="admin" ${x.papel === 'admin' ? 'selected' : ''}>Administrador</option></select></label>
          <label>${u ? 'Nova senha (opcional)' : 'Senha'}<input name="senha" type="password" minlength="8" ${u ? '' : 'required'} autocomplete="new-password"></label></div>
          <label class="check"><input type="checkbox" name="ativo" ${+x.ativo ? 'checked' : ''}> Ativo</label>
          <div class="cab__acoes">${u ? '<button class="btn btn--perigo" type="button" id="excUsu">Excluir</button>' : ''}<button class="btn btn--pri" type="submit">Salvar</button></div><p class="erro"></p></form>`);
      $('#formUsu').onsubmit = async e => {
        e.preventDefault(); const f = e.target; const d = dadosForm(f); d.id = u ? u.id : 0;
        try { await adm('usuario_salvar', d, 'POST'); fecharModal(); toast('Usuário salvo.'); equipe(); } catch (er) { $('.erro', f).textContent = er.message; }
      };
      if (u) $('#excUsu').onclick = async () => { if (!confirm('Excluir este usuário?')) return; try { await adm('usuario_excluir', { id: u.id }, 'POST'); fecharModal(); equipe(); } catch (er) { toast(er.message, true); } };
    };
    $('#novoUsu').onclick = () => form(null);
    $$('tr[data-id]').forEach(tr => tr.onclick = () => form(us.find(u => String(u.id) === tr.dataset.id)));
  }

  /* ================= minha conta / configurações ================= */
  async function minhaConta() {
    const cfg = await adm('config');
    C().innerHTML = `<div class="cab"><h1>Minha conta</h1></div>
      <div class="card" style="max-width:560px"><h2>Trocar senha</h2><form id="formSenha">
        <label>Senha atual<input type="password" name="atual" required autocomplete="current-password"></label>
        <label>Nova senha<input type="password" name="nova" minlength="8" required autocomplete="new-password"></label>
        <button class="btn btn--pri" type="submit">Trocar senha</button><p class="erro"></p></form></div>
      <div class="card" style="max-width:560px"><h2>Configurações gerais</h2><form id="formCfg">
        <label>Nome da organização<input name="nome_organizacao" value="${esc(cfg.nome_organizacao)}"></label>
        <label>E-mail que envia os ingressos<input name="email_remetente" type="email" value="${esc(cfg.email_remetente)}"><span class="ajuda">Crie esta caixa de e-mail na Hostinger para os envios não caírem no spam.</span></label>
        <button class="btn btn--pri" type="submit">Salvar</button><p class="erro"></p></form>
        <p class="ajuda" style="margin-top:12px">Versão ${esc(cfg.versao)}</p></div>`;
    $('#formSenha').onsubmit = async e => { e.preventDefault(); const f = e.target; try { await api('auth', 'senha', dadosForm(f), 'POST'); f.reset(); toast('Senha alterada.'); } catch (er) { $('.erro', f).textContent = er.message; } };
    $('#formCfg').onsubmit = async e => { e.preventDefault(); const f = e.target; try { await adm('config', dadosForm(f), 'POST'); toast('Salvo.'); } catch (er) { $('.erro', f).textContent = er.message; } };
  }

  entrar().catch(e => { document.body.innerHTML = `<p style="padding:30px">Não foi possível abrir o painel: ${esc(e.message)}</p>`; });
})();
