---
id: atlas-aaeos-deep-cores-leap-backlog
type: engineering_knowledge
title: AAEOS Deep-Cores Atomic Leap Backlog (completeness-critic wave)
doc_schema: atlas_canonical_module_doc.v1
status: planned
implementation_state: backlog_only_no_runtime
authority_class: backlog
category: agentic-engineering
priority: 93
summary: Atomic single-decision new-class pure-logic slices for the loop, mined from canonical AAEOS docs/code and adversarially filtered against scaffold + complexity. Each creates ONE new dependency-free class with ONE method computing a real decision from inputs, paired test with meaningful assertions. Decompose-ready; the loop one-shots these without I/O or existing-class edits.
owner: operator (Vitor)
risk_level: medium
tags:
  - atlas-ai
  - aaeos
  - deep-cores
  - stewardship-loop
  - backlog
capabilities:
  - loop_ready_atomic_slice_backlog
  - deep_core_decision_slices
  - completeness_critic_slices
decisions:
  - Backlog rows are planned execution candidates, not runtime proof.
  - Deep-core rows must stay atomic and dependency-free unless decomposed further.
  - Each loop-ready row must include a meaningful paired test.
maintenance:
  - Keep rows source-anchored, atomic and executable without provider discovery.
  - Run docs-health and a plan-only decompose check after changing this file.
  - Update the backlog index count when rows are added, removed or moved.
related_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-evolution-backlog-index.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
graph_id: atlas-aaeos-deep-cores-leap-backlog
graph_title: AAEOS Deep Cores Atomic Leap Backlog
graph_world: atlas
graph_layer: module
graph_kind: index
graph_parent: atlas-aaeos-evolution-backlog-index
graph_status: planned
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-deep-cores-leap-backlog.md
allowed_changes:
  - Add or refine atomic deep-core slices with exact target files, acceptance criteria and tests.
  - Move ambiguous rows out of loop-ready section until they are decomposed.
forbidden_changes:
  - Do NOT treat backlog rows as delivered runtime.
  - Do NOT count a slice without merged code, green validation and evidence receipts.
  - Do NOT fabricate items; every slice traces to a real source line.
depends_on:
  - atlas-aaeos-evolution-backlog-index
flows_to:
  - atlas-software-company-stewardship-stack
unlocks:
  - deep_core_atomic_slice_supply
  - completeness_critic_atomic_work
governs:
  - stewardship_loop.plan_backlog
evidence:
  - docs/engineering-knowledge-base/atlas-aaeos-deep-cores-leap-backlog.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:plan-execution:run --doc=docs/engineering-knowledge-base/atlas-aaeos-deep-cores-leap-backlog.md --json
requires_evidence: true
next_actions:
  - Let AP-790 consume this doc only through governed plan backlog selection and owner-flow execution.
---

## Resumo

Backlog atomico de saltos N×M para o loop autonomo. Cada fatia = UMA classe nova, UM metodo, UMA decisao pura computada dos inputs, com teste significativo. Areas: Forge, Dev runtime, Evidence, Provider-routing, Gates, Self-Construction, Mission.

## Papel no Atlas

Este documento abastece o Stewardship Loop com slices de deep-core e completeness critic que aumentam a capacidade interna da fabrica AAEOS. Ele e backlog planejado; entrega real exige codigo, teste, judge, evidence e merge honesto.

## Onde Se Encaixa

```text
atlas-software-company-stewardship-stack
  +-- atlas-aaeos-evolution-backlog-index
      +-- atlas-aaeos-deep-cores-leap-backlog
```

## Contratos

- Cada row loop-ready deve apontar um arquivo novo, um teste pareado e regras computadas.
- O decomposer deve derivar somente o arquivo novo e o teste declarado ou convencional.
- Provider, branch, validation, judge e merge continuam governados pelo fluxo AP-786/AP-790.

## Fluxo

