---
id: atlas-ai-programming-enterprise-implementation-plan
type: engineering_knowledge
title: Programming Enterprise Implementation Plan
status: active
category: architecture
priority: 99
summary: Plano canonico dos oito saltos enterprise para programacao: Agentic RAG, Semantic Code Graph, receipts/resume, action manifests, patch verifier, test impact, sandbox, repair executor e learning loop.
tags:
  - atlas-ai
  - programming
  - agentic-rag
  - semantic-code-graph
  - repair-loop
capabilities:
  - programming_agentic_rag
  - semantic_code_graph
  - programming_stage_receipts
  - programming_resume
  - programming_action_manifest
  - patch_verifier
  - test_impact_analysis
  - execution_sandbox
  - repair_loop_executor
  - programming_learning_loop
decisions:
  - Programming Agentic RAG e a camada de inteligencia antes de plan/patch/test/repair.
  - O standard operacional de nivel profissional vive em programming-professional-rag-operating-standard.md e deve ser lido antes de mudar RAG/Agentic RAG.
  - O alvo de RAG/Agentic RAG profissional e governado por programming-agentic-rag-professional-spec.md; MVP fraco nao e criterio de conclusao.
  - Semantic Code Graph e Test Impact Analysis alimentam o Retrieval Plan.
  - Stage receipts, action manifests e resume sao obrigatorios para runtime enterprise.
  - Patch Verifier e Repair Loop executor bloqueiam claims quando evidencia estiver fraca.
  - Learning Loop promove apenas candidatos curados, com evidencia e reversibilidade.
  - Rivals-Programming nao pode gastar nova bateria paga quando a ultima execucao real esta invalida; primeiro corrigir protocolo, gates e escopo.
  - Fair Claude/Rivals provider execution exige runbook pronto, workspace Atlas limpo e baseline workspace separado/limpo antes de gastar provider.
maintenance:
  - Atualize este documento quando qualquer um dos oito itens ganhar codigo, comando, API, teste ou gate.
  - Nao declare item concluido sem DoD, comando de evidencia e teste verde cobrindo o requisito.
related_paths:
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Ai/AtlasOpenBrainContextInjectionService.php
  - app/Services/Engineering/EngineeringCodeIntelligenceService.php
  - app/Services/Ai/Runtime/AiToolRuntime.php
  - app/Services/Ai/Programming/ProgrammingPythonRuntimeContract.php
  - app/Services/Ai/Programming/ProgrammingPythonRuntimeExecutor.php
  - app/Services/Ai/Programming/ProgrammingPythonRuntimeGraphProjector.php
  - app/Services/Ai/Programming/ProgrammingRivalsReadinessService.php
  - app/Services/Ai/Programming/ProgrammingProfessionalCompletionAuditService.php
  - runtimes/python/programming_intelligence
  - app/Services/Engineering/EngineeringHarnessExecutionService.php
  - app/Services/Engineering/EngineeringHarnessRunnerService.php
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/domains/programming-professional-rag-operating-standard.md
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
  - docs/engineering-knowledge-base/domains/programming-repair-contract.md
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/engineering-knowledge-base/evolution/context-builder-roadmap.md
  - docs/engineering-knowledge-base/code-intelligence.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-programming-enterprise-implementation-plan
graph_title: Programming Enterprise Implementation Plan
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-domain
graph_status: active
graph_source: repo
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md
allowed_changes:
  - Atualizar fases, DoD, comandos, contratos e artefatos quando a implementacao evoluir.
forbidden_changes:
  - Marcar qualquer fase como concluida sem evidencia executada e teste especifico.
  - Usar RAG para empurrar contexto bruto sem ranking, motivo, hash e limite.
  - Promover memoria aprendida sem curadoria ou recibo.
depends_on:
  - atlas-ai-programming-domain
  - atlas-ai-memory-context-core-open-brain
  - atlas-code-intelligence
  - super-tool-runtime-core
flows_to:
  - programming-agentic-rag
  - semantic-code-graph
  - programming-stage-receipts
  - programming-resume
  - patch-verifier
  - test-impact-analysis
  - execution-sandbox
  - repair-loop-executor
  - programming-learning-loop
unlocks:
  - atlas-programming-enterprise-runtime
  - atlas-rivals-programming-quality
governs:
  - programming
