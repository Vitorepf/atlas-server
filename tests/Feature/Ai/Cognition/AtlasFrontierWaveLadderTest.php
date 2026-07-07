<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasFrontierWaveLadder;
use Tests\TestCase;

/**
 * E — the frontier ladder etiqueta os 5 sistemas e NÃO ativa uma onda sem os ~5
 * eventos externos REAIS na anterior. "Nenhuma frente declara sucesso sobre si
 * mesma" (obra20 §15): activation é função de eventos no ledger, nunca de texto.
 */
class AtlasFrontierWaveLadderTest extends TestCase
{
    private function ladder(): AtlasFrontierWaveLadder
    {
        $path = tempnam(sys_get_temp_dir(), 'frontier_').'.jsonl';
        @unlink($path);

        return new AtlasFrontierWaveLadder($path);
    }

    public function test_etiqueta_five_systems_and_fase0_is_the_gate(): void
    {
        $status = $this->ladder()->status();

        $this->assertSame(AtlasFrontierWaveLadder::SCHEMA_VERSION, $status['schema_version']);
        $this->assertCount(5, $status['waves']);
        $this->assertSame('fase_0', $status['waves'][0]['wave']);
        $this->assertSame('portao', $status['waves'][0]['activation']);

        // The five frontier systems are all etiquetados across the waves.
        $systems = collect($status['waves'])->pluck('systems')->flatten()->all();
        foreach (['SIS2', 'SIS3', 'SIS5', 'SIS6', 'SIS7', 'economia'] as $s) {
            $this->assertContains($s, $systems, "sistema {$s} deve estar etiquetado");
        }
    }

    public function test_wave_does_not_activate_without_real_external_events(): void
    {
        $ladder = $this->ladder();

        // Fresh: onda_1 (SIS2) is waiting — fase_0 has 0 external events.
        $onda1 = collect($ladder->status()['waves'])->firstWhere('wave', 'onda_1');
        $this->assertSame('aguardando_eventos', $onda1['activation']);
        $this->assertSame(0, $onda1['prior_events']);
    }

    public function test_wave_activates_only_after_threshold_real_events(): void
    {
        $ladder = $this->ladder();

        // Accrue exactly the threshold of REAL external events on fase_0.
        for ($i = 0; $i < AtlasFrontierWaveLadder::EVENT_THRESHOLD; $i++) {
            $ladder->recordExternalEvent('fase_0', 'pack_diff_merged', "commit_{$i}");
        }
        // An unknown kind must NOT count (honest ledger).
        $ladder->recordExternalEvent('fase_0', 'not_a_real_kind', 'ignored');

        $onda1 = collect($ladder->status()['waves'])->firstWhere('wave', 'onda_1');
        $this->assertSame(AtlasFrontierWaveLadder::EVENT_THRESHOLD, $onda1['prior_events']);
        $this->assertSame('active', $onda1['activation']);

        // Onda 2 still waits — onda_1 has 0 events yet.
        $onda2 = collect($ladder->status()['waves'])->firstWhere('wave', 'onda_2');
        $this->assertSame('aguardando_eventos', $onda2['activation']);
    }
}
