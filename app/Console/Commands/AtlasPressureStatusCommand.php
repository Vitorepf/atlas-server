<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\EngineeringKernel\PressureLayerGuards;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * atlas:pressure:status — the CADENCE reader for the Cognitive Pressure Layer. Reports, PER
 * guard, the REAL accumulated verdicts from the outcome ledger (runs / pass / captures /
 * proven / capture_rate) — the honest signal Goal 4 reads to decide "queda de erro medida".
 * 0 at the start is HONEST, not fabricated. Read-only: it never records.
 *
 * @see docs/engineering-knowledge-base/atlas-orchestrator-canon.md
 */
final class AtlasPressureStatusCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:pressure:status
        {--task-category= : filter to one task_category (default: all)}
        {--json : emit JSON}';

    protected $description = 'Report accumulated Cognitive Pressure Layer guard verdicts (cadence) from the outcome ledger — the signal Goal 4 reads.';

    public function handle(AtlasDecideLiveOutcomeFeedbackService $feedback): int
    {
        $filter = trim((string) $this->option('task-category'));

        /** @var array<string,array<string,mixed>> $byGuard */
        $byGuard = [];
        foreach (PressureLayerGuards::ADVISORY_ROLES as $role) {
            $byGuard[(string) $role['role_id']] = [
                'guard' => (string) $role['role_id'],
                'prevents' => (string) ($role['prevents'] ?? ''),
                'runs' => 0,
                'pass' => 0,
                'captures' => 0,
                'proven' => 0,
            ];
        }

        foreach ($feedback->listOutcomes() as $o) {
            $role = (string) ($o['role'] ?? '');
            if (! isset($byGuard[$role])) {
                continue;
            }
            if ($filter !== '' && (string) ($o['task_category'] ?? '') !== $filter) {
                continue;
            }
            $byGuard[$role]['runs']++;
            $result = (string) ($o['result'] ?? '');
            if ($result === AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS) {
                $byGuard[$role]['pass']++;
                if (($o['proven_real'] ?? false) === true) {
                    $byGuard[$role]['proven']++;
                }
            } elseif ($result === AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE) {
                // A guard "captures" when it FAILS a candidate — the real-problem signal Goal 4 weighs.
                $byGuard[$role]['captures']++;
            }
        }

        $rows = [];
        $total = 0;
        foreach ($byGuard as $g) {
            $g['capture_rate'] = $g['runs'] > 0 ? round($g['captures'] / $g['runs'], 4) : 0.0;
            $rows[] = $g;
            $total += (int) $g['runs'];
        }

        $payload = [
            'schema' => 'atlas.pressure_layer.cadence_status.v1',
            'task_category' => $filter !== '' ? $filter : 'all',
            'total_verdicts' => $total,
            'guards' => $rows,
            'note' => $total === 0
                ? 'honest zero: no cadence accumulated yet (not fabricated)'
                : 'real ledger counters',
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('task_category', (string) $payload['task_category']);
        $this->components->twoColumnDetail('total verdicts', (string) $total);
        $this->table(
            ['guard', 'runs', 'pass', 'captures', 'proven', 'capture_rate'],
            array_map(static fn (array $g): array => [
                (string) $g['guard'],
                (string) $g['runs'],
                (string) $g['pass'],
                (string) $g['captures'],
                (string) $g['proven'],
                (string) $g['capture_rate'],
            ], $rows),
        );
        if ($total === 0) {
            $this->line('  '.(string) $payload['note']);
        }

        return self::SUCCESS;
    }
}
