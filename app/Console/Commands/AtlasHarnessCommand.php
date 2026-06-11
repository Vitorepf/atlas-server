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
        {action=surface : surface|propose|suite|reverse|autopilot}
        {key? : Surface key for reverse mode}
        {--baseline : Seal the frozen-suite baseline (suite mode)}
        {--limit=5 : Max alerts considered (propose mode)}
        {--json : Print machine-readable JSON}';

    protected $description = 'AP-819 — Atlas Harness Surface v1: surface, cluster→proposal bridge, frozen suite, reverse, autopilot (math-gated).';

    public function handle(
        AtlasHarnessSurface $surface,
        AtlasHarnessProposalBridge $bridge,
        AtlasHarnessFrozenSuite $suite,
        \App\Services\Ai\Cognitive\Harness\AtlasHarnessAutopilot $autopilot,
    ): int {
        $action = trim((string) $this->argument('action')) ?: 'surface';

        $payload = match ($action) {
            'surface' => $this->surfaceReport($surface),
            'propose' => $bridge->propose(max(1, (int) $this->option('limit'))),
            'suite' => $this->option('baseline')
                ? ['sealed' => true, 'baseline' => $suite->sealBaseline()]
                : ['evaluation' => $suite->evaluate(), 'promotion' => $suite->promotionVerdict()],
            'reverse' => $this->reverseAny($surface, trim((string) ($this->argument('key') ?? ''))),
            // monitor PRIMEIRO (fecha experimentos vencidos), depois aplica o próximo.
            'autopilot' => ['monitor' => $autopilot->monitor(), 'apply' => $autopilot->run()],
            default => null,
        };

        if ($payload === null) {
            $this->error('Ação inválida. Use: surface|propose|suite|reverse|autopilot');

            return self::FAILURE;
        }

        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /**
     * Reverse despachado pelo TIPO da chave: botão de config OU seção de instrução.
     *
     * @return array<string,mixed>
     */
    private function reverseAny(AtlasHarnessSurface $surface, string $key): array
    {
        if (isset($surface->sections()[$key])) {
            return $surface->reverseOverride($key);
        }
        $instructions = app(\App\Services\Ai\Cognitive\Harness\AtlasHarnessInstructionSurface::class);
        if (isset($instructions->sections()[$key])) {
            return $instructions->reverseOverride($key);
        }

        return ['reversed' => false, 'reason' => 'key_not_in_any_harness_surface', 'key' => $key];
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

        $instructions = app(\App\Services\Ai\Cognitive\Harness\AtlasHarnessInstructionSurface::class);
        $instructionSections = [];
        foreach ($instructions->sections() as $name => $declared) {
            $instructionSections[$name] = [
                'purpose' => $declared['purpose'],
                'current_text' => $instructions->text($name),
                'is_default' => $instructions->text($name) === $declared['default'],
                'search_space_size' => count($instructions->searchSpace($name)),
            ];
        }

        return [
            'schema_version' => AtlasHarnessSurface::SCHEMA_VERSION,
            'sections' => $sections,
            'instruction_sections' => $instructionSections,
            'active_overrides' => $surface->readOverrides(),
            'active_instruction_overrides' => $instructions->readOverrides(),
            'overrides_path' => $surface->overridesPath(),
        ];
    }
}
