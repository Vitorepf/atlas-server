<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasTaskSimplicityContractCommandTest extends TestCase
{
    private string $disk = '';

    private string $diskRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->disk = 'atlas-task-simplicity-test-'.bin2hex(random_bytes(4));
        $this->diskRoot = storage_path('app/atlas/task-serving/'.$this->disk);
        @mkdir($this->diskRoot, 0o755, true);

        Config::set('atlas.task_serving.queue_disk', $this->disk);
        Config::set('filesystems.disks.'.$this->disk, [
            'driver' => 'local',
            'root' => $this->diskRoot,
            'throw' => false,
        ]);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->diskRoot);
        parent::tearDown();
    }

    public function test_contract_audit_summarises_inspected_drift_missing_conforming_counts(): void
    {
        $this->seedPacket('packet-conforming', conforming: true);
        $this->seedPacket('packet-drifted', conforming: false);
        $this->seedPacket('packet-missing', conforming: null);

        $payload = $this->runJson(['action' => 'contract:audit']);

        self::assertSame('ok', $payload['status']);
        self::assertSame('audit', $payload['mode']);
        self::assertSame(3, $payload['inspected_count']);
        self::assertSame(1, $payload['conforming_count']);
        self::assertSame(1, $payload['drift_count']);
        self::assertSame(1, $payload['missing_count']);
        self::assertCount(3, $payload['findings']);
    }

    public function test_contract_backfill_dry_run_does_not_mutate_records(): void
    {
        $this->seedPacket('packet-drifted', conforming: false);

        $beforeHash = $this->packetHash('packet-drifted');

        $payload = $this->runJson(['action' => 'contract:backfill']);

        self::assertSame('ok', $payload['status']);
        self::assertTrue($payload['dry_run']);
        self::assertSame('dry_run', $payload['mode']);
        self::assertSame(1, $payload['candidate_count']);
        self::assertSame(0, $payload['mutated_count']);
        self::assertSame([], $payload['mutations']);

        self::assertSame($beforeHash, $this->packetHash('packet-drifted'), 'dry-run must not change the packet hash');
    }

    public function test_contract_backfill_apply_mutates_drifted_records(): void
    {
        $this->seedPacket('packet-drifted', conforming: false);
        $beforeHash = $this->packetHash('packet-drifted');

        $payload = $this->runJson(['action' => 'contract:backfill', '--apply' => true]);

        self::assertSame('ok', $payload['status']);
        self::assertFalse($payload['dry_run']);
        self::assertSame('apply', $payload['mode']);
        self::assertSame(1, $payload['mutated_count']);
        self::assertSame('simplicity_contract_backfilled', $payload['mutations'][0]['backfill_status']);

        self::assertNotSame($beforeHash, $this->packetHash('packet-drifted'), 'apply must update the packet hash');
    }

    public function test_contract_audit_for_single_packet_via_packet_flag(): void
    {
        $this->seedPacket('packet-conforming', conforming: true);
        $this->seedPacket('packet-drifted', conforming: false);

        $payload = $this->runJson(['action' => 'contract:audit', '--packet' => 'packet-drifted']);

        self::assertSame('ok', $payload['status']);
        self::assertSame(1, $payload['inspected_count']);
        self::assertSame(1, $payload['drift_count']);
        self::assertSame(0, $payload['conforming_count']);
    }

    public function test_contract_audit_unknown_packet_returns_usage_error(): void
    {
        $exit = $this->runRaw(['action' => 'contract:audit', '--packet' => 'never-existed'], $buffer);
        $payload = json_decode(trim($buffer), true);

        self::assertNotSame(0, $exit, 'unknown packet must exit non-zero');
        self::assertSame('usage_error', $payload['status']);
        self::assertSame('unknown_packet', $payload['reason']);
    }

    private function seedPacket(string $id, ?bool $conforming): void
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository(AtlasTaskServingStack::disk());
        $packet = [
            'task_packet_id' => $id,
            'task_packet_hash' => 'h_'.$id,
            'status' => 'planned',
            'objective' => 'noop',
            'allowed_files' => ['app/Foo.php'],
            'acceptance_criteria' => ['noop'],
        ];

        if ($conforming === true) {
            $packet['simplicity_contract'] = AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract();
        } elseif ($conforming === false) {
            $drifted = AgentControlPlaneTaskPacketBuilder::defaultSimplicityContract();
            $drifted['default_worktree_or_sandbox'] = true;
            $drifted['final_runtime_owner'] = 'external_assistant';
            $packet['simplicity_contract'] = $drifted;
        }

        $repo->enqueue($packet);
    }

    private function packetHash(string $id): string
    {
        $repo = new AgentControlPlaneTaskPacketQueueRepository(AtlasTaskServingStack::disk());
        $record = $repo->get($id);
        if (! is_array($record)) {
            return '';
        }

        return (string) ($record['task_packet_hash'] ?? '');
    }

    /**
     * @param  array<string,mixed>  $params
     * @return array<string,mixed>
     */
    private function runJson(array $params): array
    {
        $exit = $this->runRaw($params, $buffer);
        self::assertSame(0, $exit, 'command exited non-zero: '.$buffer);
        $decoded = json_decode(trim($buffer), true);
        self::assertIsArray($decoded, 'command did not emit JSON: '.$buffer);

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $params
     */
    private function runRaw(array $params, ?string &$buffer): int
    {
        $params['--json'] = true;
        $output = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:task', $params, $output);
        $buffer = $output->fetch();

        return $exit;
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.'/'.$entry;
            if (is_dir($full)) {
                $this->rrmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}
