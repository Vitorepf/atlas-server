---
id: atlas-ai-programming-professional-completion-audit
type: engineering_knowledge
title: Programming Professional Completion Audit
status: active
category: architecture
priority: 98
summary: Auditoria de conclusao da documentacao profissional de programacao, separando implementacao local verificada de pendencias externas bloqueadas por custo/provedor.
tags:
  - atlas-ai
  - programming
  - audit
  - agentic-rag
  - rivals
capabilities:
  - programming_agentic_rag
  - programming_rivals_readiness
  - semantic_code_graph
  - programming_quality_gates
decisions:
  - A frente profissional de programacao nao pode ser marcada como totalmente concluida enquanto Rivals-Programming real nao tiver bateria pareada verificavel.
  - Benchmarks locais comprovam fundacao tecnica, mas nao substituem score comparavel contra provider externo.
  - Qualquer claim de qualidade precisa declarar se e local, readiness ou bateria real.
maintenance:
  - Atualize esta auditoria sempre que a spec profissional mudar ou quando uma bateria real de Rivals-Programming for executada.
  - Nao mover status para complete sem evidencias reais para todos os criterios.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
  - docs/engineering-knowledge-base/domains/programming-enterprise-implementation-plan.md
  - app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php
  - app/Services/Ai/Programming/ProgrammingRetrievalExecutor.php
  - app/Services/Ai/Programming/ProgrammingRivalsReadinessService.php
  - app/Services/Ai/Programming/ProgrammingProfessionalCompletionAuditService.php
  - app/Console/Commands/AtlasProgrammingRivalsReadinessCommand.php
  - app/Console/Commands/AtlasProgrammingCompletionAuditCommand.php
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-ai-programming-professional-completion-audit
graph_title: Programming Professional Completion Audit
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-domain
graph_status: active
graph_source: repo
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
allowed_changes:
  - Atualizar checklist, evidencias e pendencias quando a implementacao evoluir.
forbidden_changes:
  - Apagar pendencia real de provider externo sem export bundle verificado.
  - Tratar readiness como score comparavel.
depends_on:
  - atlas-ai-programming-agentic-rag-professional-spec
  - atlas-ai-programming-enterprise-implementation-plan
flows_to:
  - programming-agentic-rag
  - atlas-rivals-programming-quality
unlocks:
  - programming-completion-review
governs:
  - programming
evidence:
  - docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md
required_tests:
  - "php artisan atlas:programming:completion-audit --json"
  - "php artisan atlas:programming:rivals-readiness --json"
  - "php artisan test tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php"
next_actions:
  - Executar Rivals-Programming real com aceite de custo quando o operador aprovar.
  - Atualizar esta auditoria com export bundle verificado depois da bateria real.
requires_evidence: true
risk_level: medium
---

# Programming Professional Completion Audit

## Resumo

Auditoria canonica para impedir que a frente profissional de programacao seja
marcada como concluida por esforco, teste local ou readiness quando ainda faltar
bateria real de Rivals-Programming.

## Papel no Atlas

Este documento funciona como gate de realidade para a estrutura mae de
programacao. Ele separa implementacao local verificada, evidencia de readiness e
claim comparavel contra provedores externos.

## Onde Se Encaixa

Fica abaixo da spec profissional de Agentic RAG e do plano enterprise de
programacao. Ele e consumido por revisao humana, architecture/docs-health e
relatorios de conclusao.

## Contratos

Contrato de status:

```json
{
  "local_foundation": "implemented|failed",
  "rivals_programming": "external_battery_required|external_battery_invalid|claim_ready",
  "claim_allowed": false
}
```

`claim_allowed=true` exige export bundle verificado de
`atlas:engineering:benchmark:rivals`.

## Fluxo

1. Ler spec profissional e plano enterprise.
2. Mapear requisito para artefato concreto.
3. Validar comandos locais.
4. Consultar `atlas:programming:rivals-readiness`.
5. Bloquear conclusao total quando Rivals real estiver ausente, invalido ou sem casos comparaveis.

## Regras para IA

