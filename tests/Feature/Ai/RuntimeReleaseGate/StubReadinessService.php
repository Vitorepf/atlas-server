<?php

namespace Tests\Feature\Ai\RuntimeReleaseGate;

use App\Services\Ai\RuntimeReadiness\AtlasAiRuntimeReadinessService;
use Illuminate\Container\Container;

/**
 * Deterministic stub used by Release Gate tests so we can simulate every
 * upstream status without standing up the full runtime readiness graph.
 */
class StubReadinessService extends AtlasAiRuntimeReadinessService
{
    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     * @param  array<string,mixed>  $extra
     */
    public function __construct(
        private readonly string $upstreamStatus,
        private readonly array $checks = [],
        private readonly array $blockers = [],
        private readonly array $warnings = [],
        private readonly array $extra = [],
    ) {
        parent::__construct(Container::getInstance());
    }

    public function report(): array
    {
        $summary = [
            'total' => count($this->checks),
            'passed' => count(array_filter(
                $this->checks,
                static fn (array $c): bool => ($c['status'] ?? null) === 'passed',
            )),
            'partial' => count(array_filter(
                $this->checks,
                static fn (array $c): bool => ($c['status'] ?? null) === 'warn',
            )),
            'failed' => count(array_filter(
                $this->checks,
                static fn (array $c): bool => ($c['status'] ?? null) === 'failed',
            )),
            'critical_failed' => count(array_filter(
                $this->checks,
                static fn (array $c): bool => ($c['status'] ?? null) === 'failed' && ($c['severity'] ?? null) === 'critical',
            )),
            'warn_failed' => count(array_filter(
                $this->checks,
                static fn (array $c): bool => ($c['status'] ?? null) === 'warn' || (($c['status'] ?? null) === 'failed' && ($c['severity'] ?? null) === 'warn'),
            )),
        ];

        $payload = [
            'schema_version' => parent::SCHEMA_VERSION,
            'status' => $this->upstreamStatus,
            'generated_at' => '2026-05-19T00:00:00+00:00',
            'summary' => $summary,
            'checks' => $this->checks,
            'blockers' => $this->blockers,
            'warnings' => $this->warnings,
            'evidence_refs' => array_map(
                static fn (string $c): string => $c,
                (array) ($this->extra['evidence_refs'] ?? []),
            ),
            'required_commands' => [
                'php artisan atlas:ai:product-certify --json',
                'php artisan atlas:ai:runtime-readiness --json',
            ],
            'claim_policy' => $this->extra['claim_policy'] ?? [
                'declares_benchmark' => false,
                'declares_superiority' => false,
                'scope' => 'atlas_ai_runtime_release_gate',
                'forbidden_claims' => ['better_than_claude_code'],
            ],
            'release_scope' => 'atlas_ai_runtime',
            'certification_hash' => 'stub-readiness-hash-'.$this->upstreamStatus,
        ];

        return $payload;
    }
}
