<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderEvidenceDiskGuardService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fail-closed disk guard contract for real Forge Rivals provider execution.
 *
 * Provider tokens must never be spent when the run directory cannot preserve
 * stdout, patches, receipts, replay material and manifest evidence.
 */
final class AtlasForgeRivalsProviderEvidenceDiskGuardServiceTest extends TestCase
{
    private AtlasForgeRivalsProviderEvidenceDiskGuardService $guard;

    private mixed $oldEvidenceFloor;

    private string $tmpRunBase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new AtlasForgeRivalsProviderEvidenceDiskGuardService;
        $this->oldEvidenceFloor = config('atlas_rivals.min_free_bytes_before_provider_evidence');
        $this->tmpRunBase = sys_get_temp_dir().'/fr-evidence-disk-guard-'.Str::lower(Str::random(8));
        @mkdir($this->tmpRunBase, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->wipeDir($this->tmpRunBase);
        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => $this->oldEvidenceFloor]);
        parent::tearDown();
    }

    public function test_check_returns_ok_when_free_bytes_meet_configured_floor(): void
    {
        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => 0]);

        $result = $this->guard->check($this->tmpRunBase);

        $this->assertSame('ok', $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame($this->tmpRunBase, $result['path']);
        $this->assertSame(0, $result['required_free_bytes']);
        $this->assertIsInt($result['free_bytes']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertFalse($result['provider_tokens_spent']);
    }

    public function test_check_blocks_when_free_bytes_are_below_configured_floor(): void
    {
        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => PHP_INT_MAX]);

        $result = $this->guard->check($this->tmpRunBase);

        $this->assertSame('blocked', $result['status']);
        $this->assertNotEmpty($result['blockers']);
        $this->assertStringStartsWith(
            'provider_evidence_disk_space_insufficient:',
            (string) $result['blockers'][0],
        );
        $this->assertSame($this->tmpRunBase, $result['path']);
        $this->assertSame(PHP_INT_MAX, $result['required_free_bytes']);
        $this->assertIsInt($result['free_bytes']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertFalse($result['provider_tokens_spent']);
    }

    public function test_check_probes_parent_directory_when_run_base_is_a_file_path(): void
    {
        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => 0]);

        $runFile = $this->tmpRunBase.'/run-manifest.json';
        file_put_contents($runFile, '{}');

        $result = $this->guard->check($runFile);

        $this->assertSame('ok', $result['status']);
        $this->assertSame($this->tmpRunBase, $result['path']);
    }

    public function test_check_clamps_negative_configured_floor_to_zero(): void
    {
        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => -512]);

        $result = $this->guard->check($this->tmpRunBase);

        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['required_free_bytes']);
    }

    public function test_check_uses_default_floor_when_config_is_empty(): void
    {
        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => null]);

        $result = $this->guard->check($this->tmpRunBase);

        $this->assertSame(
            AtlasForgeRivalsProviderEvidenceDiskGuardService::DEFAULT_MIN_FREE_BYTES_BEFORE_PROVIDER_EVIDENCE,
            $result['required_free_bytes'],
        );
        $this->assertContains($result['status'], ['ok', 'blocked']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertFalse($result['provider_tokens_spent']);
    }

    public function test_check_never_authorizes_provider_spend_on_any_outcome(): void
    {
        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => 0]);
        $ok = $this->guard->check($this->tmpRunBase);

        config(['atlas_rivals.min_free_bytes_before_provider_evidence' => PHP_INT_MAX]);
        $blocked = $this->guard->check($this->tmpRunBase);

        foreach ([$ok, $blocked] as $result) {
            $this->assertFalse($result['external_provider_call']);
            $this->assertFalse($result['provider_tokens_spent']);
        }
    }

    private function wipeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->wipeDir($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
