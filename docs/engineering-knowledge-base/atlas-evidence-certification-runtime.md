---
id: atlas-evidence-certification-runtime
type: engineering_knowledge
title: Atlas Evidence Certification Runtime
status: active
category: atlas-ai
priority: 100
summary: Camada universal que impede resposta fraca, falso completo e claim sem prova. Define EvidencePack, Receipt, Claim, Artifact, SourceRef, GateRun, TestResult, OperatorDecision, Certification, Blocker e AuditEvent como contratos canonicos para metas, missoes, work orders, dominios, tools e handoffs. Meta 4 backend implementada 2026-05-18.
tags:
  - atlas-ai
  - evidence
  - certification
  - audit
  - runtime
capabilities:
  - evidence_pack_global
  - receipt_runtime
  - claim_verification
  - certification_gate
  - blocker_governance
  - audit_event_ledger
decisions:
  - Evidence/Certification Runtime e a unica fronteira universal entre execucao e conclusao no Atlas AI.
  - Nada vira completed sem certification passed; ausencia de erro nao prova sucesso.
  - Claim relevante exige claim_type, evidence_refs e verification_status auditavel; superioridade exige benchmark.
  - Blocker real e resultado valido e substitui resposta convincente sem base.
  - Handoff cross-mission, cross-domain ou cross-runtime so e aceito com evidence_refs.
  - Tool execution emite receipt; domain delivery exige certification; mission completion exige evidence pack + certification.
maintenance:
  - Atualize este doc antes de mudar EvidencePack, Receipt, Claim, Certification, Blocker ou AuditEvent.
  - Nao criar nova familia Evidence/Certification paralela; estenda os models, services e migrations listados em `repo_paths`.
  - Nao relaxe evidence_refs, certification_hash ou claim_verification_status para acelerar release.
related_paths:
  - docs/engineering-knowledge-base/atlas-evidence-truth-layer.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/atlas-ai-kernel-architecture.md
  - docs/engineering-knowledge-base/atlas-autonomous-control-plane.md
  - docs/engineering-knowledge-base/atlas-mission-mode.md
  - docs/engineering-knowledge-base/atlas-objective-intelligence.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-evidence-certification-runtime
graph_title: Atlas Evidence Certification Runtime
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-autonomous-intelligence-operating-system
graph_status: active
graph_source: repo
human_name: Atlas Evidence Certification Runtime
canonical_name: Atlas Evidence Certification Runtime
technical_name: atlas-evidence-certification-runtime
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - database/migrations/2026_05_18_020000_create_ai_evidence_certification_runtime_tables.php
  - app/Models/AiEvidencePack.php
  - app/Models/AiReceipt.php
  - app/Models/AiClaim.php
  - app/Models/AiArtifact.php
  - app/Models/AiSourceRef.php
  - app/Models/AiGateRun.php
  - app/Models/AiTestResult.php
  - app/Models/AiOperatorDecision.php
  - app/Models/AiCertification.php
  - app/Models/AiBlocker.php
  - app/Models/AiAuditEvent.php
  - app/Services/Ai/Evidence/EvidenceCanonicalHash.php
  - app/Services/Ai/Evidence/EvidencePackService.php
  - app/Services/Ai/Evidence/ReceiptService.php
  - app/Services/Ai/Evidence/ClaimVerificationService.php
  - app/Services/Ai/Evidence/ArtifactRegistryService.php
  - app/Services/Ai/Evidence/SourceRefService.php
  - app/Services/Ai/Evidence/GateRunService.php
  - app/Services/Ai/Evidence/TestResultService.php
  - app/Services/Ai/Evidence/OperatorDecisionService.php
  - app/Services/Ai/Evidence/CertificationRuntimeService.php
  - app/Services/Ai/Evidence/BlockerService.php
  - app/Services/Ai/Evidence/AuditEventService.php
  - app/Services/Ai/Evidence/EvidenceReadinessService.php
  - app/Services/Ai/Evidence/EvidenceControlPlaneService.php
  - app/Services/Ai/Evidence/MissionEvidenceAdapter.php
  - app/Console/Commands/AtlasAiEvidenceCommand.php
  - tests/Concerns/CreatesEvidenceRuntimeTables.php
  - tests/Feature/Ai/Evidence/EvidenceRuntimeReadinessTest.php
  - tests/Feature/Ai/Evidence/EvidenceRuntimeSmokeTest.php
  - tests/Feature/Ai/Evidence/EvidenceRuntimeCertificationTest.php
  - tests/Feature/Ai/Evidence/EvidenceRuntimeClaimVerificationTest.php
  - tests/Feature/Ai/Evidence/EvidenceRuntimeBlockerTest.php
  - tests/Feature/Ai/Evidence/EvidenceRuntimeReceiptTest.php
  - tests/Feature/Ai/Evidence/EvidenceRuntimeGateRunTest.php
  - tests/Feature/Ai/Evidence/EvidenceRuntimeAuditEventTest.php
  - tests/Feature/Ai/Evidence/EvidenceRuntimeControlPlaneTest.php
  - tests/Feature/Ai/Evidence/EvidenceRuntimeMissionAdapterTest.php
