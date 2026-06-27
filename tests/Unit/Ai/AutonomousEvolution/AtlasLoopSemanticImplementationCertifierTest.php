<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopSemanticImplementationCertifier;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopSemanticImplementationCertifierTest extends TestCase
{
    private string $workspace;

    protected function tearDown(): void
    {
        if (isset($this->workspace) && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace]))->run();
        }

        parent::tearDown();
    }

    public function test_certifies_diff_with_external_refuter_and_receipt(): void
    {
        $this->workspace = $this->workspaceWithCandidate('good');
        $receiptPath = $this->workspace.'/receipt/semantic.json';

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
            'semantic_refuter_commands' => [$this->cleanRefuterCommand()],
            'provider_refuters_required' => 1,
            'refuter_provider' => 'deterministic_fixture',
            'receipt_path' => $receiptPath,
        ]);

        $this->assertTrue($receipt['certified']);
        $this->assertSame('semantic_implementation_certified_with_external_refuters', $receipt['level']);
        $this->assertSame(1, data_get($receipt, 'provider_refuters.executed'));
        $this->assertSame(0, data_get($receipt, 'provider_refuters.refuted'));
        $this->assertSame(0, data_get($receipt, 'adversarial_panel.refuted_count'));
        $this->assertTrue(data_get($receipt, 'evidence.diff_earned'));
        $this->assertFalse(data_get($receipt, 'invariants.merged_to_main'));
        $this->assertFileExists($receiptPath);
    }

    public function test_fails_closed_when_required_refuter_is_missing(): void
    {
        $this->workspace = $this->workspaceWithCandidate('good');

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
            'provider_refuters_required' => 1,
        ]);

        $this->assertFalse($receipt['certified']);
        $this->assertContains('provider_refuters_missing(required:1,executed:0)', $receipt['reasons']);
    }

    public function test_external_refutation_blocks_certificate(): void
    {
        $this->workspace = $this->workspaceWithCandidate('good');

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
            'semantic_refuter_commands' => [$this->refutingCommand('semantic_gap')],
            'provider_refuters_required' => 1,
        ]);

        $this->assertFalse($receipt['certified']);
        $this->assertContains('provider_refuter_1:semantic_gap', $receipt['reasons']);
    }

    public function test_completeness_criteria_are_machine_resolved_and_recorded(): void
    {
        // item9: the resolver DERIVES + RESOLVES the completeness checklist from the frozen acceptance
        // command (re-run in the workspace). The 'good' candidate makes FooTest exit 0 => 1 satisfied
        // criterion => completeness.complete=true. Gate OFF (default) => no reason added => the verdict
        // stays byte-identical (still certifies). Proves machine-resolution + record-not-block.
        $this->workspace = $this->workspaceWithCandidate('good');

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
            'semantic_refuter_commands' => [$this->cleanRefuterCommand()],
            'provider_refuters_required' => 1,
            'refuter_provider' => 'deterministic_fixture',
        ]);

        $this->assertSame(1, data_get($receipt, 'completeness.total'), 'the single frozen acceptance command derived one criterion');
        $this->assertTrue(data_get($receipt, 'completeness.complete'), 'the re-run command exits 0 => criterion satisfied => complete');
        $this->assertTrue($receipt['certified'], 'gate OFF => completeness records but does not block (byte-identical verdict)');
    }

    public function test_completeness_gate_armed_refutes_when_derived_criterion_unsatisfied(): void
    {
        // Arm the gate. A candidate whose acceptance command FAILS yields a derived criterion with
        // satisfied=false; with the gate ON the completeness 'incomplete:...' reason appears — proving
        // the gate is armable end-to-end via the resolver-populated checklist (no acceptance-supplied
        // criteria; the resolver alone built it).
        config(['atlas.loop.completeness_gate_enabled' => true]);
        $this->workspace = $this->workspaceWithCandidate('bad'); // FooTest expects 'good' => command exits 1

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
        ]);

        $this->assertFalse($receipt['certified']);
        $this->assertSame(1, data_get($receipt, 'completeness.total'), 'the failing acceptance command derived one criterion');
        $this->assertFalse(data_get($receipt, 'completeness.complete'));
        $hasIncomplete = false;
        foreach ((array) $receipt['reasons'] as $r) {
            if (str_starts_with((string) $r, 'incomplete:')) {
                $hasIncomplete = true;
                break;
            }
        }
        $this->assertTrue($hasIncomplete, 'the armed completeness gate refutes on the resolver-derived unsatisfied criterion: '.json_encode($receipt['reasons']));
    }

    public function test_changed_symbol_census_path_b_refutes_uncovered_new_public_method_when_armed(): void
    {
        // ACDE lever #3: the candidate fixes value() (so every other gate is GREEN) but ALSO adds a new
        // public method the frozen acceptance corpus never names. Armed, the Path B census refutes it —
        // the deterministic clamp on WRONG-BUT-GREEN-with-un-exercised-surface.
        config(['atlas.loop.changed_symbol_census_path_b_enabled' => true]);
        $this->workspace = $this->workspaceAddingPublicMethod('good', 'untestedHelper');

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
            'semantic_refuter_commands' => [$this->cleanRefuterCommand()],
            'provider_refuters_required' => 1,
            'refuter_provider' => 'deterministic_fixture',
        ]);

        $this->assertFalse($receipt['certified']);
        $hasCensus = false;
        foreach ((array) $receipt['reasons'] as $r) {
            if (str_starts_with((string) $r, 'changed_symbol_uncovered:')) {
                $hasCensus = true;
                $this->assertStringContainsString('src/Foo.php::untestedHelper', (string) $r);
                break;
            }
        }
        $this->assertTrue($hasCensus, 'armed census refutes the uncovered new public method: '.json_encode($receipt['reasons']));
    }

    public function test_changed_symbol_census_path_b_off_is_byte_identical(): void
    {
        // Same candidate, flag OFF (default): the extra public method alone does NOT block — the cert is
        // exactly today's verdict (certifies), and no census reason appears.
        $this->workspace = $this->workspaceAddingPublicMethod('good', 'untestedHelper');

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
            'semantic_refuter_commands' => [$this->cleanRefuterCommand()],
            'provider_refuters_required' => 1,
            'refuter_provider' => 'deterministic_fixture',
        ]);

        $this->assertTrue($receipt['certified'], 'flag OFF => the extra method does not block: '.json_encode($receipt['reasons']));
        foreach ((array) $receipt['reasons'] as $r) {
            $this->assertStringStartsNotWith('changed_symbol_uncovered:', (string) $r);
        }
    }

    private function workspaceAddingPublicMethod(string $candidateValue, string $extraMethod): string
    {
        $dir = sys_get_temp_dir().'/atlas-semantic-cert-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/Foo.php', $this->foo('bad'));
        file_put_contents($dir.'/tests/FooTest.php', <<<'PHP'
<?php
require __DIR__.'/../src/Foo.php';

$foo = new Foo();
if ($foo->value() !== 'good') {
    fwrite(STDERR, 'expected good');
    exit(1);
}
PHP);
        $this->runProcess(['git', 'init'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        // candidate: fix value() AND add a brand-new public method the corpus never names.
        file_put_contents($dir.'/src/Foo.php',
            "<?php\nfinal class Foo\n{\n    public function value(): string\n    {\n        return '".$candidateValue."';\n    }\n\n    public function ".$extraMethod."(): int\n    {\n        return 7;\n    }\n}\n");

        return $dir;
    }

    /**
     * @return array<string,mixed>
     */
    public function test_author_judge_overlap_gate_refuses_diff_touching_a_judge_owned_file(): void
    {
        // AUTHOR≠JUDGE runtime cert predicate: a proposal whose diff touches a FORBIDDEN_SELF_TARGETS
        // path (here a Brain/ critic organ) is REFUSED at certification when the gate is ON (default),
        // with an 'author_judge_overlap:' reason — the real accept→refuse flip on the self-judging lane.
        $this->workspace = $this->workspaceWithCandidate('good');
        // Untracked file at a judge-owned path => git ls-files --others reports it => $changedFiles
        // includes a FORBIDDEN_SELF_TARGETS substring. The path itself is what the guard matches on.
        $forbiddenRel = 'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPlanAdviserRedTeam.php';
        mkdir(dirname($this->workspace.'/'.$forbiddenRel), 0o755, true);
        file_put_contents($this->workspace.'/'.$forbiddenRel, "<?php\n// loop edited its own judge\n");

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
        ]);

        $this->assertFalse($receipt['certified'], 'a self-judging diff must not certify');
        $this->assertTrue($this->hasReason($receipt, 'author_judge_overlap:'), 'overlap reason present: '.json_encode($receipt['reasons']));
        $this->assertTrue(data_get($receipt, 'author_judge_overlap.violation'), 'receipt records the violation');
    }

    public function test_author_judge_overlap_gate_does_not_reject_ordinary_files(): void
    {
        // Control: an ordinary app/ file in the diff (no FORBIDDEN substring) adds NO overlap reason.
        $this->workspace = $this->workspaceWithCandidate('good');
        $ordinaryRel = 'app/Services/Ai/SomeOrdinaryFeatureService.php';
        mkdir(dirname($this->workspace.'/'.$ordinaryRel), 0o755, true);
        file_put_contents($this->workspace.'/'.$ordinaryRel, "<?php\n// ordinary change\n");

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
        ]);

        $this->assertFalse($this->hasReason($receipt, 'author_judge_overlap:'), 'no false overlap reject: '.json_encode($receipt['reasons']));
        $this->assertFalse(data_get($receipt, 'author_judge_overlap.violation'));
    }

    public function test_author_judge_overlap_gate_off_is_byte_identical(): void
    {
        // Flag OFF: the same judge-owned-touching diff adds NO overlap reason (provably no-op).
        config(['atlas.loop.author_judge_overlap_gate_enabled' => false]);
        $this->workspace = $this->workspaceWithCandidate('good');
        $forbiddenRel = 'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainPlanAdviserRedTeam.php';
        mkdir(dirname($this->workspace.'/'.$forbiddenRel), 0o755, true);
        file_put_contents($this->workspace.'/'.$forbiddenRel, "<?php\n// loop edited its own judge\n");

        $receipt = app(AtlasLoopSemanticImplementationCertifier::class)->certify($this->workspace, $this->acceptance(), [
            'objective' => 'make Foo return good',
            'allowed_files' => ['src/Foo.php'],
        ]);

        $this->assertFalse($this->hasReason($receipt, 'author_judge_overlap:'), 'flag OFF => no overlap reason: '.json_encode($receipt['reasons']));
        $this->assertFalse(data_get($receipt, 'author_judge_overlap.violation'));
    }

    private function hasReason(array $receipt, string $prefix): bool
    {
        foreach ((array) ($receipt['reasons'] ?? []) as $r) {
            if (str_starts_with((string) $r, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function acceptance(): array
    {
        return [
            'commands' => ['php tests/FooTest.php'],
            'allowed_globs' => ['src/**'],
            'frozen_globs' => ['tests/**'],
            'metric_kind' => 'gate',
            'revert_recheck' => true,
            'timeout_seconds' => 30,
        ];
    }

    private function workspaceWithCandidate(string $candidateValue): string
    {
        $dir = sys_get_temp_dir().'/atlas-semantic-cert-'.bin2hex(random_bytes(5));
        mkdir($dir.'/src', 0o755, true);
        mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/Foo.php', $this->foo('bad'));
        file_put_contents($dir.'/tests/FooTest.php', <<<'PHP'
<?php
require __DIR__.'/../src/Foo.php';

$foo = new Foo();
if ($foo->value() !== 'good') {
    fwrite(STDERR, 'expected good');
    exit(1);
}
PHP);
        $this->runProcess(['git', 'init'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        file_put_contents($dir.'/src/Foo.php', $this->foo($candidateValue));

        return $dir;
    }

    private function foo(string $value): string
    {
        return "<?php\nfinal class Foo\n{\n    public function value(): string\n    {\n        return '".$value."';\n    }\n}\n";
    }

    private function cleanRefuterCommand(): string
    {
        return <<<'CMD'
php -r '$p=getenv("ATLAS_SEMANTIC_REFUTER_PACKET"); $j=json_decode(file_get_contents($p), true); $ok=(($j["deterministic_gate"]["certified"] ?? false) === true) && (($j["adversarial_panel"]["refuted_count"] ?? 1) === 0); echo json_encode(["refuted"=>!$ok, "reason"=>$ok ? "packet_clean" : "packet_not_clean"]); exit(0);'
CMD;
    }

    private function refutingCommand(string $reason): string
    {
        return "php -r 'echo json_encode([\"refuted\"=>true,\"reason\"=>\"".$reason."\"]); exit(0);'";
    }

    /**
     * @param  list<string>  $argv
     */
    private function runProcess(array $argv, string $cwd): void
    {
        $process = new Process($argv, $cwd, null, null, 30.0);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput() ?: $process->getOutput());
    }
}
