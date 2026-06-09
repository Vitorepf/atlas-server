---
id: atlas-aaeos-final-convergence-leap-backlog
type: engineering_knowledge
title: AAEOS Final-Convergence Atomic Leap Backlog
doc_schema: atlas_canonical_module_doc.v1
status: planned
implementation_state: backlog_only_no_runtime
authority_class: backlog
category: agentic-engineering
priority: 91
summary: Atomic single-decision new-class pure-logic slices for the loop, mined from canonical AAEOS docs/code and adversarially filtered against scaffold + complexity. Each creates ONE new dependency-free class with ONE method computing a real decision from inputs, paired test with meaningful assertions. Decompose-ready; the loop one-shots these without I/O or existing-class edits.
owner: operator (Vitor)
risk_level: medium
tags:
  - atlas-ai
  - aaeos
  - final-convergence
  - stewardship-loop
  - backlog
capabilities:
  - loop_ready_atomic_slice_backlog
  - final_convergence_decision_slices
  - evidence_replay_atomic_work
decisions:
  - Backlog rows are planned execution candidates, not runtime proof.
  - Final-convergence rows must stay one pure class plus one paired meaningful test.
  - Duplicate-looking rows must be blocked by existing-file collision, not reimplemented blindly.
maintenance:
  - Keep rows source-anchored, atomic and executable without provider discovery.
  - Run docs-health and a plan-only decompose check after changing this file.
  - Update the backlog index count when rows are added, removed or moved.
related_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-evolution-backlog-index.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
graph_id: atlas-aaeos-final-convergence-leap-backlog
graph_title: AAEOS Final Convergence Atomic Leap Backlog
graph_world: atlas
graph_layer: module
graph_kind: index
graph_parent: atlas-aaeos-evolution-backlog-index
graph_status: planned
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-final-convergence-leap-backlog.md
allowed_changes:
  - Add or refine atomic final-convergence slices with exact target files, acceptance criteria and tests.
  - Move ambiguous or duplicate rows out of loop-ready section until they are decomposed.
forbidden_changes:
  - Do NOT treat backlog rows as delivered runtime.
  - Do NOT count a slice without merged code, green validation and evidence receipts.
  - Do NOT fabricate items; every slice traces to a real source line.
depends_on:
  - atlas-aaeos-evolution-backlog-index
flows_to:
  - atlas-software-company-stewardship-stack
unlocks:
  - final_convergence_atomic_slice_supply
  - evidence_replay_atomic_work
governs:
  - stewardship_loop.plan_backlog
evidence:
  - docs/engineering-knowledge-base/atlas-aaeos-final-convergence-leap-backlog.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:plan-execution:run --doc=docs/engineering-knowledge-base/atlas-aaeos-final-convergence-leap-backlog.md --json
requires_evidence: true
next_actions:
  - Let AP-790 consume this doc only through governed plan backlog selection and owner-flow execution.
---

## Resumo

Backlog atomico de saltos N×M para o loop autonomo. Cada fatia = UMA classe nova, UM metodo, UMA decisao pura computada dos inputs, com teste significativo. Areas: Forge, Dev runtime, Evidence, Provider-routing, Gates, Self-Construction, Mission.

## Papel no Atlas

Este documento abastece o Stewardship Loop com slices finais de convergencia para memoria, evidencia e proof replay. Ele e backlog planejado; entrega real exige codigo, teste, judge, evidence e merge honesto.

## Onde Se Encaixa

```text
atlas-software-company-stewardship-stack
  +-- atlas-aaeos-evolution-backlog-index
      +-- atlas-aaeos-final-convergence-leap-backlog
```

## Contratos

- Cada row loop-ready deve apontar um arquivo novo, um teste pareado e regras computadas.
- O decomposer deve derivar somente o arquivo novo e o teste declarado ou convencional.
- Provider, branch, validation, judge e merge continuam governados pelo fluxo AP-786/AP-790.

## Fluxo

O loop escolhe uma row ready, transforma em slice de baixo risco, executa owner-flow em branch/worktree isolado, valida, julga, repara se couber no budget e so conta merge quando `main_before != main_after`.

## Regras para IA