allowed_changes:
  - Refinar campos minimos, tipos de evidencia por dominio e politicas de claim.
  - Adicionar gates, blockers e audit events conforme novos dominios entrarem.
forbidden_changes:
  - Permitir completed sem certification passed.
  - Aceitar claim relevante sem evidence_refs e verification_status.
  - Esconder blocker como recomendacao vaga.
  - Confundir ausencia de erro com sucesso operacional.
  - Declarar superioridade contra rival sem benchmark.
depends_on:
  - atlas-evidence-truth-layer
  - atlas-mission-mode
  - atlas-objective-intelligence
  - atlas-autonomous-intelligence-operating-system
  - atlas-domain-company-runtimes
  - atlas-ai-kernel-architecture
  - atlas-autonomous-control-plane
  - atlas-compounding-engineering-intelligence
flows_to:
  - atlas-autonomous-control-plane
  - atlas-domain-company-runtimes
  - atlas-compounding-engineering-intelligence
unlocks:
  - verifiable-autonomous-completion
  - cross-domain-handoff-with-evidence
  - tool-receipt-runtime
governs:
  - atlas_ai.evidence_pack
  - atlas_ai.receipt
  - atlas_ai.claim
  - atlas_ai.certification
  - atlas_ai.blocker
  - atlas_ai.audit_event
evidence:
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
evidence_refs:
  - symbol: AiEvidencePack
  - command: atlas:ai:evidence
  - test: EvidenceRuntimeReadinessTest
required_tests:
  - "/opt/homebrew/bin/php artisan test --filter=EvidenceRuntime"
  - "/opt/homebrew/bin/php artisan atlas:ai:evidence --action=readiness --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:evidence --action=smoke --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:evidence --action=control-plane --json"
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
ai_entrypoints:
  - Leia Objetos Centrais, Regras Obrigatorias, Anti False Completion e Plano de Implementacao antes de implementar.
ai_usage_notes:
  - Este doc e contrato; nao implementar sem coordenacao com Meta 1, Meta 2 e Meta 3.
quality_gates:
  - evidence-pack-attached
  - receipt-emitted
  - claim-classified
  - certification-passed-or-blocked
  - blocker-explicit
  - audit-event-recorded
failure_modes:
  - Certificacao virar carimbo automatico.
  - Evidence pack acumular ruido em vez de prova.
  - Tool/runtime escrever sem receipt.
  - Handoff sem evidence_refs.
  - Operador aprovar implicito sem registro.
observability_signals:
  - evidence_pack_id
  - receipt_count
  - claim_count
  - unsupported_claims
  - certification_status
  - blocker_count
  - audit_event_count
next_actions:
  - Coordenar com Meta 1 para fixar mission_id como chave de referencia.
  - Definir interface EvidencePackBuilder global e adapter por dominio.
  - Integrar os models/services ativos com Meta 1/2/3 conforme os lifecycles amadurecerem.
