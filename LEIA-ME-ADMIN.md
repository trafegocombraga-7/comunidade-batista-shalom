# Painel Admin de Células · Comunidade Batista Shalom

## Acesso

- **Endereço:** `https://comunidadebatistashalom.com.br/admin.html`
- **Usuário:** `shalomadmin`
- **Senha:** `jesusteama`

---

## O que dá para cadastrar

Cada célula tem: **nome, tipo** (Rise/Flow/Vox/Eklektos/Famílias), **líder**, **WhatsApp do líder**,
**dia da semana**, **horário**, **bairro**, **endereço**, **idade média** e a **localização no mapa**.

A localização pode ser definida de duas formas:
1. Digitar o endereço e clicar em **“Localizar no mapa”** (busca automática).
2. Clicar direto no mapa (ou arrastar o pin) para posicionar com precisão.

Há também um interruptor **“Célula ativa”**: se desmarcado, a célula fica salva no sistema
mas **não aparece no site** — útil para células em formação ou pausadas.

Assim que salva, a célula aparece automaticamente no mapa e na lista da página **Células**.

---

## Arquivos criados

```
admin.html            → painel administrativo
api/db.php            → conexão SQLite + criação das tabelas + usuário admin
api/auth.php          → login / logout / sessão
api/celulas.php       → cadastrar, listar, editar e excluir células
data/                 → onde fica o banco (shalom.db), protegido por .htaccess
dev-server.py         → APENAS para testes locais (não precisa subir)
```

---

## Como publicar na Hostinger

1. Suba para a pasta pública (`public_html`) os arquivos:
   - `index.html`, `admin.html`, `links.html`
   - a pasta `assets/`
   - a pasta `api/`
   - o arquivo `ultimo-video.php`
   - a pasta `data/` **contendo apenas o `.htaccess`**

2. **Não suba o arquivo `data/shalom.db`.**
   O PHP cria o banco sozinho no primeiro acesso ao painel, já com o usuário admin.

   > Motivo: o banco gerado pelo servidor de testes local usa outro formato de
   > senha. Se ele for enviado junto, o login não funciona. Deixe o PHP criar o dele.

3. Confirme que a pasta `data/` tem **permissão de escrita** (755 ou 775).
   É nela que o SQLite grava.

4. Acesse `seudominio.com.br/admin.html` e faça o login.

---

## Segurança

- A senha é guardada **criptografada** (hash), nunca em texto puro.
- O acesso é por **sessão**: criar, editar e excluir exigem login.
- A listagem pública mostra **somente células ativas** e não expõe o telefone do líder
  além do que já aparece no site.
- A pasta do banco é bloqueada para acesso via navegador pelo `.htaccess`.

**Recomendação:** troque a senha padrão depois do primeiro acesso. Para isso, me peça
que eu adiciono a tela de “alterar senha” no painel.

---

## Testar localmente (opcional)

Como o computador não tem PHP instalado, existe um servidor de testes em Python
que reproduz a mesma API:

```bash
python3 dev-server.py
```

Depois abra `http://localhost:8765/admin.html`.

Esse arquivo serve só para desenvolvimento — **na Hostinger quem roda é o PHP**.