- Nao confundir backlog com runtime implementado.
- Nao duplicar classe existente; colisao deve bloquear ou escolher outro slice.
- Nao aceitar scaffold, assert tautologico, comentario/no-op ou docs-only como entrega deste doc.

## Escopo de Implementacao

Este arquivo so governa descoberta e slicing de backlog. As classes listadas nas rows pertencem aos paths explicitamente declarados e devem continuar puras, deterministicas e testadas.

## Dependencias

- AP-790 Reliable 24h Loop Runner.
- AP-805 Ten-Cycle Readiness Governor.
- Plan Execution decomposer e owner-flow executor.

## Evidencias

- Este arquivo.
- Plan-only decomposer check para confirmar rows sliced e `allowed_files`.
- Receipts de provider, lane, validation, judge, repair e merge quando uma row for consumida.

## Riscos

- Rows convergentes duplicarem classes ja planejadas em docs anteriores.
- Path de contexto ser confundido com arquivo editavel.
- Status ready ser tratado como entrega sem runtime real.

## Exemplos

Uma row boa cria uma classe pura de proof replay ou attribution, com schema fixo e teste que prova o veto de evidencia.

## Proximas Acoes

- Consumir este backlog depois dos docs anteriores ou com collision gate ativo.
- Rebaixar qualquer row que gere diff sem logica, teste fraco ou escopo maior que o declarado.

## 6. Decomposicao em slices ordenados

