<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\RealExecution\MissionDeliveryOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionDetector;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionLoopService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Self-construction loop — proves "Atlas builds Atlas" cost-free: a REAL detector
 * finds a code marker (and operator gaps), the loop routes each through the Mission
 * pipe (a fake orchestrator stands in for the spend step), and every result is a
 * BRANCH for the operator to merge — never a merge, never main.
 */
final class AtlasSelfConstructionLoopServiceTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-selfconstruct-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($this->root.'/app/Services', 0777, true, true);
        File::put(
            $this->root.'/app/Services/Widget.php',
            "<?php\n\nnamespace App\\Services;\n\nclass Widget\n{\n    // TODO: tighten the workspace guard here\n    public function run(): void {}\n}\n",
        );
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_detects_code_marker_and_delivers_a_branch_never_merging(): void
    {
        $loop = new AtlasSelfConstructionLoopService(new AtlasSelfConstructionDetector, $this->fakeOrchestrator());

        $r = $loop->run(['repo_dir' => $this->root, 'max' => 3]);

        $this->assertGreaterThanOrEqual(1, $r['detected'], 'the TODO marker must be detected');
        $this->assertGreaterThanOrEqual(1, $r['delivered_count']);
        $this->assertNotEmpty($r['branches']);
        $this->assertTrue($r['never_merged']);
        $this->assertTrue($r['main_untouched']);

        // The delivered branch traces back to the real TODO signal (file-anchored).
        $first = $r['deliveries'][0];
        $this->assertSame('code', $first['signal']['area']);
        $this->assertStringContainsString('Widget.php', (string) $first['signal']['file']);
        $this->assertStringContainsString('tighten the workspace guard', (string) $first['signal']['request']);
        $this->assertStringStartsWith('atlas/materialize/', (string) $first['branch']);
    }

    public function test_operator_gap_is_prioritised_and_delivered(): void
    {
        $loop = new AtlasSelfConstructionLoopService(new AtlasSelfConstructionDetector, $this->fakeOrchestrator());

        $r = $loop->run([
            'repo_dir' => $this->root,
            'requests' => ['Harden the G-5 secret scanner recall for DB_PASSWORD assignments'],
            'max' => 1,
        ]);

        $this->assertSame(1, $r['detected']);
        $this->assertSame('operator', $r['deliveries'][0]['signal']['area']);
        $this->assertStringContainsString('G-5 secret scanner', (string) $r['deliveries'][0]['signal']['request']);
        $this->assertTrue($r['deliveries'][0]['delivered']);
        $this->assertTrue($r['never_merged']);
    }

    private function fakeOrchestrator(): MissionDeliveryOrchestrator
    {
        return new class extends MissionDeliveryOrchestrator
        {
            public function __construct() {}

            public function deliver(string $request, array $options = []): array
            {
                $id = (string) ($options['id'] ?? 'x');

                return [
                    'schema_version' => 'atlas.ai.mission_delivery.v1',
                    'delivered' => true,
                    'stage' => 'complete',
                    'request' => $request,
                    'branch' => 'atlas/materialize/'.$id,
                    'main_untouched' => true,
                    'never_merged' => true,
                    'review_commands' => ['git checkout atlas/materialize/'.$id],
                ];
            }
        };
    }
}
