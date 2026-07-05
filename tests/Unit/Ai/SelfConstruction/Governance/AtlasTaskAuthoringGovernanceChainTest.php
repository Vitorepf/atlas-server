<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Governance;

use App\Services\Ai\SelfConstruction\Governance\AtlasTaskAuthoringGovernanceChain;
use Tests\TestCase;

final class AtlasTaskAuthoringGovernanceChainTest extends TestCase
{
    private function chain(?string $mode = null): AtlasTaskAuthoringGovernanceChain
    {
        return new AtlasTaskAuthoringGovernanceChain(modeOverride: $mode);
    }

    private function validCandidate(): array
    {
        return [
            'candidate_id' => 'cand-1',
            'objective' => 'Implement feature X',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['test passes'],
            'required_evidence' => ['test_result'],
        ];
    }

    // ── AC: enforce mode blocks candidates missing objective, allowed_files, acceptance or evidence ──

    public function test_enforce_blocks_candidate_missing_objective(): void
    {
        $candidate = $this->validCandidate();
        unset($candidate['objective']);

        $result = $this->chain('enforce')->govern([$candidate]);

        $this->assertSame('enforce', $result['mode']);
        $this->assertSame('blocked', $result['recorded']);
        $this->assertNotEmpty($result['findings']);
        $this->assertContains('objective', $result['findings'][0]['missing']);
    }

    public function test_enforce_blocks_candidate_missing_allowed_files(): void
    {
        $candidate = $this->validCandidate();
        unset($candidate['allowed_files']);

        $result = $this->chain('enforce')->govern([$candidate]);

        $this->assertSame('blocked', $result['recorded']);
        $this->assertContains('allowed_files', $result['findings'][0]['missing']);
    }

    public function test_enforce_blocks_candidate_missing_acceptance_criteria(): void
    {
        $candidate = $this->validCandidate();
        unset($candidate['acceptance_criteria']);

        $result = $this->chain('enforce')->govern([$candidate]);

        $this->assertSame('blocked', $result['recorded']);
        $this->assertContains('acceptance_criteria', $result['findings'][0]['missing']);
    }

    public function test_enforce_blocks_candidate_missing_required_evidence(): void
    {
        $candidate = $this->validCandidate();
        unset($candidate['required_evidence']);

        $result = $this->chain('enforce')->govern([$candidate]);

        $this->assertSame('blocked', $result['recorded']);
        $this->assertContains('required_evidence', $result['findings'][0]['missing']);
    }

    // ── AC: observe mode reports findings but does not block candidates ──

    public function test_observe_reports_findings_without_blocking(): void
    {
        $candidate = $this->validCandidate();
        unset($candidate['objective']);

        $result = $this->chain('observe')->govern([$candidate]);

        $this->assertSame('observe', $result['mode']);
        $this->assertNotEmpty($result['findings']);
        $this->assertNotSame('blocked', $result['recorded']);
    }

    // ── AC: off mode returns failed_open=false and performs no governance findings ──

    public function test_off_mode_returns_no_findings_and_failed_open_false(): void
    {
        $result = $this->chain('off')->govern([$this->validCandidate()]);

        $this->assertSame('off', $result['mode']);
        $this->assertFalse($result['failed_open']);
        $this->assertSame([], $result['findings']);
        $this->assertSame('skipped', $result['recorded']);
    }
}
