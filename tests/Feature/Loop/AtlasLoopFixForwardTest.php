<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * L3-4: fix-forward FECHA o ciclo. A política v2 do operador é literal: um canário vermelho
 * pós-merge NUNCA reverte — o Loop enfileira uma task de CORREÇÃO no próprio campaign,
 * alvejando o mesmo arquivo, partindo do snapshot pré-merge. Estes testes congelam: (a)
 * canário RED → task fix_forward enfileirada com o alvo e o snapshot; (b) canário verde
 * (ou ausente) → nenhuma task de correção (não polui a fila).
 */
final class AtlasLoopFixForwardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
    }

    private function proposal(): AtlasLoopProposal
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'fix-forward-test',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'config' => [],
            'max_seconds' => 60,
        ]);

        return AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'objective' => 'something',
            'target_path' => 'app/Services/Foo/Bar.php',
            'diff_text' => 'diff',
            'proposal_hash' => 'h-ff-'.bin2hex(random_bytes(4)),
        ]);
    }

    private function enqueueFixForward(AtlasLoopProposal $p, array $canary, ?string $snap): array
    {
        $m = new ReflectionMethod(AtlasLoopAutoMergeService::class, 'enqueueFixForward');
        $m->setAccessible(true);

        return $m->invoke(app(AtlasLoopAutoMergeService::class), $p, $canary, $snap);
    }

    public function test_red_canary_enqueues_a_fix_forward_correction_task(): void
    {
        $p = $this->proposal();

        $result = $this->enqueueFixForward(
            $p,
            ['ran' => true, 'passed' => false, 'target' => 'tests/Unit/Foo/BarTest.php'],
            'atlas-snap-abc123',
        );

        $this->assertTrue($result['enqueued'], 'canário RED enfileira a correção');
        $task = AtlasLoopTask::query()->where('campaign_id', $p->campaign_id)->where('source', 'fix_forward')->first();
        $this->assertNotNull($task, 'task fix_forward existe');
        $this->assertSame('app/Services/Foo/Bar.php', $task->target_path, 'alveja o arquivo mergeado');
        $this->assertStringContainsString('não reverter', (string) $task->objective);
        $payload = is_array($task->payload) ? $task->payload : (array) json_decode((string) $task->payload, true);
        $this->assertSame('fix_forward_canary_red', $payload['origin']);
        $this->assertSame('atlas-snap-abc123', $payload['snapshot_tag'], 'o snapshot pré-merge é endereçado');
        $this->assertSame(AtlasLoopTask::STATUS_PENDING, $task->status, 'pendente, o Loop pega no próximo ciclo');
    }

    public function test_fix_forward_task_dedups_across_repeated_passes(): void
    {
        $p = $this->proposal();
        $canary = ['ran' => true, 'passed' => false, 'target' => 'tests/Unit/Foo/BarTest.php'];

        $this->enqueueFixForward($p, $canary, 'atlas-snap-abc123');
        $this->enqueueFixForward($p, $canary, 'atlas-snap-abc123');

        $count = AtlasLoopTask::query()->where('campaign_id', $p->campaign_id)->where('source', 'fix_forward')->count();
        $this->assertSame(1, $count, 'a mesma correção não é enfileirada duas vezes');
    }
}
