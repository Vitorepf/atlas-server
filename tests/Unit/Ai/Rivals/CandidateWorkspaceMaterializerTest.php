<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\CandidateWorkspaceMaterializer;
use InvalidArgumentException;
use Tests\TestCase;

class CandidateWorkspaceMaterializerTest extends TestCase
{
    private function unit(): array
    {
        return [
            'case_id' => 'ledger_race',
            'unit_hash' => str_repeat('c', 64),
            'golden_sha' => str_repeat('b', 40),
            'hidden_tests' => ['tests/LedgerRaceTest.php'],
        ];
    }

    public function test_materialized_workspace_excludes_hidden_tests_and_golden(): void
    {
        $manifest = (new CandidateWorkspaceMaterializer)->materialize($this->unit(), [
            'visible_files' => ['app/Services/Ledger.php', 'composer.json'],
        ]);

        $this->assertNotContains('tests/LedgerRaceTest.php', $manifest['visible_files']);
        $this->assertSame(1, $manifest['hidden_excluded']['count']);
        // the golden solution never lands in a solver-visible field
        $this->assertArrayNotHasKey('golden_content', $manifest);
        $this->assertNotEmpty($manifest['workspace_hash']);
    }

    public function test_hidden_proof_in_visible_set_is_fail_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CandidateWorkspaceMaterializer)->materialize($this->unit(), [
            'visible_files' => ['app/Services/Ledger.php', 'tests/LedgerRaceTest.php'],
        ]);
    }

    public function test_canary_injection_is_deterministic_and_replayable(): void
    {
        $m = new CandidateWorkspaceMaterializer;
        $a = $m->materialize($this->unit(), ['visible_files' => ['a.php'], 'canary_count' => 2]);
        $b = $m->materialize($this->unit(), ['visible_files' => ['a.php'], 'canary_count' => 2]);

        $this->assertCount(2, $a['canaries']);
        $this->assertSame($a['canaries'], $b['canaries']);
        $this->assertSame($a['workspace_hash'], $b['workspace_hash']);
    }

    public function test_contamination_canary_hit_is_detected(): void
    {
        $m = new CandidateWorkspaceMaterializer;
        $manifest = $m->materialize($this->unit(), ['visible_files' => ['a.php']]);
        $leakedToken = $manifest['canaries'][0]['token'];

        $violations = $m->detectLeak($manifest, "here is my patch\n{$leakedToken}\ndone");
        $this->assertNotEmpty(preg_grep('/^contamination_canary_hit:/', $violations));

        $this->assertSame([], $m->detectLeak($manifest, 'clean patch, no canary'));
    }

    public function test_unrestricted_egress_is_denied_by_default(): void
    {
        $m = new CandidateWorkspaceMaterializer;
        $manifest = $m->materialize($this->unit(), [
            'visible_files' => ['a.php'],
            'egress_allowlist' => ['pypi.internal'],
        ]);

        $denied = $m->auditEgress($manifest, ['pypi.internal', 'evil.example.com']);
        $this->assertSame(['egress_denied:evil.example.com'], $denied);
    }
}
