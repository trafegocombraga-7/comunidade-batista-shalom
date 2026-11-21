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

/* ---------- checkout (computador) com PIX ---------- */
{
  const ctx = await nav.newContext({ viewport: { width: 1366, height: 900 } });
  const p = await ctx.newPage(); vigiar(p, 'checkout');
  await p.goto(`${BASE}/e/koinonia`);
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
  await p.goto(`${BASE}/e/koinonia`);
  await p.waitForSelector('.tipo');
  const cel = p.locator('.tipo', { hasText: 'Célula' });
  const ind = p.locator('.tipo', { hasText: 'Individual' });
  const alvo = (await cel.locator('button[data-d="1"]').isEnabled()) ? cel : ind;
  if (await alvo.locator('button[data-d="1"]').isEnabled()) await alvo.locator('button[data-d="1"]').click();
  await p.click('label.metodo:has-text("Dois cartões")');
  await p.waitForSelector('#divisor');
  await p.waitForTimeout(600);
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
  await p.fill('#busca', 'João');
  await p.waitForSelector('#lista .item');
  await print(p, 'checkin-busca');
  const liberar = p.locator('#lista button[data-c]').first();
  if (await liberar.count()) { await liberar.click(); await p.waitForSelector('#resultado:not([hidden])'); await print(p, 'checkin-liberado'); }
  await ctx.close();
}

await nav.close();
if (erros.length) { console.error('Erros de JavaScript nas páginas:\n' + erros.join('\n')); process.exit(1); }
console.log(`UI ok: ${n} prints em ${PASTA}`);
