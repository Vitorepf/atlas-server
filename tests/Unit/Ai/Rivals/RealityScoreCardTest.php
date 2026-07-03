<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\RealityScoreCard;
use Tests\TestCase;

class RealityScoreCardTest extends TestCase
{
    private array $case = [
        'diff_lines' => 10,
        'symptom_excerpt' => "expected 'ledger entry persisted' but got null",
        'changed_files' => ['code' => ['app/Ledger.php'], 'tests' => ['tests/LedgerTest.php']],
    ];

    private function diff(string ...$hunks): string
    {
        return implode("\n", $hunks)."\n";
    }

    public function test_minimal_on_target_patch_scores_clean(): void
    {
        $patch = $this->diff(
            '--- a/app/Ledger.php',
            '+++ b/app/Ledger.php',
            '+$this->flush();',
            '-return;',
        );
        $r = (new RealityScoreCard)->evaluate($this->case, $patch, 'success');

        $this->assertTrue($r['success']);
        $this->assertTrue($r['hidden_regression_pass']);
        $this->assertSame(2, $r['patch_lines']);
        $this->assertTrue($r['minimal']);
        $this->assertSame(0, $r['blast_radius_outside_golden']);
        $this->assertFalse($r['hardcode_suspect']);
        // dimensões de juiz declaradas, nunca fabricadas
        $this->assertContains('root_cause_correctness', $r['requires_judge']);
    }

    public function test_bloat_blast_radius_and_hardcode_are_penal_signals(): void
    {
        $lines = array_map(fn ($i) => "+line{$i}();", range(1, 30));
        $patch = $this->diff(
            '--- a/app/Other.php',
            '+++ b/app/Other.php',
            ...$lines,
            ...["+return 'ledger entry persisted';"],
        );
        $r = (new RealityScoreCard)->evaluate($this->case, $patch, 'success');

        $this->assertFalse($r['minimal']); // 31/10 > 2.0
        $this->assertSame(1, $r['blast_radius_outside_golden']);
        $this->assertTrue($r['hardcode_suspect']); // ecoou literal do sintoma
    }

    public function test_no_hidden_tests_means_null_not_fake_pass(): void
    {
        $case = $this->case;
        $case['changed_files']['tests'] = [];
        $case['symptom_excerpt'] = null;
        $r = (new RealityScoreCard)->evaluate($case, '', 'failure');

        $this->assertNull($r['hidden_regression_pass']);
        $this->assertNull($r['hardcode_suspect']);
        $this->assertFalse($r['success']);
    }
}
