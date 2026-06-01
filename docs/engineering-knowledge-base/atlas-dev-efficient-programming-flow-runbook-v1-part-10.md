---
id: atlas-dev-efficient-programming-flow-runbook-v1-part-10
type: engineering_knowledge
title: Atlas Dev Efficient Programming Flow Runbook v1 · Parte 10
status: active
category: programming
priority: 104
summary: Recorte focado do runbook Atlas Dev Efficient Programming Flow v1: 15.1.16 Diagnóstico operacional — receitas curtas.
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
graph_id: atlas-dev-efficient-programming-flow-runbook-v1-part-10
graph_title: Atlas Dev Efficient Programming Flow Runbook v1 Parte 10
graph_world: atlas
graph_layer: module
graph_kind: runbook
graph_parent: atlas-dev-efficient-programming-flow-runbook-v1
graph_status: active
graph_source: repo
human_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 10
canonical_name: Atlas Dev Efficient Programming Flow Runbook v1 Parte 10
technical_name: atlas-dev-efficient-programming-flow-runbook-v1-part-10
cartography_type: runbook
canonical_source: docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-10.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-10.md
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
evidence_refs:
  - symbol: AtlasDevDesktopEfficiencyEvidenceService
  - command: atlas:dev:desktop:efficiency-evidence
  - test: AtlasDevDesktopEfficiencyEvidenceServiceTest
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
next_actions:
  - Manter este recorte alinhado ao índice canônico e ao runbook operacional.
---
# Atlas Dev Efficient Programming Flow Runbook v1 · Parte 10

## Resumo

Este recorte preserva uma parte operacional do runbook Atlas Dev Efficient Programming Flow v1: 15.1.16 Diagnóstico operacional — receitas curtas.

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
### 15.1.16 Diagnóstico operacional — receitas curtas

#### A. Diagnosticar `ATLAS_DEV_KEY_MISSING` (500)

Resposta canônica do Plan/Run quando o HMAC do confirmation token não tem
chave forte o bastante. Sequência de diagnóstico:

```bash
# 1. Reproduzir o erro deterministicamente (sem chamar provider).
/opt/homebrew/bin/php artisan tinker --execute='echo strlen(base64_decode(str_replace("base64:", "", config("app.key"))));'
# Esperado: número ≥ 32. Se < 32 (ou erro de decode), APP_KEY está fraca.

# 2. Conferir env real carregado no processo PHP.
/opt/homebrew/bin/php artisan tinker --execute='echo (config("app.key") === "" ? "EMPTY" : substr(config("app.key"), 0, 8) . "...");'
# Esperado: começa em "base64:". "EMPTY" = APP_KEY ausente no .env / variável de ambiente.

# 3. Rotacionar (idempotente; gera chave nova base64:<256bits>).
/opt/homebrew/bin/php artisan key:generate --show
# Copiar para .env como APP_KEY=base64:... e reiniciar workers (queue/SSE) para
# ler config cacheada — em produção, `php artisan config:cache` antes do restart.

# 4. Re-validar smoke imediatamente após restart.
/opt/homebrew/bin/php artisan atlas:dev:debug:smoke --intent="diag" --workspace=/tmp/empty --json
# Esperado: JSON com routing.kind preenchido, sem error.code ATLAS_DEV_KEY_MISSING.
```

Não há fallback público nem "default key" — é fail-closed propositalmente
(§15.1.4 / contracts §3.5.1 invariante 2). Não comentar o cheque para
"desbloquear" o ambiente.

#### B. Sequência canônica de smoke pré-release

Ordem mínima antes de habilitar `run_enabled=true` em produção/staging:

```bash
# 1. Suite canônica (core + endpoints + adapters).
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Programming/AtlasDev tests/Feature/Ai/Programming/AtlasDev
# Esperado: 100% passed, zero warnings de schema mismatch.

# 2. Compat com CLI legada.
/opt/homebrew/bin/php artisan test tests/Feature/AtlasCliDevCommandTest.php tests/Unit/AtlasCliDevWorkflowServiceTest.php

# 3. Health dos docs canônicos.
/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json
# Esperado: status=ok, violations=[], required_missing_count=0.

# 4. Smoke público plan-only (zero-provider, com flag Plan já habilitada).
ATLAS_DEV_EFFICIENT_PLAN_ENABLED=true /opt/homebrew/bin/php artisan atlas:cli:dev "corrija typo X" --efficient --json
# Esperado: routing_decision presente, persisted_artifact_refs relativos, sem `/Users/` no body.

# 5. Smoke técnico isolado (hidden, plan-only).
/opt/homebrew/bin/php artisan atlas:dev:debug:smoke --intent="smoke" --workspace="$(pwd)" --json
# Esperado: completion_state ausente (plan-only), confirmation.token presente quando routing=fast_path.

# 6. Smoke HTTP fim-a-fim (Plan + Run + Show + Stream) com fakes determinísticos.
/opt/homebrew/bin/php artisan test tests/Feature/Ai/Programming/AtlasDev/Http/PipelineRunExecutorHttpSmokeTest.php
# Esperado: PASS, completion_state=passed, scope_guard=passed.
```

