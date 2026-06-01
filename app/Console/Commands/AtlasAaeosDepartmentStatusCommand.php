<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\AtlasAaeosDepartmentMaturityService;
use App\Services\Ai\Aaeos\AtlasAaeosQualityBarService;
use Illuminate\Console\Command;

/**
 * Runtime surface for the AAEOS department maturity + quality-bar matrices —
 * the command those canonical docs name in their next_actions. Exposes the
 * existing AtlasAaeosDepartmentMaturityService and AtlasAaeosQualityBarService
 * (per-department L0..L7 maturity and numeric SLA/SLO quality bar) so the
 * matrices stop being doc-only and become a queryable runtime read-model.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-department-maturity-matrix.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-department-quality-bar-matrix.md
 */
class AtlasAaeosDepartmentStatusCommand extends Command
{
    protected $signature = 'atlas:aaeos:department-status
        {--quality-bar : Include the quality-bar breach signal emission}
        {--json : Print machine-readable JSON}';

    protected $description = 'Show AAEOS per-department maturity (L0..L7) and numeric quality bar.';

    public function handle(
        AtlasAaeosDepartmentMaturityService $maturity,
        AtlasAaeosQualityBarService $qualityBar,
    ): int {
        $payload = [
            'schema_version' => 'atlas.aaeos.department_status.v1',
            'maturity' => $maturity->maturity(),
            'quality_bar' => $qualityBar->qualityBar(),
        ];
        if ((bool) $this->option('quality-bar')) {
            $payload['quality_bar_signal'] = $qualityBar->emitSignal();
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $departments = (array) data_get($payload, 'maturity.departments', []);
        if ($departments !== []) {
            $this->table(
                ['department', 'level', 'next', 'blockers'],
                collect($departments)->map(fn (array $d): array => [
                    (string) ($d['department'] ?? $d['id'] ?? '-'),
                    (string) ($d['level'] ?? $d['maturity_level'] ?? '-'),
                    (string) ($d['next_level'] ?? '-'),
                    (string) count((array) ($d['blockers'] ?? [])),
                ])->all(),
            );
        } else {
            $this->components->twoColumnDetail('maturity schema', (string) data_get($payload, 'maturity.schema_version', '-'));
            $this->components->twoColumnDetail('quality-bar schema', (string) data_get($payload, 'quality_bar.schema_version', '-'));
        }

        return self::SUCCESS;
    }
}
