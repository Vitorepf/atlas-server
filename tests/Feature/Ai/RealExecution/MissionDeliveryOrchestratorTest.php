<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RealExecution;

use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use App\Services\Ai\RealExecution\MissionDeliveryOrchestrator;
use Illuminate\Support\Facades\File;
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

    private function fakeDelivery(string $status): AtlasLiveCodeDeliveryService
    {
        $sandboxFile = $this->sandboxFile;

        return new class($status, $sandboxFile) extends AtlasLiveCodeDeliveryService
        {
            public function __construct(private string $status, private string $sandboxFile) {}

            public function deliver(string $goal, array $options = []): array
            {
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
