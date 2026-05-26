---
id: atlas-memory-core-runbook
type: engineering_knowledge
title: Atlas Memory Core Runbook
status: source_material
category: maintenance
priority: 99
summary: Runbook operacional para validar, sincronizar, auditar e recuperar o sistema de memoria, knowledge base e code intelligence do Atlas.
tags:
  - atlas
  - memory
  - runbook
  - operations
capabilities:
  - memory_registry
  - knowledge_base
  - code_intelligence_index
  - provider_projection
  - context_pack_recall
  - open_brain_context_injection
decisions:
  - Operacoes de memoria devem ser auditaveis, repetiveis e pequenas.
  - Dry-run deve preceder operacoes destrutivas ou de escrita em provider projections.
  - Docs canonicos e code index devem ser sincronizados juntos apos mudancas relevantes.
  - Injecao automatica de Open Brain em fluxos de codigo deve ser validada por CLI, trace e app.
maintenance:
  - Atualize este runbook quando comandos CLI, rotas ou fluxos de validacao mudarem.
  - Prefira /opt/homebrew/bin/php para Artisan neste projeto.
  - Registre validacoes reais no documento mestre depois de cada fase.
related_paths:
  - app/Console/Commands/AtlasMemoryListCommand.php
  - app/Console/Commands/AtlasMemoryAddCommand.php
  - app/Console/Commands/AtlasMemoryPrivacyCommand.php
  - app/Console/Commands/AtlasMemoryProjectionCommand.php
  - app/Console/Commands/AtlasMemoryMaintenanceCommand.php
  - app/Console/Commands/AtlasEngineeringKnowledgeCommand.php
  - app/Services/Ai/AtlasMemoryRegistryService.php
  - app/Services/Ai/AtlasMemoryMaintenanceService.php
  - app/Services/Engineering/EngineeringKnowledgeBaseService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - app/Http/Controllers/AtlasMemoryMaintenanceController.php
  - atlas-app/app/open-brain.tsx
  - docs/engineering-knowledge-base/open-brain-context-injection.md
---

# Atlas Memory Core Runbook

Este runbook e o procedimento operacional padrao para manter o sistema de
memoria do Atlas confiavel. Ele cobre verificacao rapida, sincronizacao,
auditoria, privacy review, provider projections e validacao de app.

## Regras Operacionais

- Use `/opt/homebrew/bin/php` para Artisan.
- Rode dry-run antes de operacoes destrutivas, purge ou projection apply/write.
- Depois de alterar docs canonicos, rode sync de knowledge e index-code.
- Depois de alterar codigo core, rode index-code.
- Depois de alterar memoria/contexto, rode testes focados antes de ampliar.
- Nao use provider files como fonte primaria; regenere a partir do Atlas.

## Health Check Rapido

```bash
/opt/homebrew/bin/php artisan migrate:status
/opt/homebrew/bin/php artisan route:list --path=memory
/opt/homebrew/bin/php artisan route:list --path=open-brain
/opt/homebrew/bin/php artisan route:list --path=engineering/knowledge
/opt/homebrew/bin/php artisan atlas:engineering:knowledge status --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge code-status --json
```

Testes focados:

```bash
/opt/homebrew/bin/php artisan test --filter=AtlasMemoryRegistryTest
/opt/homebrew/bin/php artisan test --filter=AtlasEngineeringKnowledgeBaseTest
```

App:

```bash
npm run typecheck
npm run test:front
```

## Sync De Docs Canonicos

Quando mudar `docs/engineering-knowledge-base`:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge sync --prune
/opt/homebrew/bin/php artisan atlas:engineering:knowledge index-code --prune --workspace=/Users/vitorepf/Develop/atlas/atlas-server
```

Inspecao:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge list --limit=20
/opt/homebrew/bin/php artisan atlas:engineering:knowledge context --category=architecture --limit=8 --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge code-status --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge modules --docs-status=undocumented --json
```

