<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasRivalsInvalidBatteryTriageRegistry;
use App\Services\Ai\Programming\RivalsForgeReadinessFingerprintService;
use Tests\TestCase;

class AtlasRivalsInvalidBatteryTriageRegistryTest extends TestCase
{
    /** @var list<string> */
    private array $fingerprintsToCleanup = [];

    protected function tearDown(): void
    {
        $registry = app(AtlasRivalsInvalidBatteryTriageRegistry::class);
        foreach ($this->fingerprintsToCleanup as $fp) {
            $prefix = substr($fp, 0, 16);
            $path = $registry->rootDirectory().DIRECTORY_SEPARATOR.$prefix.'.json';
            @unlink($path);
        }
        $this->fingerprintsToCleanup = [];
        parent::tearDown();
    }

    public function test_record_then_requires_triage_returns_true(): void
    {
        $registry = app(AtlasRivalsInvalidBatteryTriageRegistry::class);
        $fingerprint = $this->fingerprintFor(['preset' => 'quick', 'atlas_model' => 'opus']);

        $registry->recordInvalidBattery($fingerprint, ['suite' => 'atlas-fair-claude-v1']);

        $this->assertTrue($registry->requiresTriage($fingerprint));
        $entry = $registry->loadEntry($fingerprint);
        $this->assertSame(AtlasRivalsInvalidBatteryTriageRegistry::STATUS_PENDING_TRIAGE, $entry['status']);
        $this->assertSame(1, (int) $entry['occurrence_count']);
    }

    public function test_triage_clears_requires_triage_for_same_fingerprint(): void
    {
        $registry = app(AtlasRivalsInvalidBatteryTriageRegistry::class);
        $fingerprint = $this->fingerprintFor(['preset' => 'quick', 'atlas_model' => 'opus']);

        $registry->recordInvalidBattery($fingerprint);
        $registry->triage($fingerprint, 'manual review complete', 'operator@atlas');

        $this->assertFalse($registry->requiresTriage($fingerprint));
        $entry = $registry->loadEntry($fingerprint);
        $this->assertSame(AtlasRivalsInvalidBatteryTriageRegistry::STATUS_TRIAGED_QUARANTINED, $entry['status']);
        $this->assertSame('manual review complete', $entry['reason']);
        $this->assertSame('operator@atlas', $entry['triaged_by']);
    }

    public function test_triage_for_one_fingerprint_does_not_block_another(): void
    {
        $registry = app(AtlasRivalsInvalidBatteryTriageRegistry::class);
        $fpOpus = $this->fingerprintFor(['preset' => 'quick', 'atlas_model' => 'opus']);
        $fpSonnet = $this->fingerprintFor(['preset' => 'quick', 'atlas_model' => 'sonnet']);
        $this->assertNotSame($fpOpus, $fpSonnet, 'distinct intent must produce distinct fingerprints');

        $registry->recordInvalidBattery($fpOpus);
        $registry->triage($fpOpus, 'old battery quarantined');

        $registry->recordInvalidBattery($fpSonnet);

        $this->assertFalse($registry->requiresTriage($fpOpus));
        $this->assertTrue($registry->requiresTriage($fpSonnet));
    }

    public function test_resolution_command_carries_fingerprint_and_required_flags(): void
    {
        $registry = app(AtlasRivalsInvalidBatteryTriageRegistry::class);
        $fingerprint = $this->fingerprintFor(['preset' => 'full', 'atlas_model' => 'sonnet']);
        $registry->recordInvalidBattery($fingerprint);

        $cmd = $registry->resolutionCommand($fingerprint);

        $this->assertStringContainsString('--fingerprint='.$fingerprint, $cmd);
        $this->assertStringContainsString('--confirm-invalid-battery-quarantine', $cmd);
        $this->assertStringContainsString('--reason=', $cmd);
        $this->assertStringContainsString('atlas:engineering:benchmark:rivals', $cmd);
    }

    public function test_recording_same_fingerprint_twice_increments_occurrence_count(): void
    {
        $registry = app(AtlasRivalsInvalidBatteryTriageRegistry::class);
        $fp = $this->fingerprintFor(['preset' => 'quick', 'atlas_model' => 'opus', 'case_ids' => ['c1']]);

        $registry->recordInvalidBattery($fp);
        $registry->recordInvalidBattery($fp);

        $entry = $registry->loadEntry($fp);
        $this->assertSame(2, (int) $entry['occurrence_count']);
        $this->assertTrue($registry->requiresTriage($fp));
    }

    public function test_requires_triage_returns_false_for_unknown_fingerprint(): void
    {
        $registry = app(AtlasRivalsInvalidBatteryTriageRegistry::class);
        $fp = $this->fingerprintFor(['preset' => 'unique-'.bin2hex(random_bytes(8))]);
        $this->assertFalse($registry->requiresTriage($fp));
    }

    private function fingerprintFor(array $intent): string
    {
        $fingerprint = app(RivalsForgeReadinessFingerprintService::class)->compute($intent);
        $value = (string) $fingerprint['value'];
        $this->fingerprintsToCleanup[] = $value;

        return $value;
    }
}
