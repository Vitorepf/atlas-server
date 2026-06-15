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
        $node = AtlasAurgNode::query()->where('source_kind', 'organism')->first();
        $this->assertNotNull($node);
        $this->assertSame('organism', $node->source_kind, 'recorded in the UN-synced source so the daily read-model --prune (source_kind=domain) never wipes organism compounding proposals');
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

    public function test_organism_proposal_survives_the_daily_domain_source_prune(): void
    {
        // REGRESSION (#9): the read-model sync writes the 21 canonical taxonomy nodes as
        // source_kind='domain' and the DAILY `atlas:aurg:ingest --prune` deletes every source_kind=
        // 'domain' node NOT in that keep-set. The organism recorder reused 'domain', so its compounding
        // proposals were WIPED daily (priorProposals came up empty). Now it uses the un-synced
        // 'organism' source (like mission/obra) and survives the prune.
        config()->set('atlas.aurg.enabled', true);
        $this->createAurgTables();
        // A taxonomy node (the read-model keep-set) alongside the organism proposal.
        AtlasAurgNode::query()->insert([
            'id' => 'domain:domain:finance', 'kind' => 'domain', 'source_kind' => 'domain',
            'source_id' => 'finance', 'label' => 'finance', 'workspace_id' => null, 'provider_safe' => true,
            'sensitive' => false, 'meta' => '{}', 'content_hash' => 'tax-finance', 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new RealityGraphProposalRecorder)->record($this->proposal(true), ['metric' => 'm', 'value' => 1.0, 'passed' => true, 'method' => 'm']);
        $this->assertCount(1, (new RealityGraphProposalRecorder)->priorProposals('finance', 10));

        // Mimic pruneSource('domain', keepIds): delete the synced 'domain' source not in the keep-set.
        AtlasAurgNode::query()->where('source_kind', 'domain')->whereNotIn('id', ['domain:domain:finance'])->delete();

        // The organism proposal (source_kind='organism') is untouched; the taxonomy node is intact.
        $this->assertCount(1, (new RealityGraphProposalRecorder)->priorProposals('finance', 10), 'organism proposal survives the domain-source prune');
        $this->assertNotNull(AtlasAurgNode::query()->whereKey('domain:domain:finance')->first(), 'the taxonomy node is intact');
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
