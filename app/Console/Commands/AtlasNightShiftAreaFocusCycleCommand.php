<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCycleRecorderService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusEvidencePackService;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * Night Shift · Area Focus Loop · Durable Cycle + Evidence Pack (AP-720).
 *
 * Read-only. Records a read-only Area Focus projection to append-only local
 * JSONL, replays it by cycle_id, lists an area's cycles and projects an evidence
 * pack — without opening a branch, invoking a provider, merging/deploying or
 * mutating the repo.
 */
class AtlasNightShiftAreaFocusCycleCommand extends Command
{
    protected $signature = 'atlas:night-shift:area-focus-cycle
        {action=record : record|replay|list|evidence-pack}
        {--area=agentic_engineering_os : Canonical area_id}
        {--cycle= : cycle_id (for replay / evidence-pack)}
        {--hours=24 : Self-Directed Evolution gap window in hours}
        {--limit= : Cap the number of findings}
        {--json : Emit JSON}';

    protected $description = 'Atlas Night Shift · Area Focus durable cycle + evidence pack (AP-720): record/replay/list/evidence-pack over append-only JSONL. No writes to repo, no provider, no execution.';

    public function handle(
        AreaFocusCycleRecorderService $recorder,
        AreaFocusEvidencePackService $packs,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'record' => $this->runRecord($recorder),
            'replay' => $this->runReplay($recorder),
            'list' => $this->runList($recorder),
            'evidence-pack' => $this->runEvidencePack($recorder, $packs),
            default => $this->blocked('unknown_action', $action),
        };
    }

    private function runRecord(AreaFocusCycleRecorderService $recorder): int
    {
        $cycle = $recorder->record($this->recordInput());
        $this->emit($cycle, function (array $c): void {
            $this->components->twoColumnDetail('Area Focus cycle', 'recorded (read-only)');
            $this->components->twoColumnDetail('Cycle', (string) ($c['cycle_id'] ?? '?'));
            $this->components->twoColumnDetail('Area', (string) ($c['area_id'] ?? '?'));
            $this->components->twoColumnDetail('Status', (string) ($c['report_status'] ?? '?'));
            $this->components->twoColumnDetail('Findings / inbox', sprintf('%d / %d', (int) ($c['finding_count'] ?? 0), (int) ($c['inbox_decision_count'] ?? 0)));
        });

        return self::SUCCESS;
    }

    private function runReplay(AreaFocusCycleRecorderService $recorder): int
    {
        $cycleId = trim((string) $this->option('cycle'));
        if ($cycleId === '') {
            return $this->blocked('cycle_required', 'replay');
        }
        $cycle = $recorder->replay($cycleId);
        if ($cycle === null) {
            return $this->blocked('cycle_not_found', $cycleId);
        }
        $this->emit($cycle, function (array $c): void {
            $this->components->twoColumnDetail('Area Focus cycle', 'replayed');
            $this->components->twoColumnDetail('Cycle', (string) ($c['cycle_id'] ?? '?'));
            $this->components->twoColumnDetail('Cycle hash', (string) ($c['cycle_hash'] ?? '?'));
        });

        return self::SUCCESS;
    }

    private function runList(AreaFocusCycleRecorderService $recorder): int
    {
        $payload = $recorder->listCycles((string) $this->option('area'));
        $this->emit($payload, function (array $p): void {
            $this->components->twoColumnDetail('Area Focus cycles', (string) ($p['area_id'] ?? '?'));
            $this->components->twoColumnDetail('Count', (string) ($p['cycle_count'] ?? 0));
            $this->components->twoColumnDetail('Corrupted lines', (string) ($p['corrupted_line_count'] ?? 0));
            foreach ($p['cycles'] ?? [] as $cycle) {
                $this->line(sprintf(
                    '  %s · %s · findings=%d · %s',
                    (string) ($cycle['cycle_id'] ?? '?'),
                    (string) ($cycle['report_status'] ?? '?'),
                    (int) ($cycle['finding_count'] ?? 0),
                    (string) ($cycle['recorded_at'] ?? ''),
                ));
            }
        });

        return self::SUCCESS;
    }

    private function runEvidencePack(AreaFocusCycleRecorderService $recorder, AreaFocusEvidencePackService $packs): int
    {
        $cycleId = trim((string) $this->option('cycle'));
        $cycle = $cycleId !== '' ? $recorder->replay($cycleId) : $recorder->record($this->recordInput());
        if ($cycle === null) {
            return $this->blocked('cycle_not_found', $cycleId);
        }
        $pack = $packs->build($cycle);
        $this->emit($pack, function (array $p): void {
            $this->components->twoColumnDetail('Evidence pack', (string) ($p['pack_id'] ?? '?'));
            $this->components->twoColumnDetail('Cycle', (string) ($p['cycle_id'] ?? '?'));
            $this->components->twoColumnDetail('Complete', YesNo::format($p['completeness']['complete'] ?? false));
            $this->components->twoColumnDetail('Morning Inbox ready', YesNo::format($p['morning_inbox_ready'] ?? false));
        });

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function recordInput(): array
    {
        $input = [
            'area_id' => (string) $this->option('area'),
            'hours' => (int) $this->option('hours'),
        ];
        if (($limit = $this->option('limit')) !== null && $limit !== '') {
            $input['limit'] = (int) $limit;
        }

        return $input;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, ?callable $human = null): void
    {
        if ((bool) $this->option('json') || $human === null) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $human($payload);
    }

    private function blocked(string $reason, string $detail): int
    {
        $this->line(json_encode([
            'schema_version' => 'atlas.software_company_stewardship.area_focus_cycle_command_error.v1',
            'status' => 'blocked',
            'reason' => $reason,
            'detail' => $detail,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