line_limit: 620
---
# Atlas Evidence Certification Runtime
## Estado Atual
Meta 4 backend entregue 2026-05-18, mirror do padrao Mission Foundation:
11 tabelas `ai_*`, 11 models `Ai*`, 14 services em `app/Services/Ai/Evidence/`
(incluindo `EvidenceCanonicalHash` e `MissionEvidenceAdapter`), comando
`atlas:ai:evidence` com actions `readiness | smoke | pack | receipt | certify
| control-plane` e 10 feature tests em `tests/Feature/Ai/Evidence/` (367
tests / 1710 assertions verdes apos `--filter=EvidenceRuntime`).
Comandos canonicos:
```bash
php artisan atlas:ai:evidence --action=readiness --json
php artisan atlas:ai:evidence --action=smoke --json
php artisan atlas:ai:evidence --action=pack \
    --target-type=mission --target-id=<uuid> --json
php artisan atlas:ai:evidence --action=receipt \
    --type=tool_call --action-name="atlas:dev:run" --json
php artisan atlas:ai:evidence --action=certify \
    --target-type=mission --target-id=<uuid> --json
php artisan atlas:ai:evidence --action=control-plane --json
```
`MissionEvidenceAdapter` projeta uma `AiMission` para um `AiEvidencePack`
(target_type `mission`) e chama `CertificationRuntimeService::certify` sem
reescrever `MissionCertificationService`. `canCompleteMission` so e true
quando a certification universal esta `passed`.
## Resumo
Atlas Evidence Certification Runtime e a camada universal de prova do Atlas AI.
Ele impede tres falhas estruturais: resposta convincente sem base, falso
completo e claim sem evidencia. Toda meta, missao, work order, domain delivery,
tool run, handoff e operator decision deve emitir evidencia auditavel e passar
por certification antes de virar `completed`.
Este doc agora governa o backend ativo de Evidence/Certification. Meta 4
entregou migrations, models, services, comando e testes; Meta 1 Mission
Foundation, Meta 2 Domain Runtime, Meta 3 Policy e Control Plane devem integrar
por esses contratos, sem criar runtime paralelo ou rebaixar os gates de prova.

## Papel No Atlas

Evidence/Certification fica acima de qualquer execucao concreta. E condicao de
saida do Mission Mode, do Domain Company Runtime, do Tool Runtime e do Control
Plane. Ele:

- impede pedido nao trivial terminar sem prova.
- substitui resposta vaga por blocker real e governado.
- separa `running`, `blocked`, `failed`, `inconclusive` e `completed`.
- exige certification antes de qualquer estado terminal positivo.
- garante que handoff entre missions, domains e tools carrega evidencia.
- registra audit event suficiente para replay.

Nao decide tema, prompt, prioridade, custo ou provider. Quem decide e o Kernel,
Mission Mode, Domain Runtime e Policy. Evidence observa, exige, certifica ou
bloqueia.

## Onde Se Encaixa

```text
Mission / Work Order / Domain Step / Tool Run / Handoff
-> Execution
-> Receipt + Artifact + SourceRef + GateRun + TestResult + OperatorDecision
-> EvidencePack (por target)
-> Claim Policy
-> Certification Gate
-> passed | failed | blocked
-> Control Plane + Compounding Memory + Final Answer
```

Quatro promessas duras:

1. Toda missao relevante termina com EvidencePack + Certification ou Blocker.
2. Toda execucao via tool/runtime emite Receipt assinado.
3. Toda claim relevante carrega verification_status verificavel.
4. Todo handoff carrega evidence_refs.

## Contratos

Familias canonicas:

- `atlas.ai.evidence.evidence_pack.v1`
- `atlas.ai.evidence.receipt.v1`
- `atlas.ai.evidence.claim.v1`
- `atlas.ai.evidence.artifact.v1`
- `atlas.ai.evidence.source_ref.v1`
- `atlas.ai.evidence.gate_run.v1`
- `atlas.ai.evidence.test_result.v1`
- `atlas.ai.evidence.operator_decision.v1`
- `atlas.ai.evidence.certification.v1`
- `atlas.ai.evidence.blocker.v1`
- `atlas.ai.evidence.audit_event.v1`

Todos sao append-only, hash-addressable e auditaveis. Hashes usam SHA-256 sobre
payload canonico. IDs opacos com prefixo por tipo: `ep_`, `rc_`, `cl_`, `art_`,
`src_`, `gr_`, `tr_`, `od_`, `cert_`, `blk_`, `ae_`.

## Objetos Centrais

### EvidencePack

Container minimo agregando evidencia de um target. Campos: `evidence_pack_id`,
`mission_id` (FK Meta 1), `work_order_id` (nullable), `domain_id` (nullable),
`target_type` (`mission|work_order|domain_delivery|tool_run|handoff|claim`),
`target_id`, `artifact_refs`, `source_refs`, `command_refs`, `test_refs`,
`receipt_refs`, `blocker_refs`, `claim_refs`, `operator_decision_refs`, `hash`
(sha256), `created_at`. Regras: nunca remover refs; pack vazio nao certifica;
pack de missao trivial pode ter apenas command_refs + test_refs.

