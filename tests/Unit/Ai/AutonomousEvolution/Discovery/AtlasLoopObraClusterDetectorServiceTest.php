<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopObraClusterDetectorService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementProposalBacklogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Characterization coverage for the detector's durable cooldown decision.
 */
final class AtlasLoopObraClusterDetectorServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-06-20T12:00:00Z'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cluster_recorded_inside_cooldown_window_is_on_cooldown(): void
    {
        $clusterHash = 'cluster-fresh';
        $this->writeCooldownIndex($clusterHash, Carbon::now()->subHours(2)->toIso8601String());

        $this->assertTrue(
            $this->onCooldown($clusterHash, 168),
            'a cluster recorded inside the cooldown window must suppress duplicate proposals',
        );
    }

    public function test_cluster_recorded_before_cooldown_window_is_not_on_cooldown(): void
    {
        $clusterHash = 'cluster-stale';
        $this->writeCooldownIndex($clusterHash, Carbon::now()->subHours(169)->toIso8601String());

        $this->assertFalse(
            $this->onCooldown($clusterHash, 168),
            'a cluster older than the cooldown window must be eligible again',
        );
    }

    private function onCooldown(string $clusterHash, int $cooldownHours): bool
    {
        $service = new AtlasLoopObraClusterDetectorService(
            $this->backlogStub(),
        );
        $method = new ReflectionMethod($service, 'onCooldown');
        $method->setAccessible(true);

        return (bool) $method->invoke($service, $clusterHash, $cooldownHours);
    }

    private function backlogStub(): AtlasSelfImprovementProposalBacklogService
    {
        return new class extends AtlasSelfImprovementProposalBacklogService
        {
            public function __construct() {}

            /** @param array<string,mixed> $payload */
            public function createProposal(array $payload): array
            {
                return ['proposal_id' => 'prop_test'];
            }
        };
    }

    private function writeCooldownIndex(string $clusterHash, string $recordedAt): void
    {
        Storage::disk('local')->put('atlas/loop/obra-cluster/cluster-index.json', (string) json_encode([
            'schema_version' => AtlasLoopObraClusterDetectorService::SCHEMA_VERSION,
            'updated_at' => Carbon::now()->toIso8601String(),
            'clusters' => [
                $clusterHash => [
                    'proposal_id' => 'prop_test',
                    'hub_path' => 'app/Services/Hub.php',
                    'recorded_at' => $recordedAt,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
