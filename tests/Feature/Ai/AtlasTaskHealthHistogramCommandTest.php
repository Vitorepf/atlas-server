<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasTaskHealthHistogramCommand;
use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroLeaseLifetimeHistogram;
use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroQueueAgeHistogram;
use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroReplenishUrgencyClassifier;
use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroWorkerIdlePredictor;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Maestro health CLI front door: routes histogram|predict|urgency to the read-only lens services, prints
 * each lens's schema under --json, rejects unknown actions, and never mutates the queue/lease state.
 */
final class AtlasTaskHealthHistogramCommandTest extends TestCase
{
    /** A dedicated, faked serving disk so the test NEVER reads/mutates the live (or shared probe) queue. */
    private const DISK = 'atlas_health_cli_test';

    protected function setUp(): void
    {
        parent::setUp();
        // Point the serving stack at an isolated, throwaway disk — fast and never touches shared state.
        config(['atlas.task_serving.queue_disk' => self::DISK]);
        Storage::fake(self::DISK);
    }

    public function test_histogram_action_emits_both_distribution_lens_schemas(): void
    {
        $this->seedClaimablePacket('qa-1');
        $this->seedActiveLease('lease-task-1');

        [$exit, $payload] = $this->runJson('histogram');

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasTaskHealthHistogramCommand::HISTOGRAM_SCHEMA, $payload['schema']);
        $this->assertArrayHasKey('queue_age', $payload);
        $this->assertArrayHasKey('lease_lifetime', $payload);
        $this->assertSame(AtlasMaestroQueueAgeHistogram::SCHEMA, $payload['queue_age']['schema']);
        $this->assertSame(AtlasMaestroLeaseLifetimeHistogram::SCHEMA, $payload['lease_lifetime']['schema']);
        $this->assertArrayHasKey('bins', $payload['queue_age']);
        $this->assertGreaterThanOrEqual(1, $payload['queue_age']['total_claimable']);
        $this->assertGreaterThanOrEqual(1, $payload['lease_lifetime']['total_active']);
    }

    public function test_predict_action_emits_worker_idle_predictor_schema(): void
    {
        $this->seedClaimablePacket('qa-2');

        [$exit, $payload] = $this->runJson('predict');

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasMaestroWorkerIdlePredictor::SCHEMA, $payload['schema']);
        $this->assertArrayHasKey('claimable_depth', $payload);
        $this->assertArrayHasKey('serve_rate_per_minute', $payload);
        $this->assertArrayHasKey('confidence', $payload);
    }

    public function test_urgency_action_emits_replenish_urgency_schema(): void
    {
        $this->seedClaimablePacket('qa-3');

        [$exit, $payload] = $this->runJson('urgency');

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasMaestroReplenishUrgencyClassifier::SCHEMA, $payload['schema']);
        $this->assertArrayHasKey('urgency', $payload);
        $this->assertArrayHasKey('reasons', $payload);
        $this->assertArrayHasKey('inputs', $payload);
    }

    public function test_predict_human_output_includes_active_workers_and_reason(): void
    {
        $this->seedClaimablePacket('qa-predict-human');
        $this->seedActiveLease('lease-task-predict-human');

        // Confirm the predictor actually returns active_workers and reason for this fixture
        // before asserting the renderer surfaces them.
        [, $json] = $this->runJson('predict');
        $this->assertArrayHasKey('active_workers', $json);
        $this->assertArrayHasKey('reason', $json);

        [$exit, $output] = $this->runHuman('predict');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('active_workers=', $output);
        $this->assertStringContainsString((string) $json['active_workers'], $output);
        $this->assertStringContainsString('reason=', $output);
        $this->assertStringContainsString($json['reason'], $output);
    }

    public function test_urgency_human_output_includes_next_action(): void
    {
        $this->seedClaimablePacket('qa-urgency-human');

        [, $json] = $this->runJson('urgency');
        $this->assertArrayHasKey('next_action', $json);

        [$exit, $output] = $this->runHuman('urgency');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('next_action=', $output);
        $this->assertStringContainsString($json['next_action'], $output);
        $this->assertStringContainsString('urgency=', $output);
    }

    public function test_json_output_remains_machine_compatible_and_exposes_full_payload(): void
    {
        $this->seedClaimablePacket('qa-json-full');
        $this->seedActiveLease('lease-task-json-full');

        [$exitPredict, $predictPayload] = $this->runJson('predict');
        [$exitUrgency, $urgencyPayload] = $this->runJson('urgency');

        $this->assertSame(0, $exitPredict);
        $this->assertSame(0, $exitUrgency);

        // --json must expose the FULL payload (no human-only truncation/formatting), including
        // every key the human renderer surfaces.
        foreach (['schema', 'claimable_depth', 'active_workers', 'serve_rate_per_minute', 'confidence'] as $key) {
            $this->assertArrayHasKey($key, $predictPayload, "predict --json missing key: {$key}");
        }
        foreach (['schema', 'urgency', 'next_action', 'reasons', 'inputs'] as $key) {
            $this->assertArrayHasKey($key, $urgencyPayload, "urgency --json missing key: {$key}");
        }

        // No ANSI/tag formatting markers should leak into machine output.
        $rawPredict = (string) json_encode($predictPayload);
        $rawUrgency = (string) json_encode($urgencyPayload);
        $this->assertStringNotContainsString('<fg=', $rawPredict);
        $this->assertStringNotContainsString('<fg=', $rawUrgency);
    }

    public function test_unknown_action_returns_non_zero_exit(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:task:maestro-health', ['action' => 'bogus', '--json' => true]);

        $this->assertNotSame(0, $exit);
    }

    public function test_all_actions_are_read_only_queue_and_lease_state_is_byte_identical(): void
    {
        $this->seedClaimablePacket('qa-ro');
        $this->seedActiveLease('lease-task-ro');

        $before = $this->captureServingState();

        foreach (['histogram', 'predict', 'urgency'] as $action) {
            [$exit] = $this->runJson($action);
            $this->assertSame(0, $exit, $action.' must succeed');
        }

        $after = $this->captureServingState();

        $this->assertSame($before, $after, 'health lenses must not mutate the queue/lease repo state');
    }

    /**
     * @return array{0:int,1:array<string,mixed>}
     */
    private function runJson(string $action): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:task:maestro-health', ['action' => $action, '--json' => true]);
        $decoded = json_decode(trim($kernel->output()), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return [$exit, $decoded];
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runHuman(string $action): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:task:maestro-health', ['action' => $action]);

        return [$exit, $kernel->output()];
    }

    private function seedClaimablePacket(string $taskPacketId): void
    {
        (new AgentControlPlaneTaskPacketQueueRepository(self::DISK))->enqueue([
            'task_packet_id' => $taskPacketId,
            'task_packet_hash' => hash('sha256', $taskPacketId),
            'status' => 'claimable',
            'objective' => 'health lens '.$taskPacketId,
            'allowed_files' => ['app/Fake.php'],
            'scope_in' => ['app/Fake.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['ok'],
        ], ['metadata' => ['client_id' => 'worker-a']]);
    }

    private function seedActiveLease(string $taskPacketId): void
    {
        (new AgentControlPlaneClaimLeaseRepository(self::DISK))->claim(
            $taskPacketId,
            'worker-a',
            ['write_set' => ['app/Lensed'.$taskPacketId.'.php']],
        );
    }

    /**
     * @return array<string,string>
     */
    private function captureServingState(): array
    {
        $disk = Storage::disk(self::DISK);
        $state = [];
        foreach ([AgentControlPlaneTaskPacketQueueRepository::STORAGE_PREFIX, AgentControlPlaneClaimLeaseRepository::STORAGE_PREFIX] as $prefix) {
            foreach ($disk->allFiles($prefix) as $file) {
                if (str_ends_with($file, '/.lock')) {
                    continue; // advisory lock file content is stable and not part of the logical repo state
                }
                $state[$file] = sha1((string) $disk->get($file));
            }
        }
        ksort($state);

        return $state;
    }
}
