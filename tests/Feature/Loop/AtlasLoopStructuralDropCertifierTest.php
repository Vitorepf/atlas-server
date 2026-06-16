<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopSemanticImplementationCertifier;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE Leap 1 — PART B frozen ratchet for the STRUCTURAL (create-class / extract-class) drop lane.
 *
 * The certifier's public measureScopedStructuralDrop() is a sibling of measureScopedComplexityDrop()
 * that routes to the per-method-qualified-identity ANTI-RELOCATION census
 * ({@see AtlasLoopSignalAnalyzer::structuralComplexityReduced}).
 * It folds the obra's net-new files into the allowed scope (a create-class node legitimately creates
 * a file) and proves a SPECIFIC kept method got strictly simpler IN PLACE while NO new identity carries
 * the old god-method's complexity.
 *
 * Pins BOTH directions so a future edit can never silently weaken the gate:
 *   - a genuine create-class + simplify-in-place CERTIFIES;
 *   - a pure relocation (god method moved INTACT into the new file) is REFUSED (anti-relocation).
 */
final class AtlasLoopStructuralDropCertifierTest extends TestCase
{
    private string $workspace = '';

    protected function tearDown(): void
    {
        if ($this->workspace !== '' && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace]))->run();
        }
        parent::tearDown();
    }

    /** A class with a single god method `run` of cyclomatic ≈ $ifs + 1. */
    private function god(string $class, int $ifs): string
    {
        $body = "    public function run(int \$v): string {\n";
        for ($i = 0; $i < $ifs; $i++) {
            $body .= "        if (\$v > {$i}) { return 'b{$i}'; }\n";
        }
        $body .= "        return 'z';\n    }";

        return "<?php\nnamespace App;\nfinal class {$class} {\n{$body}\n}\n";
    }

    /** A committed 1-file baseline: app/Hub.php with a god `run` method (cyclomatic ≈ $ifs + 1). */
    private function repo(int $ifs): void
    {
        $this->workspace = sys_get_temp_dir().'/atlas-struct-'.bin2hex(random_bytes(5));
        @mkdir($this->workspace.'/app/Support', 0o755, true);
        file_put_contents($this->workspace.'/app/Hub.php', $this->god('Hub', $ifs));
        foreach ([['git', 'init'], ['git', 'config', 'user.email', 'a@l'], ['git', 'config', 'user.name', 'a'], ['git', 'add', '-A'], ['git', 'commit', '-q', '-m', 'baseline']] as $argv) {
            (new Process($argv, $this->workspace, null, null, 30.0))->run();
        }
    }

    private function certifier(): AtlasLoopSemanticImplementationCertifier
    {
        return app(AtlasLoopSemanticImplementationCertifier::class);
    }

    public function test_genuine_create_class_with_in_place_simplify_certifies_structurally(): void
    {
        $this->repo(12); // Hub::run worst-per-method ≈ 13

        // CANDIDATE (unstaged): Hub::run genuinely simplified IN PLACE (delegates a couple branches),
        // and a NEW HubHelper class whose helper methods are each strictly simpler than the old worst.
        file_put_contents($this->workspace.'/app/Hub.php', $this->god('Hub', 3)); // 13 -> 4 in place
        file_put_contents(
            $this->workspace.'/app/Support/HubHelper.php',
            "<?php\nnamespace App\\Support;\nfinal class HubHelper {\n"
            ."    public function stepA(int \$v): string { if (\$v > 0) { return 'a'; } return 'z'; }\n"
            ."    public function stepB(int \$v): string { if (\$v > 1) { return 'b'; } return 'z'; }\n"
            ."}\n",
        );

        $drop = $this->certifier()->measureScopedStructuralDrop(
            $this->workspace,
            ['app/Hub.php', 'app/Support/HubHelper.php'],
            ['app/Hub.php'],
            ['app/Support/HubHelper.php'],
        );

        $this->assertSame([], $drop['scope_violation'], 'the net-new file is folded into the allowed scope (no violation)');
        $this->assertTrue((bool) $drop['reduced'], 'a real create-class + in-place simplify is certified by the structural lane');
        $this->assertTrue((bool) ($drop['proof']['structural'] ?? false), 'the proof records the structural lane');
    }

    public function test_god_method_relocated_intact_into_the_new_file_is_refused_anti_relocation(): void
    {
        $this->repo(12); // Hub::run worst-per-method ≈ 13

        // CANDIDATE (unstaged): Hub::run becomes a trivial delegate, but the FULL god method is moved
        // INTACT into HubHelper::run (a NEW identity at ~the old max). The anti-relocation clause MUST
        // refuse this — nothing actually got simpler, the complexity was merely relocated.
        file_put_contents(
            $this->workspace.'/app/Hub.php',
            "<?php\nnamespace App;\nfinal class Hub {\n    public function run(int \$v): string { return (new \\App\\Support\\HubHelper())->run(\$v); }\n}\n",
        );
        file_put_contents(
            $this->workspace.'/app/Support/HubHelper.php',
            str_replace(['namespace App;', 'class HubHelper'], ['namespace App\\Support;', 'class HubHelper'], $this->god('HubHelper', 12)),
        );

        $drop = $this->certifier()->measureScopedStructuralDrop(
            $this->workspace,
            ['app/Hub.php', 'app/Support/HubHelper.php'],
            ['app/Hub.php'],
            ['app/Support/HubHelper.php'],
        );

        $this->assertFalse((bool) $drop['reduced'], 'a god method moved INTACT into the new file earns nothing (anti-relocation)');
    }

    public function test_edit_outside_allowed_and_new_scope_is_a_scope_violation(): void
    {
        $this->repo(12);

        file_put_contents($this->workspace.'/app/Hub.php', $this->god('Hub', 3));
        file_put_contents($this->workspace.'/app/Support/HubHelper.php', "<?php\nnamespace App\\Support;\nfinal class HubHelper { public function s(int \$v): int { return \$v; } }\n");

        // HubHelper.php is NOT declared as a new file => it is out of (allowed ∪ new) => scope violation.
        $drop = $this->certifier()->measureScopedStructuralDrop(
            $this->workspace,
            ['app/Hub.php', 'app/Support/HubHelper.php'],
            ['app/Hub.php'],
            [], // no new files folded in
        );

        $this->assertContains('app/Support/HubHelper.php', $drop['scope_violation'], 'an undeclared new file is a scope violation');
        $this->assertFalse((bool) $drop['reduced']);
    }
}
