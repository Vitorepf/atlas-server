<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasAcosCockpitCommandTest extends TestCase
{
    public function test_acos_cockpit_json_is_read_only_fail_open_aggregator(): void
    {
        $scoreboard = storage_path('framework/testing/acos-cockpit-scoreboard-'.bin2hex(random_bytes(4)).'.md');
        @mkdir(dirname($scoreboard), 0775, true);
        file_put_contents($scoreboard, implode("\n", [
            '# Scoreboard fixture',
            '',
            '## LOTE 3 — fixture',
            '- [x] ESP-05 — landed',
            '- [ ] TETO-08 — pending',
            '',
            '## LOTE 4 — next',
        ]));
        $before = hash_file('sha256', $scoreboard);

        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:acos:cockpit', [
            '--json' => true,
            '--scoreboard' => $scoreboard,
        ], $output);

        $this->assertSame(0, $exit);
        $this->assertSame($before, hash_file('sha256', $scoreboard));

        $payload = json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.acos.cockpit.v1', $payload['schema_version']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['mutates_state']);

        foreach (['m', 'r', 'loops_funnel', 'windows', 'pending_flips', 'review_debt', 'brakes', 'current_lote'] as $section) {
            $this->assertArrayHasKey($section, $payload['sections']);
            $this->assertArrayHasKey('source', $payload['sections'][$section]);
            $this->assertArrayHasKey('status', $payload['sections'][$section]);
        }

        $this->assertSame('unavailable', data_get($payload, 'sections.loops_funnel.payload.funnel.status'));
        $this->assertSame('source_not_landed_yet', data_get($payload, 'sections.loops_funnel.payload.funnel.reason'));
        $this->assertSame('ok', data_get($payload, 'sections.current_lote.status'));
        $this->assertSame('## LOTE 3 — fixture', data_get($payload, 'sections.current_lote.payload.heading'));
        $this->assertContains('- [ ] TETO-08 — pending', data_get($payload, 'sections.current_lote.payload.lines'));
    }
}
