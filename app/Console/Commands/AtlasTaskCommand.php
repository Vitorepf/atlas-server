<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * PART 2 · A7 — the operator/agent-facing front door of THE CONTRACT. Two verbs over one fixed JSON schema:
 *
 *   atlas:task next   --client=<opaque-id> [--tag=..]* [--json]
 *   atlas:task report --client=<opaque-id> --task=<id> --lease=<id> [--outcome=success|failed|give_back]
 *                     [--evidence=<json|->] [--json]
 *
 * `--client` is the ONLY client parameter and is OPAQUE — any AI passes its own id; the server never branches
 * on platform. Delegates 1:1 to {@see AtlasTaskServingService}. Gated by the loop master switch.
 */
class AtlasTaskCommand extends Command
{
    protected $signature = 'atlas:task {action : next|report}
        {--client= : Opaque client id (any AI/harness)}
        {--task= : task_packet_id (report)}
        {--lease= : lease_id (report)}
        {--outcome=success : success|failed|give_back (report)}
        {--evidence= : JSON evidence, or - to read STDIN (report)}
        {--tag=* : optional queue tag filter (next)}
        {--json : Print machine-readable JSON}';

    protected $description = 'The Atlas task-serving contract: PULL the next task (next) or hand back a result (report). Platform-free, client_id opaque.';

    public function handle(AtlasTaskServingService $serving): int
    {
        $action = (string) $this->argument('action');
        $client = (string) ($this->option('client') ?? '');

        try {
            $result = match ($action) {
                'next' => $serving->next($client, ['tags' => array_values((array) $this->option('tag'))]),
                'report' => $serving->report(
                    $client,
                    (string) ($this->option('task') ?? ''),
                    (string) ($this->option('lease') ?? ''),
                    ['outcome' => (string) $this->option('outcome'), 'evidence' => $this->evidence()],
                ),
                default => ['schema' => 'atlas.task_serving.error.v1', 'status' => 'unknown_action', 'action' => $action],
            };
        } catch (Throwable $e) {
            $result = ['schema' => 'atlas.task_serving.error.v1', 'status' => 'error', 'error' => $e->getMessage()];
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        $ok = in_array((string) ($result['status'] ?? ''), ['served', 'no_claimable_task', 'reported', 'disabled'], true);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string, mixed> the decoded --evidence JSON (or stdin when '-'); [] on absent/invalid. */
    private function evidence(): array
    {
        $raw = (string) ($this->option('evidence') ?? '');
        if ($raw === '-') {
            $raw = (string) @file_get_contents('php://stdin');
        }
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
