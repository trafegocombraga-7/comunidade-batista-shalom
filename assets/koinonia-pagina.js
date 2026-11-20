/* Página Koinonia: monta o conteúdo a partir do objeto KOINONIA e cuida das animações. */
(function () {
  const K = window.KOINONIA || {};
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const esc = s => String(s ?? '').replace(/[&<>"]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]));
  const calmo = matchMedia('(prefers-reduced-motion: reduce)').matches;
  const temGsap = !!(window.gsap && window.ScrollTrigger) && !calmo;
  if (temGsap) document.documentElement.classList.add('js');

  /* ---------- textos simples ---------- */
  $$('[data-k="dataTexto"]').forEach(e => e.textContent = K.dataTexto || 'Data em breve');
  $$('[data-k="local"]').forEach(e => e.textContent = K.local || '');
  const zap = K.whatsapp ? `https://wa.me/55${String(K.whatsapp).replace(/\D/g, '')}` : '';
  $$('[data-k="zapLink"]').forEach(a => {
    if (zap) { a.href = zap; a.target = '_blank'; a.rel = 'noopener'; }
    else { a.removeAttribute('href'); a.style.cursor = 'default'; }
  });

  /* ---------- faixas que correm ---------- */
  $$('[data-faixa]').forEach(t => {
    const itens = t.dataset.faixa.split('|');
    const um = itens.map(x => `<span class="k-faixa__item">${esc(x)}<img src="assets/koi-chama-pequena.webp" alt=""></span>`).join('');
    t.innerHTML = um + um + um + um;
  });

  /* ---------- manifesto: palavra por palavra ---------- */
  const man = $('#manifesto');
  if (man) {
    $$('p', man).forEach(p => {
      p.innerHTML = p.textContent.split(/\s+/).filter(Boolean).map(w => {
        const fogo = /^\*.*\*[.,]?$/.test(w);
        return `<span class="w${fogo ? ' fogo' : ''}">${esc(w.replace(/\*/g, ''))}</span>`;
      }).join(' ');
    });
  }

  /* ---------- line-up ---------- */
  const grid = $('#lineupGrid');
  if (grid) {
    grid.innerHTML = (K.lineup || []).map((p, i) => p.nome ? `
      <article class="k-pessoa">
        <div class="k-pessoa__foto"><img src="${esc(p.foto)}" alt="${esc(p.nome)}" loading="lazy"></div>
        <h3>${esc(p.nome)}</h3><small>${esc(p.papel)}</small>
      </article>` : `
      <article class="k-pessoa k-pessoa--misterio">
        <div class="k-pessoa__foto"><span>?</span><i>será revelado</i></div>
        <h3>Em breve</h3><small>${esc(p.papel || 'Convidado especial')}</small>
      </article>`).join('');
  }

  /* ---------- ingressos ---------- */
  const lista = $('#ingLista');
  if (lista) {
    lista.innerHTML = (K.ingressos || []).map(t => {
      const preco = t.preco ? `<span class="k-preco"><small>R$</small>${esc(t.preco)}</span>` : `<span class="k-preco k-preco--breve">em breve</span>`;
      const btn = t.link
        ? `<a class="k-btn" href="${esc(t.link)}" target="_blank" rel="noopener">Comprar</a>`
        : `<span class="k-btn" aria-disabled="true">Em breve</span>`;
      return `
      <article class="k-bilhete${t.destaque ? ' k-bilhete--destaque' : ''}">
        ${t.selo ? `<span class="k-selo">${esc(t.selo)}</span>` : ''}
        <div class="k-bilhete__corpo">
          <div class="k-bilhete__tipo"><span>${esc(t.tipo)}</span><img src="assets/koi-logo.webp" alt=""></div>
          <h3>${esc(t.nome)}</h3>
          <ul>${(t.itens || []).map(i => `<li><i class="fa-solid fa-fire"></i>${esc(i)}</li>`).join('')}</ul>
        </div>
        <div class="k-bilhete__corte"></div>
        <div class="k-bilhete__pe">${preco}${btn}</div>
      </article>`;
    }).join('');
  }

  /* ---------- FAQ ---------- */
  const faq = $('#faqLista');
  if (faq) faq.innerHTML = (K.faq || []).map(([q, r]) => `<details><summary>${esc(q)}</summary><p>${esc(r)}</p></details>`).join('');

  /* ---------- vídeo ---------- */
  const idYT = (K.video || '').match(/(?:v=|youtu\.be\/|embed\/|shorts\/)([\w-]{11})/);
  if (idYT) $('#videoAviso').textContent = 'Assista agora';
  $('#play')?.addEventListener('click', () => {
    if (!idYT) { $('#videoAviso').textContent = 'O vídeo chega em breve 🔥'; return; }
    $('#tela').innerHTML = `<iframe src="https://www.youtube.com/embed/${idYT[1]}?autoplay=1&rel=0" title="Koinonia" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>`;
  });

  /* ---------- contagem regressiva ---------- */
  const alvo = K.data ? new Date(K.data).getTime() : NaN;
  if (!isNaN(alvo)) {
    const u = k => $(`#relogio [data-u="${k}"]`);
    const tic = () => {
      let s = Math.max(0, Math.floor((alvo - Date.now()) / 1000));
      const d = Math.floor(s / 86400); s %= 86400;
      const h = Math.floor(s / 3600); s %= 3600;
      const m = Math.floor(s / 60); s %= 60;
      u('d').textContent = d; u('h').textContent = String(h).padStart(2, '0');
      u('m').textContent = String(m).padStart(2, '0'); u('s').textContent = String(s).padStart(2, '0');
    };
    tic(); setInterval(tic, 1000);
  }

  /* ---------- brasas pela página inteira ---------- */
  const cv = $('#brasas');
  if (cv && !calmo) {
    const ctx = cv.getContext('2d');
    let W, H, dpr;
    const medir = () => { dpr = Math.min(devicePixelRatio || 1, 2); W = cv.width = innerWidth * dpr; H = cv.height = innerHeight * dpr; };
    medir(); addEventListener('resize', medir);
    const N = innerWidth < 600 ? 16 : 30;
    const nova = (inicio) => ({ x: Math.random(), y: inicio ? Math.random() : 1.05, v: .02 + Math.random() * .05, r: .8 + Math.random() * 1.8, f: Math.random() * 6, a: .25 + Math.random() * .45 });
    const bs = Array.from({ length: N }, () => nova(true));
    let ant = performance.now(), vel = 0, ultimoY = scrollY;
    addEventListener('scroll', () => { vel = Math.min(Math.abs(scrollY - ultimoY), 60); ultimoY = scrollY; }, { passive: true });
    (function loop(agora) {
      const dt = Math.min((agora - ant) / 1000, .05); ant = agora;
      vel *= .92;
      ctx.clearRect(0, 0, W, H);
      ctx.globalCompositeOperation = 'lighter';
      for (const b of bs) {
        b.y -= (b.v + vel * .0015) * dt * 2.2;
        b.x += Math.sin(agora / 900 + b.f) * .0006;
        if (b.y < -.05) Object.assign(b, nova(false));
        const x = b.x * W, y = b.y * H, r = b.r * dpr * 3;
        const g = ctx.createRadialGradient(x, y, 0, x, y, r);
        g.addColorStop(0, `rgba(255,200,120,${b.a})`); g.addColorStop(.4, `rgba(251,94,1,${b.a * .6})`); g.addColorStop(1, 'rgba(251,94,1,0)');
        ctx.fillStyle = g; ctx.beginPath(); ctx.arc(x, y, r, 0, 6.283); ctx.fill();
      }
      requestAnimationFrame(loop);
    })(ant);
  }

  if (!temGsap) return;

  /* ═══════════════ ANIMAÇÕES DE ROLAGEM (GSAP) ═══════════════ */
  gsap.registerPlugin(ScrollTrigger);

  // abertura: o banner afunda e escurece ao sair
  gsap.to('.koi__gl, .koi__img', { scale: 1.12, yPercent: 8, ease: 'none', scrollTrigger: { trigger: '.koi', start: 'top top', end: 'bottom top', scrub: true } });
  gsap.from('.koi__info > *', { y: 40, opacity: 0, duration: 1, stagger: .12, ease: 'power3.out', delay: .4 });

  // revelações genéricas
  $$('.rv').forEach(el => {
    gsap.to(el, { opacity: 1, y: 0, duration: 1, ease: 'power3.out', scrollTrigger: { trigger: el, start: 'top 88%' } });
  });

  // manifesto acende palavra por palavra
  const palavras = $$('#manifesto .w');
  if (palavras.length) {
    ScrollTrigger.create({
      trigger: '#manifesto', start: 'top 75%', end: 'bottom 45%', scrub: true,
      onUpdate: s => { const n = Math.round(s.progress * palavras.length); palavras.forEach((w, i) => w.classList.toggle('on', i < n)); },
    });
  }

  // a mesa: rolagem lateral presa (só telas maiores)
  ScrollTrigger.matchMedia({
    '(min-width: 601px)': () => {
      const trilho = $('#trilho');
      const dist = () => trilho.scrollWidth - innerWidth;
      gsap.to(trilho, { x: () => -dist(), ease: 'none', scrollTrigger: { trigger: '.k-mesa', start: 'top top', end: () => '+=' + dist(), pin: true, scrub: 1, invalidateOnRefresh: true } });
    },
    '(max-width: 600px)': () => {
      $$('.k-card').forEach(c => gsap.from(c, { y: 60, opacity: 0, duration: .9, ease: 'power3.out', scrollTrigger: { trigger: c, start: 'top 90%' } }));
    },
  });

  // line-up entra em cascata
  gsap.fromTo('.k-pessoa', { y: 80, opacity: 0, rotate: i => (i % 2 ? 4 : -4) }, { y: 0, opacity: 1, rotate: 0, duration: 1, stagger: .12, ease: 'power3.out', scrollTrigger: { trigger: '#lineupGrid', start: 'top 85%' } });

  // vídeo: a moldura "levanta" da mesa
  gsap.fromTo('#moldura', { rotateX: 18, scale: .88 }, { rotateX: 0, scale: 1, ease: 'none', scrollTrigger: { trigger: '#moldura', start: 'top 95%', end: 'top 35%', scrub: true } });

  // ingressos: caem como bilhetes sobre a mesa
  $$('.k-bilhete').forEach((b, i) => {
    const giro = [-4, 2, 5][i % 3];
    gsap.fromTo(b, { y: -160, opacity: 0, rotate: giro * 4 }, { y: 0, opacity: 1, rotate: giro, duration: 1.1, delay: i * .14, ease: 'back.out(1.4)', scrollTrigger: { trigger: '#ingLista', start: 'top 85%' } });
  });

  // faixas aceleram com a rolagem
  $$('.k-faixa').forEach(f => gsap.fromTo(f, { xPercent: -3 }, { xPercent: 3, ease: 'none', scrollTrigger: { trigger: f, start: 'top bottom', end: 'bottom top', scrub: true } }));

  // final: o logo cresce
  gsap.fromTo('.k-final__logo', { scale: .7 }, { scale: 1, ease: 'none', scrollTrigger: { trigger: '.k-final', start: 'top bottom', end: 'center center', scrub: true } });

  addEventListener('load', () => ScrollTrigger.refresh());
})();