Só promover `run_enabled=true` depois do passo 6 verde **e** §15.1.14
executado em staging com workspace real.

#### C. Como verificar visual Desktop (Atlas AI surface)

Atlas Dev é um fluxo interno; a verificação visual happens na surface
Atlas AI Desktop Mac. Sequência mínima:

```bash
# 1. Garantir runtime local habilitado (default false para Run/Desktop).
cd ../../atlas-server
php artisan atlas:dev:desktop:enable --json

# 2. Validar contract.ts do Desktop bate com o response real do Plan/Run.
cd ../atlas-desktop
pnpm install -r
pnpm --filter @atlas/desktop lint
pnpm --filter @atlas/desktop build
# Erros em `runtime policy.atlas_dev_efficient` sinalizam contract drift.

# 3. Rodar o app em dev mode + apontar para o backend local.
VITE_ATLAS_API_BASE=http://localhost:8000 \
VITE_ATLAS_TOKEN=$ATLAS_TOKEN \
pnpm --filter @atlas/desktop dev
```

Checklist visual no Atlas AI (aba Workspace Dev):

- Composer mostra raw_intent + workspace selecionado (slug do Projeto).
- Após `Plan`, painel "Plano" lista CompactSDD + MiniSpec + TaskContract
  sem strings absolutas (`/Users/...`); só `workspace_label` (basename) +
  hashes parciais.
- Botão `Run` só habilita quando `routing_decision = atlas_dev_fast_path`
  E há `confirmation.token` no payload do Plan.
- Durante Run: stream entrega `phase:` e `receipt:` em ordem
  determinística, depois fecha imediatamente (`stream_closed`). Não há
  spinner indefinido — se ficar carregando >2s, o cliente caiu no fallback
  REST `GET /runs/{run_id}` (esperado e correto).
- Receipt renderizado mostra `persisted_receipt_refs` como
  `receipts/<run_id>/<file>`, `workspace_label`, `workspace_hash`. Se
  aparecer `/Users/` em qualquer campo: bug F-04 (path leak), abrir issue.
- `completion.status` exibido literalmente (`passed | needs_review |
  failed | blocked | escalate_forge | no_patch_needed`). Surface não
  reinterpretar.

Sem Playwright/Browser configurado, esses passos são manuais. O fluxo
Atlas Dev não substitui QA visual — apenas garante que o receipt textual
seja honesto (§15.1.15).

#### D. Como fechar a evidência comparativa 10x

O goal de produto só pode ser declarado completo quando houver evidência
observada de que Atlas Dev Desktop entrega pelo menos 10x de eficiência contra
os engines crus exigidos pelo operador (`claude_code` e `codex`). Cert local,
suite verde e smoke real com provider **não** provam esse requisito sozinhos.

O caminho canônico é:

