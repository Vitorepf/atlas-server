<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Context\TaskProfileBudgetPriorService;
use Tests\TestCase;

/**
 * MAXC-03 — task-profile priors for the AOBG source budget.
 *
 * Prova o contrato pétreo:
 * - detecção determinística (mesmo texto ⇒ mesmo perfil) usando o léxico
 *   provado do ContextRetrievalRouter;
 * - debug ⇒ code prior > 1.0 (evidence-heavy task pede mais código no pack);
 * - planning ⇒ memory prior > 1.0 (decisão/pesquisa pede memória durável);
 * - ops ⇒ code prior > 1.0 (patch/deploy é code-first);
 * - `unknown` ⇒ todos multipliers = 1.0 (nunca "chuta" perfil);
 * - piso pétreo: nenhum prior baixa qualquer multiplier para 0 (não starva
 *   fonte — piso top-1 do enforceTotalCeiling permanece invariante).
 */
final class Maxc03TaskProfileBudgetPriorTest extends TestCase
{
    private function service(): TaskProfileBudgetPriorService
    {
        return app(TaskProfileBudgetPriorService::class);
    }

    public function test_debug_task_prioritises_code_over_memory(): void
    {
        $profile = $this->service()->classify('Investigar bug: null pointer no AtlasMemory quando recall falhou');
        $this->assertSame(TaskProfileBudgetPriorService::PROFILE_DEBUG, $profile);

        $priors = $this->service()->priorMultipliers($profile);
        $this->assertGreaterThan(1.0, $priors['code'], 'debug prior must lift code budget');
        $this->assertLessThan(1.0, $priors['memory'], 'debug prior must demote memory-first sources (decisão/pesquisa)');
    }

    public function test_planning_task_prioritises_memory(): void
    {
        $profile = $this->service()->classify('Planejar arquitetura do novo módulo — decisão de trade-off entre X e Y');
        $this->assertSame(TaskProfileBudgetPriorService::PROFILE_PLANNING, $profile);

        $priors = $this->service()->priorMultipliers($profile);
        $this->assertGreaterThan(1.0, $priors['memory'], 'planning prior must lift memory (decisões/pesquisa)');
        $this->assertGreaterThan(1.0, $priors['graph'], 'planning prior lifts reality graph slightly');
    }

    public function test_ops_task_prioritises_code(): void
    {
        $profile = $this->service()->classify('Rollout do watchdog + agendar cadência hourly');
        $this->assertSame(TaskProfileBudgetPriorService::PROFILE_OPS, $profile);

        $priors = $this->service()->priorMultipliers($profile);
        $this->assertGreaterThan(1.0, $priors['code']);
    }

    public function test_unknown_task_returns_neutral_multipliers(): void
    {
        $profile = $this->service()->classify('descrição vazia sem termos');
        $this->assertSame(TaskProfileBudgetPriorService::PROFILE_UNKNOWN, $profile);

        $priors = $this->service()->priorMultipliers($profile);
        $this->assertSame(1.0, $priors['code']);
        $this->assertSame(1.0, $priors['memory']);
        $this->assertSame(1.0, $priors['graph']);
    }

    public function test_priors_never_zero_out_any_source(): void
    {
        foreach ([
            TaskProfileBudgetPriorService::PROFILE_DEBUG,
            TaskProfileBudgetPriorService::PROFILE_PLANNING,
            TaskProfileBudgetPriorService::PROFILE_OPS,
            TaskProfileBudgetPriorService::PROFILE_UNKNOWN,
        ] as $profile) {
            $priors = $this->service()->priorMultipliers($profile);
            foreach ($priors as $source => $mult) {
                $this->assertGreaterThan(
                    0.5,
                    $mult,
                    'multiplier for '.$source.' in profile '.$profile.' must never starve the source',
                );
            }
        }
    }

    public function test_describe_marks_applied_only_when_profile_is_known(): void
    {
        $known = $this->service()->describe('Debug this crash trace');
        $this->assertTrue($known['applied']);
        $this->assertSame(TaskProfileBudgetPriorService::SCHEMA_VERSION, $known['schema_version']);

        $unknown = $this->service()->describe('foo bar baz');
        $this->assertFalse($unknown['applied']);
    }
}
