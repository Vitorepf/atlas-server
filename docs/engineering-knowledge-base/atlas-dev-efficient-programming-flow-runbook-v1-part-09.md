---
id: atlas-dev-efficient-programming-flow-runbook-v1-part-09
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1 · Parte 9
status: active
category: programming
priority: 104
summary: Recorte focado do runbook Atlas Dev Efficient Programming Flow v1: 15.1.10 Checklist de release ate 15.1.15 Limitações de QA visual.
tags:
  - atlas-dev
  - efficient-programming-flow
  - runbook
  - split-doc
capabilities:
  - atlas_dev_implementation_runbook
  - atlas_dev_efficient_programming_flow
decisions:
  - Este recorte preserva uma parte operacional do runbook sem ampliar responsabilidade do indice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar junto com docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md quando o runbook Atlas Dev mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-dev-efficient-programming-flow-runbook-v1-part-09
graph_title: Atlas Dev Efficient Programming Flow Runbook v1 Parte 9
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-runbook-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 9
canonical_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 9
technical_name: atlas-dev-efficient-programming-flow-runbook-v1-part-09
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-09.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-09.md
allowed_changes:
  - Atualizar somente a parte operacional descrita neste recorte.
forbidden_changes:
  - Adicionar nova responsabilidade que pertença ao índice ou a outro recorte.
depends_on:
  - atlas-dev-efficient-programming-flow-runbook-v1
flows_to:
  - atlas_dev_efficient_flow_runtime
unlocks:
  - atlas_dev_operational_execution
governs:
  - atlas_dev.implementation.slices
evidence:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao runbook operacional.
---
# Atlas Dev Efficient Programming Flow Runbook v1 · Parte 9

## Resumo

Este recorte preserva uma parte operacional do runbook Atlas Dev Efficient Programming Flow v1: 15.1.10 Checklist de release ate 15.1.15 Limitações de QA visual.

## Papel no Atlas

Mantém o detalhe executável fora do índice principal para que a cartografia e o modal humano continuem legíveis.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1.md` e deve ser lido apenas quando a pessoa precisar do detalhe desta fatia.

## Contratos

Segue o contrato do runbook principal, o glossário canônico e o modelo obrigatório de documentação do Atlas.

## Fluxo

Índice canônico → recorte focado → execução ou revisão da fatia correspondente.

## Regras para IA

Não inferir responsabilidade nova. Não misturar patamar, versão, fonte, risco, regra ou prova. Preservar backlink para o índice.

## Escopo de Implementacao

Este arquivo só guarda o detalhe operacional extraído do runbook maior.

## Dependencias

Depende do índice `atlas-dev-efficient-programming-flow-runbook-v1` e da documentação canônica relacionada.

## Evidencias

A evidência de origem é o runbook principal e o `docs-health` verde depois da divisão.

## Riscos

Risco principal: alguém editar este recorte como se fosse novo dono de fluxo, duplicando contrato.

## Exemplos

Os exemplos abaixo são o conteúdo operacional extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar este recorte quando a fatia correspondente do Atlas Dev mudar e rodar docs-health.

## Conteudo Extraido
### 15.1.10 Checklist de release

- [ ] APP_KEY base64 ≥32 bytes confirmada.
- [ ] Migrations `atlas_dev_confirmation_tokens` e `atlas_dev_run_index` aplicadas.
- [ ] `storage/atlas-dev/receipts/` gravável.
- [ ] Flags `plan_enabled` ligada e validada antes de `run_enabled`.
- [ ] `php artisan atlas:dev:readiness --json` retorna `status=passed` no ambiente com `ATLAS_DEV_EFFICIENT_PLAN_ENABLED=true`, `ATLAS_DEV_EFFICIENT_RUN_ENABLED=true`, `ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED=true` e `ATLAS_DEV_RUN_DISPATCH_MODE=process`; o gate cobre flags, APP_KEY, storage, rotas, worker process e provider runtime (`ClaudeCliGateway` + binário `claude` executável).
- [ ] Smoke: Plan retorna `confirmation.token` (fast_path), `persisted_artifact_refs` relativos, sem `/Users/` na response.
- [ ] Show retorna `workspace_label`/`workspace_hash` e `persisted_artifact_refs`; sem path absoluto.
- [ ] Run rejeita corretamente truthy-string, hash mismatch, token reutilizado.
- [ ] Stream emite `stream_closed` final; cliente cai em REST se a conexão for cortada.

### 15.1.11 Tokens HTTP do operador (`ATLAS_TOKEN` / `X-Atlas-Token`)

Plan/Run/Stream/Show vivem no grupo de rotas autenticado por `X-Atlas-Token`
(middleware `atlas.token`). Operador precisa garantir os dois lados:

- Backend (`atlas-server`): `ATLAS_TOKEN` no `.env` (ou `config('atlas.token')`)
  com pelo menos um valor não vazio que o middleware aceita. Mesma chave é
  usada por todas as 4 rotas Atlas Dev.
- Desktop / surfaces consumindo HTTP: variável de ambiente Vite
  `VITE_ATLAS_TOKEN` deve casar com o `ATLAS_TOKEN` do backend. Sem isso
  Plan responde `401` no Desktop e qualquer cliente.
- Rotação: trocar `ATLAS_TOKEN` invalida sessões existentes. Refletir nos
  clients antes do swap.
- Em logs/telemetria/Sentry: nunca emitir `confirmation.token` (plaintext)
  nem `X-Atlas-Token`. Redator existente já filtra `confirmation_token`; é
  responsabilidade do operador não logar headers brutos.

### 15.1.12 Perfis de Projeto (`config/atlas_projects.php`)

Slug de Projeto é a única forma que a surface Desktop tem de apontar
workspace; resto dos surfaces pode mandar path absoluto direto. Garantir:

- `config/atlas_projects.php` tem `profiles[]` com `slug` + `workspace_path`
  apontando para path absoluto que **existe** no host.
- `ATLAS_CODE_DEFAULT_PROJECT` (env) define o slug fallback quando o payload
  Desktop não trouxer explicitamente.
- Adicionar Projeto novo:
  1. Editar `config/atlas_projects.php` (id, slug, name, kind,
     workspace_path, repo_root, production_status, stack_summary, commands,
     test_commands, build_commands, dev_server_command, critical_areas,
     docs_status, default_risk, deployment_notes).
  2. Reiniciar workers (queue/SSE) para reler config cacheada.
  3. Validar com `php artisan tinker -> config('atlas_projects.profiles')`.
- Slug com `workspace_path` inexistente: Plan retorna 422 antes de mintar
  token (sem efeito colateral; nenhum receipt criado).

### 15.1.13 Comandos de validação operacional

Backend (executar em `atlas-server/`):

```bash
# Suíte canônica que cobre core + endpoints + adapters
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Programming/AtlasDev tests/Feature/Ai/Programming/AtlasDev