```bash
cd atlas-server

# 1. Gerar template operator-fillable com os 5 task_kinds obrigatórios.
php artisan atlas:dev:desktop:efficiency-evidence \
  --write-template=storage/atlas-dev/receipts/desktop_efficiency/cases.json \
  --json --strict

# Esse comando também cria 15 source evidence templates em:
# storage/atlas-dev/receipts/desktop_efficiency/source/<case>/<participant>.json
# É seguro reexecutar: source evidence já existente é preservado e reportado
# em source_template_preserved_refs.

# 2. Executar os mesmos 5 casos em Atlas Dev Desktop, Claude Code cru e Codex cru.
#    Cobertura obrigatória: patch, repair, review, frontend, question.
#    Cada caso tem `task_prompt`; todos os participantes do mesmo case_id
#    precisam executar exatamente esse prompt.
#    Para cada participante, registrar:
#      - status=passed
#      - verification_passed=true
#      - task_prompt exato observado (mesmo texto para atlas/claude_code/codex)
#      - elapsed_seconds observado
#      - manual_steps observado
#      - provider_calls observado
#      - run_ref relativo para transcript/receipt/log bruto existente
#      - evidence_refs relativos existentes em storage/atlas-dev/receipts/
#    Métricas sem run_ref são "números soltos" e não provam 10x.
#    O run_ref deve apontar para um artefato bruto diferente do próprio source
#    evidence, por exemplo:
#      storage/atlas-dev/receipts/desktop_efficiency/raw/patch_case/atlas.json
#    Preferir registrar cada slot em duas etapas por comando, sem editar JSON
#    manualmente. Primeiro grave o artefato bruto observado:
php artisan atlas:dev:desktop:efficiency-evidence \
  --write-run-ref=desktop_efficiency/raw/patch_case/atlas.json \
  --case-id=patch_case \
  --task-kind=patch \
  --participant=atlas \
  --raw-summary="Atlas Dev completed patch_case with receipt <run_id> and focused verification passed." \
  --raw-verification-command="php artisan test <focused-test>" \
  --verification-passed \
  --json --strict

#    Depois grave o source evidence que aponta para esse raw run:
php artisan atlas:dev:desktop:efficiency-evidence \
  --write-source=desktop_efficiency/source/patch_case/atlas.json \
  --cases=storage/atlas-dev/receipts/desktop_efficiency/cases.json \
  --case-id=patch_case \
  --task-kind=patch \
  --participant=atlas \
  --task-prompt="Apply a narrow code patch in the workspace and verify the changed behavior with the focused test named in the task." \
  --elapsed-seconds=21 \
  --manual-steps=0 \
  --provider-calls=1 \
  --verification-passed \
  --run-ref=desktop_efficiency/raw/patch_case/atlas.json \
  --notes="Observed Atlas Dev Desktop run for patch_case" \
  --json --strict

#    Repetir para todos os 15 pares:
#      5 casos x (atlas, claude_code, codex)
#    Para ver o checklist atual, o próximo slot faltante e o comando pronto:
php artisan atlas:dev:desktop:efficiency-evidence \
  --source-status \
  --cases=storage/atlas-dev/receipts/desktop_efficiency/cases.json \
  --json --strict

#    Para enviar coletores em paralelo, emitir apenas comandos pendentes:
php artisan atlas:dev:desktop:efficiency-evidence \
  --source-commands \
  --cases=storage/atlas-dev/receipts/desktop_efficiency/cases.json \
  --json --strict

#    O gate rejeita source evidence com:
#      - ref absoluto, URL ou `..`;
#      - case_id/task_kind/participant diferente do cases.json;
#      - task_prompt ausente;
#      - task_prompt_sha256 divergente do task_prompt do cases.json;
#      - observed_at ausente ou inválido;
#      - status diferente de passed;
#      - verification_passed diferente de true;
#      - elapsed_seconds/manual_steps/provider_calls divergentes do cases.json.
#      - run_ref ausente (`run_ref_missing`);
#      - run_ref absoluto, URL ou com `..` (`run_ref_invalid`);
#      - run_ref apontando para arquivo inexistente (`run_ref_not_found`);
#      - run_ref apontando para o proprio source file (`run_ref_self_reference`).
#      - raw run JSON com case/task/participant/status/verification divergente
#        do source (`run_ref_invalid_payload`).
#    Se --task-prompt-sha256 for omitido, o comando deriva o hash de --cases
#    usando o case_id informado. Preferir esse caminho.

#    Alternativa preferida depois de preencher os 15 source files:
#    reconstruir cases.json a partir dos sources, reduzindo divergência manual.
php artisan atlas:dev:desktop:efficiency-evidence \
  --build-cases-from-sources=storage/atlas-dev/receipts/desktop_efficiency/cases.json \
  --json --strict

# 3. Calcular e persistir a evidência canônica.
php artisan atlas:dev:desktop:efficiency-evidence \
  --input=storage/atlas-dev/receipts/desktop_efficiency/cases.json \
  --persist --json --strict

# 4. Rodar o audit final do goal.
ATLAS_DEV_EFFICIENT_PLAN_ENABLED=true \
ATLAS_DEV_EFFICIENT_RUN_ENABLED=true \
ATLAS_DEV_EFFICIENT_DESKTOP_ENABLED=true \
ATLAS_DEV_RUN_DISPATCH_MODE=process \
php artisan atlas:dev:desktop:goal-audit --json --strict
```

Contrato de aceitação:

- `desktop_efficiency/latest.json` precisa ter
  `schema_version = atlas.dev.desktop_efficiency_evidence.v1`.
- `status` precisa ser `passed`.
- `measured_multiplier` precisa ser `>= 10.0`.
- `compared_against` precisa incluir `claude_code` e `codex`.
- `case_count` precisa ser `>= 5`.
- `measurement_mode` precisa ser `observed_operator_runs`.
- `blocking_findings` precisa ser `[]`.
- Cada `evidence_refs[]` precisa ser relativo, existir sob
  `storage/atlas-dev/receipts/`, e não pode conter path absoluto, URL ou `..`.
- Cada source evidence referenciado precisa carregar `run_ref` relativo para
  artefato bruto existente sob `storage/atlas-dev/receipts/`. O `run_ref` não
  pode ser absoluto, URL, conter `..`, apontar para arquivo inexistente, nem ser
  self-reference para o próprio `desktop_efficiency/source/...json`.
- Cada `run_ref` precisa apontar para JSON canônico
  `atlas.dev.desktop_efficiency_raw_run.v1`, com `status=passed`,
  `verification_passed=true`, `case_id`, `task_kind` e `participant` iguais ao
  source correspondente, além de `summary`, `verification_command` e
  `captured_at` preenchidos.

Fórmula usada pelo gate:

```text
effort_seconds = elapsed_seconds + manual_steps * 300 + provider_calls * 30
measured_multiplier = min(sum(engine_effort_seconds) / sum(atlas_effort_seconds))
```

Se o audit final bloquear apenas em `comparative_efficiency_10x_proof`, o
runtime Desktop está operacional, mas a alegação "10x melhor" ainda está
não provada. Não marcar o goal como completo nessa condição.

