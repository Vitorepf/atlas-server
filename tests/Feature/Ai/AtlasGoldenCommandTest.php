<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P5 (Obra #19) — `atlas:golden` freezes per-case DEEP-canonical hashes and detects
 * per-case drift on check. The deep-ordering test is the whole point: a NESTED map
 * reordered must hash identically (ReadinessHash::stable's top-level ksort would miss it).
 */
final class AtlasGoldenCommandTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-golden-'.bin2hex(random_bytes(5));
        @mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_freeze_then_check_identical_is_ok_and_a_changed_case_is_caught(): void
    {
        $a = $this->write('a.json', ['caseX' => ['k' => 1], 'caseY' => ['k' => 2]]);

        $this->artisan('atlas:golden', ['action' => 'freeze', 'name' => 't', '--from' => $a, '--dir' => $this->dir])
            ->assertExitCode(0);

        // Identical artifact ⇒ check passes.
        $this->artisan('atlas:golden', ['action' => 'check', 'name' => 't', '--from' => $a, '--dir' => $this->dir])
            ->assertExitCode(0);

        // caseY changed ⇒ check fails and names caseY.
        $b = $this->write('b.json', ['caseX' => ['k' => 1], 'caseY' => ['k' => 999]]);
        $code = Artisan::call('atlas:golden', ['action' => 'check', 'name' => 't', '--from' => $b, '--dir' => $this->dir, '--json' => true]);
        $out = Artisan::output();

        $this->assertSame(1, $code);
        $this->assertStringContainsString('caseY', $out);
        $this->assertStringNotContainsString('caseX', json_decode($out, true)['changed_cases'][0] ?? '');
    }

    public function test_deep_reordered_nested_map_hashes_identically(): void
    {
        // Same data, nested keys in a DIFFERENT order — must be treated as unchanged.
        $frozen = $this->write('frozen.json', ['c' => ['alpha' => 1, 'beta' => ['x' => 1, 'y' => 2]]]);
        $reordered = $this->write('reordered.json', ['c' => ['beta' => ['y' => 2, 'x' => 1], 'alpha' => 1]]);

        $this->artisan('atlas:golden', ['action' => 'freeze', 'name' => 'deep', '--from' => $frozen, '--dir' => $this->dir])
            ->assertExitCode(0);

        // Deep canonicalisation ⇒ the reordered nested map is NOT drift.
        $this->artisan('atlas:golden', ['action' => 'check', 'name' => 'deep', '--from' => $reordered, '--dir' => $this->dir])
            ->assertExitCode(0);
    }

    /** @param array<string,mixed> $data */
    private function write(string $file, array $data): string
    {
        $path = $this->dir.'/'.$file;
        file_put_contents($path, (string) json_encode($data));

        return $path;
    }
}