# CLI legado + workflow (compat com Atlas CLI Dev)
/opt/homebrew/bin/php artisan test tests/Feature/AtlasCliDevCommandTest.php tests/Unit/AtlasCliDevWorkflowServiceTest.php

# Health de docs canônicos
/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json

# Readiness Desktop-ready (zero provider; falha se flag, APP_KEY, storage, rota, worker ou provider runtime estiver indisponível)
#
# Caminho canônico local: escreve as flags necessárias no .env com backup
# automático e roda readiness em seguida. Use --dry-run para ver o patch sem
# escrever.
/opt/homebrew/bin/php artisan atlas:dev:desktop:enable --dry-run --json
/opt/homebrew/bin/php artisan atlas:dev:desktop:enable --json

# Caminho manual/CI equivalente, sem editar .env:
ATLAS_DEV_EFFICIENT_PLAN_ENABLED=true \
ATLAS_DEV_EFFICIENT_RUN_ENABLED=true \
ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED=true \
ATLAS_DEV_RUN_DISPATCH_MODE=process \
  /opt/homebrew/bin/php artisan atlas:dev:readiness --json

# Smoke público pela CLI (igual ao Desktop usaria; --yes só se quiser executar)
/opt/homebrew/bin/php artisan atlas:cli:dev "corrija teste X" --efficient --json

# Smoke técnico hidden (plan-only, zero provider, output JSON)
/opt/homebrew/bin/php artisan atlas:dev:debug:smoke --intent="..." --workspace=/abs/path --json
```

Desktop (executar em `atlas-desktop/apps/desktop/`):

```bash
pnpm install -r        # se vier limpo
pnpm --filter @atlas/desktop lint
pnpm --filter @atlas/desktop build
pnpm --filter @atlas/desktop test    # quando suite vitest existir
```

Stream / endpoints (sem provider, basta `ATLAS_TOKEN` válido):

```bash
curl -s -X POST http://localhost:8000/ai/interactions/atlas-dev/plan \
  -H "X-Atlas-Token: $ATLAS_TOKEN" -H "Content-Type: application/json" \
  -d '{"surface_id":"atlas_cli_dev","workspace":"/abs/path","raw_intent":"smoke","user_constraints":[]}' | jq

curl -s http://localhost:8000/ai/interactions/atlas-dev/runs/<run_id> \
  -H "X-Atlas-Token: $ATLAS_TOKEN" | jq

