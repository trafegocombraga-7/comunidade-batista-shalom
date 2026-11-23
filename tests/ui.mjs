// Teste visual: abre checkout, pedido, painel e check-in num navegador de verdade e tira prints.
import { chromium, devices } from 'playwright';
import fs from 'node:fs';

const BASE = 'http://127.0.0.1:8099';
const PASTA = 'tests/prints';
fs.mkdirSync(PASTA, { recursive: true });
const erros = [];
let n = 0;
const print = async (p, nome) => { await p.waitForTimeout(400); await p.screenshot({ path: `${PASTA}/${String(++n).padStart(2, '0')}-${nome}.png`, fullPage: true }); };
const vigiar = (p, onde) => {
  p.on('pageerror', e => erros.push(`[${onde}] ${e.message}`));
  p.on('console', m => { if (m.type() === 'error' && !/favicon|fonts\.g|cdnjs|jsdelivr|Failed to load resource/.test(m.text())) erros.push(`[${onde}] console: ${m.text()}`); });
};

const nav = await chromium.launch();

/* prepara um evento com vagas sobrando (o teste de API esgotou o Koinonia): publica a cópia "koinonia-copia" */
{
  const ctx = await nav.newContext();
  const r = ctx.request;
  const login = await (await r.post(`${BASE}/ingressos/api/auth.php?acao=login`, { data: { email: 'admin@teste.com', senha: 'senhaforte1' } })).json();
  const h = { 'X-CSRF': login.csrf };
  const lista = await (await r.get(`${BASE}/ingressos/api/admin.php?acao=eventos`, { headers: h })).json();
  const copia = lista.find(e => e.slug === 'koinonia-copia');
  const ev = await (await r.get(`${BASE}/ingressos/api/admin.php?acao=evento&id=${copia.id}`, { headers: h })).json();
  ev.status = 'publicado'; ev.lotacao = 0; ev.nome = 'Koinonia';
  ev.tipos = ev.tipos.map(t => ({ ...t, quantidade: 0, preco: (t.preco / 100).toFixed(2).replace('.', ',') }));
  ev.cupons = [];
  const s = await r.post(`${BASE}/ingressos/api/admin.php?acao=evento_salvar`, { headers: h, data: ev });
  if (!s.ok()) throw new Error('não publicou o evento de teste: ' + await s.text());
  await ctx.close();
}
const SLUG = 'koinonia-copia';

/* ---------- checkout (computador) com PIX ---------- */
{
  const ctx = await nav.newContext({ viewport: { width: 1366, height: 900 } });
  const p = await ctx.newPage(); vigiar(p, 'checkout');
  await p.goto(`${BASE}/e/${SLUG}`);
  await p.waitForSelector('.tipo');
  await print(p, 'checkout-inicio');
  const dupla = p.locator('.tipo', { hasText: 'Dupla' });
  await dupla.locator('button[data-d="1"]').click();
  await p.waitForFunction(() => document.querySelector('#total').textContent.includes('220'));
  await p.fill('#nome', 'João Teste Visual');
  await p.fill('#email', 'joao@exemplo.com');
  await p.fill('#telefone', '44999998888');
  await p.fill('#cpf', '52998224725');
  await print(p, 'checkout-preenchido-pix');
  await Promise.all([p.waitForURL(/pedido\.php/), p.click('#pagar')]);
  await p.waitForSelector('.pix-qr svg, .pix-qr img');
  await print(p, 'pedido-pix-aguardando');
  await p.click('#simular');
  await p.waitForSelector('.ingresso');
  await print(p, 'pedido-pago-ingressos');
  await ctx.close();
}

/* ---------- checkout (celular) com dois cartões ---------- */
{
  const ctx = await nav.newContext({ ...devices['iPhone 13'] });
  const p = await ctx.newPage(); vigiar(p, 'checkout-celular');
  await p.goto(`${BASE}/e/${SLUG}`);
  await p.waitForSelector('.tipo');
  await p.locator('.tipo', { hasText: 'Individual' }).locator('button[data-d="1"]').click();
  await p.waitForFunction(() => document.querySelector('#total').textContent.includes('120'));
  await p.click('label.metodo:has-text("Dois cartões")');
  await p.waitForSelector('#divisor');
  await p.waitForFunction(() => document.querySelectorAll('#resumoContas dt').length >= 2);
  await print(p, 'celular-dois-cartoes');
  // cartão recusado mostra erro sem sair da página
  await p.fill('#nome', 'Ana Teste Celular'); await p.fill('#email', 'ana@exemplo.com'); await p.fill('#telefone', '44988887777'); await p.fill('#cpf', '52998224725');
  const cards = p.locator('.cartao');
  if (await cards.count() === 2) {
    for (const [i, num] of [[0, '4111111111111111'], [1, '4000000000000002']]) {
      const c = cards.nth(i);
      await c.locator('[data-c="numero"]').fill(num); await c.locator('[data-c="nome"]').fill('ANA TESTE');
      await c.locator('[data-c="validade"]').fill('1230'); await c.locator('[data-c="cvv"]').fill('123');
    }
    await p.click('#pagar');
    await p.waitForFunction(() => document.querySelector('#erro').textContent.length > 0);
    await print(p, 'celular-cartao-recusado');
  }
  await ctx.close();
}

