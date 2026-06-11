<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProposalPromotionGate;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProposalReverser;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * G5 — a alça de reverse fecha o ethos apply+reverse no loop: todo proposal
 * certificado ganha um patch de rollback PROVADO por round-trip, e a cadeia
 * completa materialize → promote → reverse roda fim-a-fim sem nunca tocar main.
 */
class AtlasLoopProposalReverserTest extends TestCase
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
        $d = sys_get_temp_dir().'/atlas-rev-test-'.$tag.'-'.bin2hex(random_bytes(4));
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

    private function proposal(string $target, string $diff, string $status = AtlasLoopProposal::STATUS_CERTIFIED): AtlasLoopProposal
    {
        return (new AtlasLoopProposal())->forceFill([
            'id' => 'test-'.bin2hex(random_bytes(6)),
            'status' => $status,
            'target_path' => $target,
            'diff_text' => $diff,
            'proposal_hash' => hash('sha256', $diff),
        ]);
    }

    public function test_generates_roundtrip_proven_reverse_patch(): void
    {
        $original = "<?php\n\nreturn 1;\n";
        $modified = "<?php\n\nreturn 2;\n";
        $diff = $this->makeDiff('snippet.php', $original, $modified);

        $base = $this->tmpDir('base');
        file_put_contents($base.'/snippet.php', $original);

        $proposal = $this->proposal('snippet.php', $diff);
        $r = app(AtlasLoopProposalReverser::class)->reverse($proposal, $base);
        $this->dirs[] = (string) ($r['isolated_path'] ?? '');

        $this->assertTrue($r['reversed'], 'reason: '.(string) $r['reason']);
        $this->assertTrue($r['roundtrip_verified']);
        $this->assertTrue($r['never_merged']);
        $this->assertFileExists((string) $r['reverse_patch_path']);
        $this->assertSame(hash('sha256', (string) file_get_contents((string) $r['reverse_patch_path'])), $r['reverse_patch_hash']);
        // O base segue intocado.
        $this->assertSame($original, file_get_contents($base.'/snippet.php'));

        // PROVA INDEPENDENTE (fora do serviço): aplicar o patch reverso sobre o
        // estado modificado devolve byte-a-byte o original.
        $replay = $this->tmpDir('replay');
        file_put_contents($replay.'/snippet.php', $modified);
        $this->git($replay, ['init', '-q']);
        $this->git($replay, ['add', '-A']);
        $this->git($replay, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'modified', '--no-gpg-sign']);
        copy((string) $r['reverse_patch_path'], $replay.'/reverse.patch');
        $apply = new Process(['git', 'apply', '--whitespace=nowarn', 'reverse.patch'], $replay);
        $apply->run();
        $this->assertTrue($apply->isSuccessful(), $apply->getErrorOutput());
        $this->assertSame($original, file_get_contents($replay.'/snippet.php'));

        @unlink((string) $r['reverse_patch_path']);
    }

    public function test_refuses_uncertified_proposal(): void
    {
        $base = $this->tmpDir('base');
        file_put_contents($base.'/snippet.php', "<?php\nreturn 1;\n");

        $r = app(AtlasLoopProposalReverser::class)->reverse(
            $this->proposal('snippet.php', 'x', 'draft'),
            $base,
        );

        $this->assertFalse($r['reversed']);
        $this->assertStringStartsWith('materialize_failed:', (string) $r['reason']);
    }

    public function test_full_g5_chain_materialize_promote_reverse_never_touches_main(): void
    {
        config(['atlas.ai.loop.merge_to_source_enabled' => true]);

        $original = "<?php\n\nreturn 'before';\n";
        $modified = "<?php\n\nreturn 'after';\n";
        $diff = $this->makeDiff('chain.php', $original, $modified);

        $base = $this->tmpDir('base');
        file_put_contents($base.'/chain.php', $original);

        $proposal = $this->proposal('chain.php', $diff);

        // PROMOTE: flag + aprovação explícita + reprover injetado (frozen-judge seam).
        $promotion = app(AtlasLoopProposalPromotionGate::class)->promote(
            $proposal,
            $base,
            ['operator_id' => 'vitor', 'approved' => true],
            fn (): bool => true,
        );
        $this->dirs[] = (string) ($promotion['isolated_path'] ?? '');

        $this->assertTrue($promotion['promoted'], 'reason: '.(string) $promotion['reason']);
        $this->assertFalse($promotion['merged_to_main']);
        $this->assertTrue($promotion['never_main']);
        $this->assertNotEmpty($promotion['receipt_hash']);

        // REVERSE: a alça de rollback provada da MESMA mudança.
        $reverse = app(AtlasLoopProposalReverser::class)->reverse($proposal, $base);
        $this->dirs[] = (string) ($reverse['isolated_path'] ?? '');

        $this->assertTrue($reverse['reversed'], 'reason: '.(string) $reverse['reason']);
        $this->assertTrue($reverse['roundtrip_verified']);

        // Invariante pétreo da cadeia inteira: o base nunca mudou.
        $this->assertSame($original, file_get_contents($base.'/chain.php'));

        @unlink((string) $reverse['reverse_patch_path']);
    }
}