O loop escolhe uma row ready, transforma em slice de baixo risco, executa owner-flow em branch/worktree isolado, valida, julga, repara se couber no budget e so conta merge quando `main_before != main_after`.

## Regras para IA

- Nao confundir backlog com runtime implementado.
- Nao editar servicos existentes quando a row pede uma nova classe pura.
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

- Deep-core virar refactor amplo em vez de uma decisao pura.
- Path de contexto ser confundido com arquivo editavel.
- Status ready ser tratado como entrega sem runtime real.

## Exemplos

Uma row boa cria uma classe pura de scoring ou classificacao, com pesos declarados e teste que prova limites e tie-breaks.

## Proximas Acoes

- Consumir este backlog em rungs curtos antes de runs longos.
- Rebaixar qualquer row que gere diff sem logica, teste fraco ou escopo maior que o declarado.

## 6. Decomposicao em slices ordenados

| ID | Item | Aceite | DoD |
| --- | --- | --- | --- |

| S201 | Create a new PHP class MemoryConflictVerbClassifier at app/Services/Ai/Memory/MemoryConflictVerbClassifier.php exposing `public function classify(array $a, array $b): array` returning {schema_version:'atlas.memory.conflict_verb.v1', verdict, escalate, reason}. Pure class, zero ctor deps, no clock/I/O/DB; each input is {key,scope_type,polarity:'affirm'/'negate',recorded_ts:int,memory_type}. Apply <=7 ordered rules computed from inputs: (1) keys differ -> not_conflict; (2) same key + different scope_type -> scoped; (3) same key+scope+same polarity -> compatible; (4) same key+scope+opposite polarity+one side strictly-newer recorded_ts -> supersedes (newer wins); (5) same key+scope+opposite polarity+equal ts -> conflicts_with; (6) same key but a side has missing/empty scope_type -> related. escalate=true iff verdict in {conflicts_with,supersedes} AND memory_type in {decision,architecture,policy} (mirror AtlasMemoryConflictResolutionService HIGH_RISK_MEMORY_TYPES + VISIBLE_VERDICTS); equal-ts conflicts_with always escalates. reason names the firing rule; schema_version literal. No edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=AtlasMemoryConflictResolutionService.php:299] | Schema {schema_version,verdict,escalate,reason} with verdict in {related,compatible,scoped,conflicts_with,supersedes,not_conflict}; schema_version literal 'atlas.memory.conflict_verb.v1'. Paired test (Tests\Unit\Ai, extends Tests\TestCase, no DB) reads computed verdict/escalate/reason from the returned array (never equality to a constant table) with >=5 computed asserts: different keys -> not_conflict & escalate=false; same key + cross scope_type -> scoped; opposite polarity newer-wins -> supersedes & escalate=true for memory_type 'decision'; equal-ts opposite polarity -> conflicts_with & escalate=true; same key+scope+same polarity -> compatible & escalate=false. New test passes. | DoD: file created at stated path, paired test green, zero ctor deps, no I/O/clock/DB, no existing files edited, prohibited words absent. |
| S202 | Create a new PHP class CompactionLossRiskClassifier at app/Services/Ai/ContextIntelligence/CompactionLossRiskClassifier.php exposing `public function classify(float $mustKeepCoverage, bool $touchedCriticalKind, int $forcedDiscardCount): array` returning {schema_version:'atlas.context_intelligence.compaction_loss_risk.v1', loss_risk, write_allowed, reasons}. Pure class, zero ctor deps, no I/O. Compute via 5 rules mirroring AiCompactionService::deriveLossRisk + write gate: (1) touchedCriticalKind OR coverage<0.85 -> high; (2) else coverage<1.0 OR forcedDiscardCount>0 -> medium; (3) else low; (4) write_allowed = (coverage>=1.0 AND NOT touchedCriticalKind); (5) reasons lists each fired condition from {touched_critical_keep_kind,coverage_below_0_85,coverage_below_1_0,forced_discards_present}. No edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=AiCompactionService.php:601] | Schema {schema_version,loss_risk in {low,medium,high},write_allowed:bool,reasons:list<string>}; schema_version literal. Paired test (tests/Feature/Ai/ContextIntelligence) with 5 computed asserts: coverage=1.0,no-critical,0-discards -> low & write_allowed=true & reasons=[]; coverage=0.9,critical-touched,0-discards -> high & write_allowed=false & reasons contains 'touched_critical_keep_kind'; coverage=0.95,no-critical,1-discard -> medium & write_allowed=false & reasons contains 'coverage_below_1_0' AND 'forced_discards_present'; coverage=0.8,no-critical,0-discards -> high & reasons contains 'coverage_below_0_85'; coverage=0.85 boundary -> medium (NOT high) confirming strict <0.85 cutoff. New test passes. | DoD: file created at stated path, paired test green, pure (zero ctor deps, no I/O), boundary 0.85 proven, no existing files edited, prohibited words absent. |
| S203 | Create a new PHP class MemoryQualityStatusBandClassifier at app/Services/Ai/MemoryGovernance/MemoryQualityStatusBandClassifier.php exposing `public function classify(int $activeCount, int $compositeScore, bool $hasCriticalIssue): array` returning {schema_version:'atlas.memory_governance.quality_status_band.v1', status, ok, injection_allowed, reason}. Pure class, zero ctor deps, no I/O. Apply <=6 ordered rules mirroring AtlasMemoryQualityService::status: (1) activeCount<1 -> empty; (2) hasCriticalIssue OR compositeScore<50 -> critical; (3) compositeScore<70 -> needs_review; (4) compositeScore<85 -> watch; (5) else ready. Derive ok = status NOT in {empty,critical}; injection_allowed = status in {watch,ready} (fail-closed otherwise). reason names firing rule. No edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=AtlasMemoryQualityService.php:628] | Schema {schema_version,status in {empty,critical,needs_review,watch,ready},ok:bool,injection_allowed:bool,reason}; schema_version literal. Paired PHPUnit test (extends Tests\TestCase) with >=5 computed asserts: activeCount=0,score=90 -> empty & ok=false & injection_allowed=false; activeCount=10,score=92,no-critical -> ready & ok=true & injection_allowed=true; activeCount=10,score=92,critical=true -> critical (overrides high score) & injection_allowed=false; activeCount=10,score=72 -> watch & injection_allowed=true; activeCount=10,score=60 -> needs_review & ok=true & injection_allowed=false (proves ok != injection_allowed); activeCount=10,score=49 -> critical. New test passes. | DoD: file created at stated path, paired test green, pure, critical-override and ok!=injection_allowed proven, no existing files edited, prohibited words absent. |
| S204 | Create a new PHP class LocalPrereasoningEligibilityClassifier at app/Services/Ai/Context/LocalPrereasoningEligibilityClassifier.php exposing `public function classify(string $taskType, int $estimatedTokens): array` returning {schema_version:'atlas.token_economy.local_prereasoning_eligibility.v1', can_resolve_locally, provider_call_avoidable, allowed_operations, saved_tokens_estimate, reason}. Pure class, zero ctor deps, no I/O/clock. Rules mirror AtlasTokenEconomyRuntimeService::localPrereasoning: (R1) strtolower(taskType) in {count,diff,parse,classify,validate} -> can_resolve_locally=true AND provider_call_avoidable=true; (R2) else both false; (R3) saved_tokens_estimate = can_resolve ? max(500,estimatedTokens) : 0; (R4) allowed_operations always ['diff','count','parse','validate','hash']; (R5) empty/whitespace/unknown taskType -> normalized 'general', not avoidable; (R6) reason = can_resolve ? 'local_prereasoning_can_resolve' : 'requires_provider_reasoning'. No edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=AtlasTokenEconomyRuntimeService.php:143] | Schema as above; schema_version literal. Paired test (Tests\Unit\Services\Ai\Context) with >=5 computed asserts: 'count' -> can_resolve_locally===true && provider_call_avoidable===true && saved_tokens_estimate>=500; 'refactor' -> both false && saved_tokens_estimate===0; classify('diff',200) -> saved_tokens_estimate===500 (floor clamp); '' and 'unknown' -> provider_call_avoidable===false && reason==='requires_provider_reasoning'; allowed_operations===['diff','count','parse','validate','hash'] on every branch. New test passes. | DoD: file created at stated path, paired test green, pure, floor-clamp and 'general' normalization proven, no existing files edited, prohibited words absent. |
| S205 | Create a new PHP class AucriTokenQualityVerdict at app/Services/Ai/Context/Aucri/AucriTokenQualityVerdict.php (namespace App\Services\Ai\Context\Aucri, final) exposing `public function decide(int $inputTokensBefore, int $inputTokensAfter, float $qualityBefore, float $qualityAfter, float $mustKeepCoverage, float $evidenceCoverageBefore, float $evidenceCoverageAfter): array` returning {schema_version:'atlas.aucri.token_quality_verdict.v1', verdict, token_reduction_ratio, reasons}. Final class, zero ctor deps, no I/O. Implements AUCRI signature law (promote only if tokens fall without quality loss). Rule order: (1) after>=before -> no_change + ['no_token_reduction'], ratio via rule 5; (2) mustKeepCoverage<1.0 -> revert + 'must_keep_below_1_0' (hard veto); (3) qualityAfter<qualityBefore-1e-9 -> revert + 'quality_regressed'; (4) evidenceCoverageAfter<evidenceCoverageBefore-1e-9 -> revert + 'evidence_coverage_regressed'; (5) token_reduction_ratio = round((before-after)/max(1,before),3); (6) no veto AND ratio>0 AND quality/evidence not worse -> promote + 'safe_token_reduction'; (7) any veto forces revert regardless of reduction (vetoes 2-4 accumulate, must_keep listed first). Vetoes evaluated even when tokens dropped; reasons non-empty for promote/revert. <=110 LOC. No edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=atlas-aucri-continuous-optimization-protocol.md:122] | Schema {schema_version,verdict in {promote,revert,no_change},token_reduction_ratio:float(0..1 round 3),reasons:list}; schema_version literal. Paired PHPUnit test (Tests\Unit\Services\Ai\Context\Aucri) with >=4 computed asserts that COMPUTE the expected ratio from inputs (not hardcoded): tokens dropped + all gates pass -> ['promote', ratio==round((before-after)/before,3)>0, 'safe_token_reduction']; tokens dropped but mustKeepCoverage=0.9 -> ['revert','must_keep_below_1_0']; tokens dropped but qualityAfter<qualityBefore -> ['revert','quality_regressed']; after>=before -> ['no_change','no_token_reduction'] with ratio==0.0; tokens dropped but evidenceCoverageAfter<before -> ['revert','evidence_coverage_regressed']. New test passes. | DoD: file created at stated path, final class, paired test green, zero ctor deps, ratio computed-from-formula in tests, false-saving rejection proven, no existing files edited, prohibited words absent. |
| S206 | Create a new PHP class AemorFalseLearningGateEvaluator at app/Services/Ai/Aemor/Judgment/AemorFalseLearningGateEvaluator.php exposing `public function evaluate(bool $hasEvidenceRefs, string $outcomeStatus, ?bool $testsPassed, int $alternativeExplanationCount, bool $attributionReviewed): array` returning {schema_version:'atlas.aemor.false_learning_gate.v1', status, learning_allowed, blockers}. Pure class, zero ctor deps, no I/O; computed from primitives (no AtlasAemorOutcome/data_get coupling). Rules: R1 !hasEvidenceRefs -> blockers[]='missing_evidence_refs'; R2 outcomeStatus==='succeeded' && testsPassed!==true -> blockers[]='success_without_test_or_gate_evidence'; R3 alternativeExplanationCount>0 && attributionReviewed!==true -> blockers[]='alternative_explanations_not_reviewed'; R4 status = blockers===[] ? 'pass' : 'blocked_for_learning'; R5 learning_allowed = blockers===[]. Output must match the source kernel verdict for equivalent inputs. No edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=AtlasAemorJudgmentService.php:195] | Schema {schema_version,status in {pass,blocked_for_learning},learning_allowed:bool,blockers:list}; schema_version literal. Paired test (tests/Feature/Ai/Aemor/Judgment) asserts: (true,'succeeded',true,0,false) -> status='pass' & learning_allowed=true & blockers=[]; (true,'succeeded',null,0,false) -> blockers contains 'success_without_test_or_gate_evidence' & learning_allowed=false; (false,'failed',false,2,false) -> blockers contains both 'missing_evidence_refs' and 'alternative_explanations_not_reviewed' (exactly 2) & status='blocked_for_learning'; (true,'failed',false,0,true) -> 'success_without_test_or_gate_evidence' absent & status='pass'; schema_version==='atlas.aemor.false_learning_gate.v1'. New test passes. | DoD: file created at stated path, paired test green, pure (computed from primitives, no outcome/data_get coupling), parity with source kernel, no existing files edited, prohibited words absent. |
| S207 | Create a new PHP class ScopeContractFeasibilityClassifier at app/Services/Ai/Programming/AtlasDev/Gate/ScopeContractFeasibilityClassifier.php (namespace App\Services\Ai\Programming\AtlasDev\Gate) exposing `public function classify(array $allowedFiles, array $forbiddenFiles, int $maxFilesChanged): array` returning array{schema_version:'atlas.programming.scope_contract_feasibility.v1', verdict, dead_allowed_files, reason, satisfiable_allowed_count}. PURE, zero ctor deps, no I/O, no static verdict tables. A private inline matcher MIRRORS ScopeGuard::matchesAny exactly: forbidden pattern catches a file when (pattern===file) OR (str_contains(pattern,'*') && fnmatch(pattern,file,FNM_NOESCAPE)) OR (str_ends_with(pattern,'/') && str_starts_with(file,pattern)). Do NOT import/call ScopeGuard; do NOT use array_intersect. Live universe = non-empty allowed files (empty-string entries excluded). dead_allowed_files = non-empty allowed files caught by any forbidden pattern, sorted asc, re-indexed. satisfiable_allowed_count = count(non-empty allowed) - count(dead). First-match-wins rules: R1 allowed non-empty AND every non-empty allowed file dead -> 'unsatisfiable' (reason names R1); R2 some-but-not-all dead -> 'degraded' (reason names R2); R3 maxFilesChanged>0 AND satisfiable_allowed_count>maxFilesChanged -> 'degraded' (reason names R3); R4 allowedFiles has no non-empty target -> 'unsatisfiable' (reason names R4); R5 else 'feasible' with dead_allowed_files===[]. No existing files edited. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=PromptSections.php:92] | Schema {schema_version,verdict in {feasible,unsatisfiable,degraded},dead_allowed_files:list,reason,satisfiable_allowed_count:int}; schema_version literal. Paired PHPUnit test (Tests\Unit\Ai\Programming\AtlasDev\Gate, extends TestCase) with 5 computed asserts: allowed=['app/Services/Foo.php'],forbidden=['app/Services/*'],max=0 -> 'unsatisfiable' & dead_allowed_files===['app/Services/Foo.php'] (glob via fnmatch catches it where array_intersect misses); allowed=['app/Services/Foo.php','app/Models/Bar.php'],forbidden=['app/Services/'],max=0 -> 'degraded' & dead_allowed_files===['app/Services/Foo.php'] & satisfiable_allowed_count===1 (prefix str_starts_with); allowed=['a.php','b.php','c.php'],forbidden=[],max=2 -> 'degraded' (satisfiable_allowed_count===3 > cap 2); allowed=['app/X.php'],forbidden=['app/Other.php'],max=0 -> 'feasible' & dead_allowed_files===[]; allowed=[],forbidden=['x'],max=0 -> 'unsatisfiable'. New test passes. | DoD: file created at stated path, paired test green, pure (inline matcher mirrors ScopeGuard, no import/array_intersect), false-negative-closes proven, no existing files edited, prohibited words absent. |
| S208 | Create a new PHP class TestSelectionCoverageGapScorer at app/Services/Ai/Programming/AtlasDev/Intelligence/TestSelectionCoverageGapScorer.php (final) exposing `public function score(array $changedFiles, array $coveredFiles, string $riskLevel): array` returning array{schema_version:'atlas.programming.test_coverage_gap.v1', uncovered_production_files, production_file_count, covered_count, coverage_ratio, gap_band, confidence_ceiling}. PURE, final class, zero ctor deps, no I/O (no is_file/filesystem/artisan). Production file = path NOT str_starts_with('tests/'); uncovered = production files absent from coveredFiles, sorted asc. Rules: R1 production_file_count===0 -> coverage_ratio 1.0 + gap_band 'none'; R2 coverage_ratio = round(covered_production/production_file_count,2); R3 ratio===1.0 -> 'none'; R4 0.0<ratio<1.0 -> 'partial'; R5 ratio===0.0 with >=1 production file -> 'severe'; R6 confidence_ceiling: 'severe'->'low', 'partial'->'medium', 'none' AND riskLevel in ['R4','R5','high','critical']->'medium', else 'high'. covered_count = covered_production count. No existing files edited. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=TestSelectionIntelligenceService.php:105] | Schema {schema_version,uncovered_production_files:list(sorted asc),production_file_count:int,covered_count:int,coverage_ratio:float(round 2dp; 1.0 when count===0),gap_band in {none,partial,severe},confidence_ceiling in {high,medium,low}}; schema_version literal. Paired test (Tests\Unit\Ai\Programming\AtlasDev\Intelligence) with 5 computed asserts: changed=['app/A.php','app/B.php'],covered=['app/A.php'] -> coverage_ratio===0.5 & gap_band==='partial' & uncovered_production_files===['app/B.php'] & confidence_ceiling==='medium'; changed=['app/A.php'],covered=[] -> ratio===0.0 & 'severe' & ceiling 'low'; changed=['app/A.php'],covered=['app/A.php'],risk 'R2' -> ratio===1.0 & 'none' & ceiling 'high'; same with risk 'R5' -> 'none' yet ceiling==='medium'; changed=['tests/FooTest.php'],covered=[] -> ratio===1.0 & 'none' & uncovered_production_files===[]. New test passes. | DoD: file created at stated path, final class, paired test green, pure (no filesystem), risk-aware ceiling and tests/-exclusion proven, no existing files edited, prohibited words absent. |
| S209 | Create a new PHP class ReceiptProviderAuthorizationDecision at app/Services/Ai/Kernel/Decision/ReceiptProviderAuthorizationDecision.php exposing `public function authorize(array $providerSelection, string $runtimeProvider, ?string $runtimeStage): array` returning {schema_version:'atlas.decide.receipt_provider_authorization.v1', authorized, code, expected_provider, matched_via, fallbacks_considered}. Pure class, zero ctor deps, no I/O/facade/data_get; reads providerSelection.primary (trim->null) and .fallbacks[]. Evaluate 7 ordered rules: R1 trimmed primary empty/null OR trimmed runtimeProvider empty -> {authorized:false,'provider_mismatch',expected_provider:null} (fail-closed, expected never bound); R2 expected===runtime -> {true,'exact_match',matched_via:'primary'}; R3 expected in {auto,selected-by-decide} -> {true,'wildcard_auto','wildcard'}; R4 expected==='claude_codex' AND runtime in {claude_codex,claude_cli,codex_cli} -> {true,'family_match','family'}; R5 runtimeStage==='context_scout' AND runtime==='gemini_cli' -> {true,'stage_exception','stage'}; R6 runtime in normalized fallbacks -> {true,'fallback_match','fallback'}; R7 else {false,'provider_mismatch'}. fallbacks_considered always = normalized (trim+drop-empty) fallbacks; expected_provider bound to trimmed primary on R2-R7. No edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=DecisionReceiptRuntimeGuard.php:277] | Schema {schema_version,authorized:bool,code in {exact_match,wildcard_auto,family_match,stage_exception,fallback_match,provider_mismatch},expected_provider:?string,matched_via:?string,fallbacks_considered:list}; schema_version literal. Paired test (Tests\Unit\Ai\Kernel\Decision) with >=5 computed asserts: exact_match true; family_match for claude_codex->codex_cli true; stage_exception only under context_scout (gemini_cli outside scout -> provider_mismatch); fallback_match true with matched_via='fallback' and runtime present in fallbacks_considered; empty primary -> fail-closed provider_mismatch with expected_provider===null. New test passes. | DoD: file created at stated path, paired test green, pure (no facade/data_get), fail-closed R1 short-circuit and family/stage/fallback paths proven, no existing files edited, prohibited words absent. |

