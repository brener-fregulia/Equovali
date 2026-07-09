# Equovali

Site institucional da **Equovali**, ONG de equoterapia gratuita no Alto Vale do Itajaí (SC).

## Stack

- **Frontend:** Svelte + Vite + TypeScript, build estático.
- **Backend:** PHP mínimo — um único endpoint (`server/api/inscricao.php`) que recebe o formulário de inscrição na lista de espera e envia e-mail via PHPMailer.
- **Hospedagem:** compartilhada, acesso apenas via FTP (sem SSH). Por isso o site é estático e o PHP é o menor possível.

## Estrutura

```
Equovali/
├── site/              # Frontend Svelte (fonte)
├── server/
│   ├── api/            # Endpoint PHP (inscricao.php)
│   └── libs/PHPMailer/  # Biblioteca vendorizada (sem Composer, compatível com FTP)
├── scripts/build.sh    # Gera dist/ pronta para subir via FTP
├── .env.example         # Modelo de variáveis de ambiente
└── PROJETO.md           # Decisões de arquitetura, convenções e pendências
```

## Desenvolvimento local

```bash
cd site
npm install
npm run dev
```

## Build para deploy

```bash
./scripts/build.sh
```

Isso gera a pasta `dist/`, que é **exatamente** o conteúdo a subir para `public_html/` via FTP.

## Variáveis de ambiente

Copie `.env.example` para `.env` e preencha com as credenciais reais. O `.env` **nunca** é commitado e, em produção, fica **fora** de `public_html/` (um nível acima, ex: `/home/equovali1/.env`), fora do alcance direto da web.

A pasta `tmp/` (sessões, rate limiting, uploads temporários) segue a mesma lógica: existe apenas no servidor, fora de `public_html/`, e nunca é versionada.

## Convenções

Commits seguem [Conventional Commits](https://www.conventionalcommits.org/) em português. Detalhes completos em [`PROJETO.md`](./PROJETO.md).

## Licença

Ver [`LICENSE`](./LICENSE).