evidence:
  - docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan test tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php"
  - "php artisan test tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php"
  - "php artisan test tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php"
  - "php artisan test tests/Unit/AiToolRuntimeTest.php"
  - "php artisan test tests/Feature/Architecture/DomainProfileComplianceTest.php"
  - "php artisan atlas:programming:rivals-readiness --json"
  - "php artisan atlas:programming:completion-audit --json"
next_actions:
  - Integrar receipts/action manifests ao Evidence Ledger global quando a projection registry aceitar a nova projection de programacao.
  - Ampliar o Semantic Code Graph com parsers por linguagem alem do PHP/import metadata.
  - Expor fila de Learning Loop em UI/API quando a surface de review humano for priorizada.
  - Triar bateria Rivals real invalida antes de nova execucao paga contra providers.
  - Criar worktrees limpos para Atlas arm e baseline antes de nova bateria real.
requires_evidence: true
risk_level: medium
---

# Programming Enterprise Implementation Plan

## Resumo

Este documento estrutura a implementacao dos maiores saltos do Atlas em
programacao. A meta e sair de um fluxo governado por contratos para um runtime
enterprise que entende o sistema, executa com evidencia, retoma tarefas
quebradas e aprende sem gerar ruido.

Fluxo alvo:

```text
task -> intent/flow -> Agentic RAG plan -> Semantic Code Graph/docs/memory
-> ranked context pack -> stage plan -> action manifests -> verifier/tests
-> repair when needed -> receipts -> curated learning candidates
-> Rivals diagnostics without new provider spend when the last battery is invalid
```

## Papel no Atlas

Este plano governa a proxima camada de programacao sobre:

- `AtlasProgrammingOrchestrator`
- `AtlasOpenBrainContextInjectionService`
- `EngineeringCodeIntelligenceService`
- `AiToolRuntime`
- `EngineeringHarnessExecutionService`
- `EngineeringHarnessRunnerService`

Ele nao substitui Memory/Open Brain, Tool Runtime, Code Intelligence ou Evidence
Ledger. Ele conecta esses blocos para que programacao use contexto obrigatorio,
execucao rastreavel e validacao antes de declarar tarefa concluida.

## Onde Se Encaixa

Ordem enterprise recomendada:

| Ordem | Item | Funcao |
| --- | --- | --- |
| 1 | Programming Agentic RAG | Decide o que buscar, o que e obrigatorio e se o contexto basta. |
| 2 | Semantic Code Graph | Mapeia simbolos, dependencias, chamadas, testes e docs relacionadas. |
| 3 | Stage Receipts + Resume | Persiste etapas e permite retomada sem depender da sessao. |
| 4 | ProgrammingActionManifest | Padroniza evidencia de tool/test/lint/visual/diff/search. |
| 5 | Patch Verifier | Revisa diff, escopo, risco, testes e contratos antes do done. |
| 6 | Test Impact Analysis | Escolhe testes proporcionais ao impacto da mudanca. |
| 7 | Execution Sandbox forte | Isola patch, checkpoint, rollback e promocao de diff. |
| 8 | Repair Loop executor | Corrige falhas com tentativas rastreaveis e gates. |
| 9 | Learning Loop de programacao | Promove aprendizados curados a partir de receipts e resultados. |

O item 9 e o destino natural dos oito blocos anteriores: ele so deve promover
memoria quando houver evidencia suficiente.

## Contratos

### Programming Agentic RAG

```json
{
  "schema_version": "atlas.programming.agentic_rag.plan.v1",
  "plan_id": "<programming-plan-id>",
  "flow": "programming.dev|repair|review|frontend|forge",
  "required_sources": [
    "code_symbols",
    "related_tests",
    "canonical_docs",
    "prior_decisions",
    "stage_receipts",
    "known_failures"
  ],
  "queries": [
    {
      "source": "code_symbols",
      "query": "<ranked query>",
      "reason": "identify touched module ownership",
      "required": true
    }
  ],
  "budget": {
    "max_refs": 40,
    "max_chars": 20000
  },
  "fail_closed_when_missing": [
    "required_source_unavailable",
    "context_pack_empty",
    "contradictory_sources"
  ]
}
```

### Stage Receipt