## 10. Sequenciamento

S201 -> S209 sao fatias atomicas raiz, sem dependencias, prontas para execucao imediata.

## 11. Needs finer atomic decomposition (NOT loop-consumed)

| ID | Item | Aceite | DoD |
| --- | --- | --- | --- |
| S291 | Create a new PHP class ForgeProviderCooldownAdmissionDecider at app/Services/Ai/Programming/ForgeTopology/ForgeProviderCooldownAdmissionDecider.php exposing `public function decide(string $failureType, int $elapsedSeconds): array` returning array{schema_version:'atlas.forge.provider_cooldown_admission.v1', failure_type, cooldown_seconds, elapsed_seconds, admit, remaining_seconds, reason}. Pure, zero ctor deps, no I/O, no Carbon. Private const window map: rate_limit=60, quota_exhausted=600, timeout=30, model_unavailable=120, provider_error=30, provider_capacity_exhausted=900, auth_failed=-1 (never-readmit sentinel). Clamp elapsedSeconds to >=0 before comparison. Rules: (a) unknown failureType -> cooldown_seconds=0, admit=false, remaining_seconds=0, reason='unknown_failure_blocks' (fail-closed); (b) auth_failed (-1) -> admit=false, remaining_seconds=PHP_INT_MAX, reason='still_cooling_down'; (c) window>=0 -> admit = elapsed>=window, remaining_seconds = max(0,window-elapsed), reason = admit ? 'cooldown_elapsed' : 'still_cooling_down'. cooldown_seconds echoes resolved window (-1 surfaced as-is for auth_failed); failure_type and clamped elapsed_seconds echoed. No edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=AtlasForgeProviderFallbackPolicyService.php:324] | Schema as above; schema_version literal. Paired test (Tests\Unit\Services\Ai\Programming\ForgeTopology) with 5 computed asserts: rate_limit/61 -> admit=true, reason=cooldown_elapsed, remaining_seconds=0; rate_limit/10 -> admit=false, remaining_seconds=50, reason=still_cooling_down; provider_capacity_exhausted/900 -> admit=true AND /899 -> admit=false, remaining_seconds=1; auth_failed/99999 -> admit=false, reason=still_cooling_down, remaining_seconds=PHP_INT_MAX; 'banana'/500 -> admit=false, reason=unknown_failure_blocks, cooldown_seconds=0, remaining_seconds=0. Verify: php artisan test tests/Unit/Services/Ai/Programming/ForgeTopology/ForgeProviderCooldownAdmissionDeciderTest.php. New test passes. | DoD: file created at stated path, paired test green, pure (no Carbon/I/O), fail-closed + never-readmit sentinel proven, clamp applied, no existing files edited, prohibited words absent. |
