/* Menu e rodapé compartilhados: edite os links aqui e todas as páginas atualizam. */
(function () {
  const MENU = [
    { label: 'Quem somos', href: 'quem-somos.html' },
    { label: 'Ofertas', href: 'ofertas.html' },
    { label: 'Koinonia', href: 'koinonia.html' },
    { label: 'Shalom Online', href: 'shalom-online.html' },
  ];
  const LOCALIDADES = [
    { label: 'Todas as Células', href: 'todas-as-localidades.html' },
    { label: 'Shalom Umuarama', href: 'index.html' },
  ];
  const RODAPE = [
    { label: 'Home', href: 'index.html' },
    { label: 'Células', href: 'todas-as-localidades.html' },
    { label: 'Koinonia', href: 'koinonia.html' },
    { label: 'Shalom Online', href: 'shalom-online.html' },
    { label: 'Quem somos', href: 'quem-somos.html' },
    { label: 'Ofertas', href: 'ofertas.html' },
  ];
  const INSTAGRAM = 'https://www.instagram.com/batistashalomumuarama/';
  const YOUTUBE = 'https://www.youtube.com/@ComunidadeBatistaShalom';

  const atual = location.pathname.split('/').pop() || 'index.html';
  const ativo = href => (href === atual ? ' is-active' : '');
  const links = cls => MENU.map(m => `<li><a class="nav__link${ativo(m.href)}" href="${m.href}">${m.label}</a></li>`).join('');

  const nav = document.getElementById('site-nav');
  if (nav) {
    nav.outerHTML = `
<header class="nav" id="nav">
  <div class="nav__bg"></div>
  <div class="nav__inner">
    <a class="nav__logo" href="index.html" aria-label="Comunidade Batista Shalom"><img src="assets/logo.png" alt="Shalom"></a>
    <div class="nav__right">
      <ul class="nav__menu">${links()}</ul>
      <div class="nav__drop" data-drop>
        <button class="nav__loc-btn" type="button" aria-haspopup="true">Localidades</button>
        <div class="nav__dropmenu">${LOCALIDADES.map(l => `<a href="${l.href}" class="${ativo(l.href).trim()}">${l.label}</a>`).join('')}</div>
      </div>
      <div class="nav__drop nav__drop--lang" data-drop>
        <button class="nav__icon-btn" type="button" aria-label="Idioma">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19M12 2.5c2.6 2.8 3.9 6 3.9 9.5S14.6 18.7 12 21.5M12 2.5C9.4 5.3 8.1 8.5 8.1 12s1.3 6.7 3.9 9.5"/></svg>
        </button>
        <div class="nav__dropmenu">
          <a href="#" class="is-active">Português</a>
          <a href="#">English</a>
          <a href="#">Español</a>
        </div>
      </div>
      <button class="nav__icon-btn nav__burger" type="button" aria-label="Menu" id="burger">
        <svg class="ic-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
        <svg class="ic-close" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M5 5l14 14M19 5L5 19"/></svg>
      </button>
    </div>
  </div>
  <ul class="nav__drawer">${links()}</ul>
</header>`;
  }

  const footer = document.getElementById('site-footer');
  if (footer) {
    footer.outerHTML = `
<footer class="footer">
  <div class="footer__row">
    <a class="footer__brand" href="index.html"><img src="assets/logo.png" alt="Shalom"></a>
    <nav class="footer__links">${RODAPE.map(l => `<a href="${l.href}">${l.label}</a>`).join('')}</nav>
    <div class="footer__social">
      <a href="${INSTAGRAM}" target="_blank" rel="noopener" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
      <a href="${YOUTUBE}" target="_blank" rel="noopener" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
    </div>
  </div>
  <p class="footer__bottom">© ${new Date().getFullYear()} Comunidade Batista Shalom</p>
</footer>`;
  }

  const header = document.getElementById('nav');
  if (header) {
    const onScroll = () => header.classList.toggle('is-scrolled', window.scrollY > 10);
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('load', onScroll);
    onScroll();
    document.getElementById('burger').addEventListener('click', () => header.classList.toggle('is-open'));
  }

  document.querySelectorAll('[data-drop] > button').forEach(btn => {
    btn.addEventListener('click', e => {
      e.stopPropagation();
      const drop = btn.parentElement;
      const abrir = !drop.classList.contains('is-open');
      document.querySelectorAll('[data-drop]').forEach(d => d.classList.remove('is-open'));
      drop.classList.toggle('is-open', abrir);
    });
  });
  document.addEventListener('click', () => document.querySelectorAll('[data-drop]').forEach(d => d.classList.remove('is-open')));
})();
