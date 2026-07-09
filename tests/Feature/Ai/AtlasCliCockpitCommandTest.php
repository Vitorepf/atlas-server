<?php

namespace Tests\Feature\Ai;

use Tests\TestCase;

/**
 * GAP-CLI-01/COCKPIT-03 · smoke of the single live-engine cockpit: the aggregator must
 * ALWAYS return the full envelope (every section present, fail-open), even in a bare
 * test environment where individual organs may be unavailable.
 */
class AtlasCliCockpitCommandTest extends TestCase
{
    public function test_cockpit_json_always_returns_all_failopen_sections(): void
    {
        $this->artisan('atlas:cli:cockpit', ['--json' => true, '--landings' => 1])
            ->assertExitCode(0);

        $output = $this->artisanJsonOutput();

        $this->assertSame('atlas.cli.cockpit.v1', $output['schema_version']);
        foreach (['brain', 'task_queue', 'autonomy', 'recent_landings', 'review_inbox', 'dev_jobs', 'forge_obra'] as $sectionKey) {
            $this->assertArrayHasKey($sectionKey, $output, "seção ausente: {$sectionKey}");
            $this->assertIsArray($output[$sectionKey]);
            $this->assertArrayHasKey('ok', $output[$sectionKey], "seção sem contrato fail-open: {$sectionKey}");
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function artisanJsonOutput(): array
    {
        // Own buffer: the cockpit nests Artisan::call internally, which overwrites the
        // facade's shared lastOutput — a dedicated BufferedOutput is nesting-proof.
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput;
        \Illuminate\Support\Facades\Artisan::call('atlas:cli:cockpit', ['--json' => true, '--landings' => 1], $buffer);
        $decoded = json_decode(trim($buffer->fetch()), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
