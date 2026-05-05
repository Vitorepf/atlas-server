<?php

namespace App\Services\Ai\Kernel\Slo;

final class KernelSloTargets
{
    private const REQUIRED_STAGES = [
        'envelope.create',
        'input.normalize',
        'intent.classify',
        'domain.resolve',
        'flow.resolve',
        'profile.resolve',
        'policy.compile',
        'context.compose',
        'decide.issue',
        'ledger.append',
        'provider.prepare',
        'runtime.execute',
        'gate.evaluate',
        'repair.loop',
        'learning.project',
        'output.render',
    ];

    /**
     * @return array<string,array{p50_ms:int,p95_ms:int,p99_ms:int,success_rate:float,severity:string,notes:string}>
     */
    public function all(): array
    {
        return collect($this->targetObjects())
            ->mapWithKeys(fn (KernelSloTarget $target): array => [$target->stage => [
                'p50_ms' => $target->p50Ms,
                'p95_ms' => $target->p95Ms,
                'p99_ms' => $target->p99Ms,
                'success_rate' => $target->successRate,
                'severity' => $target->severity,
                'notes' => $target->notes,
            ]])
            ->all();
    }

    /**
     * @return array<string,KernelSloTarget>
     */
    public function targetObjects(): array
    {
        return [
            'envelope.create' => new KernelSloTarget('envelope.create', 5, 15, 30, 99.9, 'critical', 'Kernel envelope creation and invariant initialization.'),
            'input.normalize' => new KernelSloTarget('input.normalize', 25, 100, 250, 99.9, 'high', 'Text/file/image normalization entrypoint.'),
            'intent.classify' => new KernelSloTarget('intent.classify', 75, 300, 750, 99.0, 'high', 'Local-first intent and domain hints.'),
            'domain.resolve' => new KernelSloTarget('domain.resolve', 20, 80, 200, 99.9, 'critical', 'Resolve domain before profile/runtime selection.'),
            'flow.resolve' => new KernelSloTarget('flow.resolve', 20, 80, 200, 99.9, 'critical', 'Resolve flow inside selected domain.'),
            'profile.resolve' => new KernelSloTarget('profile.resolve', 30, 120, 300, 99.9, 'critical', 'Effective profile composition.'),
            'policy.compile' => new KernelSloTarget('policy.compile', 50, 200, 500, 99.9, 'critical', 'Compile permissions, tools, provider and budget policy.'),
            'context.compose' => new KernelSloTarget('context.compose', 250, 1500, 5000, 98.0, 'high', 'Open Brain, memory and domain context pack composition.'),
            'decide.issue' => new KernelSloTarget('decide.issue', 75, 300, 750, 99.5, 'critical', 'DecisionReceipt issuance.'),
            'ledger.append' => new KernelSloTarget('ledger.append', 5, 25, 50, 99.99, 'critical', 'Append-only evidence ledger hot path.'),
            'provider.prepare' => new KernelSloTarget('provider.prepare', 100, 500, 1000, 99.5, 'high', 'Provider driver request preparation and validation.'),
            'runtime.execute' => new KernelSloTarget('runtime.execute', 5000, 120000, 300000, 97.0, 'high', 'Provider/tool/domain execution.'),
            'gate.evaluate' => new KernelSloTarget('gate.evaluate', 500, 5000, 15000, 99.0, 'high', 'Quality, policy and release gate evaluation.'),
            'repair.loop' => new KernelSloTarget('repair.loop', 10000, 180000, 600000, 95.0, 'medium', 'Bounded repair loops.'),
            'learning.project' => new KernelSloTarget('learning.project', 250, 2500, 10000, 98.0, 'medium', 'Evidence to memory/curation projections.'),
            'output.render' => new KernelSloTarget('output.render', 100, 500, 1500, 99.5, 'medium', 'Surface-neutral output packet rendering.'),
        ];
    }

    public function targetFor(string $stage): ?KernelSloTarget
    {
        return $this->targetObjects()[$stage] ?? null;
    }

    public function assess(string $stage, int $durationMs, bool $success = true): KernelSloAssessment
    {
        $target = $this->targetFor($stage);

        if (! $target) {
            return new KernelSloAssessment(
                stage: $stage,
                durationMs: $durationMs,
                success: $success,
                status: 'unknown_stage',
                severity: 'high',
                target: null,
                violations: ['slo_stage_not_declared'],
            );
        }

        return $target->assess($durationMs, $success);
    }

    /**
     * @return array{ok:bool,errors:array<int,string>,count:int,stages:array<int,string>,schema_version:string}
     */
    public function complianceReport(): array
    {
        $errors = [];
        $targets = $this->all();

        foreach (self::REQUIRED_STAGES as $requiredStage) {
            if (! array_key_exists($requiredStage, $targets)) {
                $errors[] = "{$requiredStage} must be declared as a kernel SLO target";
            }
        }

        foreach ($targets as $stage => $target) {
            if ($target['p50_ms'] <= 0 || $target['p95_ms'] < $target['p50_ms'] || $target['p99_ms'] < $target['p95_ms']) {
                $errors[] = "{$stage} must satisfy 0 < p50 <= p95 <= p99";
            }

            if ($target['success_rate'] <= 0 || $target['success_rate'] > 100) {
                $errors[] = "{$stage} must declare success_rate in (0, 100]";
            }

            if (! in_array($target['severity'], ['critical', 'high', 'medium', 'low'], true)) {
                $errors[] = "{$stage} must declare severity as critical, high, medium or low";
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'count' => count($targets),
            'stages' => array_keys($targets),
            'schema_version' => 'atlas.kernel.slo_target.v1',
        ];
    }
}
