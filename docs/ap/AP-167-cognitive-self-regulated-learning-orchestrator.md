# AP-167 — Cognitive Self-Regulated Learning Orchestrator

Status: implemented-operational-read-model

Implementation note (2026-05-07): Laravel opt-in overlay, preferences,
episodes, phase controller, CLI, gate, SLOs and Ledger events are implemented.
Runtime UI hooks inside every learning surface remain consumer work; the
operational read/write model is ready and never injects SRL unless toggled on.

## Objetivo

Implementar capability Core transversal que adiciona **overlay metacognitivo opcional** sobre flows do `learning` v2, baseado em Zimmerman Self-Regulated Learning. Tres fases:

- **Forethought**: antes de qualquer flow, captura objetivo, expectativa de dificuldade, estrategia escolhida
- **Performance**: durante, monitora cognitive load + auto-rating de progresso a cada N minutos
- **Self-reflection**: depois, captura o que funcionou, o que nao, ajuste para proxima sessao

E **opt-in** e **transversal**: nao substitui flow existente, injeta hooks. Multiplica valor de tudo que ja esta documentado no Cognitive Plane.

## Nao Objetivo

Nao implementar:

- Flow novo (e overlay sobre flows existentes)
- Confidence Calibration Drill (capability separada, AP-COG-CC futuro)
- Tornar metacognicao obrigatoria (operador opta in/out por dominio ou globalmente)
- Pop-up no daily_plan ou flow continuo (proibido por C15 e governanca de Confidence Calibration)
- Substituir Cognitive Load Monitor (consome dele)

## Authority

Em conflito: Tese central > Kernel > `cognitive/overview.md` > `cognitive/principles.md` > `cognitive/capabilities-core.md` > este AP.

Status promove para `implemented-operational-read-model` apos DoD.

## Fluxo

```
Operador inicia flow (`php artisan atlas:study` | `php artisan atlas:worked-example` | etc.; wrappers `atlas ...` sao produto futuro)
  -> SRLOrchestrator detecta SRL toggle ativo para domain/operador
  -> Forethought hook: 30s captura objetivo + expectativa + estrategia
  -> Flow original executa normalmente
  -> Performance hook: a cada N min checa cognitive_load + auto-rating opcional
  -> Flow termina
  -> Self-reflection hook: 60s captura insight + ajuste
  -> SRLEpisodeRepository persiste 3 fases
  -> Ledger emite SRL_*_RECORDED
```

## Schema

### Migration `2026_05_07_170000_create_srl_episodes_table.php`

```php
Schema::create('srl_episodes', function (Blueprint $t) {
    $t->id();
    $t->string('envelope_id', 80);
    $t->string('target_flow', 64);                // learning.deep_work | learning.transfer_test | ...
    $t->string('domain', 64)->default('learning');
    $t->string('study_session_id', 80)->nullable();
    $t->json('forethought')->nullable();          // {objective, expected_difficulty, strategy_chosen, planned_duration}
    $t->json('performance_observations')->nullable(); // [{at, cognitive_load, self_rating, note}]
    $t->json('self_reflection')->nullable();      // {what_worked, what_didnt, adjustment_for_next, surprise}
    $t->string('completion_status', 32);          // partial_forethought | complete | abandoned
    $t->timestamps();
    $t->index(['envelope_id', 'target_flow']);
    $t->index('completion_status');
});

Schema::create('srl_preferences', function (Blueprint $t) {
    $t->id();
    $t->string('scope', 32);                      // global | domain
    $t->string('domain', 64)->nullable();         // null se scope=global
    $t->boolean('enabled')->default(false);
    $t->json('phase_config')->nullable();         // {forethought_seconds_max, performance_interval_min, reflection_seconds_max}
    $t->timestamps();
    $t->unique(['scope', 'domain']);
});

// Idempotente; nunca INSERT INTO migrations manualmente.
```

### Memory type `srl_episode`