### Receipt

Recibo assinado de uma acao de runtime/agente. Campos: `receipt_id`,
`receipt_type` (`command|tool_call|provider_call|domain_step|handoff|gate_run
|repair|policy_decision`), `mission_id`, `work_order_id` (nullable), `actor`
(agent/provider/surface/runtime/operator), `action`, `input_hash`,
`output_hash`, `evidence_refs`, `status` (`ok|failed|partial|blocked`),
`timestamp`, `receipt_hash`. Regras: toda tool execution emite receipt;
`partial` nao vira `ok`; sem evidence_refs so e aceito quando action e leitura
pura.

### Claim

Afirmacao verificavel feita por agente, runtime ou resposta final. Campos:
`claim_id`, `claim_text`, `claim_type` (`verified|supported|inferred|uncertain
|blocked`), `confidence` (0.0-1.0), `evidence_refs`, `verification_status`
(`unverified|supported|contradicted|insufficient|blocked`), `risk_level`
(`low|medium|high|critical`), `mission_id`, `domain_id` (nullable),
`created_at`. Regras: claim relevante exige evidence_refs nao vazio; claim de
superioridade contra rival exige benchmark referenciado; `contradicted`
rebaixa o claim, nao deleta.

### Artifact

Entregavel concreto. Campos: `artifact_id`, `mission_id`, `kind`
(`file|diff|dataset|report|screenshot|model_run|dashboard|briefing|creative
|config`), `path_or_uri`, `content_hash`, `producer` (actor), `created_at`.
Regras: nenhum artifact aceito sem content_hash; payload grande fica em
storage, registro guarda apenas referencia.

### SourceRef

Referencia auditavel a fonte externa, interna ou de dados. Campos:
`source_ref_id`, `mission_id`, `kind`
(`url|doc|code_path|db_query|api_call|dataset|vault_note`), `locator`,
`snapshot_hash`, `quality_score` (0.0-1.0), `freshness_at`, `created_at`.
Regras: fonte sem snapshot_hash nao certifica claim critica; quality_score
baixo nao impede leitura, impede uso como prova primaria.

### GateRun

Execucao auditavel de um quality gate. Campos: `gate_run_id`, `gate_name`,
`mission_id`, `work_order_id` (nullable), `status` (`passed|failed|waived
|not_applicable`), `evidence_refs`, `waiver_reason` (nullable), `executed_at`.
Regras: `waived` exige waiver_reason + OperatorDecision aprovada; gate ausente
e `not_applicable` apenas com justificativa.

### TestResult

Execucao de teste, simulacao ou validacao. Campos: `test_result_id`,
`test_kind` (`unit|feature|integration|smoke|simulation|manual_review
|replay`), `command`, `status` (`passed|failed|flaky|skipped`),
`coverage_of_requirement` (lista requirement_id), `output_hash`, `executed_at`.
Regras: teste verde fora do escopo do requisito nao prova requisito; `flaky`
nao certifica.

### OperatorDecision

Decisao humana registrada. Campos: `operator_decision_id`, `mission_id`,
`decision_type` (`approve|reject|waiver|scope_change|priority_change
|manual_override`), `actor`, `rationale`, `evidence_refs`, `created_at`.
Regras: aprovacao implicita nao existe; sem decision registrada, nao aprovada;
waiver sempre referencia o gate.

### Certification

Gate oficial de conclusao. Campos: `certification_id`, `target_type`
(`mission|work_order|domain_delivery|tool_run|handoff`), `target_id`, `status`
(`pending|passed|failed|blocked`), `checked_requirements`,
`missing_requirements`, `evidence_refs` (evidence_pack_id, receipt_id,
claim_id), `blocker_refs`, `certification_hash`, `certified_at`. Regras:
`passed` exige `missing_requirements` vazio + `evidence_refs` nao vazio;
`blocked` exige `blocker_refs` nao vazio; nao pode existir target `completed`
sem certification `passed`.

### Blocker

