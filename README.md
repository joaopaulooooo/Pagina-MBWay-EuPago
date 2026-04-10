# 💳 Pagamento MB WAY via Eupago

Integração simples e completa de pagamentos **MB WAY** usando a API da [Eupago](https://www.eupago.pt), com envio automático de emails de confirmação via SMTP.

Sem dependências externas, sem frameworks — apenas PHP puro.

---

## ✨ Funcionalidades

- ✅ Pagamento MB WAY via API Eupago v1.02
- ✅ Webhook automático para confirmação de pagamento
- ✅ Email de confirmação enviado ao cliente
- ✅ Email de notificação enviado ao administrador
- ✅ Temporizador de 4 minutos com verificação de estado em tempo real
- ✅ Suporte a valor fixo via URL (ex: `/5.90`)
- ✅ Credenciais protegidas via ficheiro `.env`
- ✅ Logs de debug em `public_html/debug.log`
- ✅ Bloqueio de acesso direto a ficheiros sensíveis via `.htaccess`

---

## 📁 Estrutura do Projeto

```
/
├── .env                        # ⚠️ Credenciais (não incluído no repositório)
├── .env.example                # Modelo das variáveis necessárias
├── phpmailer/
│   └── src/
│       ├── PHPMailer.php       # Cliente SMTP
│       ├── SMTP.php            # Protocolo SMTP
│       └── Exception.php       # Tratamento de erros
└── public_html/
    ├── index.php               # Aplicação principal
    ├── .htaccess               # Segurança e rewrite de URLs
    └── tx_data/                # Ficheiros de transações (gerado automaticamente)
```

---

## ⚙️ Instalação

### 1. Clonar o repositório

```bash
git clone https://github.com/SEU_USERNAME/pagamento-eupago.git
cd pagamento-eupago
```

### 2. Configurar as credenciais

Copie o ficheiro de exemplo e preencha com os seus dados:

```bash
cp .env.example .env
```

Edite o `.env`:

```env
# Chave de API Eupago (Backoffice → Canais → Listagem de Canais → Editar)
EUPAGO_API_KEY=a1b2c3d4-e5f6-...

# Configurações SMTP para envio de emails
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_USER=o-seu-email@gmail.com
SMTP_PASS=a-sua-password
```

### 3. Fazer upload para o servidor

Transfira os ficheiros para o seu servidor de alojamento (via FTP, cPanel, etc.):

- A pasta `public_html/` deve ficar acessível publicamente
- As pastas `phpmailer/` e o ficheiro `.env` devem ficar **fora** da `public_html/`

Estrutura recomendada no servidor:

```
/home/utilizador/
├── .env
├── phpmailer/
└── public_html/          ← raiz pública do site
    ├── index.php
    └── .htaccess
```

### 4. Configurar o Webhook na Eupago

No backoffice da Eupago, siga estes passos:

1. Aceda a **Canais** → **Listagem de Canais**
2. Clique em **Editar** no canal correspondente
3. No campo **Site de Retorno**, preencha com o URL do seu servidor:
   ```
   https://o-seu-dominio.pt/index.php
   ```
4. Copie a **Chave API** que aparece nessa mesma página e cole no ficheiro `.env` no campo `EUPAGO_API_KEY`

---

## 🚀 Utilização

### Formulário normal

Aceda a:
```
https://o-seu-dominio.pt/
```

O cliente preenche nome, email, telemóvel e valor, e recebe a notificação MB WAY.

### Valor pré-definido via URL

Para enviar um link com um valor já fixo (útil para faturas ou encomendas):

```
https://o-seu-dominio.pt/15.90
https://o-seu-dominio.pt/99.00
```

O campo de valor fica bloqueado e preenchido automaticamente.

---

## 🔒 Segurança

| Medida | Descrição |
|---|---|
| `.env` fora da `public_html/` | Credenciais nunca expostas ao browser |
| `.gitignore` | `.env` e dados de clientes excluídos do repositório |
| `.htaccess` | Bloqueia acesso direto a `debug.log` e `tx_data/` |
| HTTPS obrigatório | Redireciona automaticamente HTTP → HTTPS |
| Sanitização de inputs | Telemóvel, valor e IDs validados antes de qualquer operação |


---

## 📧 Emails enviados

Após confirmação de pagamento, são enviados automaticamente dois emails:

**Para o cliente** — confirmação com o valor e ID da transação

**Para o administrador** — notificação com todos os dados (nome, email, telefone, valor, ID)

Para personalizar os emails, edite a função `enviar_emails_pagamento()` no `index.php`.

---

## 🛠️ Requisitos

- PHP **8.1** ou superior
- Extensão `curl` ativada
- Extensão `openssl` ativada (para STARTTLS)
- Servidor Apache com `mod_rewrite` ativo
- Conta ativa na [Eupago](https://www.eupago.pt)

---

## 📄 Licença

Distribuído sob a licença **MIT**. Consulte o ficheiro [LICENSE](LICENSE) para mais detalhes.

Pode usar, modificar e distribuir livremente, mesmo em projetos comerciais.
