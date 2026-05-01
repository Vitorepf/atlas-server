# Atlas CLI Produto Final

Este documento e a referencia operacional unica do Atlas CLI no Mac. O objetivo do produto e substituir o uso direto de Claude Code, Codex CLI e chats soltos por uma superficie terminal unica, persistente e governada pelo Atlas.

## Principio

Atlas e a superficie. Claude, Codex e qualquer modelo futuro sao motores intercambiaveis. O CLI deve preservar contexto, decisao, memoria, permissoes, traces e qualidade mesmo quando o provider muda.

## Instalacao

Use apenas o bootstrap como fluxo recomendado:

```bash
./bin/atlas bootstrap --dry-run
./bin/atlas bootstrap --refresh-providers --strict
```

Para operar em qualquer pasta dentro do usuario `vitorepf`:

```bash
./bin/atlas bootstrap --operator-mode --operator-root=/Users/vitorepf --refresh-providers --strict
```

Se o launcher ainda nao estiver no `PATH`:

```bash
./bin/atlas bootstrap --write-shell-profile --refresh-providers --strict
```

O bootstrap diagnostica binarios, grava `.env` com backup, instala o launcher e roda `atlas doctor --strict` no fim.

Para habilitar tarefas agendadas do P5:

```bash
./bin/atlas bootstrap --install-scheduler-cron --strict
```

## Uso diario

```bash
atlas ask "pergunta direta"
atlas ask --image ~/Desktop/tela.png "analise essa tela"
atlas dev
atlas dev "implemente a tarefa"
atlas dev "instale dependencias e rode o setup"
atlas debug "investigue esse erro"
atlas fix "corrija o teste falhando"
atlas review "revise essa decisao"
atlas compare "faça revisao cruzada Claude/Codex"
atlas research "pesquise no contexto do Atlas"
atlas schedule list
```

Para trabalho pesado, comece por `atlas dev`. O provider e escolhido pelo Atlas, nao pelo habito de abrir uma ferramenta externa.
Quando o bootstrap foi feito com `--operator-mode --operator-root=/Users/vitorepf`, `atlas ask` e `atlas dev` entram no runtime operador por padrao: workspace atual para contexto, `/Users/vitorepf` como raiz autorizada para execucao.
Sem tarefa, `atlas dev` abre o Dev Cockpit com workspace, provider, permissao, thread, git e skills. Se o repo tiver `.atlas/skills` ou `.agents/skills`, rode `atlas skills trust` uma vez no repo para carregar as skills locais sem prompt. Skills locais so adicionam contexto/procedimentos do projeto; nomes que conflitam com skills internas do Atlas sao ignorados e aparecem como warning.
Para screenshot copiado no macOS, basta pedir naturalmente dentro do cockpit: `analise essa tela`, `corrija esse screenshot`, `o que esta errado nesse print?`. O Atlas detecta a referência visual, salva a imagem do clipboard, anexa ao pedido e usa Codex CLI como motor preferencial por ter suporte nativo a `--image`. `/paste-image` fica como fallback manual.

## Sessao longa

```bash
atlas state
atlas compact
atlas handoff --to=codex_cli
atlas trace last
```

Uma sessao longa precisa manter objetivo, fase atual, decisoes, proximos passos, artefatos relevantes e provider handoff. O Atlas nao deve responder como se cada mensagem fosse uma conversa nova.

## Runtime e permissoes

```bash
atlas runtime workspace.profile
atlas runtime git.diff
atlas runtime test.run --yes
atlas permissions status
atlas checkpoint
atlas quality --run-tests --yes
```

Escritas, shell, patches e testes passam pelo runtime do Atlas com escopo, permissao, checkpoint e auditoria. Provider nao deve executar ferramentas livremente fora dessas regras.

## Readiness

```bash
atlas doctor --strict
atlas final --strict
```

`atlas doctor --strict` valida binarios, health de providers, conselho Claude+Codex, permissao de workspace e qualidade.

`atlas final --strict` valida B0-B8, hardening de produto, CI, docs, release preflight estrutural e doctor final.

## Scheduled Tasks

```bash
atlas schedule add "briefing tecnico" --schedule="0 9 * * 1-5" --prompt="..."
atlas schedule add "check de foco" --schedule="every 2h" --prompt="..."
atlas schedule run-now <id>
php artisan atlas:scheduler:tick --dry-run --json
```

O delivery local salva output auditavel em `storage/app/atlas/scheduled/{id}/{timestamp}.md`. Targets `mobile` e `mobile_push` sao persistidos para o P6, mas a entrega fora do arquivo local ainda depende do Mobile Gateway.

`atlas:scheduler:tick --dry-run` e diagnostico seguro: mostra o que seria executado sem claim, sem avancar agenda e sem enfileirar job. Para validar claim real sem chamar provider, use `--no-dispatch`.

## Dogfooding

Uso real nao pode ser simulado. Registre tarefas reais:

```bash
atlas dogfood run
atlas dogfood start --scenario=dev_task --provider=codex_cli --notes="implementacao longa"
atlas dogfood record --scenario=dev_task --provider=codex_cli --result=passed --duration-minutes=90
atlas dogfood record --scenario=provider_handoff --provider=claude_cli --result=passed --duration-minutes=20
atlas dogfood report --strict
```

`atlas dogfood run` executa um smoke dogfood seguro: cria uma sessao sem provider, valida preflight dev, debug, handoff, quality gate, TUI e release preflight. Esse smoke marca os artefatos persistidos com `dogfood_profile=smoke` e apaga thread, trace, jobs e eventos relacionados no fim da execucao. Ele prova integracao, mas nao conta como evidencia real para release final.

Cenarios obrigatorios para declarar produto final de uso diario:

- `ask_session`
- `dev_task`
- `debug_fix`
- `provider_handoff`
- `quality_gate`
- `tui_status`
- `release_check`

O gate final exige cobertura de 3 dias, todos os cenarios obrigatorios passando como uso real, e nenhuma falha bloqueante no periodo. `atlas dogfood report --strict` ativa automaticamente o gate de uso real; para leitura explicita, use `atlas dogfood report --real`.

## Release

```bash
atlas release --version=v2.0.0
atlas release --version=v2.0.0 --create-tag
```

O release gate verifica versao, worktree, docs, checklist, CI, dogfood real e `atlas final --strict`. Tag so deve ser criada quando todos os gates passarem. Smoke automatizado nao libera release final sozinho.

Para validar apenas estrutura em CI ou pre-release local:

```bash
atlas release --version=v2.0.0 --preflight --no-final --skip-dogfood --allow-dirty
```

`--skip-dogfood` e `--no-final` so devem passar em `--preflight`. Release final sem preflight falha quando algum gate e pulado.

## CI Obrigatorio

Todo PR ou push precisa rodar:

```bash
composer validate
composer install
php artisan test
git diff --check
./bin/atlas final --strict --refresh-providers
```

O workflow oficial esta em `.github/workflows/atlas-cli.yml`.

## Definition Of Done

O Atlas CLI esta pronto quando:

- `atlas bootstrap --refresh-providers --strict` passa no Mac real;
- `atlas doctor --strict` passa;
- `atlas final --strict` passa;
- `php artisan test` passa;
- `git diff --check` passa;
- dogfood de 3 dias cobre os cenarios obrigatorios;
- release gate passa para a versao alvo;
- o usuario consegue trabalhar pelo Atlas CLI sem abrir Claude Code ou Codex CLI diretamente.
