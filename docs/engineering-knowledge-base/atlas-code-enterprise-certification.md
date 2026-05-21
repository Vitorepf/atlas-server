---
id: atlas-code-enterprise-certification
type: engineering_knowledge
title: Atlas Code Enterprise Certification
status: active
category: surface
priority: 100
summary: Certificacao executavel do produto Atlas Code SCOR-1 enterprise pesado, cobrindo Obra, WorkItem, Spec/Plan, Forge Live, history replay, human review, promotion, rollback, checkpoint e state read-model sem provider externo.
tags:
  - atlas-code
  - forge
  - enterprise-certification
  - programming
capabilities:
  - atlas_code_enterprise_certification
  - forge_governed_execution
  - forge_workspace_promotion
  - forge_workspace_rollback
decisions:
  - Atlas Code enterprise nao e certificado por uma tela bonita; precisa de comando replayable.
  - A certificacao cria Obra e workspace temporarios, executa endpoints reais e limpa o workspace.
  - Provider externo fica fora; `external_provider_call=false` e invariante.
  - O comando nao substitui Rivals externo pago/autorizado.
maintenance:
  - Atualizar quando mudar o ciclo Atlas Code -> Obra -> Forge -> Review -> Rollback -> Checkpoint.
related_paths:
  - app/Services/Ai/Programming/AtlasCodeEnterpriseCertificationService.php
  - app/Console/Commands/AtlasCodeEnterpriseCertifyCommand.php
  - tests/Feature/AtlasCodeContractTest.php
  - docs/engineering-knowledge-base/atlas-code-forge-live-execution-surface-contract.md
  - docs/engineering-knowledge-base/atlas-programming-forge-flow.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-code-enterprise-certification
graph_title: Atlas Code Enterprise Certification
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-code-forge-live-execution-surface-contract
graph_status: active
graph_source: repo
human_name: Atlas Code Enterprise Certification
canonical_name: Atlas Code Enterprise Certification
technical_name: atlas-code-enterprise-certification
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-code-enterprise-certification.md
owner: atlas-ai
repo_paths:
  - app/Services/Ai/Programming/AtlasCodeEnterpriseCertificationService.php
  - app/Console/Commands/AtlasCodeEnterpriseCertifyCommand.php
  - tests/Feature/AtlasCodeContractTest.php
allowed_changes:
  - Adicionar stages quando o produto Atlas Code ganhar novas garantias enterprise.
forbidden_changes:
  - Marcar passed sem executar endpoints reais.
  - Chamar provider externo dentro desta certificacao.
  - Omitir rollback ou checkpoint do fluxo certificado.
depends_on:
  - atlas-code-forge-live-execution-surface-contract
  - atlas-programming-forge-flow
flows_to:
  - atlas-code
  - programming.forge
unlocks:
  - atlas-code-enterprise-product-proof
governs:
  - atlas:code:enterprise-certify
evidence:
  - app/Services/Ai/Programming/AtlasCodeEnterpriseCertificationService.php
  - app/Console/Commands/AtlasCodeEnterpriseCertifyCommand.php
  - tests/Feature/AtlasCodeContractTest.php
required_tests:
  - "php artisan atlas:code:enterprise-certify --json --strict"
  - "php artisan test --filter=test_atlas_code_enterprise_certification_proves_full_product_loop"
requires_evidence: true
risk_level: high
next_actions:
  - Manter alinhado ao surface contract sempre que Atlas Code ganhar stage novo.
---
# Atlas Code Enterprise Certification

## Resumo

Contrato da certificacao executavel do produto Atlas Code SCOR-1 enterprise.

## Papel no Atlas

`atlas:code:enterprise-certify` e a prova executavel do produto Atlas Code
SCOR-1 enterprise pesado. A mesma prova tambem fica exposta como acao de
produto em `POST /atlas-code/certification` e no painel Evidence da surface
Atlas Code. Ela nao mede UX subjetiva; prova que o circuito de programacao
pesada funciona de ponta a ponta sem provider externo.

## Onde Se Encaixa

Fica acima de `atlas:forge:runtime-certify` e `atlas:forge:live-execute`.
Forge runtime prova o motor; esta certificacao prova o produto Atlas Code:
Obra, WorkItem, Spec/Plan, Forge Live, replay, review, promotion, rollback,
checkpoint e state read-model.

## Contratos

O schema do relatorio e `atlas.code.enterprise_certification.v1`.

Status possiveis:

