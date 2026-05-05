<?php

namespace App\Services\Ai\Kernel\Slo;

final class KernelSloTargets
{
    /**
     * @return array<string,array{p50_ms:int,p95_ms:int,p99_ms:int}>
     */
    public function all(): array
    {
        return [
            'input.normalize' => ['p50_ms' => 25, 'p95_ms' => 100, 'p99_ms' => 250],
            'intent.classify' => ['p50_ms' => 75, 'p95_ms' => 300, 'p99_ms' => 750],
            'domain.resolve' => ['p50_ms' => 20, 'p95_ms' => 80, 'p99_ms' => 200],
            'context.compose' => ['p50_ms' => 250, 'p95_ms' => 1500, 'p99_ms' => 4000],
            'policy.resolve' => ['p50_ms' => 50, 'p95_ms' => 200, 'p99_ms' => 500],
            'decide.issue_receipt' => ['p50_ms' => 75, 'p95_ms' => 300, 'p99_ms' => 750],
            'provider.prepare' => ['p50_ms' => 100, 'p95_ms' => 500, 'p99_ms' => 1000],
            'runtime.plan' => ['p50_ms' => 150, 'p95_ms' => 750, 'p99_ms' => 2000],
            'runtime.execute' => ['p50_ms' => 5000, 'p95_ms' => 120000, 'p99_ms' => 300000],
            'gate.evaluate' => ['p50_ms' => 500, 'p95_ms' => 5000, 'p99_ms' => 15000],
            'repair.loop' => ['p50_ms' => 10000, 'p95_ms' => 180000, 'p99_ms' => 600000],
            'evidence.record' => ['p50_ms' => 50, 'p95_ms' => 250, 'p99_ms' => 1000],
            'learning.project' => ['p50_ms' => 250, 'p95_ms' => 2500, 'p99_ms' => 10000],
            'output.render' => ['p50_ms' => 100, 'p95_ms' => 500, 'p99_ms' => 1500],
        ];
    }

    /**
     * @return array{ok:bool,errors:array<int,string>,count:int}
     */
    public function complianceReport(): array
    {
        $errors = [];

        foreach ($this->all() as $stage => $target) {
            if ($target['p50_ms'] <= 0 || $target['p95_ms'] < $target['p50_ms'] || $target['p99_ms'] < $target['p95_ms']) {
                $errors[] = "{$stage} must satisfy 0 < p50 <= p95 <= p99";
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'count' => count($this->all()),
        ];
    }
}