/* ---------- painel ---------- */
{
  const ctx = await nav.newContext({ viewport: { width: 1366, height: 900 } });
  const p = await ctx.newPage(); vigiar(p, 'painel');
  await p.goto(`${BASE}/painel`);
  await p.waitForSelector('#formLogin:not([hidden])');
  await print(p, 'painel-login');
  await p.fill('#formLogin [name=email]', 'admin@teste.com');
  await p.fill('#formLogin [name=senha]', 'senhaforte1');
  await p.click('#formLogin button');
  await p.waitForSelector('.num');
  await print(p, 'painel-inicio');
  await p.goto(`${BASE}/painel#evento/1`); await p.reload();
  await p.waitForSelector('#formEvento');
  await print(p, 'painel-evento-info');
  await p.click('.abas button[data-aba="ing"]'); await print(p, 'painel-evento-ingressos');
  await p.click('.abas button[data-aba="pag"]'); await print(p, 'painel-evento-pagamento');
  await p.click('#formEvento button[type=submit]');
  await p.waitForSelector('.toast:not([hidden])');
  await p.goto(`${BASE}/painel#vendas`); await p.reload();
  await p.waitForSelector('tr[data-id]');
  await print(p, 'painel-vendas');
  await p.click('tr[data-id] >> nth=0');
  await p.waitForSelector('#modal:not([hidden])');
  await print(p, 'painel-pedido');
  await p.click('.fechar');
  await p.goto(`${BASE}/painel#contas`); await p.reload(); await p.waitForSelector('tr[data-id]'); await print(p, 'painel-recebimentos');
  await p.click('tr[data-id] >> nth=1'); await p.waitForSelector('#formConta'); await print(p, 'painel-conta-mp'); await p.click('.fechar');
  await p.goto(`${BASE}/painel#equipe`); await p.reload(); await p.waitForSelector('tr[data-id]'); await print(p, 'painel-equipe');
  await p.goto(`${BASE}/painel#cortesias`); await p.reload(); await p.waitForSelector('#formCort'); await print(p, 'painel-cortesias');
  await p.goto(`${BASE}/painel#evento/novo`); await p.reload(); await p.waitForSelector('#formEvento');
  await p.fill('#formEvento [name=nome]', 'Conferência de Jovens');
  await p.click('#formEvento button[type=submit]');
  await p.waitForSelector('.link-box code');
  await print(p, 'painel-evento-novo-criado');
  await ctx.close();
}

/* ---------- check-in (celular) ---------- */
{
  const ctx = await nav.newContext({ ...devices['iPhone 13'] });
  const p = await ctx.newPage(); vigiar(p, 'checkin');
  await p.goto(`${BASE}/checkin`);
  await p.waitForSelector('#telaLogin:not([hidden])');
  await p.fill('#lEmail', 'porta1@teste.com'); await p.fill('#lSenha', 'portaporta');
  await p.click('#formLogin button');
  await p.waitForSelector('#telaEvento:not([hidden])');
  await print(p, 'checkin-evento');
  await p.selectOption('#selEvento', '1');
  await p.click('#btnComecar');
  await p.waitForSelector('#telaLeitor:not([hidden])');
  await p.waitForTimeout(800);
  await print(p, 'checkin-camera');
  await p.click('#trocaModo');
  await p.fill('#busca', 'Maria');
  await p.waitForSelector('#lista .item');
  await print(p, 'checkin-busca');
  const liberar = p.locator('#lista button[data-c]').first();
  if (await liberar.count()) { await liberar.click(); await p.waitForSelector('#resultado:not([hidden])'); await print(p, 'checkin-liberado'); }
  await ctx.close();
}

/* ---------- painel antigo de células: troca obrigatória da senha que ficou pública ---------- */
{
  const ctx = await nav.newContext({ viewport: { width: 1366, height: 900 } });
  const p = await ctx.newPage(); vigiar(p, 'admin-celulas');
  await p.goto(`${BASE}/admin.html`);
  await p.waitForSelector('#tela-login', { state: 'visible' });
  await p.fill('#l-user', 'shalomadmin'); await p.fill('#l-pass', 'jesus' + 'teama');
  await p.click('#btn-entrar');
  await p.waitForSelector('#tela-senha', { state: 'visible' });
  if (!(await p.isVisible('#campo-codigo'))) erros.push('[admin-celulas] campo do código não apareceu na troca obrigatória');
  await print(p, 'celulas-troca-obrigatoria');
  await p.fill('#t-atual', 'jesus' + 'teama');
  await p.fill('#t-codigo', 'TESTE-CODIGO');
  await p.fill('#t-nova', 'senha-visual-123'); await p.fill('#t-nova2', 'senha-visual-123');
  await p.click('#btn-trocar');
  await p.waitForSelector('#tela-painel', { state: 'visible' });
  await p.waitForSelector('#lista .item');
  await print(p, 'celulas-painel');
  await p.click('#btn-abrir-senha');
  await p.waitForSelector('#tela-senha', { state: 'visible' });
  if (await p.isVisible('#campo-codigo')) erros.push('[admin-celulas] troca normal não deveria pedir código');
  await print(p, 'celulas-trocar-senha');
  await p.click('#btn-senha-voltar');
  await p.waitForSelector('#tela-painel', { state: 'visible' });
  await ctx.close();
}

await nav.close();
if (erros.length) { console.error('Erros de JavaScript nas páginas:\n' + erros.join('\n')); process.exit(1); }
console.log(`UI ok: ${n} prints em ${PASTA}`);
