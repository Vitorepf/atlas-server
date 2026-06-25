<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopUtilityGradeService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * FASE 1 · wiring anti-no-op: o eixo COMPOUNDING deixa de creditar um no-op num hub. O proxy estático
 * de fan-in era cego — uma mudança só-comentário/padding num arquivo muito-chamado pontuava compounding.
 * Agora o grade re-mede o Δ de comportamento REAL fresco do commit (via recorder pétreo) e DEMOVE um hub
 * provado no-op (net==0, sem outro crédito substantivo), mantendo mudanças reais. Monotônico-pra-baixo.
 */
final class AtlasLoopUtilityGradeBehaviorDeltaTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function git(string $cwd, array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $cwd);
        $p->run();

        return trim($p->getOutput());
    }

    private function seedHubMerge(string $hash, string $commit, string $target, string $objective): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'bdelta', 'config' => [], 'max_seconds' => 60,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = true;
        try {
            AtlasLoopProposal::create([
                'campaign_id' => $campaign->id,
                'schema_version' => 'atlas.loop.proposal.v1',
                'status' => AtlasLoopProposal::STATUS_CERTIFIED,
                'objective' => $objective,
                'target_path' => $target,
                'diff_text' => '',
                'proposal_hash' => $hash,
                'merged_to_main' => true,
                'reviewed_at' => now(),
                'quality' => [
                    '_canary' => ['ran' => true, 'passed' => true, 'target' => 'sib'],
                    '_impact_receipt' => ['schema_version' => 'atlas.loop.impact_receipt.v1', 'commit' => $commit],
                ],
            ]);
        } finally {
            AtlasLoopProposal::$governedMergeInProgress = false;
        }
    }

    public function test_proven_noop_on_a_hub_is_demoted_while_a_real_surface_change_keeps_compounding(): void
    {
        if ((new Process(['git', '--version']))->run() !== 0) {
            $this->markTestSkipped('git indisponível');
        }

        $d = rtrim(sys_get_temp_dir(), '/').'/atlas-bdelta-grade-'.bin2hex(random_bytes(5));
        @mkdir($d.'/app/Services', 0o755, true);
        @mkdir($d.'/app/Callers', 0o755, true);
        $this->dirs[] = $d;

        // C1 — hub Foo + 3 callers reais (callerCounts re-resolve >=3 => wired + hub).
        file_put_contents($d.'/app/Services/Foo.php', "<?php\nnamespace App\\Services;\nfinal class Foo\n{\n    public function classify(int \$n): string { return (string) \$n; }\n}\n");
        foreach (['CallerA', 'CallerB', 'CallerC'] as $caller) {
            file_put_contents($d.'/app/Callers/'.$caller.'.php', "<?php\nnamespace App\\Callers;\nuse App\\Services\\Foo;\nfinal class {$caller} { public function go(Foo \$f): string { return \$f->classify(1); } }\n");
        }
        $this->git($d, ['init', '-q']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        // C2 — NO-OP: só comentários (>15 linhas), zero mudança de superfície pública => net_behavior_delta=0.
        $comments = '';
        for ($i = 0; $i < 18; $i++) {
            $comments .= "    // note line {$i}: housekeeping, no behavior change\n";
        }
        file_put_contents($d.'/app/Services/Foo.php', "<?php\nnamespace App\\Services;\nfinal class Foo\n{\n{$comments}    public function classify(int \$n): string { return (string) \$n; }\n}\n");
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'tidy Foo formatting', '--no-gpg-sign']);
        $noOpSha = $this->git($d, ['rev-parse', 'HEAD']);

        // C3 — MUDANÇA REAL: novo método público em Foo => api_signature muda => net_behavior_delta>0.
        file_put_contents($d.'/app/Services/Foo.php', "<?php\nnamespace App\\Services;\nfinal class Foo\n{\n    public function classify(int \$n): string { return (string) \$n; }\n    public function describe(int \$n): string { return 'n='.\$n; }\n}\n");
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'extend Foo surface', '--no-gpg-sign']);
        $changeSha = $this->git($d, ['rev-parse', 'HEAD']);

        // Ambos objetivos são neutros (não-refactor, não bug/edge/perf) => nonTrivial não os salva;
        // a decisão de compounding fica SÓ no Δ de comportamento.
        $this->seedHubMerge('noop-1', $noOpSha, 'app/Services/Foo.php', 'tidy up Foo formatting and notes');
        $this->seedHubMerge('real-1', $changeSha, 'app/Services/Foo.php', 'extend Foo with a describe capability');

        $grade = (new AtlasLoopUtilityGradeService(new AtlasLoopWiredCallerService($d), $d))->grade(50);

        $this->assertSame(2, $grade['graded_merges']);
        // WIRED não muda: ambos atingem callers de Foo. Só o COMPOUNDING aperta.
        $this->assertGreaterThanOrEqual(0.99, $grade['axes']['wired'], 'wired não é afetado pelo gate de no-op');
        // Exatamente UM hub creditado (o de superfície real); o no-op foi demovido.
        $this->assertSame(1, $grade['evidence']['hub_merges'], 'o no-op no hub foi demovido; a mudança real manteve');
        $this->assertEqualsWithDelta(0.5, $grade['axes']['compounding'], 0.001, 'compounding discrimina no-op vs mudança real');
    }
}