## Code Intelligence

Dry-run sem escrita:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge index-code --dry-run --workspace=/Users/vitorepf/Develop/atlas/atlas-server
```

Indexacao real:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge index-code --prune --workspace=/Users/vitorepf/Develop/atlas/atlas-server
```

Auditoria de simbolos:

```bash
/opt/homebrew/bin/php artisan atlas:engineering:knowledge audit-code --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge symbols --symbol-type=route --limit=50 --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge symbols --symbol-type=cli_command --limit=50 --json
/opt/homebrew/bin/php artisan atlas:engineering:knowledge show-module engineering_harness_services --json
```

Nota: `index-code --dry-run` pode mostrar `doc_link_count=0`, porque doc links
sao recalculados a partir do estado persistido durante a indexacao real.
Use `audit-code` para comparar o scan atual com o indice persistido sem escrever
no banco. `fresh` significa que o indice acompanha o workspace; `drift_detected`
significa que `index-code --prune` deve ser considerado antes de montar context
packs confiaveis.

## Memory Registry

Listar memorias:

```bash
/opt/homebrew/bin/php artisan atlas:memory:list --limit=30
/opt/homebrew/bin/php artisan atlas:memory:list --type=decision --status=active --json
```

Adicionar memoria:

```bash
/opt/homebrew/bin/php artisan atlas:memory:add decision "Decisao resumida" --body="Contexto e motivo" --scope=global --priority=80 --importance=4
```

Auditar uso de memoria em trace:

```bash
/opt/homebrew/bin/php artisan atlas:memory:audit TRACE_ID --json
```

## Privacy E Review Queue

Scan sem escrita:

```bash
/opt/homebrew/bin/php artisan atlas:memory:privacy scan --limit=200 --json
```

Aplicar politica calculada:

```bash
/opt/homebrew/bin/php artisan atlas:memory:privacy apply --limit=200 --json
```

Revisar item especifico:

```bash
/opt/homebrew/bin/php artisan atlas:memory:privacy review MEMORY_ID --privacy=private --reviewed-by=operator --note="Motivo da classificacao"
```

Fila unificada:

```bash
/opt/homebrew/bin/php artisan atlas:memory:review-queue --include-unreviewed --limit=100 --json
```

## Governance E Conflitos

Scan de duplicates/conflitos:

```bash
/opt/homebrew/bin/php artisan atlas:memory:govern scan --dry-run --json
/opt/homebrew/bin/php artisan atlas:memory:relations list --status=pending --json
```

Resolver relacao:

```bash
/opt/homebrew/bin/php artisan atlas:memory:relations resolve RELATION_ID --resolution-action=merge --reviewed-by=operator --note="Resolucao aplicada"
```

## Verbatim Store

Listar:

```bash
/opt/homebrew/bin/php artisan atlas:memory:verbatim list --limit=30
```

Adicionar evidencia exata:

```bash
/opt/homebrew/bin/php artisan atlas:memory:verbatim add "Texto exato aprovado" --type=evidence --privacy=normal --allow-external-ai
```

Bloquear ou redigir:

```bash
/opt/homebrew/bin/php artisan atlas:memory:verbatim block --id=VERBATIM_ID --review-note="Nao provider-safe"
/opt/homebrew/bin/php artisan atlas:memory:verbatim redact --id=VERBATIM_ID --review-note="Redacao manual aplicada"
```

## Provider Projection

Semear memoria core provider-safe quando a projection estiver vazia:

```bash
/opt/homebrew/bin/php artisan atlas:memory:seed-core --json
```

Status:

```bash
/opt/homebrew/bin/php artisan atlas:memory:projection status --target=all --workspace=/Users/vitorepf/Develop/atlas --json
```

O status nao deve ser considerado pronto quando `summary.empty_memory > 0`.
Nesse caso, registre/revise memorias provider-safe ou rode `atlas:memory:seed-core`
antes de aplicar `CLAUDE.md`/`AGENTS.md`.

