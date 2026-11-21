/* App de check-in: login, escolha do evento, leitura de QR pela câmera, busca por nome. */
(function () {
  'use strict';
  const $ = s => document.querySelector(s);
  const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  let csrf = null, eventos = [], evento = null, stream = null, lendo = false, ultimo = '', ultimoEm = 0;

  async function api(arq, acao, dados, metodo = 'GET') {
    const url = `/ingressos/api/${arq}.php?acao=${acao}` + (metodo === 'GET' && dados ? '&' + new URLSearchParams(dados) : '');
    const r = await fetch(url, { method: metodo, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf || '' }, body: metodo === 'POST' ? JSON.stringify(dados || {}) : undefined });
    const j = await r.json().catch(() => ({ erro: 'Sem conexão com o servidor.' }));
    if (r.status === 401) { mostrar('telaLogin'); throw new Error(j.erro); }
    if (!r.ok) throw new Error(j.erro || 'Erro.');
    return j;
  }
  function mostrar(id) { ['telaLogin', 'telaEvento', 'telaLeitor'].forEach(t => { $('#' + t).hidden = t !== id; }); if (id !== 'telaLeitor') pararCamera(); }

  /* ---------- sessão ---------- */
  async function iniciar() {
    const me = await api('auth', 'me');
    if (!me.usuario) return mostrar('telaLogin');
    csrf = me.csrf;
    $('#olaUsuario').textContent = 'Olá, ' + me.usuario.nome.split(' ')[0];
    eventos = await api('checkin', 'eventos');
    $('#selEvento').innerHTML = eventos.map(e => `<option value="${e.id}">${esc(e.nome)}${e.inicio ? ' · ' + new Date(e.inicio.replace(' ', 'T')).toLocaleDateString('pt-BR') : ''}</option>`).join('') || '<option value="">Nenhum evento disponível</option>';
    const salvo = localStorage.getItem('checkin_evento');
    if (salvo && eventos.some(e => String(e.id) === salvo)) $('#selEvento').value = salvo;
    mostrar('telaEvento');
  }
  $('#formLogin').addEventListener('submit', async e => {
    e.preventDefault(); $('#lErro').textContent = '';
    try { await api('auth', 'login', { email: $('#lEmail').value, senha: $('#lSenha').value }, 'POST'); await iniciar(); }
    catch (er) { $('#lErro').textContent = er.message; }
  });
  $('#btnSair').addEventListener('click', async () => { await api('auth', 'logout', {}, 'POST').catch(() => {}); csrf = null; mostrar('telaLogin'); });
  $('#btnComecar').addEventListener('click', () => {
    evento = eventos.find(e => String(e.id) === $('#selEvento').value);
    if (!evento) return;
    try { localStorage.setItem('checkin_evento', String(evento.id)); } catch (e) {}
    $('#nomeEvento').innerHTML = `${esc(evento.nome)}<small>Check-in</small>`;
    mostrar('telaLeitor'); modo('camera'); resumo();
  });
  $('#voltar').addEventListener('click', () => mostrar('telaEvento'));

  async function resumo() {
    try { const r = await api('checkin', 'resumo', { evento: evento.id }); $('#cPresentes').textContent = r.presentes; $('#cTotal').textContent = r.total; } catch (e) {}
  }
  setInterval(() => { if (!$('#telaLeitor').hidden) resumo(); }, 15000);

  /* ---------- câmera ---------- */
  async function ligarCamera() {
    const off = $('#camOff');
    off.hidden = true;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { off.hidden = false; off.textContent = 'Este navegador não libera a câmera. Use o campo abaixo ou a busca por nome.'; return; }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false });
      const v = $('#video'); v.srcObject = stream; await v.play();
      lendo = true; ler();
    } catch (e) {
      off.hidden = false;
      off.innerHTML = 'Não conseguimos abrir a câmera.<br>Permita o acesso à câmera nas configurações do navegador e recarregue a página.';
    }
  }
  function pararCamera() { lendo = false; if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } }
  const canvas = document.createElement('canvas');
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  function ler() {
    if (!lendo) return;
    const v = $('#video');
    if (v.readyState === v.HAVE_ENOUGH_DATA && $('#resultado').hidden && window.jsQR) {
      const w = 480, h = Math.round(480 * v.videoHeight / v.videoWidth) || 360;
      canvas.width = w; canvas.height = h;
      ctx.drawImage(v, 0, 0, w, h);
      const img = ctx.getImageData(0, 0, w, h);
      const qr = window.jsQR(img.data, w, h, { inversionAttempts: 'dontInvert' });
      if (qr && qr.data && !(qr.data === ultimo && Date.now() - ultimoEm < 3000)) { ultimo = qr.data; ultimoEm = Date.now(); validar(qr.data); }
    }
    requestAnimationFrame(ler);
  }

  /* ---------- validar ---------- */
  async function validar(codigo) {
    let r;
    try { r = await api('checkin', 'validar', { evento_id: evento.id, codigo }, 'POST'); }
    catch (e) { return resultado('r-ruim', 'fa-solid fa-wifi', 'Sem conexão', e.message); }
    const g = r.ingresso;
    const det = g ? `<div class="nome">${esc(g.titular)}</div><small>${esc(g.tipo)} · ${esc(g.codigo)}</small>` : '';
    if (r.resultado === 'ok') { resultado('r-ok', 'fa-solid fa-circle-check', 'Entrada liberada', '', det); resumo(); }
    else if (r.resultado === 'ja_usado') resultado('r-aviso', 'fa-solid fa-triangle-exclamation', 'Já utilizado', r.mensagem, det);
    else resultado('r-ruim', 'fa-solid fa-circle-xmark', 'Não liberado', r.mensagem, det);
    if (!$('#modoBusca').hidden) buscar();
  }
  function resultado(cls, ic, titulo, msg, det = '') {
    const el = $('#resultado');
    el.className = 'resultado ' + cls;
    el.innerHTML = `<i class="${ic}"></i><h2>${titulo}</h2>${msg ? `<p>${esc(msg)}</p>` : ''}${det}<button type="button">Próximo</button>`;
    el.hidden = false;
    try { navigator.vibrate && navigator.vibrate(cls === 'r-ok' ? 120 : [80, 60, 80, 60, 200]); } catch (e) {}
    bip(cls === 'r-ok');
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.hidden = true; }, cls === 'r-ok' ? 1800 : 4000);
    el.querySelector('button').onclick = () => { el.hidden = true; };
  }
  let audio;
  function bip(ok) {
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      const o = audio.createOscillator(), g = audio.createGain();
      o.frequency.value = ok ? 880 : 220; g.gain.value = .15;
      o.connect(g); g.connect(audio.destination); o.start(); o.stop(audio.currentTime + (ok ? .15 : .4));
    } catch (e) {}
  }
  $('#formManual').addEventListener('submit', e => { e.preventDefault(); const c = $('#codManual').value.trim(); if (c) { validar(c); $('#codManual').value = ''; } });

  /* ---------- busca por nome ---------- */
  function modo(m) {
    $('#modoCamera').style.display = m === 'camera' ? 'flex' : 'none';
    $('#modoBusca').hidden = m !== 'busca';
    $('#trocaModo').innerHTML = m === 'camera' ? '<i class="fa-solid fa-magnifying-glass"></i>' : '<i class="fa-solid fa-qrcode"></i>';
    $('#trocaModo').dataset.m = m;
    if (m === 'camera') ligarCamera(); else { pararCamera(); $('#busca').focus(); }
  }
  $('#trocaModo').addEventListener('click', () => modo($('#trocaModo').dataset.m === 'camera' ? 'busca' : 'camera'));
  let tb;
  $('#busca').addEventListener('input', () => { clearTimeout(tb); tb = setTimeout(buscar, 300); });
  async function buscar() {
    const q = $('#busca').value.trim();
    if (q.length < 3) { $('#lista').innerHTML = ''; return; }
    const r = await api('checkin', 'buscar', { evento: evento.id, q }).catch(() => []);
    $('#lista').innerHTML = r.map(g => `<div class="item"><div><b>${esc(g.titular)}</b><small>${esc(g.tipo)} · pedido ${esc(g.pedido)} · ${esc(g.codigo)}</small></div>
      ${g.status !== 'valido' ? '<span class="feito" style="color:var(--ruim)">Cancelado</span>' : g.checkin_em ? `<span class="feito">Entrou ${new Date(g.checkin_em.replace(' ', 'T')).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}</span>` : `<button type="button" data-c="${esc(g.codigo)}">Liberar</button>`}</div>`).join('') || '<p class="sub" style="margin-top:12px">Ninguém encontrado.</p>';
  }
  $('#lista').addEventListener('click', e => { const b = e.target.closest('button[data-c]'); if (b) validar(b.dataset.c); });

  document.addEventListener('visibilitychange', () => { if (document.hidden) pararCamera(); else if (!$('#telaLeitor').hidden && $('#trocaModo').dataset.m === 'camera') ligarCamera(); });

  iniciar().catch(() => mostrar('telaLogin'));
})();
