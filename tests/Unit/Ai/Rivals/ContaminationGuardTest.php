<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ContaminationGuard;
use Tests\TestCase;

class ContaminationGuardTest extends TestCase
{
    private function cleanCase(): array
    {
        return [
            'case_id' => 'ab_test',
            'title' => 'fix: outcome ledger drops entries under load',
            'ticket_body' => 'Entries vanish when two writers race on the ledger.',
            'symptom_excerpt' => "Failed asserting that 3 matches expected 5.",
            'base_sha' => str_repeat('a', 40),
            'golden_sha' => str_repeat('b', 40),
            'check_command' => 'php vendor/bin/phpunit --no-coverage x',
            'changed_files' => ['code' => ['app/Services/Ledger.php'], 'tests' => ['tests/LedgerTest.php']],
            'mined_at' => now()->toIso8601String(),
        ];
    }

    public function test_clean_fresh_case_passes_and_gets_fingerprint(): void
    {
        $audit = (new ContaminationGuard)->audit($this->cleanCase());
        $this->assertSame([], $audit['violations']);
        $this->assertNotNull($audit['prompt_fingerprint']);
    }

    public function test_missing_repo_snapshot_is_violation(): void
    {
        $case = $this->cleanCase();
        $case['base_sha'] = '';
        $this->assertContains('repo_snapshot_missing:base_sha', (new ContaminationGuard)->audit($case)['violations']);
    }

    public function test_stale_case_is_violation_long_lived_corpus_dies(): void
    {
        $case = $this->cleanCase();
        $case['mined_at'] = now()->subDays(45)->toIso8601String();
        $violations = (new ContaminationGuard)->audit($case)['violations'];
        $this->assertNotEmpty(preg_grep('/^case_stale:/', $violations));
    }

    public function test_recipe_leaks_are_violations(): void
    {
        $guard = new ContaminationGuard;

        $case = $this->cleanCase();
        $case['ticket_body'] = 'Run tests/LedgerTest.php to see the failure';
        $this->assertContains('recipe_test_path_leak', $guard->audit($case)['violations']);

        $case = $this->cleanCase();
        $case['ticket_body'] = 'The bug is in app/Services/Ledger.php line 10';
        $this->assertContains('recipe_target_file_leak:app/Services/Ledger.php', $guard->audit($case)['violations']);

        $case = $this->cleanCase();
        $case['ticket_body'] = "1. open the class\n2. add a lock\n3. flush the buffer";
        $this->assertContains('recipe_step_checklist', $guard->audit($case)['violations']);
    }

    public function test_case_without_hidden_check_is_violation(): void
    {
        $case = $this->cleanCase();
        $case['changed_files']['tests'] = [];
        $this->assertContains('no_hidden_check', (new ContaminationGuard)->audit($case)['violations']);
    }
}
