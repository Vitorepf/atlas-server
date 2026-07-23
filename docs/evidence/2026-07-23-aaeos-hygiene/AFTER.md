# AAEOS Hygiene AFTER — 2026-07-23

## Live `app/Services/Ai/Aaeos`

| Metric | Before | After |
|--------|-------:|------:|
| PHP files | 60 | 20 |
| LOC | 11910 | 1387 |
| Foreign paths (non Control/Spine) | many | 0 |

Tree:
- `Control/AaeosAdmissionPolicy.php`
- `Control/AaeosAdmissionVerdict.php`
- `Control/AaeosCycleOutcomeRecorder.php`
- `Control/AaeosCycleRuntime.php`
- `Control/AaeosDifficultyClassifier.php`
- `Control/AaeosDifficultyLevel.php`
- `Control/AaeosExecutorMode.php`
- `Control/AaeosIntentCompiler.php`
- `Control/AaeosModeSelector.php`
- `Control/AaeosModeToDualCoreRoute.php`
- `Control/AaeosOrgStateProjector.php`
- `Control/AaeosScorecardProjector.php`
- `Control/AaeosWorldSnapshot.php`
- `Control/AaeosWorldSnapshotBuilder.php`
- `Control/Adapters/AaeosExecutorModeAdapter.php`
- `Control/Adapters/AutonomosModeAdapter.php`
- `Control/Adapters/DevModeAdapter.php`
- `Control/Adapters/ForgeModeAdapter.php`
- `Spine/AaeosEngineeringSpine.php`
- `Spine/AaeosSpineGate.php`

## Moves

- Maturity/truth root → `AgenticEngineeringOs/Maturity/` (renamed strip AtlasAaeos*)
- Cores → `AgenticEngineeringOs/Scoring/`
- Support helpers → `AgenticEngineeringOs/Support/`
- Source connectors → `AutonomousEvolution/Brain/`
- Generated LearningProposals → `Compounding/AtlasLearningProposalDecisionService`
- Generated Immune kernel → `Memory/`
- AssertGuaranteeHeld → `Memory/Concerns/`

## Deleted

- 39 orphan Generated unit tests (archive-only targets)

## Certify

- `atlas:aaeos:certify` god_sota=true
- aaeos_tree.pure=true, php_files=20
- orphan_generated_tests=0
- quarantine_production_imports=0

## Compat

- Temporary aliases: `app/Services/Ai/Compat/AaeosHygieneLegacyAliases.php` (Phase E burn later)