```yaml
srl_episode:
  envelope_id: uuid
  target_flow: string
  study_session_id: uuid (optional)
  forethought:
    objective: text
    expected_difficulty: 1..5
    strategy_chosen: string
    planned_duration_min: int
  performance_observations:
    - at: ISO-8601
      cognitive_load: low | medium | high
      self_rating: 1..5
      note: text (optional)
  self_reflection:
    what_worked: text
    what_didnt: text
    adjustment_for_next: text
    surprise: text (optional)
  completion_status: partial_forethought | complete | abandoned
```

## Components

| Componente | Responsabilidade | Local sugerido |
|---|---|---|
| `SRLOrchestrator` | injeta hooks pre/during/post nos flows; respeita toggle | `app/Services/Ai/Cognitive/SRL/` |
| `SRLPhaseController` | gerencia transicoes entre 3 fases; previne pulos | `app/Services/Ai/Cognitive/SRL/` |
| `SRLEpisodeRepository` | CRUD + queries por janela/dominio | `app/Services/Ai/Cognitive/SRL/` |
| `SRLPreferenceService` | toggle global/dominio | `app/Services/Ai/Cognitive/SRL/` |
| `SRLForethoughtCapture` | UI/CLI prompts para fase 1 | `app/Services/Ai/Cognitive/SRL/` |
| `SRLPerformanceObserver` | hook periodico durante flow; consome `CognitiveLoadMonitor` | `app/Services/Ai/Cognitive/SRL/` |
| `SRLReflectionCapture` | UI/CLI prompts para fase 3 | `app/Services/Ai/Cognitive/SRL/` |
| `SRLPhaseAppropriateGate` | gate executavel | `app/Services/Ai/Kernel/Gates/` |
| `AtlasSRLCommand` | CLI base `php artisan atlas:srl` | `app/Console/Commands/` |

## Gates Executaveis

### `srl_phase_appropriate`

```php
final class SRLPhaseAppropriateGate {
    public function evaluate(SRLEpisode $episode, string $requestedPhase): GateResult {
        $expectedOrder = ['forethought', 'performance', 'self_reflection'];
        $currentPhase = $episode->currentPhase();
        $currentIndex = array_search($currentPhase, $expectedOrder);
        $requestedIndex = array_search($requestedPhase, $expectedOrder);

        if ($requestedIndex < $currentIndex) {
            return GateResult::block('srl_phase_already_completed');
        }
        if ($requestedIndex > $currentIndex + 1) {
            return GateResult::block('srl_phase_skipped');
        }
        return GateResult::pass();
    }
}
```

### `srl_optional` (consulta de toggle)

Antes de injetar hooks: consulta `SRLPreferenceService` para domain do envelope. Se desabilitado, pula sem registrar nada. Garante que SRL nunca se torna fricca obrigatoria.

## Surfaces e Comandos

| Comando | Output |
|---|---|
| `php artisan atlas:srl on [--domain=...]` | habilita overlay (global ou por dominio) |
| `php artisan atlas:srl off [--domain=...]` | desabilita |
| `php artisan atlas:srl status [--domain=...]` | mostra dominios com SRL ativo |
| `php artisan atlas:srl start <flow> --objective=...` | inicia episodio se o overlay estiver ativo |
| `php artisan atlas:srl observe <episode-id>` | adiciona observation durante flow |
| `php artisan atlas:srl reflect <episode-id>` | adiciona reflection manual pos-flow |
| `php artisan atlas:srl episode <id>` | inspeciona episode com 3 fases |
| `php artisan atlas:srl history --days=30 --domain=...` | lista episodes recentes |

### Tela / Interacao tipica

```
$ php artisan atlas:study laravel-queues

[SRL ativo para domain=programming]

[Forethought - 30s]
  Objetivo desta sessao: ____________
  Dificuldade esperada (1-5): __
  Estrategia: explorar | revisar | aplicar | comparar | ____
  Duracao planejada (min): __

[Sessao executa normal]

[Performance check 1 - 30min]
  Cognitive load atual: low | medium | high
  Auto-rating de progresso (1-5): __

[Sessao termina]

[Self-reflection - 60s]
  O que funcionou:
  O que nao:
  Ajuste para proxima:
  Surpresa (opcional):

SRL_REFLECTION_RECORDED. Episode #142 completo.
```

