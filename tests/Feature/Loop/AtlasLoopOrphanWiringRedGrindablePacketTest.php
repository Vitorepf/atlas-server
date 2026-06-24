<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasEvolutionTaskGenerator;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationDeliveryBridge;
use App\Services\Ai\AutonomousEvolution\AtlasLoopWiringMaterialGrader;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBackService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopQueueRefiller;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetDiscoveryService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopTargetRepository;
use App\Services\Ai\AutonomousEvolution\LoopExecutionDriver;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

final class AtlasLoopOrphanWiringRedGrindablePacketTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_targets')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    public function test_flag_off_keeps_orphan_wiring_payload_byte_identical(): void
    {
        $compileCalls = 0;
        $repo = $this->repo();
        $campaign = $this->campaign($repo);
        $spec = $this->spec();
        config(['atlas.loop.orphan_wiring_red_grindable_packet_enabled' => false]);

        $this->mint($this->refiller($this->bridge(true, $compileCalls)), $campaign, $spec, $repo);

        $payload = $this->payload($campaign);
        unset($payload['_target_id']);
        $this->assertSame($spec['payload'], $payload);
        $this->assertSame(0, $compileCalls, 'flag OFF must not call the delivery bridge/factory path');
    }

    public function test_ready_bridge_attaches_red_required_packet_to_orphan_wiring_payload(): void
    {
        $compileCalls = 0;
        $repo = $this->repo();
        $campaign = $this->campaign($repo);
        config(['atlas.loop.orphan_wiring_red_grindable_packet_enabled' => true]);

        $this->mint($this->refiller($this->bridge(true, $compileCalls)), $campaign, $this->spec(), $repo);

        $payload = $this->payload($campaign);
        $this->assertTrue((bool) ($payload['red_required'] ?? false));
        $this->assertTrue((bool) data_get($payload, 'acceptance.red_required'));
        $this->assertNotEmpty($payload['verification_atoms'] ?? []);
        $this->assertSame('method_return', data_get($payload, 'verification_atoms.0.type'));
        $this->assertNotEmpty($payload['verifier_refuter_commands'] ?? []);
        $this->assertStringContainsString('Widget', (string) data_get($payload, 'verifier_refuter_commands.0'));
        $this->assertSame(1, $compileCalls);
    }

    public function test_not_ready_bridge_preserves_legacy_orphan_wiring_payload(): void
    {
        $compileCalls = 0;
        $repo = $this->repo();
        $campaign = $this->campaign($repo);
        $spec = $this->spec();
        config(['atlas.loop.orphan_wiring_red_grindable_packet_enabled' => true]);

        $this->mint($this->refiller($this->bridge(false, $compileCalls)), $campaign, $spec, $repo);

        $payload = $this->payload($campaign);
        unset($payload['_target_id']);
        $this->assertSame($spec['payload'], $payload);
        $this->assertSame(1, $compileCalls, 'flag ON reaches the bridge, but ready=false must fail open to legacy');
    }

    public function test_app_service_provider_binding_resolves_the_delivery_bridge_arg(): void
    {
        $refiller = app(AtlasLoopQueueRefiller::class);
        $property = new ReflectionProperty(AtlasLoopQueueRefiller::class, 'deliveryBridge');
        $property->setAccessible(true);

        $this->assertInstanceOf(AtlasLoopOriginationDeliveryBridge::class, $property->getValue($refiller));
    }

    private function bridge(bool $ready, int &$compileCalls): AtlasLoopOriginationDeliveryBridge
    {
        return new AtlasLoopOriginationDeliveryBridge(
            new AtlasLoopWiringMaterialGrader,
            function (string $repo, string $intent, array $payload) use ($ready, &$compileCalls): array {
                $compileCalls++;
                if (! $ready) {
                    return ['ready' => false, 'blockers' => [['code' => 'forced_not_ready']]];
                }

                return [
                    'ready' => true,
                    'payload' => [
                        'verification_atoms' => $payload['verification_atoms'],
                        'verifier_refuter_commands' => $payload['verifier_refuter_commands'],
                        'acceptance' => ['commands' => ['php tests/Unit/ConsumerTest.php']],
                    ],
                ];
            },
        );
    }

    private function refiller(AtlasLoopOriginationDeliveryBridge $bridge): AtlasLoopQueueRefiller
    {
        $this->app->bind(AtlasEvolutionTaskGenerator::class, fn () => new AtlasEvolutionTaskGenerator(new class implements LoopExecutionDriver
        {
            public function attempt(string $surfaceId, string $workspace, string $intent, array $userConstraints, array $surfaceHints): array
            {
                return ['status' => 'noop'];
            }
        }));

        return new AtlasLoopQueueRefiller(
            discovery: app(AtlasLoopTargetDiscoveryService::class),
            repository: app(AtlasLoopTargetRepository::class),
            generator: app(AtlasEvolutionTaskGenerator::class),
            loopBack: app(AtlasLoopBackService::class),
            store: app(AtlasLoopStore::class),
            harnessGuard: new AtlasLoopHarnessGuard,
            deliveryBridge: $bridge,
        );
    }

    private function mint(AtlasLoopQueueRefiller $refiller, AtlasLoopCampaign $campaign, array $spec, string $repo): void
    {
        $method = new ReflectionMethod(AtlasLoopQueueRefiller::class, 'mintOrphanWiringTask');
        $method->setAccessible(true);

        $this->assertTrue((bool) $method->invoke($refiller, $campaign, $spec, $repo));
    }

    /** @return array<string,mixed> */
    private function spec(): array
    {
        return [
            'objective' => 'Wire the orphaned capability App\\X\\Widget into a production caller and prove it load-bearing.',
            'payload' => [
                'objective_kind' => 'orphan_wiring',
                'source' => 'orphan_wiring',
                'orphan_path' => 'app/X/Widget.php',
                'orphan_fqcn' => 'App\\X\\Widget',
                'public_methods' => ['build'],
                'sibling_test' => 'tests/Unit/ConsumerTest.php',
                'wired_proof' => true,
                'production_caller' => false,
                'wired_target' => ['orphan_path' => 'app/X/Widget.php'],
            ],
            'members' => ['app/X/Widget.php'],
        ];
    }

    /** @return array<string,mixed> */
    private function payload(AtlasLoopCampaign $campaign): array
    {
        $task = AtlasLoopTask::query()
            ->where('campaign_id', $campaign->id)
            ->where('source', 'orphan_wiring')
            ->latest('id')
            ->firstOrFail();

        return is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
    }

    private function campaign(string $repo): AtlasLoopCampaign
    {
        return AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => 'running',
            'goal' => 'orphan-red-grindable',
            'base_workspace' => $repo,
            'provider' => '',
            'config' => ['decision_priority_enabled' => false],
        ]);
    }

    private function repo(): string
    {
        $dir = sys_get_temp_dir().'/atlas-orphan-red-packet-'.bin2hex(random_bytes(6));
        $this->dirs[] = $dir;
        File::ensureDirectoryExists($dir.'/app/X');
        File::ensureDirectoryExists($dir.'/tests/Unit');
        File::put($dir.'/app/X/Widget.php', "<?php\n\nnamespace App\\X;\n\nfinal class Widget\n{\n    public function build(): string { return 'legacy'; }\n}\n");
        File::put($dir.'/tests/Unit/ConsumerTest.php', "<?php\n\nfinal class ConsumerTest extends \\PHPUnit\\Framework\\TestCase\n{\n    public function test_placeholder(): void\n    {\n        \$this->assertTrue(true);\n    }\n}\n");

        return $dir;
    }
}
