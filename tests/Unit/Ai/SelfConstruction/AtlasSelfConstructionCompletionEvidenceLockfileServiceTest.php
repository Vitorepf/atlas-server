<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceLockfileService;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCompletionEvidenceLockfileServiceTest extends TestCase
{
    private function svc(): AtlasSelfConstructionCompletionEvidenceLockfileService
    {
        return new AtlasSelfConstructionCompletionEvidenceLockfileService;
    }

    public function test_clean_lockfile_passes(): void
    {
        $svc = $this->svc();
        $evidence = ['bundle_hash' => 'abc'];
        $lockfile = $svc->build($evidence);

        $result = $svc->verify($lockfile, $evidence);

        $this->assertSame('passed', $result['status']);
        $this->assertSame(0, $result['violation_count']);
    }

    public function test_mutated_safety_flags_block(): void
    {
        $svc = $this->svc();
        $evidence = ['bundle_hash' => 'abc'];
        $lockfile = $svc->build($evidence);

        // Mutate: set execution_allowed to true
        $lockfile['safety_flags']['execution_allowed'] = true;
        // Recompute hash so self-consistency check passes — safety check is independent
        $lockfile['lockfile_hash'] = hash('sha256', 'tampered');

        $result = $svc->verify($lockfile, $evidence);

        $this->assertSame('blocked', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('lockfile_safety_flags_mismatch', $codes);
    }

    public function test_persistence_allowed_true_blocks(): void
    {
        $svc = $this->svc();
        $evidence = ['bundle_hash' => 'abc'];
        $lockfile = $svc->build($evidence);

        $lockfile['persistence_allowed'] = true;
        $lockfile['lockfile_hash'] = hash('sha256', 'tampered');

        $result = $svc->verify($lockfile, $evidence);

        $this->assertSame('blocked', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('lockfile_safety_flags_mismatch', $codes);
    }

    public function test_existing_violations_still_detected(): void
    {
        $svc = $this->svc();
        $evidence = ['bundle_hash' => 'abc'];
        $lockfile = $svc->build($evidence);

        // Corrupt schema AND safety_flags
        $lockfile['schema_version'] = 'wrong';
        $lockfile['safety_flags']['execution_allowed'] = true;
        $lockfile['lockfile_hash'] = hash('sha256', 'tampered');

        $result = $svc->verify($lockfile, $evidence);

        $this->assertSame('blocked', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('lockfile_schema_invalid', $codes);
        $this->assertContains('lockfile_safety_flags_mismatch', $codes);
    }
}