Review antes de aplicar:

```bash
/opt/homebrew/bin/php artisan atlas:memory:projection review --target=all --workspace=/Users/vitorepf/Develop/atlas --json
```

Aplicar somente depois de review:

```bash
/opt/homebrew/bin/php artisan atlas:memory:projection apply --target=all --workspace=/Users/vitorepf/Develop/atlas --yes --json
```

Auditar projection:

```bash
/opt/homebrew/bin/php artisan atlas:memory:projection audit-summary --json
```

Purge exige dry-run/fingerprint no backend e pode exigir operador conforme
configuracao:

```bash
/opt/homebrew/bin/php artisan atlas:memory:projection audit-purge --older-than-days=30 --dry-run --json
```

## Recall Hibrido E Open Brain

Recall provider-safe entre Memory Registry, Verbatim Store e notas semanticas:

```bash
/opt/homebrew/bin/php artisan atlas:memory:recall "contexto para continuar a implementacao de memoria" --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
```

Exportar um Context Pack auditado para ferramentas locais:

```bash
/opt/homebrew/bin/php artisan atlas:open-brain:context "continuar implementacao de memoria" --workspace=/Users/vitorepf/Develop/atlas/atlas-server --include-prompt --json
```

Servir Open Brain para Claude/Codex via MCP local:

```bash
./bin/atlas open-brain mcp --describe --json
./bin/atlas open-brain mcp
```

Request de smoke MCP:

```bash
./bin/atlas open-brain mcp --once='{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

Endpoint HTTP JSON-RPC autenticado:

```bash
curl -s \
  -H "X-Atlas-Token: $ATLAS_TOKEN" \
  -H "Accept: application/json, text/event-stream" \
  -H "Content-Type: application/json" \
  -H "MCP-Protocol-Version: 2025-06-18" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
  http://127.0.0.1:8000/ai/open-brain/mcp
