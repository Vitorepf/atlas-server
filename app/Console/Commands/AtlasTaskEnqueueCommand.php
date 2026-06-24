<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * PART 2 — the manual ENQUEUE front door: the operator describes a real task and it enters the canonical
 * serving queue, quality-gated so a cold AI can implement it. The primary fuel source for the swarm (the
 * brain's autonomous origination is the model-bound complement — see atlas:task:replenish).
 *
 *   atlas:task:enqueue --objective="..." --allow=path --allow=path --accept="..." --evidence=tests_or_gates_result
 *   atlas:task:enqueue --file=tasks.json     # batch: [{objective, allowed_files, acceptance_criteria, required_evidence, scope_in?}]
 *
 * A task whose spec is not self-sufficient (no acceptance / no evidence / a bare-dir write scope) is REJECTED
 * with the exact deficiencies — the operator fixes the spec instead of stranding an AI later.
 */
class AtlasTaskEnqueueCommand extends Command
{
    protected $signature = 'atlas:task:enqueue
        {--objective= : what the AI must achieve (one task)}
        {--allow=* : a file the AI MAY edit (the commit scope); repeat per file}
        {--read=* : a file the AI may READ but not edit (scope_in); repeat per file}
        {--accept=* : an acceptance criterion (how we know it is done); repeat}
        {--evidence=* : a required evidence id (e.g. tests_or_gates_result); repeat}
        {--operator=operator : operator id stamped on the packet}
        {--id= : explicit task_packet_id (default: auto)}
        {--file= : JSON file with a batch of task specs}
        {--json : machine-readable output}';

    protected $description = 'Enqueue a real task for AIs to pull (atlas:task next). Quality-gated: an underspecified task is rejected.';

    public function handle(AtlasTaskPacketQualityInspector $inspector): int
    {
        // Enqueue onto the DEDICATED serving queue (isolated from the certification-probe pollution).
        $orchestrator = \App\Services\Ai\SelfConstruction\AtlasTaskServingStack::orchestrator();
        $specs = $this->collectSpecs();
        if ($specs === []) {
            $this->error('nothing to enqueue: pass --objective + --allow (+ --accept --evidence) or --file=tasks.json');

            return self::FAILURE;
        }

        $results = [];
        $allOk = true;
        foreach ($specs as $spec) {
            $packetInput = $this->buildPacketInput($spec);

            // Pre-gate the SPEC for self-sufficiency before it ever reaches the queue.
            $quality = $inspector->inspect($packetInput);
            if (! (bool) $quality['self_sufficient']) {
                $allOk = false;
                $results[] = ['ok' => false, 'task_packet_id' => $packetInput['task_packet_id'], 'reason' => 'not_self_sufficient', 'blocking_deficiencies' => $quality['blocking_deficiencies']];

                continue;
            }

            try {
                $res = $orchestrator->prepareAndEnqueue(['task_packet' => $packetInput]);
            } catch (Throwable $e) {
                $allOk = false;
                $results[] = ['ok' => false, 'task_packet_id' => $packetInput['task_packet_id'], 'reason' => 'enqueue_exception', 'error' => $e->getMessage()];

                continue;
            }

            $event = (string) ($res['event'] ?? '');
            $ok = $event === 'prepared_and_enqueued';
            $allOk = $allOk && $ok;
            $results[] = [
                'ok' => $ok,
                'task_packet_id' => $packetInput['task_packet_id'],
                'event' => $event,
                'reason' => $ok ? null : (string) data_get($res, 'reason', 'enqueue_blocked'),
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $allOk, 'enqueued' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($results as $r) {
                $this->line(($r['ok'] ? '<fg=green>✓</>' : '<fg=red>✗</>').' '.$r['task_packet_id'].($r['ok'] ? '' : '  — '.($r['reason'] ?? '').(isset($r['blocking_deficiencies']) ? ' ['.implode(',', $r['blocking_deficiencies']).']' : '')));
            }
        }

        return $allOk ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<array<string,mixed>> */
    private function collectSpecs(): array
    {
        $file = (string) ($this->option('file') ?? '');
        if ($file !== '') {
            $raw = is_file($file) ? (string) file_get_contents($file) : '';
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
        }

        $objective = (string) ($this->option('objective') ?? '');
        if ($objective === '') {
            return [];
        }

        return [[
            'task_packet_id' => (string) ($this->option('id') ?? ''),
            'objective' => $objective,
            'allowed_files' => array_values((array) $this->option('allow')),
            'scope_in' => array_values((array) $this->option('read')),
            'acceptance_criteria' => array_values((array) $this->option('accept')),
            'required_evidence' => array_values((array) $this->option('evidence')),
        ]];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private function buildPacketInput(array $spec): array
    {
        $allowed = array_values((array) ($spec['allowed_files'] ?? []));
        $scopeIn = array_values((array) ($spec['scope_in'] ?? []));
        // scope_in must cover allowed_files (the AI reads what it writes) — merge so the packet is coherent.
        $scopeIn = array_values(array_unique(array_merge($scopeIn, $allowed)));

        $id = (string) ($spec['task_packet_id'] ?? '');
        if ($id === '') {
            $id = 'op-'.Str::lower((string) Str::ulid());
        }

        return [
            'task_packet_id' => $id,
            'objective' => trim((string) ($spec['objective'] ?? '')),
            'operator_id' => (string) ($this->option('operator') ?? 'operator'),
            'allowed_files' => $allowed,
            'scope_in' => $scopeIn,
            'acceptance_criteria' => array_values((array) ($spec['acceptance_criteria'] ?? [])),
            'required_evidence' => array_values((array) ($spec['required_evidence'] ?? [])),
            // ORDER: prerequisite task ids + the version-ladder wave (the serving gates a claim on depends_on).
            'depends_on' => array_values(array_filter((array) ($spec['depends_on'] ?? []), 'is_string')),
            'wave' => (int) ($spec['wave'] ?? 0),
        ];
    }
}
