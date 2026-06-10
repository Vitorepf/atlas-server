<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Organism;

use App\Models\AtlasAurgNode;
use App\Services\Ai\Organism\AbstractDomainActuator;
use App\Services\Ai\Organism\DomainProposal;
use App\Services\Ai\Organism\RealityGraphProposalRecorder;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AOBG N4.F1 — the PRODUCTION recorder over the AURG reality-graph store.
 *
 * Proves cross-domain COMPOUNDING persists provider-safe + sensitive-correct, and that the
 * recorder is FAIL-OPEN (no store ⇒ honest recorded:false, never a throw).
 */
final class RealityGraphProposalRecorderTest extends TestCase
{
    private function createAurgTables(): void
    {
        if (! Schema::hasTable('atlas_aurg_nodes')) {
            Schema::create('atlas_aurg_nodes', function ($t): void {
                $t->string('id', 300)->primary();
                $t->string('kind', 40)->index();
                $t->string('source_kind', 20)->index();
                $t->string('source_id', 220);
                $t->string('label', 220);
                $t->string('workspace_id', 160)->nullable()->index();
                $t->boolean('provider_safe')->default(false)->index();
                $t->boolean('sensitive')->default(false)->index();
                $t->json('meta')->default('{}');
                $t->string('content_hash', 64)->index();
                $t->timestamps();
                $t->unique(['source_kind', 'source_id', 'kind'], 'uniq_atlas_aurg_nodes_source');
            });
        }
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_aurg_nodes');
        parent::tearDown();
    }

    private function proposal(bool $sensitive = true): DomainProposal
    {
        return DomainProposal::fromArray([
            'domain' => 'finance',
            'intent' => 'mean reversion idea',
            'content' => 'Trade idea on BTC/USDT — propose only',
            'rationale' => 'brain anchored',
            'brain_refs' => ['ref:obra:1'],
            'sensitive' => $sensitive,
            'payload' => ['daily_returns' => [0.01, 0.02], 'secret_keys' => 'DO-NOT-LEAK'],
        ]);
    }

    public function test_records_a_provider_safe_sensitive_node_without_leaking_payload(): void
    {
        config()->set('atlas.aurg.enabled', true);
        $this->createAurgTables();

        $recorder = new RealityGraphProposalRecorder;
        $validation = ['metric' => 'annualized_sharpe', 'value' => 1.23, 'passed' => true, 'method' => 'honest_metrics.sharpe.in_process(win_rate_forbidden)'];

        $res = $recorder->record($this->proposal(true), $validation);

        $this->assertTrue($res['recorded']);
        $node = AtlasAurgNode::query()->where('source_kind', 'domain')->first();
        $this->assertNotNull($node);
        $this->assertTrue($node->sensitive, 'a finance proposal must be stored sensitive');
        $this->assertTrue($node->provider_safe);

        // No payload / secret in the persisted node (provider-safe by construction).
        $raw = json_encode($node->toArray()) ?: '';
        $this->assertStringNotContainsString('DO-NOT-LEAK', $raw);
        $this->assertStringNotContainsString('secret_keys', $raw);
        $this->assertStringNotContainsString('daily_returns', $raw);

        // The honest metric numbers + the propose-only ceiling are recorded.
        $this->assertSame('annualized_sharpe', $node->meta['validation']['metric']);
        $this->assertSame('requires_operator', $node->meta['actuation_gate']);
        $this->assertSame(AbstractDomainActuator::CEILING, $node->meta['ceiling']);
        $this->assertTrue($node->meta['never_auto_promote']);
    }

    public function test_prior_proposals_reads_back_compounding_signal(): void
    {
        config()->set('atlas.aurg.enabled', true);
        $this->createAurgTables();
        $recorder = new RealityGraphProposalRecorder;
        $v = ['metric' => 'annualized_sharpe', 'value' => 1.0, 'passed' => true, 'method' => 'm'];

        $recorder->record($this->proposal(true), $v);

        $prior = $recorder->priorProposals('finance', 10);
        $this->assertCount(1, $prior);
        $this->assertSame('finance', $prior[0]['domain']);
        $this->assertTrue($prior[0]['sensitive']);
        // The read-back is label-only (no payload).
        $this->assertStringNotContainsString('DO-NOT-LEAK', json_encode($prior) ?: '');
    }

    public function test_fail_open_when_store_absent(): void
    {
        config()->set('atlas.aurg.enabled', true);
        Schema::dropIfExists('atlas_aurg_nodes');

        $recorder = new RealityGraphProposalRecorder;
        $res = $recorder->record($this->proposal(), ['metric' => 'm', 'value' => 1.0, 'passed' => true, 'method' => 'm']);

        $this->assertFalse($res['recorded']);
        $this->assertSame('store_missing', $res['reason']);
        $this->assertSame([], $recorder->priorProposals('finance'));
    }

    public function test_fail_open_when_aurg_disabled(): void
    {
        config()->set('atlas.aurg.enabled', false);
        $this->createAurgTables();

        $recorder = new RealityGraphProposalRecorder;
        $res = $recorder->record($this->proposal(), ['metric' => 'm', 'value' => 1.0, 'passed' => true, 'method' => 'm']);

        $this->assertFalse($res['recorded']);
        $this->assertSame('aurg_disabled', $res['reason']);
    }
}
