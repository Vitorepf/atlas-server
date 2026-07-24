<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Lead-authoring surface — loads a reviewed batch of Autonomous-Government lane packet specs into the live
 * esteira, idempotently, through the SAME validated path a normal enqueue takes
 * ({@see AgentControlPlaneTaskQueueOrchestrator::prepareAndEnqueue}: build → scope-lock validate → quality
 * self-sufficiency gate → enqueue). It is the durable, reviewable alternative to ad-hoc scripting.
 *
 * INPUT: a JSON file (--specs=) holding {"packets":[ {task_packet_id, objective, allowed_files[], scope_in[],
 * acceptance_criteria[], evidence_requirements[], depends_on[], wave, risk_level} ... ]}.
 *
 * SAFE BY CONSTRUCTION:
 *   - IDEMPOTENT: a task_packet_id already present in the queue is SKIPPED (re-runnable; never duplicates).
 *   - HONEST: a spec the quality gate rejects is reported as `prepare_blocked` with the reason — never forced in.
 *   - --dry-run shows the full plan (per-spec verdict) without touching the queue.
 * It never bypasses the inspector, so a non-self-sufficient packet cannot be seeded.
 */
final class AtlasTaskSeedGovLanesCommand extends Command
{
    use EmitsCanonicalJson;

    /** @var string */
    protected $signature = 'atlas:task:seed-gov-lanes {--specs= : path to the JSON specs file} {--dry-run} {--json}';

    /** @var string */
    protected $description = 'Idempotently seed reviewed Autonomous-Government lane task packets into the serving queue.';

    public function handle(): int
    {
        $path = (string) ($this->option('specs') ?? '');
        if ($path === '' || ! is_file($path)) {
            return $this->emit(['status' => 'usage_error', 'reason' => '--specs=<path to JSON> required'], self::FAILURE);
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            return $this->emit(['status' => 'invalid_json', 'reason' => $e->getMessage()], self::FAILURE);
        }

        $packets = is_array($decoded['packets'] ?? null) ? $decoded['packets'] : (array_is_list($decoded) ? $decoded : []);
        if ($packets === []) {
            return $this->emit(['status' => 'no_packets', 'reason' => 'specs file has no packets[]'], self::FAILURE);
        }

        // Top-level (batch-wide) reviewed axis exceptions — forwarded verbatim into every
        // packet's builder input. The builder itself only honors these for the
        // autonomous-gov-bootstrap source this command stamps below, and silently ignores any
        // entry that is not an exact known FORBIDDEN_AXES prefix.
        $reviewedAxisExceptions = array_values(array_map(
            'strval',
            (array) ($decoded['reviewed_axis_exceptions'] ?? []),
        ));

        $dryRun = (bool) $this->option('dry-run');
        // CRITICAL: seed onto the SERVING stack's DEDICATED disk (ATLAS_TASK_SERVING_QUEUE_DISK) — the exact disk
        // `atlas:task next` reads. Resolving the container default writes to the shared/legacy disk that workers
        // never read, so the packets would be invisible to the swarm.
        $orch = AtlasTaskServingStack::orchestrator();
        $queue = AtlasTaskServingStack::queueRepo();
        $builder = new AgentControlPlaneTaskPacketBuilder;
        $inspector = new AtlasTaskPacketQualityInspector;

        $results = [];
        $counts = ['enqueued' => 0, 'skipped_exists' => 0, 'prepare_blocked' => 0, 'dry_run' => 0, 'error' => 0];

        foreach ($packets as $i => $spec) {
            $id = trim((string) ($spec['task_packet_id'] ?? ''));
            if ($id === '') {
                $results[] = ['index' => $i, 'status' => 'error', 'reason' => 'missing task_packet_id'];
                $counts['error']++;

                continue;
            }

            if ($queue->get($id) !== null) {
                $results[] = ['task_packet_id' => $id, 'status' => 'skipped_exists'];
                $counts['skipped_exists']++;

                continue;
            }

            $packetInput = $this->toPacketInput($spec, $id, $reviewedAxisExceptions);

            if ($dryRun) {
                // Build + inspect ONLY (no enqueue) so the operator sees self-sufficiency + scope BEFORE anything
                // lands — mirrors prepareAndEnqueue's pre-enqueue gate without the side effect.
                $packet = $builder->build($packetInput);
                $quality = $inspector->inspect($packet);
                $results[] = [
                    'task_packet_id' => $id,
                    'status' => 'dry_run',
                    'build_status' => (string) ($packet['status'] ?? ''),
                    'self_sufficient' => (bool) ($quality['self_sufficient'] ?? false),
                    'allowed_files' => array_values((array) data_get($packet, 'normalized_scope.allowed_files', [])),
                    'quality_reasons' => array_values((array) ($quality['reasons'] ?? $quality['deficiencies'] ?? [])),
                ];
                $counts['dry_run']++;

                continue;
            }

            try {
                $env = $orch->prepareAndEnqueue([
                    'task_packet' => $packetInput,
                    'queue' => ['priority' => (int) ($spec['priority'] ?? 5), 'tags' => array_values(array_map('strval', (array) ($spec['tags'] ?? ['autonomous-gov'])))],
                ]);
                $event = (string) ($env['event'] ?? $env['status'] ?? '');
                if ($event === 'prepared_and_enqueued') {
                    $results[] = ['task_packet_id' => $id, 'status' => 'enqueued', 'wave' => (int) ($spec['wave'] ?? 1)];
                    $counts['enqueued']++;
                } else {
                    $results[] = ['task_packet_id' => $id, 'status' => 'prepare_blocked', 'reason' => (string) data_get($env, 'reason', $event), 'detail' => data_get($env, 'validation') ?? data_get($env, 'packet_quality')];
                    $counts['prepare_blocked']++;
                }
            } catch (Throwable $e) {
                $results[] = ['task_packet_id' => $id, 'status' => 'error', 'reason' => $e->getMessage()];
                $counts['error']++;
            }
        }

        return $this->emit([
            'status' => 'ok',
            'dry_run' => $dryRun,
            'counts' => $counts,
            'results' => $results,
        ], self::SUCCESS);
    }