- Nao declarar conclusao total sem checklist completo.
- Nao tratar readiness como score comparavel.
- Nao usar benchmark local como substituto de provider real.
- Nao executar provider externo sem aceite explicito de custo.
- Nao gastar nova bateria paga quando a ultima bateria real estiver invalida; primeiro corrigir protocolo, gates e escopo do workspace.

## Escopo de Implementacao

Objetivo auditado: implementar a documentacao profissional de programacao de
forma enterprise, com foco em RAG, Agentic RAG, desempenho, qualidade,
receipts, runtime local, benchmarks e integridade de Rivals-Programming.

| Requisito | Evidencia | Status |
| --- | --- | --- |
| Spec profissional sem MVP fraco | `programming-agentic-rag-professional-spec.md` | implementado |
| Plano enterprise dos blocos de programacao | `programming-enterprise-implementation-plan.md` | implementado |
| Agentic RAG plan profissional | `ProgrammingRetrievalPlanner` emite `atlas.programming.agentic_rag.professional_plan.v1` | implementado |
| Context pack persistido e replayable | `ProgrammingContextPackStore`, migration `atlas_programming_context_packs` | implementado |
| Hybrid retrieval graph + vector local | `ProgrammingRetrievalExecutor`, `ProgrammingLocalVectorIndex`, `ProgrammingProfessionalReranker` | implementado localmente |
| Gap critic fail-closed | `ProgrammingGapCritic` e teste de fonte obrigatoria ausente | implementado |
| Semantic Code Graph | `ProgrammingSemanticCodeGraphService` com Code Intelligence e fallback filesystem | implementado localmente |
| Stage receipts + resume | `ProgrammingStageReceiptStore`, validator, `ProgrammingResumeService`, comando `atlas:programming:resume` | implementado |
| Action manifests | `ProgrammingActionManifestFactory`, store e Tool Runtime | implementado |
| Patch verifier | `ProgrammingPatchVerifier` e `atlas:programming:patch-verifier-benchmark` | implementado |
| Test impact | `ProgrammingTestImpactAnalyzer` e `atlas:programming:test-impact-benchmark` | implementado |
| Execution sandbox | `ProgrammingSandboxManager` com worktree e rollback receipt | implementado localmente |
| Repair loop executor | `ProgrammingRepairExecutor` e `ProgrammingRepairAttemptStore` | implementado |
| Learning loop curado | `ProgrammingLearningCandidateProjector`, store e promotion gate | implementado |
| Python runtime governado | `runtimes/python/programming_intelligence` e `ProgrammingPythonRuntimeExecutor` | implementado localmente |
| Benchmarks locais | retrieval, test impact, patch verifier | verificado com gate local |
| Rivals-Programming readiness | `ProgrammingRivalsReadinessService`, `atlas:programming:rivals-readiness` | implementado |
| Completion audit executavel | `ProgrammingProfessionalCompletionAuditService`, `atlas:programming:completion-audit` | implementado |
| Audit protocol | `atlas.programming.professional_completion_audit_protocol.v1` restata objetivo, criterios de sucesso e mapa prompt-artefato-evidencia | implementado |
| Verification evidence | `atlas.programming.professional_completion_verification_evidence.v1` expõe métricas locais, runtime cache por benchmark, status externo e segurança do operador em formato direto | implementado |
| Executive report | `atlas.programming.professional_completion_executive_report.v1` entrega headline, estado, métricas principais, bloqueio atual e próxima ação para app/operador | implementado |
| Human command report | `atlas:programming:completion-audit` e `atlas:programming:rivals-readiness` exibem headline, métricas, bloqueios, safety e próxima ação sem depender de JSON | implementado |
| Local benchmark runtime cache | `atlas.programming.local_benchmark_runtime_cache.v1` e `atlas.programming.retrieval_benchmark_runtime_cache.v1`, process-memory only, com flags de refresh para recomputar | implementado |
| Operator execution packet | `atlas.programming.rivals_operator_execution_packet.v1` declara comando, custo e confirmacoes sem provider dispatch | implementado |
| Fair Claude provider preflight | `AtlasEngineeringBenchmarkFairCommand` bloqueia provider mesmo com custo confirmado se runbook/workspaces nao estiverem prontos | implementado |
| Current workspace preflight | `atlas.programming.current_workspace_provider_preflight.v1` mostra se o workspace atual pode receber bateria paga e lista amostra de dirty files | implementado |
| Structure mother safe Rivals commands | `AtlasStructureMotherAuditReadModel` publica apenas comandos com `<clean-atlas-workspace>` e `<separate-clean-baseline-workspace>` para bateria paga | implementado |
| Local gate execution hardening | Quality scan roda Pint com `memory_limit=1024M`; visual smoke detecta Laravel API sem web route e usa health route real | implementado |
| Rivals integrity assurance | `atlas.programming.rivals_integrity_assurance.v1` declara validade tipo A/B, controle de variaveis externas e score admission gate | implementado |
| Rivals result integrity diagnostics | `atlas.programming.rivals_result_integrity_diagnostics.v1` separa derrota real, caso inconclusivo e protocolo Atlas invalido para evitar placar enganoso | implementado |
| Rerun token-spend guard | `rivals_rerun_preconditions_prevent_token_spend_on_invalid_battery` no audit protocol exige precondicoes atuais antes de nova bateria paga | implementado |
| Fair Claude report result integrity | `atlas.fair_claude.result_integrity.v1` no report backend bloqueia UI/mobile de renderizar vencedor quando nao ha score comparavel admitido | implementado |
| Programming readiness result integrity projection | `atlas.programming.fair_claude_result_integrity_projection.v1` projeta o contrato do report Fair Claude para `atlas:programming:rivals-readiness` e `atlas:programming:completion-audit` | implementado |
| Fair Claude export semantic verification | `verifyFairClaudeExportBundle` valida `result_integrity`, `claim.winner`, `claim_winner_admitted` e seção `Result Integrity` no claim markdown | implementado |
| Latest real battery evidence | `atlas.programming.latest_real_battery_evidence.v1` explica se a ultima bateria prova score, nao prova perda, ou exige triagem antes de novo custo | implementado |
| Invalid battery triage packet | `atlas.programming.invalid_battery_triage_packet.v1` consolida testes falhos, release gate, risk flags, replay artifact e decisao para bloquear novo gasto ate triagem | implementado |
| Current rerun preconditions | `atlas.programming.current_rivals_rerun_preconditions.v1` separa falhas historicas da bateria de pre-condicoes atuais, lista diagnosticos sem provider e mantem dispatch bloqueado | implementado |
| Rivals-Programming real | bateria pareada provider externo com export bundle verificado | pendente por custo/operador |