## SLO Targets

| Metric | Target p95 |
|---|---|
| `cognitive.srl.forethought` | <= 100ms |
| `cognitive.srl.performance_observation` | <= 50ms |
| `cognitive.srl.episode_persist` | <= 80ms |
| `cognitive.srl.gate` | <= 30ms |

Namespace `cognitive.srl.*`.

## Evidence Ledger Events

`SRL_OVERLAY_TOGGLED`, `SRL_FORETHOUGHT_RECORDED`, `SRL_PERFORMANCE_OBSERVATION`, `SRL_REFLECTION_RECORDED`, `SRL_EPISODE_ABANDONED`. Taxonomia fechada, aditiva.

## Tests

| Test | Local |
|---|---|
| `SRLOrchestratorTest` (toggle on/off respeitado) | `tests/Unit/Ai/Cognitive/SRL/` |
| `SRLPhaseControllerTest` (3 fases em ordem; nao pula) | `tests/Unit/Ai/Cognitive/SRL/` |
| `SRLEpisodeRepositoryTest` | `tests/Unit/Ai/Cognitive/SRL/` |
| `SRLPhaseAppropriateGateTest` (3 cenarios block + pass) | `tests/Unit/Ai/Kernel/Gates/` |
| `SRLPreferenceServiceTest` (global vs domain override) | `tests/Unit/Ai/Cognitive/SRL/` |
| `LearningSRLIntegrationTest` (runtime hook futuro) | `tests/Feature/Ai/Cognitive/` |
| `AtlasSRLCommandTest` | `tests/Feature/Ai/Cognitive/` |
| `CognitiveDomainComplianceTest::test_srl_overlay_optional_never_forced` (futuro hard invariant) | `tests/Feature/Architecture/` |

## Validation Commands

```bash
php artisan migrate
php artisan test tests/Unit/Ai/Cognitive/SRL tests/Unit/Ai/Kernel/Gates/SRLPhaseAppropriateGateTest.php tests/Feature/Ai/Cognitive/AtlasSRLCommandTest.php
php artisan atlas:srl status --json
php artisan atlas:srl on --domain=programming
php artisan atlas:srl history --days=14 --json
php artisan atlas:ai:architecture-validate --json
atlas engineering knowledge sync --prune
atlas engineering knowledge index-code --prune
atlas engineering knowledge docs-health
```

## Definition Of Done

1. Migrations `srl_episodes` + `srl_preferences` aplicadas e idempotentes
2. `SRLOrchestrator`, `SRLPhaseController`, `SRLEpisodeRepository`, `SRLPreferenceService`, `SRLForethoughtCapture`, `SRLPerformanceObserver`, `SRLReflectionCapture` implementados e testados
3. `SRLPhaseAppropriateGate` executavel e ligado ao policy compiler
4. CLI `php artisan atlas:srl on/off/status/episode/history/reflect` operacional
5. SRL toggle por dominio funciona (programming on, finance off, etc.)
6. Runtime hooks em learning surfaces ficam como consumer work; `SRLOrchestrator::beginIfEnabled()` e CLI `start` ja provam opt-in e episode creation
7. Performance observation respeita interval configuravel; nao polui flow continuo
8. Ledger emite `SRL_FORETHOUGHT_RECORDED`, `SRL_PERFORMANCE_OBSERVATION`, `SRL_REFLECTION_RECORDED` end-to-end
9. SLOs registrados em `KernelSloTargets`
10. Unit/feature tests garantem que SRL e sempre opt-in; architecture hard invariant fica como proxima fase
11. `atlas:ai:architecture-validate --json` continua verde
12. `cognitive/capabilities-core.md` marca SRL Orchestrator como `implemented-operational-read-model`
