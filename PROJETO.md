# Equovali - Notas do Projeto

Documento de referência pra manutenção do site da ONG Equovali. Serve como guia rápido pra decisões já tomadas, convenções adotadas e o que ainda está em aberto.

---

## Contexto

- ONG de equoterapia gratuita, tocada por uma pessoa com pouco contato com tecnologia - **não** há necessidade de CMS ou edição de conteúdo por leigos no curto prazo.
- Projeto nasceu como trabalho acadêmico (Unidavi), agora virou repositório público open source: `brener-fregulia/Equovali`.
- Hospedagem: **apenas FTP** (FileZilla, porta 21). Sem SSH, sem acesso a shell remoto. PHP roda no servidor (versão a confirmar via diagnóstico).
- Necessidade funcional real, além da homepage: **formulário de inscrição na lista de espera envia e-mail** pra ONG (via PHPMailer/SMTP). É a única "regra de negócio" que existe hoje.
- App mobile e "sistema" completo são visão de longo prazo, **não é prioridade agora**. Não forçar abstrações pensando nisso - só evitar decisões que fechem essa porta.

## Decisões de arquitetura

- **Frontend**: Svelte + Vite. Build local, artefato final (`dist/`) é o que vai por FTP pra `public_html/`. Repositório não versiona o build.
- **Backend**: PHP mínimo, só o necessário pra hospedagem simples. Um único endpoint (`server/api/inscricao.php`) recebe o POST do formulário, valida e envia e-mail via PHPMailer. Nada de framework PHP.
- **Sem Redis** - hospedagem provavelmente não tem. Rate limiting já funciona via arquivo JSON local (`tmp/rate_limit/`), suficiente pro volume desse site.
- **Segredos**: `.env` na raiz do `public_html/`, fora do controle de versão, subido manualmente via FTP (nunca commitado). `.env.example` no repo documenta as chaves sem valores reais.
- **Deploy**: manual via FTP por padrão. Automação futura possível via GitHub Actions + Action de FTP deploy, usando GitHub Secrets (seguro mesmo em repo público - PRs de forks não têm acesso aos secrets).
- **Reaproveitamento futuro (web → mobile)**: lógica de validação/tipos do formulário isolada em TypeScript puro (`src/lib/`), sem acoplar a framework, caso um dia sirva de base pra um app Tauri. Não expandir isso além do necessário agora.

## Convenções de commit

**Conventional Commits em pt-BR.** Formato:

```
tipo(escopo): descrição curta no infinitivo/particípio, em português
```

Tipos usados:
- `feat` - nova funcionalidade
- `fix` - correção de bug
- `refactor` - mudança de código sem alterar comportamento
- `style` - formatação, espaçamento, sem lógica (não confundir com CSS)
- `docs` - documentação
- `chore` - tarefas de manutenção, dependências, configs
- `perf` - melhoria de performance
- `test` - testes

Exemplos reais:
```
feat(faleconosco): adicionar validação de CID opcional
fix(header): corrigir link quebrado do favicon
refactor(controller): isolar envio de e-mail em função própria
docs(readme): atualizar passo a passo de deploy via FTP
chore(deps): remover dependência do Predis (Redis não usado em produção)
```

## Versionamento

- **Sem SemVer numerado** - não faz sentido pra uma homepage sem consumidores de API.
- **Changelog automático** a partir dos Conventional Commits (ferramenta a definir - `git-cliff` é candidato).
- **Tags leves de deploy** (`deploy-AAAA-MM-DD`) como marcador de rollback, não como "versão do produto".

## Pendências / próximos passos

- [ ] Rodar `_diag.php` no servidor pra confirmar versão do PHP e extensões disponíveis (`fileinfo`, `mail`, etc.) - depois apagar o arquivo.
- [ ] Trocar a senha de e-mail que estava exposta em `config/mail.json` (arquivo órfão, não será migrado pro novo repo).
- [ ] Decidir destino do BCC pessoal hardcoded no controller (`luscavogel@gmail.com`) - manter como variável de ambiente opcional ou remover.
- [ ] Definir estrutura final de pastas do novo repositório (proposta inicial já discutida: `site/` para o Svelte, `server/` para o PHP mínimo).
- [ ] Avaliar se automação de deploy via GitHub Actions entra já na v1 ou fica pra depois (por ora, deploy manual segue sendo o plano padrão).
- [ ] Página de desenvolvedores com LinkedIn de cada integrante (substitui a necessidade de importar histórico do repo antigo).