## Dependencias

- Programming Agentic RAG Professional Spec.
- Programming Enterprise Implementation Plan.
- Programming Rivals Readiness.
- Atlas Rivals/Fair Claude battery.

## Evidencias

Comandos usados:

```bash
php artisan atlas:programming:rivals-readiness --json
php artisan atlas:programming:completion-audit --json
php artisan atlas:programming:retrieval-benchmark --json
php artisan atlas:programming:test-impact-benchmark --json
php artisan atlas:programming:patch-verifier-benchmark --json
php artisan test tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php
php artisan atlas:engineering:knowledge docs-health --json
```

Evidencia local mais recente:

- Retrieval benchmark: `passed`, `recall_at_k=1.0`, `precision_at_k=0.2222`, minimos `0.9/0.2`.
- Test Impact benchmark: `passed`, recall/precision `1.0`.
- Patch Verifier benchmark: `passed`, grounded patch rate `1.0`.
- Rivals readiness: fundacao local pronta, claim externo bloqueado ate bateria real pareada.
- Audit protocol: `restated_objective`, `success_criteria`, `prompt_to_artifact_map` e `proxy_signal_policy` expostos no JSON de conclusao.
- Completion audit API guard coverage: `artifact_coverage.api_rivals_battery_guard` prova que o controller mobile/API tem `battery-plan`, bloqueio de plan no `/run`, bloqueio de bateria historica invalida, workspace sujo, workspace nao-Git, baseline sujo/nao-Git, baseline separado e `no_provider_call=true`.
- Verification evidence: status e metricas locais aparecem em `verification_evidence.local_benchmarks`, e claim externo continua separado em `verification_evidence.rivals_external_claim`.
- Executive report: `headline`, `status_label`, `primary_state`, `key_metrics`, `current_blocker`, `operator_next_action` e `safety_summary` ficam prontos para UI/relatorio.
- Human command report: comandos sem `--json` mostram status local, claim externo, métricas principais, bloqueios, safety e comando recomendado; teste cobre a presença desses campos.
- Local benchmark runtime cache: usado apenas em memoria do processo para evitar recomputar benchmarks locais repetidos; `provider_state_cached=false`, `--refresh-local-benchmarks` e `atlas:programming:retrieval-benchmark --refresh` forçam recomputação.
- Operator execution packet: `provider_dispatches_now=false`, primeira bateria recomendada `quick`, exige `--confirm-runbook-reviewed` e `--confirm-provider-cost`.
- Provider execution preflight: a bateria real agora exige workspace Atlas Git auditavel e limpo, baseline workspace Git auditavel, separado e limpo, e runbook pronto; se houver workspace sujo ou nao-Git, o comando retorna `fair_claude_provider_execution_preflight_blocked` sem chamada de provider. Coberto por teste para dirty workspace e workspace nao-Git mesmo com `--confirm-provider-cost`.
- Current workspace preflight: readiness de programacao expõe `current_workspace_preflight.status`, `ready_for_provider_battery`, `dirty_count`, amostra dos arquivos sujos e comandos para criar worktrees limpos.
- Clean workspace runbook: o runbook expõe `prepare_clean_atlas_worktree`, `prepare_clean_baseline_worktree` e verificações `git -C <workspace> status --short` para preparar a próxima bateria sem contaminar o teste.
- Structure mother safe commands: `AtlasStructureMotherAuditReadModel` nao deve publicar comando de bateria paga com o workspace atual. A acao `approve_rivals_programming_real_battery` fica `blocked_until_clean_worktrees_and_operator_cost_approval`, `actionable_now=false`, e a API exige `workspace=<clean-atlas-workspace>` mais `claude_code_baseline_workspace=<separate-clean-baseline-workspace>`.
- Rivals integrity assurance: `claim_blocked` sem mesmo caso, estado inicial equivalente, gates iguais, provider/model auditados, replay/export verificados e casos comparaveis reais.
- Fair Claude report result integrity: `/engineering/benchmarks/suites/{suite}/fair-claude-report` expõe `result_integrity.status`, `score_admitted`, `claim_winner_admitted`, `winner_for_claim`, `provisional_leader`, policy de score e `ui_contract.must_not_render_winner`. A tela de Atlas Rivals deve usar este bloco como fonte primaria do estado, nao inferir vencedor por score bruto, tempo ou baseline isolado. O `evidence_packet.claim.winner` representa apenas vencedor de claim admitido; liderança bloqueada aparece só como `provisional_leader`.
- Programming readiness result integrity projection: `atlas:programming:rivals-readiness` e `atlas:programming:completion-audit` carregam `fair_claude_result_integrity` para que a auditoria central tenha o mesmo contrato anti-placar-falso usado pelo endpoint mobile.
- Fair Claude export semantic verification: `atlas:engineering:benchmark:rivals verify --output-dir=...` reprova bundle com `claim.winner` presente sem `claim_winner_admitted=true`, com violação de `ui_contract.must_not_render_winner`, sem `result_integrity` ou sem seção `## Result Integrity` no markdown.
- Historical failure policy: `atlas.programming.rivals_historical_failure_policy.v1` declara que falhas historicas da bateria sao diagnosticas, nao autorizam novo gasto, e que pre-condicoes atuais precisam ficar verdes antes de qualquer rerun.
- Provider rerun guard: `AtlasEngineeringBenchmarkFairCommand` bloqueia nova execucao paga com `historical_invalid_battery_requires_triage` quando existe bateria Fair Claude historica invalida sem caso comparavel, mesmo se o operador passar `--confirm-runbook-reviewed` e `--confirm-provider-cost`.
- API battery-plan guard: `/engineering/benchmarks/suites/{suite}/rivals/battery-plan` e o endpoint `/run` tambem bloqueiam com `historical_invalid_battery_requires_triage` quando a suite tem bateria Fair Claude invalida sem caso comparavel; o app mobile nao pode contornar a trava do CLI.
- API workspace preflight: o battery-plan do app tambem exige workspace Atlas Git limpo, baseline Git limpo e baseline separado; planos com `atlas_workspace_dirty`, `atlas_workspace_not_git_worktree`, `claude_code_baseline_workspace_dirty` ou `claude_code_baseline_workspace_not_git_worktree` ficam `blocked` e `no_provider_call=true`.
- Current rerun preconditions: `atlas.programming.current_rivals_rerun_preconditions.v1` mostra benchmarks locais, estado do workspace, motivos atuais de bloqueio e comandos diagnosticos sem provider para evitar gastar tokens enquanto a bateria antiga esta invalida.
- Rivals real quick executado em 2026-05-13: 2 runs pareados oficiais encontrados, 0 casos comparaveis, 2 casos invalidos por `atlas_protocol_invalid`, baseline Claude Code verificado, Atlas sem pass verificado. Isto e evidencia diagnostica real, nao placar valido.
- Falha da ultima bateria real: final packet invalido, protocol_valid=false, release gate failed, 3 testes/gates falhos e diff amplo. O readiness deve reportar `external_battery_invalid`, `rerun_provider_battery_allowed_now=false` e proxima acao de triagem antes de gastar novo provider.
- Recheck local em 2026-05-13: `atlas:engineering:quality-scan --changed-only` passou para os arquivos alterados, `atlas:engineering:visual-smoke` passou em `/health`, `ProgrammingEnterpriseRuntimeTest` passou 19 testes/335 asserts, e o guard Fair Claude passou testes de runbook, dirty workspace e workspace nao-Git. O Pint repo-wide sem `--changed-only` ainda falha por divida antiga fora do escopo do patch; isso nao deve decidir score A/B de Rivals, mas o proximo run provider continua exigindo worktree Atlas limpo e baseline separado.