```json
{
  "schema_version": "atlas.programming.stage_receipt.v1",
  "receipt_id": "sha256(plan_id|stage|attempt)",
  "plan_id": "<plan-id>",
  "parent_plan_id": "<parent-plan-id|null>",
  "stage": "plan|review|patch|test|repair",
  "attempt": 1,
  "status": "passed|failed|blocked|skipped",
  "evidence_refs": [],
  "input_hash": "sha256",
  "output_hash": "sha256",
  "created_at": "iso8601"
}
```

### Action Manifest

```json
{
  "schema_version": "atlas.programming.action_manifest.v1",
  "action_id": "<tool-invocation-id>",
  "plan_id": "<plan-id>",
  "stage": "test|patch|review|repair",
  "tool": "programming.test|programming.lint|programming.visual_smoke",
  "permission_mode": "read|write|danger",
  "dry_run": false,
  "changed_files": [],
  "rollback": {
    "available": true,
    "checkpoint_id": "<id|null>",
    "restore_tool": "checkpoint.restore|null"
  },
  "gate_effect": "passed|failed|advisory|not_applicable",
  "next_action": "continue|repair|stop|human_review"
}
```

## Fluxo

1. `AtlasProgrammingOrchestrator` classifica objetivo, flow e risco.
2. `ProgrammingRetrievalPlanner` monta plano de busca obrigatoria.
3. `SemanticCodeGraphQueryService` retorna simbolos, dependencias e testes.
4. `ProgrammingContextPackAssembler` monta contexto ranked e provider-safe.
5. Stage `plan` grava receipt com `context_pack_hash`.
6. Stage `review` avalia risco, ownership e docs canonicos.
7. Stage `patch` aplica menor diff ou bloqueia por escopo.
8. Tool Runtime emite `ProgrammingActionManifest` para cada acao.
9. `TestImpactAnalyzer` escolhe testes proporcionais.
10. `PatchVerifier` valida diff, evidencia, gates e contratos.
11. Se falhar, `ProgrammingRepairExecutor` abre tentativa rastreavel.
12. Se passar, Learning Loop gera candidatos curados.

## Status de Implementacao

Status em 2026-05-13:

| Item | Status | Artefato atual |
| --- | --- | --- |
| Programming Agentic RAG | Implementado localmente com contrato profissional | `ProgrammingRetrievalPlanner` mantem `atlas.programming.agentic_rag.plan.v1` e adiciona `atlas.programming.agentic_rag.professional_plan.v1`; `ProgrammingRetrievalExecutor` emite context pack legado e `atlas.programming.context_pack.professional.v1` com retrieval hibrido graph + vector local, reranking, critic, eval online proxy, hash, budget, provider-safe e replay via `atlas_programming_context_packs`; `ProgrammingPythonRuntimeContract` declara runtime Python governado para AST/simbolos/embedding local, `ProgrammingPythonRuntimeExecutor` executa apenas com approval, runtime boundary verde e Decision Receipt, e `ProgrammingPythonRuntimeGraphProjector` converte receipt aprovado em fragmento de Semantic Code Graph; `atlas:programming:retrieval-benchmark` roda golden set local de recall/precision. |
| Semantic Code Graph | Implementado localmente | `ProgrammingSemanticCodeGraphService` prefere Code Intelligence indexado, usa scan de filesystem como fallback e emite edges `depends_on` por metadata/imports PHP. |
| Stage Receipts + Resume | Implementado localmente | `ProgrammingStageReceiptStore`, migration `atlas_programming_stage_receipts`, validator, `ProgrammingResumeService` e comando `atlas:programming:resume`. |
| ProgrammingActionManifest | Implementado localmente | `ProgrammingActionManifestFactory`, `ProgrammingActionManifestStore`, migration `atlas_programming_action_manifests` e anexo no `AiToolRuntime`. |
| Patch Verifier | Implementado localmente com benchmark | `ProgrammingPatchVerifier` bloqueia falta de teste/razao, manifest ausente/malformado, arquivo sensivel, rollback ausente e cobertura parcial de manifest; `atlas:programming:patch-verifier-benchmark` mede golden set local de grounded patch. |
| Test Impact Analysis | Implementado localmente com benchmark | `ProgrammingTestImpactAnalyzer` seleciona testes por grafo e convencao, com comandos recomendados; `atlas:programming:test-impact-benchmark` mede golden set local de test selection accuracy. |
| Execution Sandbox forte | Implementado localmente | `ProgrammingSandboxManager` define modo, rollback, snapshot strategy, acoes permitidas/bloqueadas, integrity gate, provisiona worktree git isolada para write high/critical e emite `atlas.programming.sandbox_rollback_receipt.v1`. |
| Repair Loop executor | Implementado localmente | `ProgrammingRepairExecutor` gera attempt plan; `ProgrammingRepairAttemptStore` gera receipt de repair com failure packet, patch manifest e test manifest. |
| Learning Loop de programacao | Implementado localmente | `ProgrammingLearningCandidateProjector`, `ProgrammingLearningCandidateStore` e `ProgrammingLearningPromotionGate` criam fila deduplicada/expiravel e bloqueiam promocao sem review humano. |
| Rivals-Programming readiness | Implementado como contrato seguro | `ProgrammingRivalsReadinessService` e `atlas:programming:rivals-readiness` consolidam os benchmarks locais, expõem `atlas.programming.rivals_contract.v1`, emitem `atlas.programming.rivals_operator_execution_packet.v1`, apontam os comandos reais de `atlas:engineering:benchmark:rivals` e bloqueiam claim comparavel ate existir bateria pareada real com provider/custo aprovados. |
| Completion audit | Implementado como gate executavel | `ProgrammingProfessionalCompletionAuditService` e `atlas:programming:completion-audit` mapeiam requisito -> artefato -> evidencia, retornam `blocked` enquanto Rivals real nao estiver claim-ready e impedem marcar a frente completa por proxy. |

