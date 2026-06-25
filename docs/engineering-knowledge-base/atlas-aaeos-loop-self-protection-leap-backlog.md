---
id: atlas-aaeos-loop-self-protection-leap-backlog
title: AAEOS Loop Self-Protection Atomic Leap Backlog (pre-spend contract + diff-safety + atomicity)
doc_schema: atlas_canonical_module_doc.v1
type: engineering_knowledge
status: planned
implementation_state: backlog_only_no_runtime
authority_class: backlog
category: agentic-engineering
priority: 98
summary: Atomic single-decision new-class pure-logic slices for the loop, mined from canonical AAEOS docs/code and adversarially filtered against scaffold + complexity. Each creates ONE new dependency-free class with ONE method computing a real decision from inputs, paired test with meaningful assertions. Decompose-ready; the loop one-shots these without I/O or existing-class edits.
owner: operator (Vitor)
risk_level: medium
tags:
  - atlas-ai
  - aaeos
  - stewardship-loop
  - self-protection
  - pre-spend
capabilities:
  - loop_self_protection_backlog
  - pre_spend_contract_quality
  - destructive_diff_safety
  - one_shot_feasibility_scoring
decisions:
  - This file is a backlog source, not delivered runtime.
  - Each ready slice must remain one pure class plus one paired meaningful unit test.
  - The loop should consume these slices before spending provider on broad or destructive work.
maintenance:
  - Keep every Section 6 row atomic, decompose-ready, and free of existing-file edits.
  - Move broad or integration-heavy ideas to Section 11 until decomposed.
  - Re-run plan decompose and docs-health after structural edits.
related_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-evolution-backlog-index.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
graph_id: atlas-aaeos-loop-self-protection-leap-backlog
graph_title: AAEOS Loop Self-Protection Atomic Leap Backlog
graph_world: atlas
graph_layer: module
graph_kind: index
graph_parent: atlas-aaeos-evolution-backlog-index
graph_status: planned
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-aaeos-loop-self-protection-leap-backlog.md
allowed_changes:
  - Add or refine atomic loop self-protection slices with explicit target class and test.
  - Tighten acceptance criteria when decompose or runtime evidence finds ambiguity.
forbidden_changes:
  - Do NOT treat backlog rows as delivered runtime.
  - Do NOT fabricate items; every slice traces to a real source line.
  - Do NOT add provider calls, git operations, controllers, or existing-class wiring to this doc.
depends_on:
  - atlas-aaeos-evolution-backlog-index
flows_to:
  - atlas-software-company-stewardship-stack
unlocks:
  - higher_loop_utilization_pre_spend_filtering
  - safer_provider_diff_acceptance
governs:
  - stewardship_loop.plan_backlog
evidence:
  - docs/engineering-knowledge-base/atlas-aaeos-loop-self-protection-leap-backlog.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
  - php artisan atlas:plan-execution:decompose --doc=docs/engineering-knowledge-base/atlas-aaeos-loop-self-protection-leap-backlog.md --json
requires_evidence: true
next_actions:
  - Let AP-790 consume these rows through governed plan backlog selection and owner-flow execution.
---

> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` e `docs/engineering-knowledge-base/atlas-autonomous-engineering-government.md`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. Na arquitetura final, o Loop é o **Autopoiesis / Evolution Engine** dentro do `Atlas Autonomous Engineering Government`, não o OS inteiro. O alvo 24/7 é o Government evoluir Atlas e project lanes via Task Fabric, Maestro, Verification Court, Merge Governor, Receipts e Knowledge Sync.


## Resumo

Backlog atomico de saltos N×M para o loop autonomo. Cada fatia = UMA classe nova, UM metodo, UMA decisao pura computada dos inputs, com teste significativo. Areas: Forge, Dev runtime, Evidence, Provider-routing, Gates, Self-Construction, Mission.

## Papel no Atlas

Reduzir provider burn ruim antes do gasto: contrato fraco, slice amplo demais, remocao destrutiva de teste, superficie de mudanca ampla e diff perigoso devem virar bloqueio honesto ou prioridade menor antes do owner-flow gastar provider.

## Onde Se Encaixa

```text
atlas-aaeos-evolution-backlog-index
  +-- atlas-aaeos-loop-self-protection-leap-backlog
      +-- AP-790 plan backlog bridge