Status atual: `external_battery_invalid` quando a base local tem as runs reais
registradas; em ambientes sem historico de runs o status aparece como
`external_battery_required`. A fundacao local esta pronta para uso e avaliacao
interna. O claim comparavel contra Claude/Codex/Gemini continua bloqueado ate
existirem casos reais comparaveis, protocolo valido e export bundle verificado.

## Riscos

| Risco | Mitigacao |
| --- | --- |
| Concluir por proxy | Checklist requisito-artefato-evidencia. |
| Score sintetico | `synthetic_scores_allowed=false`. |
| Custo externo acidental | Rivals real exige flags de confirmacao. |
| Re-run pago sobre falha invalida | `rerun_provider_battery_allowed_now=false`; CLI e API bloqueiam com `historical_invalid_battery_requires_triage` ate triagem do protocolo e gates. |
| Provider rodando em workspace sujo | `AtlasEngineeringBenchmarkFairCommand` bloqueia com `atlas_workspace_dirty` ou `claude_code_baseline_workspace_dirty`. |
| Provider rodando em workspace nao auditavel | `AtlasEngineeringBenchmarkFairCommand` bloqueia com `atlas_workspace_not_git_worktree` ou `claude_code_baseline_workspace_not_git_worktree`. |
| App mobile iniciando bateria contaminada | Battery-plan API bloqueia workspaces sujos/nao-Git e suite com bateria invalida historica antes do endpoint `/run`. |

## Exemplos

Exemplo correto: "fundacao local enterprise concluida; claim externo pendente".

Exemplo incorreto: "Atlas venceu Claude" sem bateria real e export bundle.

## Proximas Acoes

1. Triar a ultima bateria real invalida: protocolo Atlas, gates falhos, escopo de diff e integridade de replay.
2. Reexecutar somente comandos de diagnostico sem provider ate a triagem estar limpa.
3. Executar nova bateria Rivals-Programming apenas quando houver aprovacao explicita de custo e `rerun_provider_battery_allowed_now=true`.
4. Verificar export bundle de Rivals.
5. Reexecutar `atlas:programming:completion-audit --json`.
6. Atualizar esta auditoria para `claim_ready` se a evidencia real passar.
