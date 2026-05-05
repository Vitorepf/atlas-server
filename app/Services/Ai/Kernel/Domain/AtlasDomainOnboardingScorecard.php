<?php

namespace App\Services\Ai\Kernel\Domain;

class AtlasDomainOnboardingScorecard
{
    private const PHASES = [
        'charter',
        'profile',
        'context',
        'orchestrator',
        'runtime',
        'gates',
        'learning',
        'surface',
        'maturity_gate',
    ];

    /**
     * @param  array<string,mixed>  $domain
     * @param  array<int,array<string,mixed>>  $flows
     * @param  array<string,mixed>|null  $orchestrator
     * @return array<string,mixed>
     */
    public function forDomain(array $domain, array $flows, ?array $orchestrator): array
    {
        $checks = [
            'charter' => $this->check(
                trim((string) ($domain['description'] ?? '')) !== '',
                'Domain has a written charter/description.',
                'Add a domain charter with mission, scope, non-goals, risk class, and owner.'
            ),
            'profile' => $this->check(
                $this->hasProfile($domain, $flows),
                'Domain and default flow profiles are declared.',
                'Declare active domain profile, default flow, autonomy, runtime family, and at least one active flow.'
            ),
            'context' => $this->check(
                $this->hasAnyPolicy($domain, ['context_policy', 'memory_policy', 'skill_policy']),
                'Context, memory, or skill policy is declared.',
                'Define context pack sources, memory projection rules, and required skill bundles for this domain.'
            ),
            'orchestrator' => $this->check(
                $this->hasImplementedOrchestrator($orchestrator),
                'Orchestrator class implements the AtlasDomainOrchestrator SDK.',
                'Register an orchestrator class that implements AtlasDomainOrchestrator and declares domain/flow support.'
            ),
            'runtime' => $this->check(
                collect($flows)->isNotEmpty() && collect($flows)->every(fn (array $flow): bool => trim((string) ($flow['runtime'] ?? '')) !== ''),
                'Every active flow declares a runtime.',
                'Declare runtime adapters for every active flow.'
            ),
            'gates' => $this->check(
                $this->flowsHaveGatePolicy($flows),
                'Every active flow declares gate policy.',
                'Define required evidence/gates for every flow before promoting the domain.'
            ),
            'learning' => $this->check(
                $this->hasLearningPolicy($domain, $flows),
                'Learning or memory feedback policy is declared.',
                'Define how evidence becomes memory, benchmarks, improvements, or domain knowledge.'
            ),
            'surface' => $this->check(
                $this->hasSurfacePolicy($domain, $flows),
                'Surface exposure is declared.',
                'Declare supported surfaces such as CLI, API, app, worker, MCP, or scheduler.'
            ),
            'maturity_gate' => $this->check(
                (string) ($orchestrator['maturity'] ?? 'planned') === 'implemented',
                'Orchestrator is marked implemented.',
                'Keep the domain at scaffold/planned until runtime, gates, tests, docs, and API/CLI contracts pass.'
            ),
        ];

        $completed = collect($checks)->filter(fn (array $check): bool => (bool) $check['passed'])->keys()->values()->all();
        $missing = collect($checks)->reject(fn (array $check): bool => (bool) $check['passed'])->keys()->values()->all();
        $score = count($completed) / count(self::PHASES);

        return [
            'schema_version' => 1,
            'status' => $this->status($score, $checks),
            'score' => round($score, 2),
            'completed_count' => count($completed),
            'total_count' => count(self::PHASES),
            'completed_phases' => $completed,
            'missing_phases' => $missing,
            'checks' => $checks,
            'next_actions' => collect($checks)
                ->reject(fn (array $check): bool => (bool) $check['passed'])
                ->map(fn (array $check, string $phase): array => [
                    'phase' => $phase,
                    'action' => $check['next_action'],
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{passed:bool,description:string,next_action:string}
     */
    private function check(bool $passed, string $description, string $nextAction): array
    {
        return [
            'passed' => $passed,
            'description' => $description,
            'next_action' => $passed ? '' : $nextAction,
        ];
    }

    /**
     * @param  array<string,mixed>  $domain
     * @param  array<int,array<string,mixed>>  $flows
     */
    private function hasProfile(array $domain, array $flows): bool
    {
        return trim((string) ($domain['id'] ?? '')) !== ''
            && trim((string) ($domain['default_flow'] ?? '')) !== ''
            && trim((string) ($domain['runtime_family'] ?? '')) !== ''
            && trim((string) ($domain['autonomy_default'] ?? '')) !== ''
            && collect($flows)->isNotEmpty();
    }

    /**
     * @param  array<string,mixed>|null  $orchestrator
     */
    private function hasImplementedOrchestrator(?array $orchestrator): bool
    {
        $class = (string) ($orchestrator['class'] ?? '');

        return $class !== ''
            && class_exists($class)
            && is_subclass_of($class, AtlasDomainOrchestrator::class);
    }

    /**
     * @param  array<string,mixed>  $item
     * @param  array<int,string>  $keys
     */
    private function hasAnyPolicy(array $item, array $keys): bool
    {
        foreach ($keys as $key) {
            if (filled((array) ($item[$key] ?? []))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int,array<string,mixed>>  $flows
     */
    private function flowsHaveGatePolicy(array $flows): bool
    {
        return collect($flows)->isNotEmpty()
            && collect($flows)->every(fn (array $flow): bool => filled((array) ($flow['gate_policy'] ?? [])));
    }

    /**
     * @param  array<string,mixed>  $domain
     * @param  array<int,array<string,mixed>>  $flows
     */
    private function hasLearningPolicy(array $domain, array $flows): bool
    {
        if ($this->hasAnyPolicy($domain, ['memory_policy'])) {
            return true;
        }

        return collect($flows)->contains(fn (array $flow): bool => filled(data_get($flow, 'memory_policy'))
            || filled(data_get($flow, 'metadata.learning'))
            || filled(data_get($flow, 'metadata.learning_policy')));
    }

    /**
     * @param  array<string,mixed>  $domain
     * @param  array<int,array<string,mixed>>  $flows
     */
    private function hasSurfacePolicy(array $domain, array $flows): bool
    {
        if (filled(data_get($domain, 'metadata.surfaces')) || filled(data_get($domain, 'metadata.surface_policy'))) {
            return true;
        }

        return collect($flows)->contains(fn (array $flow): bool => filled(data_get($flow, 'metadata.surfaces'))
            || filled(data_get($flow, 'metadata.surface_policy')));
    }

    /**
     * @param  array<string,array{passed:bool,description:string,next_action:string}>  $checks
     */
    private function status(float $score, array $checks): string
    {
        if ($score >= 1.0) {
            return 'ready';
        }

        if (($checks['orchestrator']['passed'] ?? false)
            && ($checks['runtime']['passed'] ?? false)
            && ($checks['maturity_gate']['passed'] ?? false)) {
            return 'executable_incomplete';
        }

        return 'scaffold';
    }
}