Evidencia local ja executada:

```bash
/opt/homebrew/bin/php artisan test tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php
/opt/homebrew/bin/php artisan test tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php
/opt/homebrew/bin/php artisan test tests/Unit/AiToolRuntimeTest.php
/opt/homebrew/bin/php artisan test tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php
/opt/homebrew/bin/php artisan test tests/Feature/Architecture/DomainProfileComplianceTest.php
/opt/homebrew/bin/php artisan atlas:programming:retrieval-benchmark --json
/opt/homebrew/bin/php artisan atlas:programming:test-impact-benchmark --json
/opt/homebrew/bin/php artisan atlas:programming:patch-verifier-benchmark --json
/opt/homebrew/bin/php artisan atlas:programming:rivals-readiness --json
/opt/homebrew/bin/php artisan atlas:programming:completion-audit --json
PYTHONPATH=runtimes/python/programming_intelligence python3 -m unittest discover runtimes/python/programming_intelligence/tests
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
```

## Regras para IA

- Nunca usar RAG como despejo de contexto bruto.
- Nunca concluir tarefa de codigo sem receipt de stage ou bloqueio claro.
- Nunca promover memoria de programacao sem evidencia e curadoria.
- Nunca considerar screenshot suficiente para frontend.
- Nunca rodar bateria externa de Rivals-Programming sem aceite de custo.
- Nunca declarar score comparavel de Rivals-Programming a partir de benchmark local.
- Nunca retomar tarefa quebrada sem validar `parent_plan_id` e receipts.
- Preferir contexto com hash, fonte, motivo de inclusao e escopo.
- Bloquear quando fonte obrigatoria estiver ausente em flow strict/forge/repair.

## Escopo de Implementacao

### Fase 1 - Agentic RAG + Code Graph

- Criar `ProgrammingRetrievalPlanner`.
- Criar `ProgrammingRetrievalExecutor`.
- Evoluir Code Intelligence para nodes/edges.
- Adicionar sufficiency gate para required sources.
- Incluir `agentic_rag_plan` no plan de programacao.

DoD:

- Plan inclui retrieval plan, fontes obrigatorias e budget.
- Context pack inclui code/docs/memory/receipt refs ranqueados.
- Required source ausente falha fechado quando policy exigir.

### Fase 2 - Receipts + Resume

- Criar `ProgrammingStageReceiptStore`.
- Criar `ProgrammingStageReceiptValidator`.
- Criar `ProgrammingResumeService`.
- Projetar timeline por `plan_id`.

DoD:

- Stage fora de ordem bloqueia.
- Receipt adulterado bloqueia.
- Resume reconstrui contexto, decisoes e ultimo gate.

### Fase 3 - Manifests + Verifier + Test Impact