```

Via API:

```bash
POST /ai/memory/recall
POST /ai/open-brain/context-pack
GET  /ai/open-brain/audits
POST /ai/memory/maintain
GET  /ai/open-brain/mcp
POST /ai/open-brain/mcp
```

No app:

- abra `Home > Atlas Open Brain > abrir`;
- use `Buscar memoria` para recall provider-safe por pergunta;
- use `Gerar context pack` para ver e copiar o payload que iria para Claude/Codex;
- use `Auditorias` para recarregar exports Open Brain;
- use `Rodar maintain` para sync docs, index-code, projection status e health MCP;
- `Aplicar projection` exige segundo toque e chama o backend com confirmacao explicita.

Regras:

- o recall e `provider_safe_only`;
- o MCP local e o endpoint HTTP JSON-RPC sao read-only nesta fase;
- HTTP exige `X-Atlas-Token` e valida `Origin` quando o header existe;
- HTTP aceita requests JSON-RPC por `POST`; `GET` com `Accept: text/event-stream`
  retorna `405` ate existir Streamable HTTP/SSE completo;
- tools MCP disponiveis: `atlas_memory_recall`, `atlas_open_brain_context_pack`,
  `atlas_memory_maintenance_status`;
- `ATLAS_SEMANTIC_EMBEDDING_PROVIDER` fica em `local_hash` por padrao;
- provider externo de embedding exige opt-in explicito e respeita privacy policy;
- todo export Open Brain grava auditoria quando `atlas_open_brain_access_logs` existe;
- ChromaDB, Streamable HTTP completo com SSE/sessoes persistentes, tools MCP
  destrutivas e sync multiusuario continuam fora desta entrega.

## Open Brain Context Injection

Status: implementado em `open-brain-context-injection.md` para backend, CLI e
Atlas AI App runtime.

Fluxo operacional:

```bash
./bin/atlas chat --mode=dev "implemente a feature X" --json
./bin/atlas chat --mode=review "revise a alteracao X" --json
./bin/atlas dev --task-id=<task-id> --plan-only --json
./bin/atlas continue --json
```

Em `atlas chat`, o resultado deve expor `open_brain_injection.status`,
`context_pack_hash`, `audit_id`, contagem de refs e warnings. Em
`atlas dev --plan-only`, o resultado deve expor `open_brain_preview` com
`status`, `context_ready`, `provider_execution_allowed`, `context_pack_hash`,
`audit_id`, contagem de refs e warnings. Nenhum desses campos deve conter
`prompt_section` nem `context_refs` brutos em metadata persistida ou JSON
compacto de CLI. Para uma conversa comum:

```bash
./bin/atlas chat --mode=direct "resuma meu dia" --json
```

o status esperado e `skipped` ou ausencia de injecao automatica, salvo opt-in
explicito.

Validacao minima:

```bash
/opt/homebrew/bin/php artisan test --filter=open_brain_context_injection
/opt/homebrew/bin/php artisan test --filter=AtlasOpenBrainContextInjectionServiceTest
/opt/homebrew/bin/php artisan test --filter=AiSessionManagerTest
/opt/homebrew/bin/php artisan test --filter=AtlasCliDevWorkflowServiceTest
/opt/homebrew/bin/php artisan test --filter='AtlasPhpBinaryTest|AtlasTestCommandResolverTest|AtlasCliDevCommandTest|AtlasCliContinueCommandTest'
npm run typecheck
git diff --check
./bin/atlas memory maintain --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
```

Regras operacionais:

- `atlas dev`, `atlas continue` e `atlas chat --mode=dev|debug|review` devem
  usar Open Brain automaticamente por padrao;
- app em `programming`, `review` ou `debug` deve depender do backend para
  injecao, nao de prompt manual no front;
- `--no-open-brain` e payload `open_brain.mode=off` devem existir para opt-out;
- `--require-open-brain` e payload `open_brain.mode=required` devem falhar
  fechado quando o contexto nao puder ser montado;
- toda injecao deve ser provider-safe e auditar `context_pack_hash`;
- toda injecao de dev/debug/review deve incluir `summary.memory_quality` e o
  bloco `Memory Quality Gate`, salvo quando
  `ATLAS_OPEN_BRAIN_INJECTION_INCLUDE_MEMORY_QUALITY=false`.
- comandos internos Artisan devem usar `App\Support\AtlasPhpBinary`; no Mac de
  desenvolvimento o caminho esperado e `/opt/homebrew/bin/php`, independente do
  PHP ativo no prompt.

## Rotina De Manutencao Sem Memoria Solta

Para nao depender de lembrar comandos manualmente, use esta ordem quando alterar
docs, codigo core, memoria ou context packs. O caminho preferido e o comando
unico:

```bash
./bin/atlas memory maintain --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
```

O mesmo fluxo existe por API para o app:

```bash
POST /ai/memory/maintain
```

Quando a projection precisar ser atualizada e a review estiver aceitavel:

```bash
./bin/atlas memory maintain --workspace=/Users/vitorepf/Develop/atlas/atlas-server --apply-projection --yes --json
```

O comando executa a rotina local nesta ordem:

```bash
./bin/atlas engineering knowledge sync --prune --json
./bin/atlas engineering knowledge index-code --prune --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
./bin/atlas memory quality --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
./bin/atlas memory projection status --target=all --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
./bin/atlas open-brain mcp --once='{"jsonrpc":"2.0","id":10,"method":"tools/call","params":{"name":"atlas_memory_maintenance_status","arguments":{"workspace":"/Users/vitorepf/Develop/atlas/atlas-server"}}}'
```

Antes do projection status, `atlas memory maintain` tambem roda uma etapa
interna `learning_promotion`. Por padrao ela promove automaticamente apenas
`ai_memory_deltas` ja revisados como `accepted`. Deltas `pending` sem
confirmacao humana so sao promovidos com `--auto-promote-candidates` e
`--promotion-min-confidence`, mantendo memoria canonica conservadora. Em
dry-run, `would_promote` lista somente o que a configuracao atual realmente
promoveria.

O scorecard `memory_quality` e read-only e mede prontidao, provider-safety,
governance, frescor, feedback e completude. Quando `workspace` e informado,
relacoes e feedback tambem sao calculados a partir das memorias ativas daquele
contexto, evitando que problemas de outro workspace contaminem o readiness
local. Ele aparece em:

```bash
./bin/atlas memory quality --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
./bin/atlas memory quality snapshot --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
./bin/atlas memory quality history --workspace=/Users/vitorepf/Develop/atlas/atlas-server --days=30 --json
./bin/atlas memory maintain --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
GET /ai/memory/quality
GET /ai/memory/quality/history
POST /ai/memory/quality/snapshots
POST /ai/memory/maintain
```

Quando a tabela `atlas_memory_quality_snapshots` existe, `atlas memory maintain`
registra um snapshot persistente por padrao. Use `--no-quality-snapshot` apenas
quando precisar de manutencao sem historico, por exemplo em smoke local
descartavel. Historico deve ser usado para detectar regressao de score,
acumulo de issues e melhora apos governance/privacy/review.

O scorecard tambem calcula `trend` usando snapshots recentes. Estados
`improved` e `stable` indicam que a memoria esta mantendo ou elevando qualidade;
`watch_regressed` pede revisao leve; `regressed` exige inspecionar historico,
issues e mudancas recentes antes de confiar cegamente no recall. O comando
principal para diagnostico e:

```bash
./bin/atlas memory quality history --workspace=/Users/vitorepf/Develop/atlas/atlas-server --days=30 --json
```

Quando `trend.drivers` existir, trate os primeiros itens como fila de
diagnostico. Exemplos:

- `component_drop:provider_safety` aponta queda no score de provider-safety;
- `count_increase:negative_feedback` aponta piora por feedback real;
- `count_increase:accepted_learning_backlog` aponta deltas aceitos ainda nao
  promovidos;
- `issue_increase:no_provider_safe_memory` aponta recall inseguro;
- `score_drop:score` e fallback quando a queda e real, mas sem driver
  estrutural claro.

O Open Brain automatico consome esse scorecard no runtime. Em modo `auto`, uma
memoria `watch`/`needs_review` segue para o provider com warnings; em modo
`required`, estados `critical`, `empty` ou `not_migrated` falham fechado para
evitar finalizar codigo com recall inseguro.
Regressoes de tendencia adicionam `memory_quality_trend_regressed` ou
`memory_quality_trend_watch_regressed` aos warnings e degradam o resultado em
modo `auto`; elas nao bloqueiam sozinhas o modo `required`, porque a decisao de
falhar fechado continua baseada na qualidade atual da memoria.

A tela `Atlas Open Brain` no app consome os mesmos endpoints e deve ser usada
quando voce quiser verificar score, tendencia, drivers e snapshots sem abrir
terminal.

Se `projection status` retornar `needs_review`, rode review antes do apply:

```bash
./bin/atlas memory projection review --target=all --workspace=/Users/vitorepf/Develop/atlas/atlas-server --json
./bin/atlas memory projection apply --target=all --workspace=/Users/vitorepf/Develop/atlas/atlas-server --yes --json
```

## Validacao Antes De Encerrar Uma Fase

Backend:

```bash
/opt/homebrew/bin/php artisan test --filter=AtlasMemoryRegistryTest
/opt/homebrew/bin/php artisan test --filter=AtlasEngineeringKnowledgeBaseTest
/opt/homebrew/bin/php artisan route:list --path=memory
/opt/homebrew/bin/php artisan route:list --path=engineering/knowledge
```

Frontend:

```bash
npm run typecheck
npm run test:front
```

Documentacao:

```bash
rg -n "[ \t]+$" Atlas_AI_Memory_Context_Core_Open_Brain.md atlas-server/docs/engineering-knowledge-base
```
