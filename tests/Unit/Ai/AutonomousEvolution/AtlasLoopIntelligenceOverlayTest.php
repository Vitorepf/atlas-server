<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopIntelligenceOverlay;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopIntelligenceOverlayTest extends TestCase
{
    private string $repo;

    private string $runDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-loop-intel-repo-'.bin2hex(random_bytes(4));
        $this->runDir = sys_get_temp_dir().'/atlas-loop-intel-run-'.bin2hex(random_bytes(4));
        mkdir($this->repo.'/app/Services/Ai/Security', 0o755, true);
        mkdir($this->runDir, 0o755, true);
        config()->set('atlas.ai.providers', [
            'provider_auto' => ['allow_auto' => true, 'allow_manual' => true, 'model' => 'auto-model', 'model_tier' => 'daily'],
            'provider_manual' => ['allow_auto' => false, 'allow_manual' => true, 'model' => 'manual-model', 'model_tier' => 'premium'],
        ]);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->repo, $this->runDir]))->run();
        parent::tearDown();
    }

    public function test_overlay_prioritizes_by_impact_learning_provider_matrix_and_domain_slots(): void
    {
        $service = new AtlasLoopIntelligenceOverlay;
        $service->recordFeedback($this->runDir, [
            'path' => 'app/Services/Ai/Security/AuthGuard.php',
            'mode' => 'fake_implemented',
            'action' => 'approved',
            'reason' => 'operator wants security first',
        ]);
        $service->recordFeedback($this->runDir, [
            'path' => 'docs/engineering-knowledge-base/archive/old.md',
            'mode' => 'doc_duplicate',
            'action' => 'rejected',
            'reason' => 'archive noise',
        ]);

        $overlay = $service->overlay($this->repo, $this->runDir, [
            'flags' => [
                [
                    'mode' => 'doc_duplicate',
                    'disposition' => 'flag',
                    'path' => 'docs/engineering-knowledge-base/archive/old.md',
                    'count' => 20,
                    'route' => 'human_doc_authority_consolidation',
                ],
                [
                    'mode' => 'fake_implemented',
                    'disposition' => 'flag',
                    'path' => 'app/Services/Ai/Security/AuthGuard.php',
                    'count' => 1,
                    'route' => 'implement_or_mark_planned',
                ],
            ],
        ], ['provider' => 'provider_auto']);

        $this->assertSame(2, $overlay['summary']['flag_count']);
        $this->assertSame(2, $overlay['learning']['feedback_count']);
        $this->assertSame('app/Services/Ai/Security/AuthGuard.php', $overlay['prioritized_flags'][0]['path']);
        $this->assertFalse($overlay['routing_effect']['changes_provider']);
        $this->assertFalse($overlay['prioritized_flags'][0]['review_contract']['auto_apply_allowed']);
        $this->assertSame(2, $overlay['provider_matrix']['known_count']);
        $this->assertSame('provider_auto', $overlay['provider_matrix']['selected_provider']);
        $this->assertContains('cyber_security', array_column($overlay['cross_domain_slots'], 'domain'));
        $this->assertNotEmpty($overlay['meta_clusters']);
    }
}
