<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AcosMax\AcosMaxProceduralSkillPromoterService;
use Illuminate\Console\Command;

final class AtlasAiProceduralSkillPromoterCommand extends Command
{
    protected $signature = 'atlas:ai:procedural-skill-promoter
        {--floor= : Override procedural case_count floor for local inspection}
        {--enqueue : Materialise floor-met skill.v1 proposals into the ASI-02 held queue when the default-OFF flag is enabled}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero until at least one procedural playbook reaches the floor}';

    protected $description = 'MULTJ-04 — procedural playbook to skill.v1 promoter, default-OFF and held under ASI-02.';

    public function handle(AcosMaxProceduralSkillPromoterService $service): int
    {
        $floorOpt = $this->option('floor');
        $floor = is_numeric($floorOpt) ? (int) $floorOpt : null;
        $report = $service->report($floor, (bool) $this->option('enqueue'));

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>MULTJ-04 procedural skill promoter</>', (string) ($report['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Playbooks', (string) data_get($report, 'totals.procedural_playbooks', 0));
            $this->components->twoColumnDetail('Floor met', (string) data_get($report, 'totals.floor_met', 0));
            $this->components->twoColumnDetail('Enqueued', (string) data_get($report, 'totals.enqueued', 0));
        }

        if ((bool) $this->option('strict') && ($report['status'] ?? null) !== 'ok') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
