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
        'voice.wake_word_detect',
        'voice.turn_to_first_audio',
        'voice.interruption_stop_audio',
        'cognitive.dreyfus.lookup',
        'cognitive.dreyfus.resolve',
        'cognitive.dreyfus.aggregate',
        'cognitive.dreyfus.gate',
        'cognitive.worked_example.select',
        'cognitive.worked_example.render',
        'cognitive.worked_example.gate',
        'cognitive.process_pattern.catalog',
        'cognitive.process_pattern.matcher',
        'cognitive.process_pattern.gate',
        'cognitive.failure.classify',
        'cognitive.failure.similarity',
        'cognitive.failure.diversity',
        'cognitive.failure.alert',
        'cognitive.failure.gate',
        'cognitive.srl.forethought',
        'cognitive.srl.performance_observation',
        'cognitive.srl.episode_persist',
        'cognitive.srl.gate',
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
            'voice.wake_word_detect' => new KernelSloTarget('voice.wake_word_detect', 50, 250, 600, 99.0, 'critical', 'Local wake-word/VAD detection before any audio stream leaves the device.'),
            'voice.turn_to_first_audio' => new KernelSloTarget('voice.turn_to_first_audio', 250, 600, 1200, 98.5, 'critical', 'Realtime voice turn latency from accepted transcript to first synthesized audio.'),
            'voice.interruption_stop_audio' => new KernelSloTarget('voice.interruption_stop_audio', 60, 250, 500, 99.0, 'critical', 'Barge-in latency from interruption request to audio stop acknowledgement.'),
            'cognitive.dreyfus.lookup' => new KernelSloTarget('cognitive.dreyfus.lookup', 10, 50, 120, 99.5, 'medium', 'Lookup Dreyfus overlay for a knowledge node and domain.'),
            'cognitive.dreyfus.resolve' => new KernelSloTarget('cognitive.dreyfus.resolve', 50, 200, 500, 99.0, 'medium', 'Resolve Dreyfus stage and pedagogy mode for a learning flow.'),
            'cognitive.dreyfus.aggregate' => new KernelSloTarget('cognitive.dreyfus.aggregate', 250, 2500, 10000, 98.0, 'medium', 'Aggregate ledger evidence into Dreyfus stage signals.'),
            'cognitive.dreyfus.gate' => new KernelSloTarget('cognitive.dreyfus.gate', 5, 30, 100, 99.5, 'medium', 'Validate pedagogy mode is resolved before cognitive provider/runtime work.'),
            'cognitive.worked_example.select' => new KernelSloTarget('cognitive.worked_example.select', 50, 150, 400, 99.0, 'medium', 'Select an appropriate worked example for node, domain and source preference.'),
            'cognitive.worked_example.render' => new KernelSloTarget('cognitive.worked_example.render', 75, 300, 750, 99.0, 'medium', 'Render worked example with fading schedule.'),
            'cognitive.worked_example.gate' => new KernelSloTarget('cognitive.worked_example.gate', 5, 50, 120, 99.5, 'medium', 'Validate worked example fading level matches Dreyfus stage.'),
            'cognitive.process_pattern.catalog' => new KernelSloTarget('cognitive.process_pattern.catalog', 25, 100, 250, 99.0, 'medium', 'Query the process pattern catalog.'),
            'cognitive.process_pattern.matcher' => new KernelSloTarget('cognitive.process_pattern.matcher', 100, 600, 1500, 98.0, 'medium', 'Rank applicable process patterns for a problem description.'),
            'cognitive.process_pattern.gate' => new KernelSloTarget('cognitive.process_pattern.gate', 5, 50, 120, 99.5, 'medium', 'Validate process pattern structure before cataloging or provider use.'),
            'cognitive.failure.classify' => new KernelSloTarget('cognitive.failure.classify', 50, 250, 600, 99.0, 'medium', 'Classify ledger failures into provider-safe cognitive failure signatures.'),
            'cognitive.failure.similarity' => new KernelSloTarget('cognitive.failure.similarity', 75, 400, 1000, 98.0, 'medium', 'Compute similarity and recurrence against previous failure signatures.'),
            'cognitive.failure.diversity' => new KernelSloTarget('cognitive.failure.diversity', 250, 2500, 10000, 98.0, 'medium', 'Compute failure diversity index and repeated-signature read model.'),
            'cognitive.failure.alert' => new KernelSloTarget('cognitive.failure.alert', 100, 1000, 60000, 99.0, 'medium', 'Emit repeated failure alerts within the C20 productive-failure window.'),
            'cognitive.failure.gate' => new KernelSloTarget('cognitive.failure.gate', 5, 50, 120, 99.5, 'medium', 'Validate failure signatures before provider/runtime use.'),
            'cognitive.srl.forethought' => new KernelSloTarget('cognitive.srl.forethought', 25, 100, 250, 99.0, 'medium', 'Capture opt-in SRL forethought before a learning flow.'),
            'cognitive.srl.performance_observation' => new KernelSloTarget('cognitive.srl.performance_observation', 10, 50, 150, 99.0, 'medium', 'Persist lightweight SRL performance observation without disrupting the flow.'),
            'cognitive.srl.episode_persist' => new KernelSloTarget('cognitive.srl.episode_persist', 25, 80, 250, 99.0, 'medium', 'Persist SRL reflection and episode completion.'),
            'cognitive.srl.gate' => new KernelSloTarget('cognitive.srl.gate', 5, 30, 100, 99.5, 'medium', 'Validate SRL phase ordering and opt-in safety.'),
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
