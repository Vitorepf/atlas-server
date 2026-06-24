<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCrossTypeLeverageSelector;
use App\Services\Ai\AutonomousEvolution\AtlasLoopLeverageSelector;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMorningDigestService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationPipeline;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionOriginationCandidates;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use ReflectionClass;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopLeverageDroppedCandidatesDigestTest extends TestCase
{
    private string $repoRoot;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoRoot = sys_get_temp_dir().'/atlas-leverage-dropped-'.bin2hex(random_bytes(4));
        $this->logPath = $this->repoRoot.'/storage/leverage-dropped-candidates.jsonl';
        mkdir($this->repoRoot.'/app', 0o755, true);
        file_put_contents($this->repoRoot.'/app/Orphan.php', "<?php\nfinal class Orphan {}\n");
        config([
            'atlas.loop.leverage_selection_enabled' => true,
            'atlas.loop.morning_digest.leverage_dropped_log_path' => $this->logPath,
            'atlas.loop.morning_digest.leverage_dropped_log_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->repoRoot]))->run();
        parent::tearDown();
    }

    public function test_flag_off_never_creates_the_leverage_dropped_jsonl(): void
    {
        config(['atlas.loop.leverage_first_origination_enabled' => false]);

        $this->invokeLeveragePick($this->model());

        $this->assertFileDoesNotExist($this->logPath);
    }

    public function test_flag_on_logs_proxy_drops_and_morning_digest_counts_skipped_reasons(): void
    {
        config(['atlas.loop.leverage_first_origination_enabled' => true]);

        $picked = $this->invokeLeveragePick($this->model());

        $this->assertIsArray($picked);
        $this->assertStringContainsString('App\\Orphan', $picked[0]);
        $this->assertSame('app/Orphan.php', $picked[1]);
        $this->assertFileExists($this->logPath);

        $lines = array_values(array_filter(explode("\n", trim((string) file_get_contents($this->logPath)))));
        $this->assertCount(2, $lines);
        $records = array_map(static fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
        $reasons = array_column($records, 'skipped_reason');
        sort($reasons);
        $this->assertSame([
            'doc_gap_no_target',
            'proxy_clone_unification',
        ], $reasons);
        $kinds = array_column($records, 'kind');
        sort($kinds);
        $this->assertSame([
            AtlasLoopComprehensionOriginationCandidates::KIND_CLONE_UNIFICATION,
            AtlasLoopComprehensionOriginationCandidates::KIND_DOC_GAP_CAPABILITY,
        ], $kinds);

        $digest = (new AtlasLoopMorningDigestService)->digest(24);
        $block = $digest['sections']['leverage_dropped_candidates'];

        $this->assertSame('ok', $block['status']);
        $this->assertSame(2, $block['records_24h']);
        $this->assertSame(1, $block['counts_by_skipped_reason']['doc_gap_no_target'] ?? null);
        $this->assertSame(1, $block['counts_by_skipped_reason']['proxy_clone_unification'] ?? null);
        $this->assertContains(AtlasLoopComprehensionOriginationCandidates::KIND_DOC_GAP_CAPABILITY, array_column($block['top_kinds'], 'kind'));
        $this->assertContains(AtlasLoopComprehensionOriginationCandidates::KIND_CLONE_UNIFICATION, array_column($block['top_kinds'], 'kind'));
    }

    /**
     * @return array{0:string,1:string}|null
     */
    private function invokeLeveragePick(AtlasLoopScopeComprehensionModel $model): ?array
    {
        $selector = new AtlasLoopCrossTypeLeverageSelector(
            new AtlasLoopLeverageSelector(fn (string $provider, string $prompt): string => "<<<PICK>>>\n2"),
        );
        $pipeline = new AtlasLoopOriginationPipeline(null, null, $selector);
        $method = (new ReflectionClass($pipeline))->getMethod('leverageFirstMaterialTarget');
        $method->setAccessible(true);

        return $method->invoke($pipeline, $model, $this->repoRoot);
    }

    private function model(): AtlasLoopScopeComprehensionModel
    {
        return new AtlasLoopScopeComprehensionModel(
            inventory: [[
                'fqcn' => 'App\\Orphan',
                'rel_path' => 'app/Orphan.php',
                'public_methods' => ['run'],
                'is_orphan' => true,
                'is_forbidden' => false,
                'clone_cluster_id' => null,
            ]],
            edges: [],
            orphans: ['App\\Orphan'],
            cloneClusters: [[
                'cluster_id' => 'clone-a',
                'clone_hash' => 'hash-a',
                'members' => [
                    ['path' => 'app/CloneA.php', 'symbol' => 'App\\CloneA'],
                    ['path' => 'app/CloneB.php', 'symbol' => 'App\\CloneB'],
                ],
            ]],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: ['AtlasDigestSurface'],
            snapshotId: 'leverage-dropped-test',
        );
    }
}
