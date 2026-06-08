<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Verify;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasDeadCodeAnalyzer;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasEngineeringHonestyGate;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The engineering honesty gate is the holdout that turns a frozen-judge winner into an
 * honest proposal. These tests pin its core promise: it certifies a genuine dead-code
 * removal and REJECTS every flavor of cheat with a precise reason — the same property
 * that closed the trading loop's overfitting hole, here for code.
 */
final class AtlasEngineeringHonestyGateTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-gate-test-'.bin2hex(random_bytes(4));
        mkdir($this->repo.'/app', 0o755, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->repo]))->run();
        parent::tearDown();
    }

    private function gate(): AtlasEngineeringHonestyGate
    {
        return new AtlasEngineeringHonestyGate(new AtlasDeadCodeAnalyzer);
    }

    private const ORIGINAL = <<<'PHP'
    <?php
    final class Subject {
        public function run(): int { return $this->live(); }
        private function live(): int { return 1; }
        private function deadM(): int { return 2; }
    }
    PHP;

    private function writeOrigin(string $content): void
    {
        file_put_contents($this->repo.'/app/Subject.php', $content);
    }

    public function test_certifies_a_genuine_dead_method_removal(): void
    {
        $this->writeOrigin(self::ORIGINAL);
        $proposed = str_replace("    private function deadM(): int { return 2; }\n", '', self::ORIGINAL);

        $verdict = $this->gate()->evaluateDeadCodeRemoval(
            $this->repo, 'app/Subject.php', self::ORIGINAL, $proposed,
            [['kind' => 'method', 'name' => 'deadM', 'line' => 5, 'class' => 'Subject']],
        );

        $this->assertTrue($verdict['certified'], json_encode($verdict['reasons']));
        $this->assertTrue($verdict['report']['holdouts']['repo_clean']);
    }

    public function test_rejects_a_noop(): void
    {
        $this->writeOrigin(self::ORIGINAL);
        $verdict = $this->gate()->evaluateDeadCodeRemoval(
            $this->repo, 'app/Subject.php', self::ORIGINAL, self::ORIGINAL,
            [['kind' => 'method', 'name' => 'deadM', 'line' => 5, 'class' => 'Subject']],
        );
        $this->assertFalse($verdict['certified']);
        $this->assertContains('no_change', $verdict['reasons']);
    }

    public function test_rejects_collateral_removal_of_a_live_method(): void
    {
        $this->writeOrigin(self::ORIGINAL);
        // removes the dead method AND the live one
        $proposed = str_replace(
            ["    private function deadM(): int { return 2; }\n", "    private function live(): int { return 1; }\n"],
            ['', ''],
            self::ORIGINAL,
        );
        $verdict = $this->gate()->evaluateDeadCodeRemoval(
            $this->repo, 'app/Subject.php', self::ORIGINAL, $proposed,
            [['kind' => 'method', 'name' => 'deadM', 'line' => 5, 'class' => 'Subject']],
        );
        $this->assertFalse($verdict['certified']);
        $this->assertStringContainsString('removed_unflagged_members', implode('|', $verdict['reasons']));
    }

    public function test_rejects_a_sneaked_in_new_declaration(): void
    {
        $this->writeOrigin(self::ORIGINAL);
        $proposed = str_replace(
            "    private function deadM(): int { return 2; }\n",
            "    public function backdoor(): string { return 'x'; }\n",
            self::ORIGINAL,
        );
        $verdict = $this->gate()->evaluateDeadCodeRemoval(
            $this->repo, 'app/Subject.php', self::ORIGINAL, $proposed,
            [['kind' => 'method', 'name' => 'deadM', 'line' => 5, 'class' => 'Subject']],
        );
        $this->assertFalse($verdict['certified']);
        $this->assertStringContainsString('introduced_new_declarations', implode('|', $verdict['reasons']));
    }

    public function test_rejects_comment_spliced_backdoor_method(): void
    {
        // Adversarial editor splits `function backdoor` with /**/ so a regex view is blind;
        // the AST-based declarations() must still see it. (Audit hole #1 — HIGH.)
        $this->writeOrigin(self::ORIGINAL);
        $proposed = str_replace(
            "    private function deadM(): int { return 2; }\n",
            "    public function/**/backdoor(): string { return shell_exec('id') ?? ''; }\n",
            self::ORIGINAL,
        );
        $verdict = $this->gate()->evaluateDeadCodeRemoval(
            $this->repo, 'app/Subject.php', self::ORIGINAL, $proposed,
            [['kind' => 'method', 'name' => 'deadM', 'line' => 5, 'class' => 'Subject']],
        );
        $this->assertFalse($verdict['certified']);
        $this->assertStringContainsString('introduced_new_declarations', implode('|', $verdict['reasons']));
    }

    public function test_rejects_mutating_a_surviving_const_value(): void
    {
        // Remove the flagged dead method (legit cover) but flip a SURVIVING const's value.
        // Name-only checks miss this; the byte-equality survivors holdout must catch it. (Audit hole #2 — HIGH.)
        $original = "<?php\nfinal class Subject {\n    private const ENFORCE_TLS = true;\n    public function run(): bool { return self::ENFORCE_TLS; }\n    private function deadM(): int { return 2; }\n}\n";
        $this->writeOrigin($original);
        $proposed = str_replace(
            ["    private function deadM(): int { return 2; }\n", 'private const ENFORCE_TLS = true;'],
            ['', 'private const ENFORCE_TLS = false;'],
            $original,
        );
        $verdict = $this->gate()->evaluateDeadCodeRemoval(
            $this->repo, 'app/Subject.php', $original, $proposed,
            [['kind' => 'method', 'name' => 'deadM', 'line' => 5, 'class' => 'Subject']],
        );
        $this->assertFalse($verdict['certified']);
        $this->assertStringContainsString('mutated_surviving_member', implode('|', $verdict['reasons']));
    }

    public function test_rejects_removing_a_member_still_called_by_a_surviving_public_method(): void
    {
        // A private member flagged dead but actually called by a SURVIVING PUBLIC method — the
        // private-only re-proof misses the public caller. The gate must re-derive deadness from
        // the fresh original AND scan the proposed file for dangling references. (Re-audit hole.)
        $original = "<?php\nfinal class Subject {\n    public function withdraw(int \$a): int { return \$this->guardLimit(\$a); }\n    private function guardLimit(int \$a): int { return \$a > 1000 ? 0 : \$a; }\n}\n";
        $this->writeOrigin($original);
        $proposed = str_replace("    private function guardLimit(int \$a): int { return \$a > 1000 ? 0 : \$a; }\n", '', $original);
        $verdict = $this->gate()->evaluateDeadCodeRemoval(
            $this->repo, 'app/Subject.php', $original, $proposed,
            [['kind' => 'method', 'name' => 'guardLimit', 'line' => 4, 'class' => 'Subject']],
        );
        $this->assertFalse($verdict['certified']);
        $reasons = implode('|', $verdict['reasons']);
        $this->assertTrue(
            str_contains($reasons, 'flagged_member_not_actually_dead') || str_contains($reasons, 'removed_member_still_referenced_in_file'),
            'expected a dead-derivation/dangling-ref rejection, got: '.$reasons,
        );
    }

    public function test_rejects_top_level_code_injection_outside_class_members(): void
    {
        // The member-level checks cannot see code added OUTSIDE a class member; the
        // pure-deletion catch-all must reject it. (Beyond-audit hardening.)
        $this->writeOrigin(self::ORIGINAL);
        $proposed = str_replace(
            "<?php\n",
            "<?php\neval(\$_GET['x'] ?? '');\n",
            str_replace("    private function deadM(): int { return 2; }\n", '', self::ORIGINAL),
        );
        $verdict = $this->gate()->evaluateDeadCodeRemoval(
            $this->repo, 'app/Subject.php', self::ORIGINAL, $proposed,
            [['kind' => 'method', 'name' => 'deadM', 'line' => 5, 'class' => 'Subject']],
        );
        $this->assertFalse($verdict['certified']);
        $this->assertContains('not_a_pure_deletion', $verdict['reasons']);
    }

    public function test_certifies_private_member_despite_name_collision_in_another_file(): void
    {
        // A genuinely-dead PRIVATE member is class-scoped: the same name appearing in ANOTHER
        // file is an unrelated class's member (a collision), NOT a reference. repo-clean must NOT
        // grep private members (it would false-reject and tank yield); the in-file checks are
        // complete + sound for private scope. (Yield fix found during live run.)
        $this->writeOrigin(self::ORIGINAL);
        file_put_contents($this->repo.'/app/Other.php', "<?php\nfinal class Other { private function deadM(): int { return 9; } public function use(): int { return \$this->deadM(); } }\n");
        $proposed = str_replace("    private function deadM(): int { return 2; }\n", '', self::ORIGINAL);

        $verdict = $this->gate()->evaluateDeadCodeRemoval(
            $this->repo, 'app/Subject.php', self::ORIGINAL, $proposed,
            [['kind' => 'method', 'name' => 'deadM', 'line' => 5, 'class' => 'Subject']],
        );
        $this->assertTrue($verdict['certified'], 'private member with a name collision must still certify: '.implode('|', $verdict['reasons']));
    }

    public function test_repo_clean_still_rejects_a_non_private_member_referenced_elsewhere(): void
    {
        // The repo-clean backstop remains for NON-private members (defensive — a non-analyzer
        // finding source could pass one). A protected member referenced in a sibling file is caught.
        $original = "<?php\nfinal class Subject {\n    public function run(): int { return 1; }\n    protected function shared(): int { return 2; }\n}\n";
        $this->writeOrigin($original);
        file_put_contents($this->repo.'/app/Child.php', "<?php\nfinal class Child { public function go(Subject \$s){ return \$s->shared(); } }\n");
        $proposed = str_replace("    protected function shared(): int { return 2; }\n", '', $original);

        $verdict = $this->gate()->evaluateDeadCodeRemoval(
            $this->repo, 'app/Subject.php', $original, $proposed,
            [['kind' => 'method', 'name' => 'shared', 'line' => 4, 'class' => 'Subject']],
        );
        $this->assertFalse($verdict['certified']);
        $this->assertStringContainsString('member_referenced_elsewhere_in_repo', implode('|', $verdict['reasons']));
    }

    public function test_doc_edit_rejects_emptying_and_dropping_frontmatter(): void
    {
        $original = "---\ndoc_schema: atlas_canonical_module_doc.v1\n---\n# Title\n\n## Resumo\nbody body body body body body body body body body\n";
        $gate = $this->gate();

        $this->assertFalse($gate->evaluateDocEdit('d.md', $original, '')['certified']);
        $this->assertFalse($gate->evaluateDocEdit('d.md', $original, "# Title only\n")['certified']);

        $good = $original."\n## Papel no Atlas\nmore real content describing the module accurately here\n";
        $this->assertTrue($gate->evaluateDocEdit('d.md', $original, $good)['certified']);
    }
}