Registro estruturado de bloqueio real. Campos: `blocker_id`, `mission_id`,
`kind` (`missing_evidence|missing_permission|safety_gate|external_dependency
|unauthorized_action|data_unavailable|legal_or_policy|cost_or_budget
|inconclusive_result`), `description`, `evidence_refs`, `next_action`,
`severity` (`low|medium|high|critical`), `status` (`open|mitigated|resolved
|accepted`), `created_at`. Regras: blocker e resultado valido; recomendacao
vaga sem `kind` e `next_action` nao e blocker, e ruido.

### AuditEvent

Evento atomico no ledger global. Campos: `audit_event_id`, `mission_id`,
`actor`, `event_type` (`evidence_attached|receipt_emitted|claim_made
|claim_revised|gate_run|certification_attempted|certification_passed
|certification_failed|blocker_opened|blocker_closed|operator_decision`),
`target_type`, `target_id`, `payload_hash`, `created_at`. Regras: ledger e
append-only; replay reconstroi estado a partir do stream.

## Regras Obrigatorias

1. Nada vira `completed` sem `Certification.status = passed`.
2. `Certification.passed` exige `evidence_refs` nao vazio.
3. Claim de superioridade exige benchmark referenciado em `evidence_refs`.
4. Blocker real deve ser registrado como Blocker; nunca escondido como nota.
5. Evidence deve ser auditavel e ligada ao `target_id` correto.
6. Handoff cross-mission, cross-domain ou cross-runtime carrega
   `evidence_refs` no payload.
7. Tool execution exige Receipt; sem receipt, runtime trata como bypass.
8. Domain delivery exige certification proprio do dominio.
9. Operator approval virtual nao existe; precisa OperatorDecision.
10. `Certification.failed` ou `blocked` nao volta a `pending` sem nova
    evidencia.

## Tipos De Evidencia Por Dominio

| Dominio | Evidencia minima aceita |
| --- | --- |
| Programming | diffs, tests (unit/feature/integration), commands, static analysis, review notes, receipts, gate_runs |
| Research | sources com snapshot_hash, citations, source_quality_score, contradiction_check_report |
| Finance | data_snapshot, assumptions, valuation_model_run, risk_check, OperatorDecision approve para ordem real |
| Marketing | brief, creatives, campaign_config, metrics_snapshot, OperatorDecision approve para publish/spend |
| Cyber | authorization doc, scope, RoE, evidence_chain, remediation_proof, legal_or_policy gate |
| Strategy | assumptions, market_model_run, experiment_result, decision_memo |
| Personal Development | user_approved_goal, plan, review_note, non_clinical_boundary check |

Domain Manifest pode estender lista, nunca reduzir o minimo.

## Anti False Completion

Falso completo e o principal inimigo. Mecanismos canonicos:

- **Requirement coverage map.** Cada `requirement_id` precisa pelo menos um
  evidence_ref que o cubra. Sem cobertura, Certification recusa.
- **Ausencia de erro != sucesso.** Build verde, comando sem stderr ou
  ferramenta que retornou 0 nao certificam objetivo sem TestResult cobrindo o
  requisito real.
- **Status honestos.** `incomplete`, `blocked`, `failed` e `inconclusive` sao
  estados de primeira classe; sem coercao silenciosa para `completed`.
- **Claim verification status.** Resposta final separa `verified`,
  `supported`, `inferred`, `uncertain` e `blocked`; nunca colapsa em prosa.
- **OperatorDecision explicita.** Sem decision registrada, aprovacao nao
  existe; assistant nao se autoaprova.
- **Replay.** Audit ledger permite reconstruir o caminho; se replay nao
  reproduz, Certification nao pode estar `passed`.

## Fluxo

```text
1. Mission Mode classifica trivial | task | mission | obra.
2. Objective Intelligence emite requirements e DoD.
3. Domain Runtime planeja work_orders e domain_steps.
4. Cada execucao emite Receipts + Artifacts + SourceRefs.
5. GateRuns rodam quality gates; TestResults rodam validacoes.
6. EvidencePackBuilder agrega refs por target.
7. Claim Policy classifica claims contra evidence.
8. Certification Gate compara checked vs missing requirements.
9. Resultado: passed | failed | blocked.
10. AuditEvent stream registra cada passo.
11. Control Plane projeta estado; Compounding aprende com outcomes.
```

## Regras Para IA

