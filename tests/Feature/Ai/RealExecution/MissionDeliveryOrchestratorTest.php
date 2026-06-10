<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RealExecution;

use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use App\Services\Ai\RealExecution\MissionDeliveryOrchestrator;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Mission e2e — proves the FULL chain (request → certified delivery → diff →
 * real branch) end to end with ZERO provider spend: a fake delivery stands in for
 * the LLM step (returning a certified sandbox file), while the REAL materializer
 * produces a real branch and the never-main invariant is asserted.
 */
final class MissionDeliveryOrchestratorTest extends TestCase
{
    private string $repo = '';

    private string $headBefore = '';

    private string $sandboxFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-mission-repo-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($this->repo, 0777, true, true);
        File::put($this->repo.'/README.md', "base\n");
        $this->g(['init', '-q']);
        $this->g(['add', '-A']);
        $this->g(['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);
        $this->headBefore = trim($this->gOut(['rev-parse', 'HEAD']));

        // The "generated" certified artifact (stands in for the provider output).
        $this->sandboxFile = sys_get_temp_dir().'/atlas-mission-sandbox-'.substr(md5(uniqid('', true)), 0, 8).'.php';
        File::put($this->sandboxFile, "<?php\n\nnamespace App\\Generated;\n\nclass MissionProof\n{\n    public function ok(): bool\n    {\n        return true;\n    }\n}\n");
    }

    protected function tearDown(): void
    {
        if ($this->repo !== '') {
            File::deleteDirectory($this->repo);
        }
        @unlink($this->sandboxFile);
        parent::tearDown();
    }

    public function test_delivers_from_request_to_ready_to_merge_branch_without_touching_main(): void
    {
        $statusBefore = $this->gOut(['status', '--porcelain']);

        $orch = new MissionDeliveryOrchestrator(
            $this->fakeDelivery(AtlasLiveCodeDeliveryService::STATUS_CERTIFIED),
            new GovernedBranchMaterializationService,
        );

        $r = $orch->deliver('add a MissionProof helper class', ['repo_dir' => $this->repo, 'id' => 'e2e-1']);

        $this->assertTrue($r['delivered'], 'reason: '.($r['reason'] ?? ''));
        $this->assertSame('complete', $r['stage']);
        $this->assertSame('atlas/materialize/e2e-1', $r['branch']);
        $this->assertTrue($r['main_untouched']);
        $this->assertTrue($r['never_merged']);
        $this->assertContains('app/Generated/MissionProof.php', $r['delivery']['files']);

        // The generated file lives ON THE BRANCH...
        $onBranch = $this->gOut(['show', 'atlas/materialize/e2e-1:app/Generated/MissionProof.php']);
        $this->assertStringContainsString('class MissionProof', $onBranch);
        // ...and NOT on main (HEAD + working tree byte-identical; file absent from main tree).
        $this->assertSame($this->headBefore, trim($this->gOut(['rev-parse', 'HEAD'])));
        $this->assertSame($statusBefore, $this->gOut(['status', '--porcelain']));
        $this->assertFileDoesNotExist($this->repo.'/app/Generated/MissionProof.php');
    }

    public function test_blocked_delivery_never_materializes(): void
    {
        $orch = new MissionDeliveryOrchestrator(
            $this->fakeDelivery(AtlasLiveCodeDeliveryService::STATUS_BLOCKED),
            new GovernedBranchMaterializationService,
        );

        $r = $orch->deliver('a request the provider could not certify', ['repo_dir' => $this->repo, 'id' => 'e2e-2']);

        $this->assertFalse($r['delivered']);
        $this->assertSame('delivery', $r['stage']);
        $this->assertNull($r['branch']);
        // No branch was created from an uncertified delivery.
        $p = new Process(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/atlas/materialize/e2e-2'], $this->repo);
        $p->run();
        $this->assertFalse($p->isSuccessful());
    }

    // ------------------------------------------------------------------
    // S2.F1 — the closed mission loop (brain feeds delivery; fail-open)
    // ------------------------------------------------------------------

    public function test_flag_on_threads_provider_bound_brain_context_into_the_delivery(): void
    {
        config()->set('atlas.mission.brain_context_enabled', true);
        // Avoid the brain write-back touching a DB in this prompt-focused test.
        config()->set('atlas.mission.record_outcome_enabled', false);

        $delivery = $this->fakeDelivery(AtlasLiveCodeDeliveryService::STATUS_CERTIFIED);
        $query = $this->spyQuery(
            paths: [['nodes' => ['memory:memory_entry:m1', 'code:module:mod1']]],
            nodes: [
                ['id' => 'memory:memory_entry:m1', 'label' => 'prior mission learning', 'kind' => 'memory_entry', 'source_kind' => 'memory'],
                ['id' => 'code:module:mod1', 'label' => 'the touched module', 'kind' => 'module', 'source_kind' => 'code'],
            ],
        );

        $orch = new MissionDeliveryOrchestrator(
            $delivery,
            new GovernedBranchMaterializationService,
            $query,
            null, // no ingestion → record_outcome short-circuits (and is off anyway)
        );

        $r = $orch->deliver('build on the prior mission', ['repo_dir' => $this->repo, 'id' => 'f1-on']);

        $this->assertTrue($r['delivered'], 'reason: '.($r['reason'] ?? ''));
        // The delivery RECEIVED a provider-bound brain_context with the crafted chain.
        $this->assertArrayHasKey('brain_context', $delivery->lastOptions);
        $ctx = (string) $delivery->lastOptions['brain_context'];
        $this->assertStringContainsString('prior mission learning (memory_entry)', $ctx);
        $this->assertStringContainsString('the touched module (module)', $ctx);
        $this->assertStringContainsString('[src=memory,code]', $ctx);
        // The query was ALWAYS asked provider-bound (it rides a provider prompt).
        $this->assertTrue($query->lastProviderBound);
        $this->assertTrue($r['brain']['context_used']);
    }

    public function test_flag_off_passes_no_brain_context_to_the_delivery(): void
    {
        config()->set('atlas.mission.brain_context_enabled', false);
        config()->set('atlas.mission.record_outcome_enabled', false);

        $delivery = $this->fakeDelivery(AtlasLiveCodeDeliveryService::STATUS_CERTIFIED);
        // A spy that would loudly fail the assertion if it were ever consulted.
        $query = $this->spyQuery(
            paths: [['nodes' => ['x']]],
            nodes: [['id' => 'x', 'label' => 'should never appear', 'kind' => 'memory_entry', 'source_kind' => 'memory']],
        );

        $orch = new MissionDeliveryOrchestrator(
            $delivery,
            new GovernedBranchMaterializationService,
            $query,
            null,
        );

        $r = $orch->deliver('a request with the flag off', ['repo_dir' => $this->repo, 'id' => 'f1-off']);

        $this->assertTrue($r['delivered']);
        $this->assertArrayNotHasKey('brain_context', $delivery->lastOptions, 'flag OFF must pass no brain_context');
        $this->assertFalse($r['brain']['context_used']);
        // Flag off ⇒ the brain was never even queried.
        $this->assertNull($query->lastProviderBound);
    }

    public function test_brain_query_failure_is_fail_open_and_the_delivery_still_succeeds(): void
    {
        config()->set('atlas.mission.brain_context_enabled', true);
        config()->set('atlas.mission.record_outcome_enabled', false);

        $delivery = $this->fakeDelivery(AtlasLiveCodeDeliveryService::STATUS_CERTIFIED);

        $orch = new MissionDeliveryOrchestrator(
            $delivery,
            new GovernedBranchMaterializationService,
            $this->throwingQuery(), // brain is down
            null,
        );

        $r = $orch->deliver('survive a dead brain', ['repo_dir' => $this->repo, 'id' => 'f1-failopen']);

        // The delivery proceeded to a real branch despite the brain throwing.
        $this->assertTrue($r['delivered'], 'reason: '.($r['reason'] ?? ''));
        $this->assertSame('atlas/materialize/f1-failopen', $r['branch']);
        $this->assertFalse($r['brain']['context_used'], 'a thrown query degrades to no context');
        // No brain_context reached the delivery (the prompt stays byte-identical).
        $this->assertArrayNotHasKey('brain_context', $delivery->lastOptions);
    }

    private function fakeDelivery(string $status): AtlasLiveCodeDeliveryService
    {
        $sandboxFile = $this->sandboxFile;

        return new class($status, $sandboxFile) extends AtlasLiveCodeDeliveryService
        {
            /** @var array<string,mixed> */
            public array $lastOptions = [];

            public function __construct(private string $status, private string $sandboxFile) {}

            public function deliver(string $goal, array $options = []): array
            {
                $this->lastOptions = $options; // spy: what the orchestrator passed in

                if ($this->status !== AtlasLiveCodeDeliveryService::STATUS_CERTIFIED) {
                    return ['schema_version' => 'x', 'status' => $this->status, 'certified' => false, 'reason' => 'provider_returned_not_ok'];
                }

                return [
                    'schema_version' => 'x',
                    'status' => AtlasLiveCodeDeliveryService::STATUS_CERTIFIED,
                    'certified' => true,
                    'provider' => 'fake_for_test',
                    'files' => [['path' => 'app/Generated/MissionProof.php', 'sandbox_path' => $this->sandboxFile]],
                    'syntax_check' => ['ok' => true, 'tool' => 'php -l'],
                ];
            }
        };
    }

    /**
     * Spy query service returning crafted AURG paths/nodes (no DB, no provider).
     *
     * @param  list<array<string,mixed>>  $paths
     * @param  list<array<string,mixed>>  $nodes
     */
    private function spyQuery(array $paths, array $nodes): AtlasRealityGraphQueryService
    {
        return new class($paths, $nodes) extends AtlasRealityGraphQueryService
        {
            public ?bool $lastProviderBound = null;

            /**
             * @param  list<array<string,mixed>>  $paths
             * @param  list<array<string,mixed>>  $nodes
             */
            public function __construct(private array $paths, private array $nodes) {}

            public function query(string $query, array $opts = []): array
            {
                $this->lastProviderBound = (bool) ($opts['provider_bound'] ?? false);

                return ['paths' => $this->paths, 'nodes' => $this->nodes];
            }
        };
    }

    private function throwingQuery(): AtlasRealityGraphQueryService
    {
        return new class extends AtlasRealityGraphQueryService
        {
            public function __construct() {}

            public function query(string $query, array $opts = []): array
            {
                throw new RuntimeException('brain is down');
            }
        };
    }

    /** @param list<string> $argv */
    private function g(array $argv): void
    {
        (new Process(array_merge(['git'], $argv), $this->repo))->run();
    }

    /** @param list<string> $argv */
    private function gOut(array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $this->repo);
        $p->run();

        return $p->getOutput();
    }
}
