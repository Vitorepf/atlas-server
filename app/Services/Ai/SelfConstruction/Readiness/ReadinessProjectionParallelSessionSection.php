<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

/** SC-01 fatia parallelSessionPlan (Obra 4). */
final class ReadinessProjectionParallelSessionSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;
    public function setMother(AtlasSelfConstructionReadinessService $mother): self { $this->mother = $mother; return $this; }
    public function __call(string $name, array $arguments): mixed {
        if ($this->mother === null) throw new \RuntimeException("ReadinessProjectionParallelSessionSection mother not bound");
        $method = new \ReflectionMethod($this->mother, $name);
        return $method->invokeArgs($this->mother, $arguments);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function parallelSessionPlan(array $options = []): array
    {
        $queuePayload = $this->packetQueue($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);
        $assignable = array_values(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'available'));
        $blocked = array_values(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'blocked_by_dependency'));
        $withheld = array_values(array_filter($entries, fn (array $entry): bool => $entry['queue_state'] === 'withheld'));

        $slots = [];
        for ($slot = 1; $slot <= 5; $slot++) {
            $entry = $assignable[$slot - 1] ?? null;

            $slots[] = [
                'slot_id' => sprintf('SESSION-SLOT-%03d', $slot),
                'state' => $entry === null ? 'idle_no_safe_packet' : 'preview_assignable',
                'packet_id' => data_get($entry, 'packet_id'),
                'lane' => data_get($entry, 'lane'),
                'bootstrap_command' => $entry === null ? null : $this->packetCommand('ai-session-bootstrap', data_get($entry, 'packet_id')),
                'required_first_commands' => $entry === null ? [] : [
                    'git status --short',
                    'git diff --stat',
                    'git diff --name-only',
                    'php artisan atlas:ai:self-construction --parallel-session-plan --json',
                    $this->packetCommand('ai-session-bootstrap', data_get($entry, 'packet_id')),
                    $this->packetCommand('scope-validator', data_get($entry, 'packet_id')),
                ],
                'execution_allowed' => false,
                'claim_persisted' => false,
                'ledger_write_allowed' => false,
                'dispatch_allowed' => false,
                'reason' => $entry === null
                    ? 'No additional dependency-free packet is safely assignable in read-only mode.'
                    : 'Packet is dependency-free and can be previewed by one AI session without persisted claim.',
            ];
        }

        $plan = [
            'plan_id' => 'PARALLEL-SESSION-PLAN-SELF-CONSTRUCTION-0001',
            'max_session_slots' => 5,
            'slot_count' => count($slots),
            'preview_assignable_count' => count($assignable),
            'idle_slot_count' => count(array_filter($slots, fn (array $slot): bool => $slot['state'] === 'idle_no_safe_packet')),
            'blocked_packet_count' => count($blocked),
            'withheld_packet_count' => count($withheld),
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'slots' => $slots,
            'blocked_packets' => array_map(fn (array $entry): array => [
                'packet_id' => data_get($entry, 'packet_id'),
                'depends_on' => data_get($entry, 'depends_on'),
                'reason' => 'Packet dependency is not complete in the read-only queue.',
            ], $blocked),
            'withheld_packets' => array_map(fn (array $entry): array => [
                'packet_id' => data_get($entry, 'packet_id'),
                'forbidden_files' => data_get($entry, 'forbidden_files'),
                'reason' => 'Hot external scope is not assignable by Self-Construction.',
            ], $withheld),
            'execution_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_parallel_session_plan.v1',
            'status' => 'parallel_session_plan_ready',
            'mode' => 'read_only_parallel_session_plan',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'plan' => $plan,
            'plan_hash' => $this->stableHash($plan),
            'non_execution_guarantees' => [
                'parallel_session_plan_does_not_persist_claim',
                'parallel_session_plan_does_not_write_ledger',
                'parallel_session_plan_does_not_dispatch_work',
                'parallel_session_plan_does_not_enable_execution',
            ],
            'human_summary' => 'Parallel session plan is ready: up to five AI slots are previewed while claims, dispatch, ledger writes and execution stay disabled.',
        ];
    }
}
