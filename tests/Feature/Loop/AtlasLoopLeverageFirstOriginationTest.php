<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationPipeline;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * DIRECTIVE #2/#3 — LEVERAGE-FIRST, MATERIAL-ONLY origination. The loop COMPREHENDS its scope into grounded
 * candidates and originates the highest-leverage MATERIAL one (orphan-wiring — a built-but-unwired capability)
 * — NEVER the behaviour-preserving clone-unification PROXY (canon: preserva comportamento = melhoria ZERO).
 * This pins the decision-function fix: the parked CrossTypeLeverageSelector is now wired, proxy is dropped,
 * and a scope with only proxy candidates originates NOTHING (no fake material).
 */
final class AtlasLoopLeverageFirstOriginationTest extends TestCase
{
    /** @var list<string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private function pipeline(): AtlasLoopOriginationPipeline
    {
        // leverageFirstMaterialTarget uses only the (defaulted) selector + the model + filesystem.
        return (new ReflectionClass(AtlasLoopOriginationPipeline::class))->newInstanceWithoutConstructor();
    }

    private function invokePick(AtlasLoopScopeComprehensionModel $model): ?array
    {
        $p = $this->pipeline();
        $m = (new ReflectionClass($p))->getMethod('leverageFirstMaterialTarget');
        $m->setAccessible(true);

        return $m->invoke($p, $model, base_path());
    }

    private function model(array $orphans, array $cloneClusters): AtlasLoopScopeComprehensionModel
    {
        // a real orphan fqcn → real file path (so the target resolves on the filesystem)
        $fqcn = 'App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopOriginationPipeline';
        $rel = 'app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php';

        return new AtlasLoopScopeComprehensionModel(
            inventory: [['fqcn' => $fqcn, 'rel_path' => $rel]],
            edges: [],
            orphans: $orphans,
            cloneClusters: $cloneClusters,
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: 'snap-test',
        );
    }

    private function cloneCluster(): array
    {
        return [[
            'cluster_id' => 'c1',
            'clone_hash' => 'hash1',
            'members' => [
                ['path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopAbstainAndAsk.php', 'symbol' => 'A'],
                ['path' => 'app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php', 'symbol' => 'B'],
            ],
        ]];
    }

    public function test_originates_the_material_orphan_and_drops_the_proxy_clone(): void
    {
        // scope has BOTH a proxy clone-unification AND a material orphan-wiring candidate.
        $picked = $this->invokePick($this->model(
            orphans: ['App\\Services\\Ai\\AutonomousEvolution\\AtlasLoopOriginationPipeline'],
            cloneClusters: $this->cloneCluster(),
        ));

        $this->assertIsArray($picked, 'a material orphan candidate must be originated');
        [$objective, $target] = $picked;
        $this->assertStringContainsString('AtlasLoopOriginationPipeline', $objective, 'the orphan is the chosen evolution');
        $this->assertStringContainsString('built but has NO production caller', $objective);
        $this->assertSame('app/Services/Ai/AutonomousEvolution/AtlasLoopOriginationPipeline.php', $target);
    }

    public function test_a_scope_with_only_proxy_clones_originates_nothing(): void
    {
        // clone-unification is behaviour-preserving PROXY → dropped; no material → null (no fake material work).
        $picked = $this->invokePick($this->model(orphans: [], cloneClusters: $this->cloneCluster()));
        $this->assertNull($picked, 'a proxy-only scope must NOT mint a material origination');
    }

    public function test_an_orphan_with_no_real_file_is_skipped(): void
    {
        // an orphan whose fqcn does not map to a real file on disk is not a resolvable target.
        $model = new AtlasLoopScopeComprehensionModel(
            inventory: [['fqcn' => 'App\\X\\Ghost', 'rel_path' => 'app/Services/Ai/AutonomousEvolution/GhostZZZ.php']],
            edges: [],
            orphans: ['App\\X\\Ghost'],
            cloneClusters: [],
            forbidden: [],
            docPurposes: [],
            docStatedGaps: [],
            snapshotId: 'snap-test',
        );
        $this->assertNull($this->invokePick($model));
    }

    public function test_multn1703_open_evidence_lead_can_feed_the_existing_leverage_pick(): void
    {
        config()->set('atlas.loop.multi_source_opportunity_scanner_enabled', true);
        $repo = $this->repoWithFiles([
            'app/Live/OpenOpportunity.php' => "<?php\nfinal class OpenOpportunity {}\n",
            'app/Live/ClosedOpportunity.php' => "<?php\nfinal class ClosedOpportunity {}\n",
            'docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md' => implode("\n", [
                '- [x] closed gap already handled `app/Live/ClosedOpportunity.php`',
                '- [ ] open gap with resolving evidence `app/Live/OpenOpportunity.php`',
            ]),
        ]);

        $picked = $this->invokePickWithRepo($this->model(orphans: [], cloneClusters: []), $repo);

        $this->assertIsArray($picked);
        $this->assertSame('app/Live/OpenOpportunity.php', $picked[1]);
        $this->assertStringContainsString('open gap with resolving evidence', $picked[0]);
    }

    public function test_multn1703_drops_dead_or_closed_evidence_without_fabricating_a_lead(): void
    {
        config()->set('atlas.loop.multi_source_opportunity_scanner_enabled', true);
        $repo = $this->repoWithFiles([
            'docs/engineering-knowledge-base/atlas-open-gaps-regressions-ledger.md' => implode("\n", [
                '- [x] closed gap `app/Live/ClosedOnly.php`',
                '- [ ] open gap with missing target `app/Live/Missing.php`',
            ]),
        ]);

        $this->assertNull($this->invokePickWithRepo($this->model(orphans: [], cloneClusters: []), $repo));
    }

    /**
     * @param  array<string,string>  $files
     */
    private function repoWithFiles(array $files): string
    {
        $repo = sys_get_temp_dir().'/atlas-multn1703-'.bin2hex(random_bytes(5));
        $this->tmpDirs[] = $repo;
        foreach ($files as $path => $contents) {
            $full = $repo.'/'.$path;
            @mkdir(dirname($full), 0775, true);
            file_put_contents($full, $contents);
        }

        return $repo;
    }

    private function invokePickWithRepo(AtlasLoopScopeComprehensionModel $model, string $repoRoot): ?array
    {
        $p = $this->pipeline();
        $m = (new ReflectionClass($p))->getMethod('leverageFirstMaterialTarget');
        $m->setAccessible(true);

        return $m->invoke($p, $model, $repoRoot);
    }
}
