<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the atom paraphrase audit is live at the operator surface: two atoms that read as near-paraphrases are
 * surfaced as a pair (high Jaccard); a set of distinct atoms surfaces no pair.
 */
final class AtlasLoopAtomParaphraseAuditCommandTest extends TestCase
{
    private string $input = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->input = sys_get_temp_dir().'/atlas-paraphrase-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->input);
        parent::tearDown();
    }

    private function audit(array $atoms): array
    {
        file_put_contents($this->input, (string) json_encode($atoms));
        $exit = Artisan::call('atlas:loop:atom-paraphrase-audit', ['--input' => $this->input, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_near_paraphrase_atoms_are_paired(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->audit([
            ['description' => 'the widget must render correctly on the initial page load'],
            ['description' => 'the widget must render correctly on the initial page load'], // identical ⇒ sim 1.0
            ['description' => 'a completely unrelated database migration runs to completion'],
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.atom_paraphrase_audit.v1', $d['schema']);
        $this->assertSame(1, $d['pair_count'], (string) json_encode($d));
        $this->assertSame(0, $d['pairs'][0]['a']);
        $this->assertSame(1, $d['pairs'][0]['b']);
        $this->assertGreaterThanOrEqual(0.85, $d['pairs'][0]['similarity']);
    }

    public function test_distinct_atoms_surface_no_pair(): void
    {
        ['d' => $d] = $this->audit([
            ['description' => 'the login form validates the email address'],
            ['description' => 'the report exports as a PDF document'],
            ['description' => 'the scheduler retries a failed job once'],
        ]);

        $this->assertSame(0, $d['pair_count']);
        $this->assertSame([], $d['pairs']);
    }

    public function test_missing_input_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:atom-paraphrase-audit', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
