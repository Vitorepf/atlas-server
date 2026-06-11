<?php

namespace App\Console\Commands;

use App\Services\Ai\Cognitive\Harness\AtlasHarnessFrozenSuite;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessProposalBridge;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessSurface;
use Illuminate\Console\Command;

/**
 * AP-819 Obra B — superfície do harness + ponte cluster→proposta + suite congelada.
 *
 *   surface  — lista as seções declaradas, valores vivos e overrides ativos.
 *   propose  — roda a ponte (alertas ≥3 → proposta harness_config, PROPOSE-ONLY).
 *   suite    — avalia a suite congelada; --baseline sela o baseline pré-loop;
 *              sem --baseline compara com o baseline (regra Δ_in≥0 ∧ Δ_ho≥0 ∧ max>0).
 *   reverse  — reverte um override aplicado (a alça de rollback do operador).
 */
class AtlasHarnessCommand extends Command
{
    protected $signature = 'atlas:harness
        {action=surface : surface|propose|suite|reverse}
        {key? : Surface key for reverse mode}
        {--baseline : Seal the frozen-suite baseline (suite mode)}
        {--limit=5 : Max alerts considered (propose mode)}
        {--json : Print machine-readable JSON}';

    protected $description = 'AP-819 Obra B — Atlas Harness Surface v1: surface, cluster→proposal bridge, frozen suite, reverse.';

    public function handle(
        AtlasHarnessSurface $surface,
        AtlasHarnessProposalBridge $bridge,
        AtlasHarnessFrozenSuite $suite,
    ): int {
        $action = trim((string) $this->argument('action')) ?: 'surface';

        $payload = match ($action) {
            'surface' => $this->surfaceReport($surface),
            'propose' => $bridge->propose(max(1, (int) $this->option('limit'))),
            'suite' => $this->option('baseline')
                ? ['sealed' => true, 'baseline' => $suite->sealBaseline()]
                : ['evaluation' => $suite->evaluate(), 'promotion' => $suite->promotionVerdict()],
            'reverse' => $surface->reverseOverride(trim((string) ($this->argument('key') ?? ''))),
            default => null,
        };

        if ($payload === null) {
            $this->error('Ação inválida. Use: surface|propose|suite|reverse');

            return self::FAILURE;
        }

        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * @return array<string,mixed>
     */
    private function surfaceReport(AtlasHarnessSurface $surface): array
    {
        $sections = [];
        foreach ($surface->sections() as $key => $section) {
            $sections[$key] = $section + ['current_value' => $surface->currentValue($key)];
        }

        return [
            'schema_version' => AtlasHarnessSurface::SCHEMA_VERSION,
            'sections' => $sections,
            'active_overrides' => $surface->readOverrides(),
            'overrides_path' => $surface->overridesPath(),
        ];
    }
}