curl -N -s http://localhost:8000/ai/interactions/atlas-dev/runs/<run_id>/stream \
  -H "X-Atlas-Token: $ATLAS_TOKEN" -H "Accept: text/event-stream"
```

### 15.1.14 Smoke do provider (consumindo token)

Validar end-to-end Plan → Run em workspace **isolado** (não em produção):

1. `ATLAS_DEV_EFFICIENT_PLAN_ENABLED=true` e
   `ATLAS_DEV_EFFICIENT_RUN_ENABLED=true` no ambiente.
2. Garantir `ClaudeCliGateway` bind real configurado no container (provider
   adapter de produção). Sem isso o `RunController` cai no
   `PipelineRunExecutor` que devolve `completion=blocked` honesto e o smoke
   detecta o gap antes de gastar token.
3. Plan via CLI ou curl, capturar `run_id` + `confirmation.token` +
   `task_contract_hash`.
4. Run:

   ```bash
   curl -s -X POST http://localhost:8000/ai/interactions/atlas-dev/run \
     -H "X-Atlas-Token: $ATLAS_TOKEN" -H "Content-Type: application/json" \
     -d '{"run_id":"<id>","task_contract_hash":"<hash>","confirmation_token":"<plain>","operator_confirmed":true}'
   ```

5. Conferir `verification_receipt` em `storage/atlas-dev/receipts/<run_id>/`
   com `completion.status = passed` (caso happy path) e diff aplicado.
6. Conferir `atlas_dev_run_index` populado por `run_id` para servir Show
   rápido.

Smoke já comprovado em workspace de desenvolvimento; replicar em staging
antes de habilitar `run_enabled` em produção.

### 15.1.14a Follow-ups operacionais ainda nao entregues

Estado real do P1+ apos a fatia atual. Tudo aqui e **follow-up explicito** — nao
prometer como entregue ate o runbook listar a fatia que fecha:

- **Bundle hash publico para o operador.** O Plan ja pina server-side dois
  hashes na linha HMAC-keyed do confirmation_token (`task_contract_hash` e
  `compact_sdd_hash` — coluna adicionada pela migration
  `2026_05_16_020000_add_compact_sdd_hash_to_atlas_dev_confirmation_tokens.php`).
  O `PipelineRunExecutor` valida tres camadas antes de chamar o provider:
  (a) self-hash do `compact_sdd.json`, (b) `compact_sdd_hash` pinado pelo
  `mini_programming_spec.json`, (c) pin server-side no token row. Qualquer
  divergencia rejeita 422 (`COMPACT_SDD_TAMPERED` /
  `TASK_CONTRACT_HASH_MISMATCH`) sem custo de token. O que **ainda nao**
  existe: (i) hash unico do bundle completo `(envelope, compact_sdd,
  mini_spec, task_contract, prompt_projection)` publicado pelo Plan para o
  operador pinar lado a lado, e (ii) pin HMAC para `envelope_hash` e
  `prompt_projection_hash` (so `task_contract_hash` e `compact_sdd_hash`
  estao cobertos). Documentado como gap em
  `atlas-dev-efficient-programming-flow-v1.md` §26.4 ("Follow-ups
  explicitos").
- **Live async stream.** §15.1.6 ja deixa claro: o canal e
  snapshot-replay-then-close + REST poll. Live `phase:` durante a execucao
  real ainda nao existe. Cliente atual trata `stream_closed` como fim
  canonico.
- **Paridade de surface alem de Desktop + CLI.** App / API publica
  consumindo Plan/Run ainda nao tem adapter dedicado. Smokes operacionais
  cobrem somente Desktop (`atlas_desktop_ai`) e CLI (`atlas_cli_dev`).

### 15.1.15 Limitações de QA visual

Atlas Dev fast-path **não** roda Playwright/Browser por dentro do
`PipelineRunExecutor`. Frontend gate visual fica a cargo do operador:

- Sem Playwright/Browser disponível no host: declarar
  `verification_profile = generic_no_test` (ou usar `no_test_reason`
  documentado no `LightTaskContract`). O `verification_gate` aceita esse
  caminho como `no_patch_needed` ou `needs_review`, mas nunca como `passed`
  silencioso.
- Frontend changes que dependem de QA visual sobem para `R3` por default e
  exigem revisão humana antes de `completed`. Atlas Dev pode preparar o
  diff, mas a aprovação visual fica fora do receipt automático.
- Se a equipe tiver Browser/Playwright configurado, anexar comandos no
  `verification_plan.commands` do `LightTaskContract`; o gate só roda o que
  estiver listado.
- Limitação conhecida: SSE atual é snapshot-replay-then-close; não há
  streaming live de execução visual, então clientes Desktop devem consultar
  o REST Show ao final para conferir status.