- Nao declarar completed sem citar `certification_id`.
- Nao misturar Receipt e EvidencePack; Receipt prova acao, Pack agrega.
- Nao inventar `coverage_of_requirement`; mapear so o que o teste cobre.
- Nao tratar `waiver` como atalho; waiver e excecao auditavel.
- Nao mover Blocker para "nota" para acelerar fechamento.
- Nao usar fonte sem `snapshot_hash` como prova primaria de claim critica.
- Nao usar SourceRef interno como prova de superioridade externa.

## Escopo De Implementacao

Backend ativo. Detalhes em "Estado Atual"; paths em `repo_paths`. Mudancas
estruturais exigem manter testes verdes em `tests/Feature/Ai/Evidence/` e
docs-health limpo.

### Relacao Com Outras Metas

- **Meta 1 Mission Foundation:** consumimos `mission_id`, `work_order_id` e
  evidence refs via `MissionEvidenceAdapter`, sem reescrever
  `MissionCertificationService`.
- **Meta 2 Domain Runtime:** cada manifest estende `evidence_schema` e
  `quality_gates`; nunca reduz. `target_type=domain_delivery` reservado.
- **Meta 3 Policy:** Policy/Permission/Budget bloqueia antes; Evidence
  certifica depois. Camadas nao se substituem.
- **Control Plane:** `EvidenceControlPlaneService::snapshot` projeta
  certifications, blockers e audit stream.
- **Compounding Intelligence:** consome AuditEvent + outcome para learning.

## Dependencias

- Atlas Evidence Truth Layer (claim policy, source quality, freshness).
- Atlas Mission Mode (mission contract).
- Atlas Objective Intelligence (requirements, DoD).
- Atlas Autonomous Intelligence Operating System (Kernel + identidade).
- Atlas Domain Company Runtimes (domain delivery + certification).
- Atlas AI Kernel Architecture (Decision Receipt + Evidence Ledger).
- Atlas Autonomous Control Plane (estado vivo + read models).
- Atlas Compounding Engineering Intelligence (aprendizado pos outcome).

## Evidencias

Este doc registra contrato e runtime ativo. Evidencias atuais ficam em
`repo_paths`, no comando `atlas:ai:evidence` e nos testes `EvidenceRuntime`.
Evidencias futuras devem ampliar esses contratos JSON
`atlas.ai.evidence.*.v1` e relatorios de Certification sem substituir o backend
existente.

## Riscos

- Burocracia em pedido trivial. Mitigacao: trivial usa Receipt + TestResult
  leve; pack obrigatorio so a partir de `task`.
- Pack inflado com ruido. Mitigacao: ClaimVerifier rejeita refs sem cobertura.
- Certification virar carimbo. Mitigacao: `missing_requirements` precisa
  estar vazio; gate falha se vazio for implicito.
- AuditEvent virar log barulhento. Mitigacao: event_type taxonomia fechada.
- Bloquear demais e parar Atlas. Mitigacao: Blocker tem `severity`,
  `next_action` e pode ser `accepted` por OperatorDecision com waiver.

## Exemplos

- Programming `corrigir bug X`: Receipts (diff/build/test) + TestResults
  (unit+feature) -> Claim `verified` -> Certification `passed`.
- Research `avaliar Y`: SourceRefs com snapshot_hash + quality_score -> Claim
  `supported` -> Certification `passed` ou `blocked` se fonte primaria
  inacessivel.
- Cyber engagement: OperatorDecision approve com authorization -> Artifacts
  scope/RoE/chain -> Blocker `legal_or_policy` se escopo expirar.

## Proximas Acoes

1. Integrar `MissionEvidenceAdapter` ao lifecycle guard global da Meta 1.
2. Quando Meta 2 amadurecer manifests, ligar `domain_certification_requirements`
   via `CertificationRuntimeService::certify` com `required_requirements`.
3. Coordenar com Meta 3 (Policy) para fronteira Policy x Evidence.
4. Atualizar `atlas-evidence-truth-layer.md` para apontar contratos
   `atlas.ai.evidence.*.v1` desta runtime.

## Definition Of Done

Atendido quando: mission relevante termina com EvidencePack + Certification
ou Blocker; tool execution emite Receipt; claim relevante tem
`verification_status` e `evidence_refs`; handoff carrega `evidence_refs`;
Control Plane projeta certifications/blockers/audit stream; docs-health
passa; testes em `tests/Feature/Ai/Evidence/` verdes.