```

## Contratos

- Cada row pronta cria uma classe nova, final, pura, sem constructor deps, sem I/O e sem provider.
- Cada row inclui teste pareado com assercoes computadas e fronteiras.
- Nenhuma row promete wiring runtime; ela entrega o kernel que torna o wiring posterior menor e julgavel.

## Fluxo

O loop seleciona uma row pronta, cria a classe e o teste, valida, julga, repara se necessario e mergeia apenas quando a governanca permitir e main avancar.

## Regras para IA

- Nao editar classes existentes a partir deste doc.
- Nao chamar provider externo dentro das classes geradas.
- Nao usar scaffold ou teste shape-only.
- Consultar `atlas-canonical-glossary-and-naming.md` quando usar termos Atlas Dev, Forge ou AAEOS.

## Escopo de Implementacao

Somente novos kernels PHP e testes pareados nos paths declarados. Toda integracao multi-arquivo fica fora deste doc.

## Dependencias

- AP-790 Reliable 24h Loop Runner.
- AP-786 owner-flow.
- Atlas Dev provider execution gates.

## Evidencias

- Decompose completo deste doc.
- Testes pareados gerados por cada slice quando executado.
- Receipts do AP-790/AP-786 para cada entrega real.

## Riscos

- Confundir kernel puro com protecao runtime ja instalada.
- Aceitar row de self-protection que edita gate existente antes de existir teste isolado.
- Transformar filtro pre-spend em bloqueio amplo sem razao auditavel.

## Exemplos

Uma row segura transforma "slice one-shot feasibility" em um avaliador puro que pontua tamanho, regras, dependencias e arquivos permitidos sem ler git, filesystem ou provider.

## Proximas Acoes

- Consumir este doc junto dos outros backlogs atomicos.
- Criar child slices de wiring somente depois que o kernel correspondente existir e estiver testado.

## 6. Decomposicao em slices ordenados

| ID | Item | Aceite | DoD |
| --- | --- | --- | --- |

| S261 | Create a new PHP class SliceOneShotFeasibilityScorer at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/SliceOneShotFeasibilityScorer.php (namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop) with ONE pure method `public function score(int $estimatedLoc, int $ruleCount, int $declaredDependencyCount, int $allowedFileCount, bool $isNewFileSlice): array` returning `array{schema_version:'atlas.software_company_stewardship.slice_one_shot_feasibility.v1', one_shot_able:bool, feasibility_band:'one_shot'/'tight'/'multi_shot', score:float, spend_allowed:bool, reasons:list<string>}`. final class, zero ctor deps, no I/O/clock/DB/git/provider — caller passes all size estimates IN; identical args always yield identical output. <=7 ordered rules: (0) clamp every int to >=0; (R1) estimatedLoc>180 OR ruleCount>9 OR declaredDependencyCount>2 OR allowedFileCount>FindingSlicePlannerService::MAX_FILES_PER_SLICE => band='multi_shot', one_shot_able=false; (R2) else estimatedLoc>110 OR ruleCount>6 OR allowedFileCount>2 => band='tight', one_shot_able=true; (R3) else band='one_shot'; (R4) score=round(1-max(0.0,min(1.0,(estimatedLoc/200+ruleCount/10+declaredDependencyCount/3+allowedFileCount/5)/4)),2); (R5) isNewFileSlice relaxes exactly one tier — a slice that would land 'tight' under R2 from the file/loc tier is promoted to 'one_shot' (mirrors ZeroProviderPreflightGate subject_exists=false admit path at :209), a 'multi_shot' boundary driven only by allowedFileCount/loc is NOT relaxed; (R6) spend_allowed=one_shot_able; (R7) reasons ordered list from {loc_over_budget,rule_count_over_budget,too_many_dependencies,too_many_files,within_one_shot_budget} (within_one_shot_budget only when band='one_shot' and no over-budget trigger fired). Reference MAX_FILES_PER_SLICE via FindingSlicePlannerService::MAX_FILES_PER_SLICE not magic 4. No edits to existing code; do NOT wire into AutonomousEvolutionSessionService or ZeroProviderPreflightGate. [area=aaeos route=atlas_dev r_level=R2 north_star=false status=ready src=ZeroProviderPreflightGate.php:216] | schema_version constant === 'atlas.software_company_stewardship.slice_one_shot_feasibility.v1'. Paired PHPUnit test at tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/SliceOneShotFeasibilityScorerTest.php (namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop extends Tests\TestCase, NO DB trait) with >=5 computed asserts (never assert vs a hardcoded constant lookup table): score(40,3,0,2,true) => one_shot_able===true AND feasibility_band==='one_shot' AND spend_allowed===true; score(260,12,4,5,false) => one_shot_able===false AND feasibility_band==='multi_shot' AND in_array('loc_over_budget',reasons,true) AND in_array('rule_count_over_budget',reasons,true); score(130,5,0,2,false) => feasibility_band==='tight' AND one_shot_able===true; score(130,5,0,2,true)['feasibility_band']==='one_shot' while score(130,5,0,2,false)['feasibility_band']==='tight' for identical numbers (proves R5 flips exactly one tier); score(0,0,0,1,true)['score'] (~0.95) strictly greater than score(200,10,3,5,false)['score'] (proves monotonic decrease). | DoD: file + paired test created; `php artisan test --filter=SliceOneShotFeasibilityScorerTest` green; class is final, pure (zero ctor deps, no I/O); references FindingSlicePlannerService::MAX_FILES_PER_SLICE; no existing files edited; prohibited words (Jarvis/Rivals/benchmark/superiority/concurrent) absent from class and test. |
| S262 | Create a new PHP class DestructiveTestCoverageRemovalContract at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/DestructiveTestCoverageRemovalContract.php (namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop) using the contract seam (private __construct + `public static function defaults(): self` + `public static function fromArray(array $input): self` with int>=0 keys test_insertions/test_deletions/product_insertions/product_deletions/test_files_deleted_count + instance `public function toArray(): array`). toArray() returns `array{schema_version:'atlas.software_company_stewardship.destructive_test_coverage_removal.v1', contract_id:'destructive_test_coverage_removal', quality_bar_matrix_canonical:string, trivial_product_change_floor:int=2, inputs:{...5 ints}, outputs:{verdict:'coverage_removed'/'coverage_preserved', coverage_removed:bool, whole_test_file_deleted_with_no_product_change:bool, net_test_lines_dropped_without_product_growth:bool, test_cut_masked_by_trivial_product_edit:bool, trigger_reasons:list<string>}}`. Pure: zero ctor args usable as inputs, no I/O, every output field derived from inputs, all inputs clamped via max(0,(int)). verdict='coverage_removed' when ANY of: (a) test_files_deleted_count>0 AND product_insertions==0 AND product_deletions==0; (b) test_deletions>test_insertions AND product_insertions==0; (c) test_deletions>0 AND (product_insertions+product_deletions)<=trivial_product_change_floor(2) AND product_insertions+product_deletions>0. Else verdict='coverage_preserved'. trigger_reasons lists exactly fired rule keys (whole_test_file_deleted_with_no_product_change / net_test_lines_dropped_without_product_growth / test_cut_masked_by_trivial_product_edit). Catches the sub-threshold gap below the shipped large_test_deletion rule (fires only deletions>=80 && ratio>=2.0 at AtlasMinimaxFirstWorkerService.php:407). No edits to existing code. [area=aaeos route=atlas_dev r_level=R2 north_star=false status=ready src=AtlasMinimaxFirstWorkerService.php:407] | schema_version === 'atlas.software_company_stewardship.destructive_test_coverage_removal.v1'. Paired test at tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/DestructiveTestCoverageRemovalContractTest.php (extends Tests\TestCase, no DB) with 4-5 computed asserts: defaults()->toArray() => verdict='coverage_preserved' AND trigger_reasons===[]; rule (a) pure-file-removal (test_files_deleted_count>0, product 0/0) => coverage_removed AND whole_test_file_deleted_with_no_product_change===true; rule (b) net-drop (test_deletions>test_insertions, product_insertions=0) => coverage_removed AND net_test_lines_dropped_without_product_growth key in trigger_reasons; rule (c) trivial-edit-mask (test_deletions>0, product_insertions+product_deletions<=2 and >0) => coverage_removed AND test_cut_masked_by_trivial_product_edit fired; a healthy diff (test_insertions>=test_deletions, product grows) => coverage_preserved. | DoD: file + paired test created; `php artisan test --filter=DestructiveTestCoverageRemovalContractTest` green; pure (no statics-as-output, every field derived from inputs, inputs clamped >=0); no existing files edited; prohibited words absent. |
| S263 | Create a new PHP class SurgicalAnchorPresenceValidator at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/SurgicalAnchorPresenceValidator.php (namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow) with ONE pure method `public function validate(array $finding): array` returning `array{decision:'anchor_concrete'/'anchor_missing_or_vague', matched_path:?string, reason:string}`. final class, zero ctor deps, no I/O — caller passes $finding fields IN. Promotes the proven private logic at Ap786OwnerFlowExecutor.php:2127-2205 (hasStructuredNarrowAnchor / isConcreteNarrowAnchor / looksLikeConcreteSymbol) into ONE reusable decider; byte-for-byte preserves the existing regex/branches, no new behavior. <=7 rules: (1) scan fixed anchor field paths via data_get (target_method, target_symbol, method_anchor, symbol_anchor, line_anchor, surgical_anchor, mutation_anchor + nested self_construction_packet/task_packet/continuation_context variants of target_method/target_symbol/method_anchor/surgical_anchor); (2) target_method/method_anchor/line_anchor (and nested *.target_method/*.method_anchor) concrete when trimmed string non-empty; (3) any *target_symbol*/*symbol_anchor* path concrete only if looksLikeConcreteSymbol (contains '::' or '->' or matches identifier()/bare identifier); (4) *surgical_anchor*/*mutation_anchor* concrete only if it carries a `(target_)method:<non-empty>` or `line(_anchor):<digits>` token, or a `(target_)symbol:` token whose value looksLikeConcreteSymbol — matching the planner's emitted 'file:..; target_method:..; ..; constraint:..' format from FindingSlicePlannerService.php:491; (5) reject placeholder `runtime_signal:`-prefixed symbol values as vague; (6) empty/whitespace -> anchor_missing_or_vague; (7) return matched_path (first concrete path, or null) + reason string. First concrete path short-circuits to anchor_concrete; no path qualifies -> anchor_missing_or_vague. No edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=Ap786OwnerFlowExecutor.php:2127] | Paired test at tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/SurgicalAnchorPresenceValidatorTest.php (extends Tests\TestCase, no DB) with 5 computed asserts: target_method='handleX' => decision==='anchor_concrete' AND matched_path==='target_method'; surgical_anchor='file:Foo.php; target_method:bar; constraint:modify_existing_runtime_surface_and_focused_test_only' => anchor_concrete; surgical_anchor='make it better' => anchor_missing_or_vague; target_symbol='Foo::bar' => anchor_concrete AND target_symbol='runtime_signal:foo' => anchor_missing_or_vague; [] (empty finding) => anchor_missing_or_vague. | DoD: file + paired test created; `php artisan test --filter=SurgicalAnchorPresenceValidatorTest` green; pure (zero ctor deps, no I/O); regex/branches byte-for-byte preserved from the existing executor (no new behavior); no existing files edited; prohibited words absent. |
| S264 | Create a new PHP class PreSpendDestructiveDiffShapeClassifier at app/Services/Ai/Programming/AtlasDev/MinimaxFirst/PreSpendDestructiveDiffShapeClassifier.php (namespace App\Services\Ai\Programming\AtlasDev\MinimaxFirst) with ONE pure method `public function classify(array $diffStats): array` returning `{schema_version:'atlas.dev.minimax_first.destructive_diff_shape_classification.v1', decision:'accept'/'reject_destructive_or_filler', reasons:list<string>, inputs:array<string,int/bool>}`. final class, zero ctor deps, no git/no worktree/no file reads. classify() reads ONLY passed keys (product_insertions, product_deletions, test_changed, test_insertions, test_deletions, largest_single_file_deletions, finding_requires_test_update), all coerced via (int)/(bool) with defaults. Applies exactly 7 rules distilled from AtlasMinimaxFirstWorkerService::providerDiffQualityGate (lines 396-428), preserving rule ORDER and proven thresholds: R1 finding_requires_test_update && !test_changed -> 'required_test_update_missing'; R2 product_deletions>0 && !test_changed && product_deletions>=80 -> 'large_product_deletion_without_test_update'; R3 (product_insertions+product_deletions)>0 && !test_changed && (product_insertions+product_deletions)>=220 -> 'large_product_diff_without_test_update'; R4 !test_changed && largest_single_file_deletions>=80 -> 'large_single_file_deletion_without_test_update'; R5 !test_changed && product_deletions>=30 && product_insertions>0 && product_deletions/max(1,product_insertions)>=3.0 -> 'deletion_heavy_product_diff_without_test_update'; R6 test_deletions>=80 && test_deletions/max(1,test_insertions)>=2.0 -> 'large_test_deletion' (independent of test_changed, matching original); R7 else accept. decision='reject_destructive_or_filler' iff reasons non-empty (deduped, preserve first-seen order); else 'accept' with reasons=[]. Verdict for any stats array MUST equal the existing gate's pass/fail for the same summary numbers (no new policy). No edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=AtlasMinimaxFirstWorkerService.php:313] | schema_version === 'atlas.dev.minimax_first.destructive_diff_shape_classification.v1'. Paired test at tests/Unit/Ai/Programming/AtlasDev/MinimaxFirst/PreSpendDestructiveDiffShapeClassifierTest.php (extends Tests\TestCase, no DB) with 5 meaningful asserts: (a) {product_deletions:120, test_changed:false} => decision==='reject_destructive_or_filler' AND reasons contains 'large_product_deletion_without_test_update'; (b) {test_deletions:200, test_insertions:10} => reject AND reasons contains 'large_test_deletion'; (c) {product_insertions:40, product_deletions:5, test_changed:true} => accept AND reasons===[]; (d) {finding_requires_test_update:true, test_changed:false} => reject AND reasons contains 'required_test_update_missing'; (e) {} empty stats => accept AND reasons===[]. | DoD: file + paired test created; `php artisan test --filter=PreSpendDestructiveDiffShapeClassifierTest` green; pure (zero ctor deps, no git/worktree/file reads, value computed from inputs not static return); verdict parity with existing providerDiffQualityGate for equal numbers; no existing files edited; prohibited words absent. |
| S265 | Create a new PHP class ChangeSurfaceBreadthScorer at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ChangeSurfaceBreadthScorer.php (namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop) with ONE pure method `public function score(array $allowedFiles): array` returning `array{schema_version:'atlas.software_company_stewardship.change_surface_breadth.v1', file_count:int, layer_count:int, directory_count:int, breadth_band:'narrow'/'moderate'/'broad', breadth_score:float, bounded:bool, reasons:list<string>}`. final class, zero ctor deps, no I/O/DB/provider/git — caller passes resolved allowed_files IN. Rules (<=7): (1) normalize each path (trim, backslash->slash, ltrim '/') + dedupe; file_count = deduped count; (2) layer derivation mirrors ZeroProviderPreflightGate::layerOf — production layer = first-two path segments; test mirrors (first segment 'tests'/'test' OR path ends with 'Test.php') return null and are NOT counted as a layer; if production layers empty but files non-empty, layer_count=1; (3) directory_count = distinct dirname() of PRODUCTION files only; R1 file_count>FindingSlicePlannerService::MAX_FILES_PER_SLICE OR layer_count>1 OR directory_count>2 => band='broad'; R2 else file_count>2 OR directory_count>1 => band='moderate'; R3 else band='narrow'; R4 breadth_score=round(clamp((file_count/4+layer_count/2+directory_count/3)/3,0,1),2); R5 bounded=band!=='broad'; R6 empty allowedFiles after normalize => band='broad', bounded=false, reasons=['no_allowed_files'] (fail-closed, mirrors ZeroProviderPreflightGate:111-113), counts 0, breadth_score=0.0; R7 reasons names fired triggers {too_many_files (file_count>4), multiple_layers (layer_count>1), directory_spread (directory_count>2), within_breadth_budget (band narrow/moderate with nothing fired)}. Reference MAX_FILES_PER_SLICE via FindingSlicePlannerService::MAX_FILES_PER_SLICE. No edits to existing code; loop runtime wiring (ranking/deprioritization) out of scope. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=ZeroProviderPreflightGate.php:470] | schema_version === 'atlas.software_company_stewardship.change_surface_breadth.v1'. Paired test at tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ChangeSurfaceBreadthScorerTest.php (namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop) with >=5 computed asserts: (a) ['app/Services/Ai/X.php','tests/Unit/Ai/XTest.php'] => band==='narrow' AND layer_count===1 AND bounded===true; (b) ['app/Services/Ai/A.php','app/Console/B.php','app/Models/C.php'] => band==='broad' AND layer_count===3 AND in_array('multiple_layers',reasons,true); (c) ['a/X.php','a/Y.php','b/Z.php','c/W.php','d/V.php'] => band==='broad' AND file_count===5 AND in_array('too_many_files',reasons,true); (d) [] => band==='broad' AND bounded===false AND reasons===['no_allowed_files']; (e) monotonicity: a 2-file single-layer single-dir set yields breadth_score strictly less than the 5-file set in (c). | DoD: file + paired test created; `php artisan test --filter=ChangeSurfaceBreadthScorerTest` green; pure (zero ctor deps, no I/O, computed from inputs not static return); references FindingSlicePlannerService::MAX_FILES_PER_SLICE; no existing files edited; prohibited words absent. |
| S266 | Create a new PHP class DestructiveChangeBalanceScoreContract at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/DestructiveChangeBalanceScoreContract.php (namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow) with ONE pure method `public function toArray(int $totalInsertions, int $totalRemovals, int $productInsertions, int $productRemovals): array` returning `array{schema_version:'atlas.software_company_stewardship.destructive_change_balance_score.v1', verdict:'removal_dominant_low_replacement'/'balanced', removal_dominant:bool, reason:string, net_balance:int, removal_ratio:float, signals:{total_insertions,total_removals,product_insertions,product_removals,net_balance,removal_ratio,product_growth_trivial:bool,removals_meet_floor:bool}}`. final class, zero ctor deps, zero I/O — caller passes all 4 changeset totals IN as ints, clamped at 0 (negatives floored). Computes net_balance=totalInsertions-totalRemovals and removal_ratio=totalRemovals/max(1,totalInsertions). verdict='removal_dominant_low_replacement' (removal_dominant=true) WHEN ALL of: (1) totalRemovals>=MIN_REMOVALS_FLOOR(30) [removals_meet_floor]; (2) removal_ratio>=REMOVAL_RATIO_FLOOR(3.0); (3) productInsertions<=TRIVIAL_PRODUCT_GROWTH(5) [product_growth_trivial]. Else verdict='balanced', removal_dominant=false, reason='change_balance_within_tolerance'; reason mirrors verdict when dominant. Scope-agnostic: NO test-touched conditional, unlike the shipped per-bucket gate (AutonomousEvolutionSessionService.php:5154 / AtlasMinimaxFirstWorkerService.php:422) which only evaluates inside `if (productChanged !== [] && !testChanged)` and is bypassed by a single test edit; this scorer flags a large product gutting with a token test edit as removal_dominant. No edits to existing code; loop I/O wiring out of scope. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=AtlasMinimaxFirstWorkerService.php:422] | schema_version === 'atlas.software_company_stewardship.destructive_change_balance_score.v1'. Paired test at tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/DestructiveChangeBalanceScoreContractTest.php (extends Tests\TestCase, no DB) with >=5 computed asserts: (a) big gutting with trivial adds (totalRemovals>=30, ratio>=3.0, productInsertions<=5) => verdict==='removal_dominant_low_replacement'; (b) healthy add-heavy change => 'balanced'; (c) high ratio but substantial product growth (productInsertions>5) => 'balanced' (compensating growth defeats it); (d) removals below the 30 floor at any ratio => 'balanced'; (e) 4 ins / 80 rem => net_balance===-76 AND removal_ratio===20.0 AND negative inputs clamped to 0. | DoD: file + paired test created; `php artisan test --filter=DestructiveChangeBalanceScoreContractTest` green; pure (zero ctor deps, zero I/O, computed from inputs); no test-touched conditional; no existing files edited; prohibited words absent. |

## 10. Sequenciamento

S261 -> S266 sao fatias atomicas raiz, sem dependencias, prontas para execucao imediata.

## 11. Needs finer atomic decomposition (NOT loop-consumed)

| ID | Item | Aceite | DoD |
| --- | --- | --- | --- |
| S291 | Create a new PHP class TheWeakFindingContractGradeContract at app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/TheWeakFindingContractGradeContract.php (namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop) mirroring the TheAdmissionDeficitReasonContract seam (private __construct + `public static function defaults(): self` + `public static function fromArray(array $finding): self` + instance `public function toArray(): array`), zero ctor deps, no I/O. Grade computed by `private resolveGrade(array $evidenceRefs, array $affectedPaths, string $recommendedAction, string $detail)` via <=6 rules. Constants: SCHEMA='atlas.software_company_stewardship.weak_finding_contract_grade.v1', CONTRACT_ID='weak_finding_contract_grade', PREFLIGHT_BLOCK_STATUS=LoopPreflightCycleFirewallService::BLOCK_ADMISSION_BLOCKED, GRADE_ACTIONABLE='actionable_contract', GRADE_WEAK='weak_contract', + deficit consts no_concrete_evidence_refs/empty_affected_paths/default_recommended_action/empty_detail. toArray() returns `{schema_version:SCHEMA, contract_id, preflight_block_status, finding_type, inputs:{evidence_refs,affected_paths,recommended_action,detail}, outputs:{contract_grade:'actionable_contract'/'weak_contract', blocks_before_spend:bool, deficit_reasons:list<string> sorted asc, concrete_evidence_ref_count:int}}`. Rules: R1 no concrete evidence ref (each entry empty OR synthetic stub matching /(^/[^:])(replay_expected/desktop_expected/dev_forge_routing_expected):true$/ or has no '/' path-segment and no ':<digit>' line-segment) -> 'no_concrete_evidence_refs'; R2 affected_paths empty after trim-filter -> 'empty_affected_paths'; R3 trim(recommended_action)==='Operator review required.' -> 'default_recommended_action'; R4 trim(detail)==='' -> 'empty_detail'; R5 contract_grade = deficit_reasons===[] ? 'actionable_contract' : 'weak_contract', blocks_before_spend=(grade==='weak_contract'); R6 concrete_evidence_ref_count = count of entries NOT empty and NOT synthetic-stub-or-bare-token. deficit_reasons sorted asc via array_values. No edits to existing code. [area=aaeos route=atlas_dev r_level=R1 north_star=false status=ready src=LoopPreflightCycleFirewallService.php:451] | schema_version===SCHEMA literal. Paired test at tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/TheWeakFindingContractGradeContractTest.php (extends Tests\TestCase, no DB) with 5 computed asserts: (1) evidence_refs=['app/Services/Ai/Foo.php:42'], affected_paths=['app/Services/Ai/Foo.php'], recommended_action='Wire the missing X.', detail='Concrete gap.' => contract_grade==='actionable_contract' && blocks_before_spend===false && deficit_reasons===[] && concrete_evidence_ref_count===1; (2) evidence_refs=['replay_expected:true'], affected_paths=[], recommended_action='Operator review required.', detail='' => contract_grade==='weak_contract' && blocks_before_spend===true && deficit_reasons===['default_recommended_action','empty_affected_paths','empty_detail','no_concrete_evidence_refs'] && concrete_evidence_ref_count===0; (3) evidence_refs=['desktop_expected:true'], affected_paths=['docs/x.md'], recommended_action='Add a desktop surface reference.', detail='Has detail.' => deficit_reasons===['no_concrete_evidence_refs'] && blocks_before_spend===true; (4) evidence_refs=['app/x.php:9'], affected_paths=['app/x.php'], recommended_action='Operator review required.', detail='ok' => deficit_reasons===['default_recommended_action'] && contract_grade==='weak_contract'; (5) schema_version===SCHEMA && contract_id==='weak_finding_contract_grade' && preflight_block_status===LoopPreflightCycleFirewallService::BLOCK_ADMISSION_BLOCKED && defaults()->toArray()['outputs']['contract_grade']==='weak_contract'. | DoD: file + paired test created; `php artisan test --filter=TheWeakFindingContractGradeContractTest` green; pure (zero ctor deps, no I/O, grade derived from passed-in fields); no existing files edited; prohibited words (Jarvis/Rivals/benchmark/superiority/concurrent) absent from class and test. |