| ID | Item | Aceite | DoD |
| --- | --- | --- | --- |
| S241 | Create a new PHP class MemoryConflictVerbClassifier at app/Services/Ai/Memory/MemoryConflictVerbClassifier.php exposing `public function classify(array $a, array $b): array` that returns {schema_version:'atlas.memory.conflict_verb.v1', verdict, escalate:bool, reason} computed purely from two memory records {key,scope_type,polarity,recorded_ts,memory_type} via 6 ordered rules; zero ctor deps, no clock/IO/DB, no edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=AtlasMemoryConflictResolutionService.php:299] | Returns schema_version literal 'atlas.memory.conflict_verb.v1'. Rules: (1) keys differ->not_conflict; (2) a/b missing scope_type same key->related; (3) same key diff scope_type->scoped; (4) same key+scope+same polarity->compatible; (5) same key+scope+opposite polarity+strictly-newer recorded_ts->supersedes; (6) opposite polarity+equal ts->conflicts_with. escalate=true iff verdict in {conflicts_with,supersedes} AND memory_type in {decision,architecture,policy}; equal-ts conflicts_with always escalates. reason names firing rule. Paired test Tests\\Unit\\Ai\\MemoryConflictVerbClassifierTest extends Tests\\TestCase (no DB) with >=5 computed asserts reading verdict/escalate/reason from returned array (never equality to a const table): different keys->not_conflict&escalate=false; cross scope_type->scoped; opposite-polarity newer-wins->supersedes&escalate=true for memory_type 'decision'; equal-ts opposite polarity->conflicts_with&escalate=true; same polarity->compatible&escalate=false. New test passes. | DoD: both files created; `vendor/bin/phpunit tests/Unit/Ai/MemoryConflictVerbClassifierTest.php` green; zero ctor deps & no IO/clock/DB; no existing files edited; schema_version literal present; >=5 computed asserts (none compare to a const table); prohibited words (Jarvis,Rivals,benchmark,superiority,concurrent) absent from code and test. |
| S242 | Memory quality status policy unified. `MemoryQualityStatusBandClassifier` now wraps shared `MemoryQualityStatusPolicy`; `AtlasMemoryQualityService::scorecard()` consumes the same policy instead of carrying a private status copy. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=app/Services/Ai/MemoryQualityStatusPolicy.php:1, app/Services/Ai/AtlasMemoryQualityService.php:63, app/Services/Ai/MemoryGovernance/MemoryQualityStatusBandClassifier.php:1] | Schema remains `atlas.memory_governance.quality_status_band.v1`; status bands, ok and injection_allowed semantics are unchanged and remain computed from activeCount, compositeScore and hasCriticalIssue. | DoD: single policy owner, zero-dep governance wrapper, scorecard status path uses the same policy, and focused memory governance tests stay green. |
| S243 | Local prereasoning policy unified. `LocalPrereasoningEligibilityClassifier` now wraps shared `LocalPrereasoningPolicy`; `AtlasTokenEconomyRuntimeService::localPrereasoning()` consumes the same policy instead of carrying a private task-type/savings copy. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=app/Services/Ai/Context/LocalPrereasoningPolicy.php:1, app/Services/Ai/Context/AtlasTokenEconomyRuntimeService.php:143, app/Services/Ai/Context/LocalPrereasoningEligibilityClassifier.php:1] | Eligibility schema remains `atlas.token_economy.local_prereasoning_eligibility.v1`; runtime receipt schema remains `atlas.token_economy.local_prereasoning.v1`; task eligibility and saved-token semantics are unchanged. | DoD: single policy owner, zero-dep eligibility wrapper, runtime receipt shape preserved, focused unit and feature tests green. |
| S244 | Create a new PHP class ReceiptReversibilityConsentGate (final) at app/Services/Ai/Kernel/Decision/Reversibility/ReceiptReversibilityConsentGate.php, namespace App\\Services\\Ai\\Kernel\\Decision\\Reversibility, exposing `public function evaluate(string $reversalTier, int $elapsedHoldSeconds, int $requiredHoldSeconds, bool $operatorConfirmed): array` returning {schema_version:'atlas.decide.receipt_reversibility_consent.v1', verdict, committable:bool, requires_operator_confirmation:bool, remaining_hold_seconds:int, reason} computed purely from inputs; zero ctor deps, no IO/Carbon/facade, <=110 LOC, no edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=DecisionReceiptRuntimeGuard.php:277] | Returns schema_version literal 'atlas.decide.receipt_reversibility_consent.v1'. Tier=strtolower(trim) into {cheap,moderate,expensive,unrecoverable}, unknown/empty->'unrecoverable' (fail-closed); hold seconds clamped >=0; remaining_hold_seconds=max(0,required-elapsed). <=7 ordered first-match rules R1..R7 (R1 unrecoverable & !confirmed->blocked_irreversible_without_consent committable=false req_confirm=true; R2 unrecoverable & confirmed->committable_with_explicit_consent committable=true req_confirm=true; R3 expensive & remaining>0 & !confirmed->holding_for_cooldown committable=false; R4 expensive & (remaining=0 OR confirmed)->committable_after_hold committable=true; R5 moderate & remaining>0 & !confirmed->holding_for_cooldown; R6 moderate->committable_after_hold; R7 cheap->committable_immediately req_confirm=false); req_confirm defaults false except R1/R2; reason set per rule. Paired test extends Tests\\TestCase (no DB) with >=5 computed asserts: ('unrecoverable',0,0,false)->committable===false && req_confirm===true && verdict==='blocked_irreversible_without_consent'; ('unrecoverable',0,0,true)->committable===true && req_confirm===true; ('expensive',10,60,false)->committable===false && remaining_hold_seconds===50 && verdict==='holding_for_cooldown'; ('expensive',60,60,false)->committable===true && remaining_hold_seconds===0 && verdict==='committable_after_hold'; ('cheap',0,9999,false)->committable===true && req_confirm===false && verdict==='committable_immediately'; plus ('banana',0,0,false) coerces to unrecoverable->committable===false; plus schema_version literal. New test passes. | DoD: both files created; `vendor/bin/phpunit tests/Unit/Ai/Kernel/Decision/Reversibility/ReceiptReversibilityConsentGateTest.php` green; final class & correct namespace; zero ctor deps & no IO/Carbon/facade; <=110 LOC; no existing files edited; fail-closed coercion proven; schema_version literal present; >=5 computed asserts; prohibited words absent from code and test. |
| S245 | Create a new PHP class ScopeContractFeasibilityClassifier at app/Services/Ai/Programming/AtlasDev/Gate/ScopeContractFeasibilityClassifier.php exposing `public function classify(array $allowedFiles, array $forbiddenFiles, int $maxFilesChanged): array` returning {schema_version:'atlas.programming.scope_contract_feasibility.v1', verdict, dead_allowed_files:list<string>, reason, satisfiable_allowed_count:int} computed purely from inputs via a PRIVATE INLINE matcher mirroring ScopeGuard::matchesAny (file===pattern OR str_contains(pattern,'*')&&fnmatch(pattern,file,FNM_NOESCAPE) OR str_ends_with(pattern,'/')&&str_starts_with(file,pattern)); do NOT import/call ScopeGuard, do NOT use array_intersect; zero ctor deps, no IO, no edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=PromptSections.php:92] | Returns schema_version literal 'atlas.programming.scope_contract_feasibility.v1'. live universe = non-empty allowed files; dead_allowed_files = non-empty allowed caught by any forbidden pattern (sort asc, array_values re-index); satisfiable_allowed_count = count(non-empty allowed)-count(dead). First-match rules: R1 >=1 non-empty allowed AND all non-empty allowed dead->unsatisfiable; R2 some-but-not-all non-empty dead->degraded; R3 maxFilesChanged>0 AND satisfiable_allowed_count>maxFilesChanged->degraded; R4 no non-empty allowed target->unsatisfiable; R5 else feasible with dead_allowed_files===[]; reason names firing rule. Paired test extends PHPUnit\\Framework\\TestCase in Tests\\Unit\\Ai\\Programming\\AtlasDev\\Gate with 5 computed asserts proving glob+prefix catch what array_intersect misses: (a) ['app/Services/Foo.php'],['app/Services/*'],0->unsatisfiable AND dead===['app/Services/Foo.php']; (b) ['app/Services/Foo.php','app/Models/Bar.php'],['app/Services/'],0->degraded AND dead===['app/Services/Foo.php'] AND satisfiable_allowed_count===1; (c) ['a.php','b.php','c.php'],[],2->degraded (3>2); (d) ['app/X.php'],['app/Other.php'],0->feasible AND dead===[]; (e) [],['x'],0->unsatisfiable. New test passes. | DoD: both files created; `vendor/bin/phpunit tests/Unit/Ai/Programming/AtlasDev/Gate/ScopeContractFeasibilityClassifierTest.php` green; private inline matcher present, ScopeGuard NOT imported/called, array_intersect NOT used; zero ctor deps & no IO; no existing files edited; schema_version literal present; 5 computed asserts incl. glob and trailing-slash-prefix catches; prohibited words absent from code and test. |
| S246 | Compaction loss-risk policy unified. `CompactionLossRiskClassifier` is now the ACIE schema wrapper over shared `CompactionLossPolicy`; `AiCompactionService::compactForScope()` consumes the same policy instead of carrying a private `deriveLossRisk` copy. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=app/Services/Ai/CompactionLossPolicy.php:1, app/Services/Ai/AiCompactionService.php:382, app/Services/Ai/ContextIntelligence/CompactionLossRiskClassifier.php:1] | Schema remains `atlas.context_intelligence.compaction_loss_risk.v1`; risk bands, write_allowed and reason semantics are unchanged and covered by the existing computed asserts. | DoD: single policy owner, zero-dep ACIE wrapper, focused compaction and ContextIntelligence tests green. |
| S247 | Create a new PHP class ProviderProofAttributionScorer (final) at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Attribution/ProviderProofAttributionScorer.php, namespace App\\Services\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\Attribution, exposing `public function score(string $owner, int $providerCalls, bool $hasChangedFiles, array $lanes): array` returning {schema_version:'atlas.stewardship.provider_proof_attribution.v1', verdict, attributed:bool, attribution_score:float, attributed_lane_count:int, total_lane_count:int, unattributed_lanes:list<string>, reason} computed purely from inputs over the 5 imported ProviderAndModelAttributionPerLaneContract::LANE_ROLES; zero ctor deps, no IO/DB/git, <=110 LOC, no edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=ProviderAndModelAttributionPerLaneContract.php:18] | Returns schema_version literal 'atlas.stewardship.provider_proof_attribution.v1'. owner=strtolower(trim); evaluate ONLY the 5 canonical LANE_ROLES (ignore extra keys); a lane is attributed when provider is a non-empty trimmed string; total_lane_count=5; unattributed_lanes sort() asc; attribution_score=round(attributed/5,2). 7 ordered first-match rules with ordering so all asserts pass: R1 owner==='forge' && hasChangedFiles && providerCalls<=0->unattributed_forge_diff attributed=false score forced 0.0; then !hasChangedFiles->no_diff_no_proof_required attributed=true (MUST precede R2/R3); R2 attributed_lane_count===0 && hasChangedFiles->no_lane_attribution attributed=false; R3 owner==='forge' && score<0.6->weak_attribution_coverage attributed=false; R4 score<1.0 && >=0.6->partial_attribution attributed=true; R5 score===1.0->fully_attributed attributed=true unattributed_lanes===[]. Paired test extends Tests\\TestCase (no DB), namespace Tests\\Unit\\Ai\\SoftwareCompanyStewardship\\AreaFocusLoop\\Attribution, 5 computed asserts: (forge,0,true,all 5 set)->verdict==='unattributed_forge_diff' && attributed===false && attribution_score===0.0; (forge,3,true,context_scout+architect only)->attribution_score===0.4 && verdict==='weak_attribution_coverage' && attributed===false; (forge,3,true,3 of 5 set)->attribution_score===0.6 && verdict==='partial_attribution' && attributed===true && count(unattributed_lanes)===2; (dev,3,true,all 5 set)->attribution_score===1.0 && verdict==='fully_attributed' && unattributed_lanes===[]; (forge,3,false,none set)->verdict==='no_diff_no_proof_required' && attributed===true AND schema_version literal. New test passes. | DoD: both files created; `vendor/bin/phpunit tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Attribution/ProviderProofAttributionScorerTest.php` green; final class & correct namespace; imports LANE_ROLES const (does not re-list lanes); no-diff guard precedes R2/R3; zero ctor deps & no IO/DB/git; <=110 LOC; no existing files edited; schema_version literal present; 5 computed asserts; prohibited words absent from code and test. |
| S248 | Create a new PHP class EvidenceReplayCompletenessVerdict (final) at app/Services/Ai/Evidence/Replay/EvidenceReplayCompletenessVerdict.php, namespace App\\Services\\Ai\\Evidence\\Replay, exposing `public function decide(array $requiredComponents, array $presentComponents, int $chainLength, int $chainGapCount, int $brokenRefCount): array` returning {schema_version:'atlas.evidence.replay_completeness.v1', verdict:string(complete/incomplete_missing_components/incomplete_unreplayable), replay_green:bool, completeness_ratio:float, missing_components:list<string>, reasons:list<string>} computed purely from inputs; zero ctor deps, no IO/hash/clock/static state, <=110 LOC, no edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=AtlasSelfConstructionFinalEvidenceReplayService.php:23] | Returns schema_version literal 'atlas.evidence.replay_completeness.v1'. Clamp chainLength/chainGapCount/brokenRefCount to >=0 via max(0,n); missing_components = required absent from present (sort asc, array_values); completeness_ratio = requiredComponents empty ? 1.0 : round((count(required)-count(missing))/count(required),2). reasons accumulates every fired condition in order: R1 brokenRefCount>0->'broken_evidence_refs'; R2 chainGapCount>0 OR chainLength<=0->'hash_chain_discontinuous'; R3 missing_components non-empty->'required_components_missing'. verdict tier: T1 (R1 OR R2)->incomplete_unreplayable & replay_green=false; T2 (only R3)->incomplete_missing_components & replay_green=false; T3 (no reason AND ratio===1.0)->complete & replay_green=true & reasons===[]; T4 fall-through->incomplete_missing_components & replay_green=false. replay_green true ONLY in T3. Paired test extends Tests\\TestCase (no DB) with 5 computed asserts (cases 1-5 incl. case 4 verifying chain veto wins verdict while all 3 reasons recorded, and case 5 empty-required ratio===1.0 complete + schema_version literal). New test passes. | DoD: both files created; `vendor/bin/phpunit tests/Unit/Ai/Evidence/Replay/EvidenceReplayCompletenessVerdictTest.php` green; final class & correct namespace; zero ctor deps & no IO/hash/clock/static state; <=110 LOC; no existing files edited; schema_version literal present; replay_green true only in T3; 5 computed asserts incl. broken-ref veto + empty-required ratio===1.0 proofs; prohibited words absent from code and test. |

## 10. Sequenciamento

S241 -> S248 sao fatias atomicas raiz de pura-logica, sem dependencias, prontas para execucao imediata e paralela.
