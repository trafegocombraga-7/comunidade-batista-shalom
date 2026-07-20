# Deployment Automático · Comunidade Batista Shalom

Este repositório está configurado para fazer **deploy automático na Hostinger**.

## Como funciona

Toda vez que você faz `git push` para a branch `main`, um **workflow automático**:
1. Pega o código atualizado
2. Faz upload automático via FTP para `public_html/` na Hostinger
3. Você vê o site atualizado em minutos

**Nenhum manual zip, nenhum File Manager, nenhuma configuração manual.**

---

## Configuração (Uma única vez)

### 1. Criar repositório no GitHub

1. Vá para https://github.com/new
2. Nome do repositório: `comunidade-batista-shalom` (ou o nome que quiser)
3. Clique em "Create repository"
4. **NÃO inicialize com README** (deixe tudo em branco)

### 2. Conectar seu computador ao GitHub

No terminal (na pasta DEV SITE SHALOM):

```bash
# Adicionar o repositório GitHub como origin
git remote add origin https://github.com/SEU_USUARIO/comunidade-batista-shalom.git

# Enviar o código inicial
git add .
git commit -m "Inicial: site Comunidade Batista Shalom"
git push -u origin main
```

**Substitua `SEU_USUARIO` pelo seu nome de usuário do GitHub.**

Se pedir autenticação, use um **Personal Access Token**:
- Vá para https://github.com/settings/tokens
- Clique em "Generate new token (classic)"
- Dê acesso a `repo` (todo o repositório)
- Copie o token e use como senha

### 3. Adicionar credenciais FTP nos GitHub Secrets (O passo mais importante)

1. Vá ao seu repositório GitHub: `https://github.com/SEU_USUARIO/comunidade-batista-shalom`
2. Clique em **Settings** (engrenagem no topo)
3. Na esquerda, clique em **Secrets and variables** → **Actions**
4. Clique em **"New repository secret"**

**Adicione 3 secrets:**

#### Secret 1: FTP_HOST
- **Name:** `FTP_HOST`
- **Value:** `62.72.62.145`

#### Secret 2: FTP_USER
- **Name:** `FTP_USER`
- **Value:** `u712946051.comunidadebatistashalom.com.br`

#### Secret 3: FTP_PASSWORD
- **Name:** `FTP_PASSWORD`
- **Value:** (sua senha FTP)

**Pronto!** Suas credenciais estão seguras no GitHub. O workflow as usará automaticamente.

---

## Usando (A partir de agora)

Toda vez que quiser atualizar o site:

```bash
# Fazer alterações no index.html, admin.html, etc...

# Depois, enviar pro GitHub (e automaticamente para Hostinger):
git add .
git commit -m "Descrever o que você mudou"
git push
```

**Espere 2-5 minutos** e o site estará atualizado em `https://comunidadebatistashalom.com.br/`.

---

## Checklist

- [ ] Criei conta/repositório no GitHub
- [ ] Rodei `git push -u origin main` com sucesso
- [ ] Adicionei os 3 secrets no GitHub (FTP_HOST, FTP_USER, FTP_PASSWORD)
- [ ] Fiz um commit teste e fiz `git push`
- [ ] Checando o repositório, em "Actions" vejo o workflow rodando
- [ ] Após 2-5 minutos, o site foi atualizado na Hostinger

---

## FAQ

**P: Posso editar aqui no Claude e depois fazer push?**
R: Sim! Se eu editar o arquivo (ex: index.html), você pode depois fazer `git add . && git commit -m "..." && git push` que sobe tudo automaticamente.

**P: Como vejo se o deploy funcionou?**
R: Vá ao repositório GitHub → aba "Actions" → vê o histórico de deployments com status ✅ ou ❌.

**P: E se der erro no workflow?**
R: Clique no workflow falhado na aba Actions → vê os logs → geralmente é problema de credenciais nos secrets.

**P: Preciso fazer upload manual de nova foto?**
R: Nope! Coloca a foto na pasta `assets/`, faz `git add . && git commit && git push` e pronto, sobe automaticamente.

---

## Troubleshooting

Se o workflow falhar com erro de autenticação FTP:
1. Verificar se os 3 secrets estão corretos no GitHub
2. Testar as credenciais no File Manager da Hostinger (confirmar que funcionam)
3. Executar novamente: vá ao workflow falhado → clique "Re-run failed jobs"

**Precisa de ajuda?** Me avisa o erro da aba "Actions" no GitHub que eu resolvo.
