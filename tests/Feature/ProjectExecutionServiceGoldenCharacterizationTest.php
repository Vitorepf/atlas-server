<?php

namespace Tests\Feature;

use App\Models\Capture;
use App\Services\ProjectExecutionService;
use Illuminate\Support\Carbon;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Golden characterization net for ProjectExecutionService (GOD-DEBULK).
 *
 * This file has NO behavioural assertions of its own design — it freezes the
 * CURRENT behaviour of the untouched godfile so the star-topology split can be
 * proven behaviour-preserving:
 *
 *  1. inferPlan() is pure (no DB, no clock, no uuids) and drives the bulk of the
 *     private heuristic helpers. Its full output is frozen as an exact JSON
 *     snapshot over 9 deterministic inputs (one per project_type branch + an
 *     explicit-data case + a capture-backed case).
 *  2. The public method surface (names + full signatures + return types) is
 *     frozen as a reflection fingerprint, so any drift in the façade API during
 *     the split fails loudly.
 *
 * DB-heavy public methods are covered by the fingerprint + the verbatim-body
 * discipline of the split (bodies moved unchanged into leaf sections).
 */
class ProjectExecutionServiceGoldenCharacterizationTest extends TestCase
{
    private const FIXTURE = __DIR__.'/Fixtures/project_execution_infer_plan_golden.json';

    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    protected function service(): ProjectExecutionService
    {
        return $this->app->make(ProjectExecutionService::class);
    }

    /**
     * The exact set of deterministic cases captured from the untouched service.
     *
     * @return array<string, array{0: ?Capture, 1: array<string, mixed>, 2: string}>
     */
    private function cases(): array
    {
        $cap = new Capture;
        $cap->content_text = 'Preciso revisar o material antes da prova de sexta.';

        return [
            'study' => [null, [], 'Estudar cálculo para a prova de investimento'],
            'technical' => [null, [], 'Implementar backend da API do app iOS'],
            'writing' => [null, [], 'Escrever artigo e roteiro sobre foco'],
            'business' => [null, [], 'Lançar produto Black Ink e gerar receita com cliente'],
            'health' => [null, [], 'Marcar exame médico e treino de sono'],
            'tedious' => [null, [], 'Organizar e pagar burocracia chata'],
            'personal_plain' => [null, [], 'Resolver uma coisa qualquer'],
            'explicit_data' => [null, [
                'goal' => 'Fechar a decisão do trimestre',
                'description' => 'Decidir entre escolher A ou B',
                'reason' => 'Prazo urgente hoje',
                'next_action' => 'Listar opções e escolher',
                'priority' => 'high',
                'desired_outcome' => 'Decisão registrada',
                'due_at' => '2026-08-01T00:00:00Z',
                'estimated_minutes' => 40,
            ], 'Decidir o rumo crítico'],
            'with_capture' => [$cap, ['reason' => 'ansiedade com a prova difícil'], 'Revisar para a prova'],
        ];
    }

    public function test_infer_plan_output_matches_frozen_snapshot(): void
    {
        // Freeze the clock even though inferPlan is currently clock-free, so the
        // net stays valid if a future body accidentally introduces now().
        Carbon::setTestNow('2026-07-22T12:00:00Z');

        $service = $this->service();

        $actual = [];
        foreach ($this->cases() as $name => [$capture, $data, $title]) {
            $actual[$name] = $service->inferPlan($capture, $data, $title);
        }

        $expected = trim((string) file_get_contents(self::FIXTURE));
        $this->assertSame(
            $expected,
            json_encode($actual, self::JSON_FLAGS),
            'inferPlan output drifted from the frozen golden snapshot.'
        );

        Carbon::setTestNow();
    }

    public function test_public_method_fingerprint_is_stable(): void
    {
        $expected = [
            "activateStep(App\\Models\\AtlasProject \$project, App\\Models\\AtlasProjectStep \$step, string \$source='projects.step.activate'): App\\Models\\AtlasTask",
            "advanceAfterTaskCompletion(App\\Models\\AtlasTask \$task, string \$source='tasks.complete', array \$completion=array()): ?App\\Models\\AtlasTask",
            "blockStep(App\\Models\\AtlasProject \$project, App\\Models\\AtlasProjectStep \$step, string \$source='projects.step.block', array \$data=array()): void",
            "closeStepAndAdvance(App\\Models\\AtlasProject \$project, App\\Models\\AtlasProjectStep \$step, string \$status='skipped', string \$source='projects.step.close'): ?App\\Models\\AtlasTask",
            "ensureNextActionTask(App\\Models\\AtlasProject \$project, ?App\\Models\\Capture \$capture, array \$data=array(), array \$plan=array(), string \$source='project'): App\\Models\\AtlasTask",
            "ensureProjectPlan(App\\Models\\AtlasProject \$project, array \$plan=array(), bool \$replace=false, string \$source='project'): Illuminate\\Support\\Collection",
            "executionPacket(App\\Models\\AtlasProject \$project, ?App\\Models\\AtlasTask \$task=NULL, array \$context=array()): array",
            'inferPlan(?App\\Models\\Capture $capture, array $data, string $title): array',
            "recordEvent(App\\Models\\AtlasProject \$project, string \$eventType, array \$payload=array(), string \$source='app'): ?App\\Models\\AtlasProjectEvent",
            "recordTaskCompleted(App\\Models\\AtlasTask \$task, array \$data=array(), mixed \$completedAt=NULL, string \$source='tasks.complete'): array",
            "recordTaskDeferred(App\\Models\\AtlasTask \$task, ?string \$reason=NULL, mixed \$deferUntil=NULL, string \$source='tasks.defer', ?string \$reasonCode=NULL): array",
            "recoverProject(App\\Models\\AtlasProject \$project, array \$data=array(), string \$source='projects.recover'): array",
            "reopenStep(App\\Models\\AtlasProject \$project, App\\Models\\AtlasProjectStep \$step, string \$source='projects.step.reopen'): void",
            'reviewProject(App\\Models\\AtlasProject $project, array $data, string $source=\'projects.review\'): array',
            "reviewSuggestion(App\\Models\\AtlasProject \$project, array \$health=array()): array",
            "startExecution(App\\Models\\AtlasProject \$project, array \$data=array(), string \$source='projects.execution.start'): array",
            "transitionStatus(App\\Models\\AtlasProject \$project, string \$status, array \$data=array(), string \$source='projects.status'): ?App\\Models\\AtlasTask",
        ];

        $this->assertSame($expected, $this->publicFingerprint());
    }

    /**
     * @return array<int, string>
     */
    private function publicFingerprint(): array
    {
        $rc = new ReflectionClass(ProjectExecutionService::class);
        $fp = [];
        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            if ($m->getDeclaringClass()->getName() !== ProjectExecutionService::class || $m->isConstructor()) {
                continue;
            }
            $params = [];
            foreach ($m->getParameters() as $p) {
                $type = $p->hasType() ? (string) $p->getType() : 'mixed';
                $def = '';
                if ($p->isDefaultValueAvailable()) {
                    $def = '='.str_replace(["\n", ' '], '', var_export($p->getDefaultValue(), true));
                }
                $params[] = $type.' $'.$p->getName().$def;
            }
            $ret = $m->hasReturnType() ? (string) $m->getReturnType() : 'void';
            $fp[$m->getName()] = $m->getName().'('.implode(', ', $params).'): '.$ret;
        }
        ksort($fp);

        return array_values($fp);
    }
}