- Criar `ProgrammingActionManifest`.
- Emitir manifest nos tools de programacao.
- Criar `ProgrammingPatchVerifier`.
- Criar `TestImpactAnalyzer`.

DoD:

- Todo tool de programacao retorna manifest.
- Patch sem teste ou razao verificavel bloqueia.
- Testes selecionados incluem motivo e relacao com arquivos/simbolos.

### Fase 4 - Sandbox + Repair + Learning

- Criar `ProgrammingSandboxManager`.
- Criar `ProgrammingRepairExecutor`.
- Criar `RepairAttemptStore`.
- Criar `ProgrammingLearningCandidateProjector`.
- Criar `ProgrammingLearningPromotionGate`.

DoD:

- Execucao write cria checkpoint/snapshot.
- Repair tem tentativa, failure packet, patch manifest e test manifest.
- Learning gera candidatos, nao memoria automatica.

## Dependencias

- Memory/Open Brain para contexto provider-safe.
- Code Intelligence para grafo semantico.
- Evidence Ledger para receipts e replay.
- Tool Runtime para manifests e rollback.
- Engineering Harness para gates, tests e sandbox.
- Rivals-Programming para medir ganho real depois da implementacao.
  `atlas:programming:rivals-readiness` apenas informa prontidao e pendencias; a
  bateria comparavel continua sendo `atlas:engineering:benchmark:rivals` com
  providers reais.

## Evidencias

Comandos minimos:

```bash
php artisan atlas:engineering:knowledge docs-health --json
php artisan atlas:ai:architecture-validate --json
php artisan test tests/Unit/Ai/AtlasProgrammingOrchestratorTest.php
php artisan test tests/Unit/Ai/AtlasOpenBrainContextInjectionServiceTest.php
php artisan test tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php
php artisan test tests/Unit/AiToolRuntimeTest.php
php artisan test tests/Feature/Architecture/DomainProfileComplianceTest.php
php artisan atlas:ai:structure-mother-audit --hours=720 --workspace=/Users/vitorepf/develop/Atlas/atlas-server --json
```

Evidencias de conclusao:

- `atlas.programming.agentic_rag.plan.v1`
- `atlas.programming.stage_receipt.v1`
- `atlas.programming.action_manifest.v1`
- Patch verifier report
- Test impact receipt
- Sandbox rollback receipt
- Repair attempt receipt
- Learning candidate receipt
- Programming Rivals readiness receipt
- Fair Claude/Rivals export bundle verificado quando houver claim comparavel

## Riscos

| Risco | Mitigacao |
| --- | --- |
| RAG virar ruido | Ranking, budget, required sources e sufficiency gate. |
| Resume contaminado | Validator de receipts e hash de input/output. |
| Patch bom com teste errado | Test Impact + Patch Verifier. |
| Repair em loop | Max attempts, failure classifier e no-progress blocker. |
| Sandbox pesado | Politica por risco: strict/forge usa isolamento forte. |
| Learning poluir memoria | Candidatos curados, expiracao e review. |

## Exemplos

### Bugfix

1. Agentic RAG busca simbolos, testes, erros similares e docs.
2. Code Graph aponta arquivos e testes relacionados.
3. Patch stage muda menor diff.
4. Test Impact roda teste alvo.
5. Patch Verifier valida escopo.
6. Receipt final registra evidencia.

### Repair

1. Test falha.
2. Failure packet e repair receipt sao criados.
3. Agentic RAG busca historico da falha.
4. Repair executor aplica tentativa.
5. Teste impactado roda novamente.
6. Sem progresso, bloqueia para review humano.

## Proximas Acoes

1. Registrar uma projection oficial no Evidence Ledger para receipts/manifests de programacao.
2. Ampliar parsers do Semantic Code Graph para TypeScript/Swift/Kotlin quando essas stacks forem priorizadas.
3. Expor Learning Queue em surface humana dedicada.
4. Conectar replay/resume ao fluxo visual do app mobile quando a tela de programacao for priorizada.

Regra de parada: esta frente so pode ser marcada como concluida quando todos os
itens acima tiverem artefato, teste e gate correspondente. Enquanto algum item
depender de custo externo, calendario ou decisao de produto, registrar a
pendencia e continuar para o proximo item local verificavel.
