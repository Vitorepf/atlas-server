<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Merge-livre v2 (decisão do operador): a travessia REAL. Uma proposta certificada cujo
 * contrato re-prova GREEN é mergeada EM MAIN (commit de verdade, merged_to_main=true);
 * uma proposta stale (diff não aplica mais) é aposentada honestamente, nunca mergeada;
 * fora do escopo governado, nada consegue marcar merged_to_main.
 */
final class AtlasLoopAutoMergeServiceTest extends TestCase
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
        config(['atlas.ai.loop.auto_merge_to_main' => true]);
        config(['atlas.ai.loop.impact_receipts_enabled' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function repo(string $original): string
    {
        $d = sys_get_temp_dir().'/atlas-automerge-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/snippet.php', $original);
        $this->git($d, ['init', '-q']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        return $d;
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $cwd);
        $p->run();

        return trim($p->getOutput());
    }

    private function makeDiff(string $original, string $modified): string
    {
        $repo = $this->repo($original);
        file_put_contents($repo.'/snippet.php', $modified);
        $p = new Process(['git', 'diff'], $repo);
        $p->run();

        return $p->getOutput();
    }

    private function certifiedProposal(string $diff, string $hash): AtlasLoopProposal
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'automerge proof',
            'config' => [],
            'max_seconds' => 60,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = false;

        return AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'fix val to 2',
            'target_path' => 'snippet.php',
            'diff_text' => $diff,
            'proposal_hash' => $hash,
            'metric' => null,
            'quality' => ['_acceptance_contract' => [
                'commands' => ["php -r \"require 'snippet.php'; exit(val()===2?0:1);\""],
                'allowed_globs' => ['snippet.php'],
                'frozen_globs' => ['composer.json'],
                'metric_kind' => 'gate',
            ]],
        ]);
    }

    public function test_certified_reproven_proposal_merges_to_main_for_real(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $modified = "<?php\nfunction val(){ return 2; }\n";
        $repo = $this->repo($original);
        $proposal = $this->certifiedProposal($this->makeDiff($original, $modified), 'automerge-pass-1');

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(1, $result['merged_count'], json_encode($result['results']));
        // O arquivo na ÁRVORE REAL mudou e o commit existe em main.
        $this->assertSame($modified, file_get_contents($repo.'/snippet.php'));
        $this->assertStringContainsString('atlas loop auto-merge', $this->git($repo, ['log', '-1', '--pretty=%s']));
        // A proposta está marcada como mergeada (via escopo governado).
        $this->assertTrue((bool) $proposal->fresh()->merged_to_main);
        $impact = $proposal->fresh()->quality['_impact_receipt'] ?? null;
        $this->assertIsArray($impact, 'todo merge novo carrega impact receipt');
        $this->assertSame('atlas.loop.impact_receipt.v1', $impact['schema_version']);
        $this->assertSame('bug', $impact['category']);
        $this->assertSame('real', $impact['target_kind']);
        $this->assertSame('real', $impact['real_vs_generated']);
        $this->assertSame(1, $impact['size']['files_changed']);
        $this->assertSame(1, $impact['size']['added_lines']);
        $this->assertSame(1, $impact['size']['deleted_lines']);
        $this->assertGreaterThan(0.0, $impact['impact_score']);
        $this->assertSame($result['results'][0]['commit'], $impact['commit']);
        $this->assertSame($impact['category'], $result['results'][0]['impact_receipt']['category']);

        // L2-5: a âncora de snapshot pré-merge existe e aponta para o estado ANTES do
        // merge (fix-forward barato: restaurar = checkout da tag).
        $snapTag = (string) ($result['results'][0]['snapshot_tag'] ?? '');
        $this->assertNotSame('', $snapTag, 'merge deve carregar snapshot_tag');
        $tagSha = $this->git($repo, ['rev-parse', $snapTag]);
        $headParent = $this->git($repo, ['rev-parse', 'HEAD~1']);
        $this->assertSame($headParent, $tagSha, 'a tag de snapshot aponta para o estado pré-merge');
    }

    public function test_stale_proposal_is_retired_never_merged(): void
    {
        // O diff foi gerado contra um conteúdo que NÃO é o da árvore atual → não aplica.
        $repo = $this->repo("<?php\nfunction val(){ return 99; }\n");
        $proposal = $this->certifiedProposal(
            $this->makeDiff("<?php\nfunction val(){ return 1; }\n", "<?php\nfunction val(){ return 2; }\n"),
            'automerge-stale-1',
        );

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(0, $result['merged_count']);
        $fresh = $proposal->fresh();
        $this->assertFalse((bool) $fresh->merged_to_main, 'stale nunca mergeia');
        $this->assertNotNull($fresh->reviewed_at, 'stale é aposentada para não bloquear a fila');
    }

    public function test_nothing_outside_the_governed_scope_can_mark_merged(): void
    {
        $proposal = $this->certifiedProposal('diff --git a/x b/x', 'automerge-guard-1');

        $proposal->forceFill(['merged_to_main' => true])->save();

        $this->assertFalse((bool) $proposal->fresh()->merged_to_main, 'fora do escopo governado, o guard força false');
    }

    public function test_disabled_flag_merges_nothing(): void
    {
        config(['atlas.ai.loop.auto_merge_to_main' => false]);
        $repo = $this->repo("<?php\nfunction val(){ return 1; }\n");

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame('disabled', $result['status']);
    }

    /**
     * L2-6: o guard de saldo líquido aperta o dial SOZINHO quando a taxa medida de
     * falha do canário domina a janela (fix-forward perdendo a corrida) — e o drain
     * respeita o throttle. Com canários saudáveis, merges seguem LIVRES.
     */
    public function test_net_direction_guard_throttles_the_drain_on_measured_breakage(): void
    {
        // Semeia 4 merges com canário FALHO na janela. (certifiedProposal reseta o
        // escopo governado internamente — criar TUDO antes de flipar o flag.)
        $seeded = [];
        for ($i = 0; $i < 4; $i++) {
            $seeded[] = $this->certifiedProposal('diff --git a/x b/x', 'net-dir-'.$i);
        }
        \App\Models\AtlasLoopProposal::$governedMergeInProgress = true;
        try {
            foreach ($seeded as $i => $p) {
                $p->forceFill([
                    'merged_to_main' => true,
                    'reviewed_at' => now()->subMinutes(10 - $i),
                    'quality' => ['_canary' => ['ran' => true, 'passed' => false, 'target' => 't']],
                ])->save();
            }
        } finally {
            \App\Models\AtlasLoopProposal::$governedMergeInProgress = false;
        }

        $verdict = app(\App\Services\Ai\AutonomousEvolution\AtlasLoopNetDirectionGuard::class)->verdict();
        $this->assertTrue($verdict['throttled'], 'quebra dominando a janela tem que apertar o dial: '.json_encode($verdict).' merged='.\App\Models\AtlasLoopProposal::query()->where('merged_to_main', true)->count());

        $repo = $this->repo("<?php\nfunction val(){ return 1; }\n");
        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);
        $this->assertSame('throttled', $result['status'], 'o drain respeita o saldo líquido negativo');
    }

    public function test_net_direction_guard_keeps_merges_free_when_canaries_are_green(): void
    {
        $seeded = [];
        for ($i = 0; $i < 5; $i++) {
            $seeded[] = $this->certifiedProposal('diff --git a/y b/y', 'net-ok-'.$i);
        }
        \App\Models\AtlasLoopProposal::$governedMergeInProgress = true;
        try {
            foreach ($seeded as $i => $p) {
                $p->forceFill([
                    'merged_to_main' => true,
                    'reviewed_at' => now()->subMinutes(10 - $i),
                    'quality' => [
                        '_canary' => ['ran' => true, 'passed' => true, 'target' => 't'],
                        '_impact_receipt' => [
                            'schema_version' => 'atlas.loop.impact_receipt.v1',
                            'category' => 'bug',
                            'target_kind' => 'real',
                            'real_vs_generated' => 'real',
                            'target_path' => 'snippet.php',
                            'size' => [
                                'files_changed' => 1,
                                'php_files_changed' => 1,
                                'added_lines' => 1,
                                'deleted_lines' => 1,
                                'touched_lines' => 2,
                                'bucket' => 'small',
                            ],
                            'impact_score' => 0.8,
                        ],
                    ],
                ])->save();
            }
        } finally {
            \App\Models\AtlasLoopProposal::$governedMergeInProgress = false;
        }

        $verdict = app(\App\Services\Ai\AutonomousEvolution\AtlasLoopNetDirectionGuard::class)->verdict();
        $this->assertFalse($verdict['throttled'], 'direção líquida positiva = merges livres');
        $this->assertGreaterThanOrEqual(5, $verdict['impact_receipts']['observed']);
        $this->assertGreaterThan(0.0, $verdict['impact_receipts']['avg_impact_score']);
    }
}
