<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProposalPromotionGate;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The governed merge-promotion gate must be structurally incapable of writing the
 * operator's main / working tree: it denies unless config-enabled AND operator-
 * approved AND re-proven, and even then only commits to a NEW branch in an isolated
 * workspace — the base is never touched, merged_to_main is always false.
 */
class AtlasLoopProposalPromotionGateTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach (array_filter($this->dirs) as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function tmpDir(string $tag): string
    {
        $d = sys_get_temp_dir().'/atlas-promote-'.$tag.'-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;

        return $d;
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $cwd))->run();
    }

    private function makeDiff(string $file, string $original, string $modified): string
    {
        $repo = $this->tmpDir('diffgen');
        file_put_contents($repo.'/'.$file, $original);
        $this->git($repo, ['init', '-q']);
        $this->git($repo, ['add', '-A']);
        $this->git($repo, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);
        file_put_contents($repo.'/'.$file, $modified);
        $p = new Process(['git', 'diff'], $repo);
        $p->run();

        return $p->getOutput();
    }

    private function proposal(string $diff): AtlasLoopProposal
    {
        return (new AtlasLoopProposal())->forceFill([
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'target_path' => 'snippet.php',
            'diff_text' => $diff,
            'proposal_hash' => 'hashabc123',
        ]);
    }

    private function base(string $original): string
    {
        $b = $this->tmpDir('base');
        file_put_contents($b.'/snippet.php', $original);

        return $b;
    }

    private function gate(): AtlasLoopProposalPromotionGate
    {
        return app(AtlasLoopProposalPromotionGate::class);
    }

    public function test_denied_when_merge_to_source_disabled(): void
    {
        config(['atlas.ai.loop.merge_to_source_enabled' => false]);

        $r = $this->gate()->promote($this->proposal('d'), $this->base('x'), ['operator_id' => 'vitor', 'approved' => true]);

        $this->assertFalse($r['promoted']);
        $this->assertSame('merge_to_source_disabled', $r['reason']);
        $this->assertFalse($r['merged_to_main']);
    }

    public function test_denied_without_operator_approval(): void
    {
        config(['atlas.ai.loop.merge_to_source_enabled' => true]);

        $r = $this->gate()->promote($this->proposal('d'), $this->base('x'), ['operator_id' => '', 'approved' => false]);

        $this->assertFalse($r['promoted']);
        $this->assertSame('operator_approval_required', $r['reason']);
    }

    public function test_denied_when_reproof_fails(): void
    {
        config(['atlas.ai.loop.merge_to_source_enabled' => true]);
        $original = "<?php\n\nreturn 1;\n";
        $diff = $this->makeDiff('snippet.php', $original, "<?php\n\nreturn 2;\n");

        $r = $this->gate()->promote(
            $this->proposal($diff),
            $this->base($original),
            ['operator_id' => 'vitor', 'approved' => true],
            static fn (string $ws, AtlasLoopProposal $p): bool => false,
        );

        $this->assertFalse($r['promoted']);
        $this->assertSame('reproof_failed', $r['reason']);
    }

    /**
     * O-3 (closes O-1 #1/#2): the DEFAULT reproof (no injected reprover) re-runs the real
     * frozen judge against the PERSISTED acceptance contract. A proposal whose diff makes
     * the frozen test pass is promoted; the gate genuinely re-verified before merge.
     */
    public function test_default_reproof_uses_the_persisted_acceptance_contract_and_promotes_on_pass(): void
    {
        config(['atlas.ai.loop.merge_to_source_enabled' => true]);
        $original = "<?php\nfunction val(){ return 1; }\n";
        $modified = "<?php\nfunction val(){ return 2; }\n";
        $diff = $this->makeDiff('snippet.php', $original, $modified);

        // The materializer reconstructs ONLY the target file, so the acceptance command
        // must be self-contained (it asserts against snippet.php, which the diff fixed).
        $base = $this->base($original);

        $proposal = (new AtlasLoopProposal())->forceFill([
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'target_path' => 'snippet.php',
            'diff_text' => $diff,
            'proposal_hash' => 'hash-contract-pass',
            'metric' => ['some' => 'numeric verdict'], // metric is NOT the contract
            'quality' => ['_acceptance_contract' => [
                'commands' => ["php -r \"require 'snippet.php'; exit(val()===2?0:1);\""],
                'allowed_globs' => ['snippet.php'],
                'frozen_globs' => ['composer.json'], // explicit, non-target; snippet.php stays editable
                'metric_kind' => 'gate',
            ]],
        ]);

        // No injected reprover — the REAL defaultReprove runs.
        $r = $this->gate()->promote($proposal, $base, ['operator_id' => 'vitor', 'approved' => true]);
        $this->dirs[] = (string) $r['isolated_path'];

        $this->assertTrue($r['promoted'], 'reason: '.(string) $r['reason']);
        $this->assertFalse($r['merged_to_main']);
    }

    /**
     * O-1 #1/#2 regression: a proposal with ONLY the numeric `metric` and NO persisted
     * acceptance contract must fail closed — the old code read `metric` as acceptance and
     * could mis-promote. Now: no contract => reproof denied, never promoted.
     */
    public function test_numeric_metric_without_a_contract_fails_reproof_closed(): void
    {
        config(['atlas.ai.loop.merge_to_source_enabled' => true]);
        $original = "<?php\nfunction val(){ return 1; }\n";
        $diff = $this->makeDiff('snippet.php', $original, "<?php\nfunction val(){ return 2; }\n");

        $proposal = (new AtlasLoopProposal())->forceFill([
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'target_path' => 'snippet.php',
            'diff_text' => $diff,
            'proposal_hash' => 'hash-no-contract',
            'metric' => ['gate' => 'pass', 'value' => 1.0], // looks like acceptance, ISN'T
        ]);

        $r = $this->gate()->promote($proposal, $this->base($original), ['operator_id' => 'vitor', 'approved' => true]);

        $this->assertFalse($r['promoted'], 'numeric metric must never be mistaken for a runnable contract');
        $this->assertSame('reproof_failed', $r['reason']);
    }

    public function test_promotes_to_a_branch_never_touching_main_or_base(): void
    {
        config(['atlas.ai.loop.merge_to_source_enabled' => true]);
        $original = "<?php\n\nreturn 1;\n";
        $modified = "<?php\n\nreturn 2;\n";
        $diff = $this->makeDiff('snippet.php', $original, $modified);
        $base = $this->base($original);

        $r = $this->gate()->promote(
            $this->proposal($diff),
            $base,
            ['operator_id' => 'vitor', 'approved' => true],
            static fn (string $ws, AtlasLoopProposal $p): bool => true,
        );
        $this->dirs[] = (string) $r['isolated_path'];

        $this->assertTrue($r['promoted'], 'reason: '.(string) $r['reason']);
        $this->assertStringStartsWith('atlas-loop-promotion-', (string) $r['branch']);
        $this->assertFalse($r['merged_to_main']);
        $this->assertTrue($r['never_main']);
        $this->assertNotEmpty($r['receipt_hash']);

        // The change lives committed on the branch in the isolated workspace...
        $head = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], (string) $r['isolated_path']);
        $head->run();
        $this->assertSame($r['branch'], trim($head->getOutput()));
        $this->assertSame($modified, file_get_contents($r['isolated_path'].'/snippet.php'));

        // ...and the operator's base (main / working tree) is UNTOUCHED.
        $this->assertSame($original, file_get_contents($base.'/snippet.php'));
    }
}
