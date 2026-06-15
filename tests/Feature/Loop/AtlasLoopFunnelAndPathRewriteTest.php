<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopFunnelService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProposalMaterializer;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * L3-1 (keystone do flywheel): as duas correções que destravaram "82 candidatas / 0 merges".
 *
 *  1. NORMALIZAÇÃO DE PATH — o provider gera o diff num workspace isolado onde o arquivo
 *     mora em `src/<basename>`, mas o repo o coloca em `<target_path>`. Sem reescrever os
 *     headers a/<x> b/<x>, `git apply` falhava em 100% das propostas (git_apply_failed).
 *  2. INSTRUMENTAÇÃO DO FUNIL — sem contagem por estágio, "0 merges" era narrativa; o
 *     funil torna o gargalo MEDIDO (drenáveis vs aposentadas vs mergeadas).
 *
 * Estes testes congelam ambas: o rewrite é cirúrgico (um arquivo, preserva /dev/null,
 * no-op idempotente, não corrompe multi-arquivo) e o funil reporta os estágios reais.
 */
final class AtlasLoopFunnelAndPathRewriteTest extends TestCase
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

    private function materializer(): AtlasLoopProposalMaterializer
    {
        return app(AtlasLoopProposalMaterializer::class);
    }

    // ── 1. path rewrite ──────────────────────────────────────────────────────

    public function test_rewrite_maps_provider_src_path_to_real_target_path(): void
    {
        $diff = "diff --git a/src/Foo.php b/src/Foo.php\n"
            ."index 111..222 100644\n--- a/src/Foo.php\n+++ b/src/Foo.php\n"
            ."@@ -1,1 +1,2 @@\n line\n+added\n";

        $out = $this->materializer()->rewriteDiffToTarget($diff, 'app/Services/X/Foo.php');

        $this->assertStringContainsString('diff --git a/app/Services/X/Foo.php b/app/Services/X/Foo.php', $out);
        $this->assertStringContainsString('--- a/app/Services/X/Foo.php', $out);
        $this->assertStringContainsString('+++ b/app/Services/X/Foo.php', $out);
        $this->assertStringNotContainsString('src/Foo.php', $out);
        // O corpo do hunk é preservado intacto.
        $this->assertStringContainsString("+added", $out);
    }

    public function test_rewrite_preserves_dev_null_for_new_files(): void
    {
        $diff = "diff --git a/src/New.php b/src/New.php\n"
            ."new file mode 100644\n--- /dev/null\n+++ b/src/New.php\n"
            ."@@ -0,0 +1,1 @@\n+<?php\n";

        $out = $this->materializer()->rewriteDiffToTarget($diff, 'app/New.php');

        $this->assertStringContainsString('--- /dev/null', $out, '/dev/null no lado de criação é preservado');
        $this->assertStringContainsString('+++ b/app/New.php', $out);
    }

    public function test_rewrite_is_noop_for_multifile_diffs(): void
    {
        // Segurança: diffs multi-arquivo NÃO são reescritos (não corromper paths legítimos).
        $diff = "diff --git a/src/A.php b/src/A.php\n--- a/src/A.php\n+++ b/src/A.php\n@@ -1 +1 @@\n-a\n+b\n"
            ."diff --git a/src/B.php b/src/B.php\n--- a/src/B.php\n+++ b/src/B.php\n@@ -1 +1 @@\n-c\n+d\n";

        $out = $this->materializer()->rewriteDiffToTarget($diff, 'app/Only.php');

        $this->assertStringContainsString('a/src/A.php', $out, 'multi-arquivo fica intacto');
        $this->assertStringContainsString('a/src/B.php', $out);
        $this->assertStringNotContainsString('app/Only.php', $out);
    }

    public function test_rewrite_is_idempotent_when_already_target(): void
    {
        $diff = "diff --git a/app/Y.php b/app/Y.php\n--- a/app/Y.php\n+++ b/app/Y.php\n@@ -1 +1,2 @@\n x\n+y\n";
        $once = $this->materializer()->rewriteDiffToTarget($diff, 'app/Y.php');
        $twice = $this->materializer()->rewriteDiffToTarget($once, 'app/Y.php');

        $this->assertSame($once, $twice, 'reescrever um diff já-correto é no-op');
    }

    // ── 2. funnel instrumentation ────────────────────────────────────────────

    public function test_funnel_reports_drainable_and_retired_stages_with_reasons(): void
    {
        $campaign = AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'l3-1-funnel-test',
            'config' => [],
            'max_seconds' => 60,
            'objective' =>'funnel-test',
            'status' => 'running',
        ]);

        // Uma drenável (certificada, não-mergeada, não-revisada).
        AtlasLoopProposal::query()->create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'objective' => 'drainable one',
            'target_path' => 'app/A.php',
            'diff_text' => 'diff',
            'proposal_hash' => 'h-drain-'.bin2hex(random_bytes(3)),
        ]);
        // Uma aposentada (reviewed_at set, nunca mergeada) — o ramo retired_stale.
        $retired = AtlasLoopProposal::query()->create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'objective' => 'retired one',
            'target_path' => 'app/B.php',
            'diff_text' => 'diff',
            'proposal_hash' => 'h-ret-'.bin2hex(random_bytes(3)),
        ]);
        $retired->forceFill(['reviewed_at' => now()])->save();
        // Uma PARQUEADA p/ o operador (reviewed_at set + decision=park) — NÃO é retired_stale (#11):
        // é trabalho aguardando humano, não trabalho perdido; reportar como retired era desonesto.
        $parked = AtlasLoopProposal::query()->create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'objective' => 'parked one',
            'target_path' => 'app/C.php',
            'diff_text' => 'diff',
            'proposal_hash' => 'h-park-'.bin2hex(random_bytes(3)),
            'quality' => ['_operator_review' => ['decision' => 'park', 'status' => 'parked_for_operator_review']],
        ]);
        $parked->forceFill(['reviewed_at' => now()])->save();

        $snap = app(AtlasLoopFunnelService::class)->snapshot($campaign->id);

        $this->assertSame(3, $snap['stages']['certified'], 'as três certificadas');
        $this->assertSame(1, $snap['stages']['drainable'], 'só a não-revisada é drenável');
        $this->assertSame(0, $snap['stages']['merged'], 'nenhuma mergeada (never-merge default)');
        $this->assertSame(1, $snap['branches']['retired_stale'], 'só a aposentada de verdade conta como retired (a parqueada NÃO)');
        $this->assertSame(1, $snap['branches']['parked_for_operator'], 'a parqueada é reportada como parked, não como retired');
        $this->assertIsString($snap['verdict']);
    }

    public function test_funnel_verdict_flags_drain_not_running_when_drainable_and_zero_merged(): void
    {
        $campaign = AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'l3-1-funnel-test',
            'config' => [],
            'max_seconds' => 60,
            'objective' =>'verdict-test',
            'status' => 'running',
        ]);
        AtlasLoopProposal::query()->create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'objective' => 'drainable',
            'target_path' => 'app/C.php',
            'diff_text' => 'diff',
            'proposal_hash' => 'h-v-'.bin2hex(random_bytes(3)),
        ]);

        $snap = app(AtlasLoopFunnelService::class)->snapshot($campaign->id);

        $this->assertStringContainsString('gargalo de consumo', $snap['verdict']);
    }

    // ── 3. never-merge default preserved (model guard) ───────────────────────

    public function test_model_guard_forces_merged_false_outside_governed_scope(): void
    {
        $campaign = AtlasLoopCampaign::query()->create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'l3-1-funnel-test',
            'config' => [],
            'max_seconds' => 60,
            'objective' =>'guard-test',
            'status' => 'running',
        ]);
        $p = AtlasLoopProposal::query()->create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'objective' => 'guarded',
            'target_path' => 'app/D.php',
            'diff_text' => 'diff',
            'proposal_hash' => 'h-g-'.bin2hex(random_bytes(3)),
        ]);

        // Fora do escopo governado, qualquer tentativa de marcar merged volta a false.
        $p->merged_to_main = true;
        $p->save();
        $p->refresh();

        $this->assertFalse((bool) $p->merged_to_main, 'never-merge é o default à prova de bug');
    }
}
