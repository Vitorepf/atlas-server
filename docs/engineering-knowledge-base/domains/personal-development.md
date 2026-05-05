# Personal Development Domain

Personal Development is an isolated Atlas AI domain for habits, routine, focus, energy, learning, personal performance, and life review. It is private by default and explicitly non-clinical: the domain may help organize reflection, evidence, plans, routine experiments, and review checkpoints, but it must not diagnose psychological conditions, prescribe medical treatment, or present itself as a therapeutic workflow.

## Scope

Supported flows:

- `personal_development.reflect`
- `personal_development.daily_review`
- `personal_development.weekly_review`
- `personal_development.habit_design`
- `personal_development.focus_plan`
- `personal_development.learning_plan`
- `personal_development.energy_review`
- `personal_development.goal_decomposition`
- `personal_development.recovery_plan`
- `personal_development.forge`

The runtime returns structured plans and artifacts only. It does not mutate calendars, tasks, habit trackers, external systems, or user records automatically.

## Safety Contract

- Use operational language: plan, reflection, evidence, routine, experiment, checkpoint.
- Keep all memory private by default.
- Mark provider context as provider-safe only after redaction.
- Route sensitive recommendations to human review.
- Require approval for `personal_development.forge`.
- Do not diagnose psychological states.
- Do not provide medical treatment guidance.
- Do not make automatic schedule or task changes.

## Architecture

Implementation lives under `app/Services/Ai/PersonalDevelopment/`:

- `PersonalDevelopmentFlowCatalog` is the isolated source of truth for flow IDs, cadence, risk level, focus areas, and primary artifact IDs.
- `PersonalDevelopmentInputNormalizer` accepts only operational context keys for artifacts and records ignored keys without retaining raw input.
- `AtlasPersonalDevelopmentOrchestrator` normalizes and validates flows, resolves the domain/flow profile, and produces a plan contract.
- `PersonalDevelopmentRuntime` creates the structured plan and artifacts, checks sensitive markers, and reports side-effect flags.
- `PersonalDevelopmentSafetyPolicy` owns non-clinical safety rules, sensitive marker review, blocked actions, and side-effect invariants.
- `PersonalDevelopmentMemoryPolicy` classifies memory as private and permits provider-safe payloads only when redacted content is available.

The database contract is seeded by `database/migrations/2026_05_05_080000_expand_personal_development_domain_contract.php`. This keeps the domain contract out of shared config and registry files while preparing the profile for the main domain catalog integration.

## Integration Status

Personal Development is now centrally registered as a first-class Atlas AI domain.

- `config/atlas_ai.php` maps `AtlasPersonalDevelopmentOrchestrator` to `App\Services\Ai\PersonalDevelopment\AtlasPersonalDevelopmentOrchestrator`.
- `AtlasDomainProfileRegistry` exposes all 10 Personal Development flows in static fallback mode.
- `database/migrations/2026_05_05_080000_expand_personal_development_domain_contract.php` seeds the active database profiles.
- `atlas:ai:domains --json` reports Personal Development as `ready 9/9` with 10 flows.
- `atlas:ai:architecture-validate --json` includes Personal Development in the ready domain count.

Validation:

- `php artisan test tests/Unit/Ai/PersonalDevelopment tests/Feature/Ai/PersonalDevelopment`
- `php artisan test tests/Feature/Architecture/DomainProfileComplianceTest.php tests/Feature/Ai/AtlasAiDomainsCommandTest.php`
- `php artisan atlas:ai:domains --json`
- `php artisan atlas:ai:architecture-validate --json`