- `passed` — todos os stages passaram, sem blockers e sem provider externo.
- `blocked` — algum stage falhou ou uma precondicao real nao existe.

Invariantes:

- `requires_obra=true`.
- `external_provider_call=false`.
- O report fica persistido em `latest_atlas_code_enterprise_certification`
  na Obra efemera de certificacao e reaparece em
  `/atlas-code/works/{obra}/state.atlas_code_enterprise_certification`.
- Obras efemeras de certificacao usam `metadata.origin=atlas-code-enterprise-certification`
  e ficam ocultas no index normal `/atlas-code/works`, para nao poluir a lista
  operacional de Obras do usuario.
- WorkItem binding deve apontar para `programming.forge`.
- Forge governado deve usar `governed_shadow_patch`.
- O workspace vivo nao pode mudar antes do review humano.
- History replay deve ser read-only.
- Promotion deve exigir review aprovado.
- Rollback deve restaurar o hash inicial.
- Checkpoint deve persistir um artefato de retomada.
- A surface deve renderizar o pacote em `Enterprise Certification`, sem
  inventar stages ou status quando o endpoint ainda nao foi rodado.

## Fluxo

1. `preflight` — tabelas essenciais existem.
2. `obra_workspace_fixture` — Obra e workspace temporarios foram criados.
3. `work_item_binding` — WorkItem real foi vinculado a Obra.
4. `spec_plan_task_queue` — Spec, Plan e Tasks reais foram persistidos.
5. `forge_live_execution_governed` — Forge Live executou com task contract.
6. `state_history_after_run` — history e task queue apareceram no state.
7. `history_replay_read_only` — replay historico nao reexecutou nada.
8. `human_review_promotion` — review aprovou e promoveu patch governado.
9. `governed_rollback` — rollback restaurou o workspace.
10. `checkpoint_resume` — checkpoint de retomada foi criado.
11. `final_state_read_model` — state final refletiu rollback/evidence.
12. `workspace_cleanup` — workspace temporario foi removido, salvo `--keep-workspace`.

## Regras para IA

- Nunca chamar provider externo nesta certificacao.
- Nunca marcar `passed` sem executar endpoints/controllers reais.
- Tratar qualquer blocker como falha do produto ou precondicao ausente.
- Manter Rivals externo separado: ele exige autorizacao/custo de operador.

## Escopo de Implementacao

Dentro: service, command, teste de contrato, Obra temporaria, WorkItem,
Spec/Plan/Tasks, Forge Live governado, history replay, review, promotion,
rollback, checkpoint, final state, cleanup, API `POST /atlas-code/certification`,
read-model `GET /atlas-code/certification`, persistencia no `/state` e painel
Evidence da surface Atlas Code.

Fora:

- Benchmark Rivals externo.
- Chamadas a Claude, Codex, Gemini ou provider pago.
- Provar qualidade humana do patch; a certificacao prova o circuito operacional.

## Dependencias

- `AtlasCodeEnterpriseCertificationService`.
- `AtlasCodeEnterpriseCertifyCommand`.
- `AtlasCodeEnterpriseCertificationController`.
- Controllers Atlas Code de WorkItem, Live Execution, Review e Checkpoint.
- `AtlasCodeContractTest`.

## Riscos

- O comando virar proxy sem executar o produto real.
- Workspace vivo mutar antes do review.
- Rollback nao restaurar o hash inicial.
- Replay historico reexecutar provider ou mutar disco.
- Checkpoint depender de memoria de chat.

## Exemplos

```bash
php artisan atlas:code:enterprise-certify --json --strict
```

```bash
curl -s -X POST /atlas-code/certification -H 'Accept: application/json'
```

```bash
curl -s /atlas-code/certification -H 'Accept: application/json'
```

Saida esperada: `atlas_code_enterprise_status=passed`,
`stage_summary.blocked=0`, `remaining_blockers=[]`,
`external_provider_call=false`.

## Evidencias

- `AtlasCodeContractTest::test_atlas_code_enterprise_certification_proves_full_product_loop`.
- `AtlasCodeContractTest::test_atlas_code_enterprise_certification_api_exposes_product_proof_packet`.
- `php artisan atlas:code:enterprise-certify --json --strict`.

## Proximas Acoes

O produto Atlas Code enterprise pesado so pode alegar que o loop operacional
local esta certificado quando este comando retorna exit 0, `stage_summary`
sem bloqueios, `remaining_blockers=[]` e `external_provider_call=false`.