    /**
     * Map a reviewed spec into the builder's input shape. depends_on + wave ride INSIDE the packet input
     * (that is where prepareAndEnqueue reads them for the queue metadata).
     *
     * @param  array<string,mixed>  $spec
     * @param  list<string>  $reviewedAxisExceptions  batch-wide, forwarded verbatim; the builder
     *   decides whether to honor it (autonomous-gov-bootstrap source only, exact axis match only)
     * @return array<string,mixed>
     */
    private function toPacketInput(array $spec, string $id, array $reviewedAxisExceptions = []): array
    {
        $input = [
            'task_packet_id' => $id,
            'objective' => (string) ($spec['objective'] ?? ''),
            'source' => 'autonomous-gov-bootstrap',
            'operator_id' => 'atlas-lead',
            'allowed_files' => array_values(array_map('strval', (array) ($spec['allowed_files'] ?? []))),
            'scope_in' => array_values(array_map('strval', (array) ($spec['scope_in'] ?? []))),
            'forbidden_files' => array_values(array_map('strval', (array) ($spec['forbidden_files'] ?? []))),
            'acceptance_criteria' => array_values(array_map('strval', (array) ($spec['acceptance_criteria'] ?? []))),
            'required_evidence' => array_values(array_map('strval', (array) ($spec['evidence_requirements'] ?? []))),
            'risk_level' => strtolower(trim((string) ($spec['risk_level'] ?? 'medium'))),
            'depends_on' => array_values(array_filter((array) ($spec['depends_on'] ?? []), 'is_string')),
            'wave' => (int) ($spec['wave'] ?? 1),
            'reviewed_axis_exceptions' => $reviewedAxisExceptions,
        ];
        if (($spec['quality_foundry_required'] ?? false) === true) {
            $input['quality_foundry_required'] = true;
            if (is_array($spec['execution_order'] ?? null)) {
                $input['execution_order'] = $spec['execution_order'];
            }
        }

        return $input;
    }

    /** @param array<string,mixed> $payload */
    private function emit(array $payload, int $code): int
    {
        $this->line($this->encode($payload));

        return $code;
    }
}
