<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P3b §0.3.1: dual GateEvaluated reviews must record/reload with eventIntegrityValid.
 * Drives shipped AtlasEvidenceLedger::record + eventById + eventIntegrityValid on real schema.
 */
final class AaeosP3bGateEvaluatedIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        if (is_file(database_path('migrations/2026_07_12_011200_repair_atlas_ledger_events_hash_and_scope_columns.php'))) {
            (require database_path('migrations/2026_07_12_011200_repair_atlas_ledger_events_hash_and_scope_columns.php'))->up();
        }
        if (is_file(database_path('migrations/2026_07_12_021500_add_hash_chain_to_atlas_ledger_events.php'))) {
            (require database_path('migrations/2026_07_12_021500_add_hash_chain_to_atlas_ledger_events.php'))->up();
        }
        (require database_path('migrations/2026_07_23_230000_harden_atlas_ledger_chain_and_journey_queries.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_dual_gate_evaluated_reviews_verify_integrity_and_sod(): void
    {
        $ledger = app(AtlasEvidenceLedger::class);
        $reviewBasisSha = hash('sha256', 'p3b-test-review-basis|'.uniqid('', true));
        $corr = 'p3b-test-'.substr(hash('sha256', (string) microtime(true)), 0, 12);
        $specId = substr('s'.hash('sha256', $corr.'|spec'), 0, 32);
        $govId = substr('g'.hash('sha256', $corr.'|gov'), 0, 32);

        $spec = $this->recordReview($ledger, 'specification', $specId, $reviewBasisSha, $corr, null);
        $gov = $this->recordReview($ledger, 'governance_quality', $govId, $reviewBasisSha, $corr, $specId);

        $this->assertNotNull($spec);
        $this->assertNotNull($gov);

        $reloadSpec = $ledger->eventById((string) $spec->event_id, 'default');
        $reloadGov = $ledger->eventById((string) $gov->event_id, 'default');
        $this->assertNotNull($reloadSpec);
        $this->assertNotNull($reloadGov);

        $this->assertTrue($ledger->eventIntegrityValid($reloadSpec), $ledger->eventIntegrityStatus($reloadSpec));
        $this->assertTrue($ledger->eventIntegrityValid($reloadGov), $ledger->eventIntegrityStatus($reloadGov));
        $this->assertSame(AtlasEvidenceLedger::SCHEMA_VERSION_V2, (string) $reloadSpec->schema_version);

        $sp = (array) $reloadSpec->payload;
        $gp = (array) $reloadGov->payload;
        $this->assertSame('APPROVED', $sp['verdict'] ?? null);
        $this->assertSame('APPROVED', $gp['verdict'] ?? null);
        $this->assertSame('specification', $sp['reviewer_role'] ?? null);
        $this->assertSame('governance_quality', $gp['reviewer_role'] ?? null);
        $this->assertSame($reviewBasisSha, $sp['review_basis_sha256'] ?? null);
        $this->assertSame($reviewBasisSha, $gp['review_basis_sha256'] ?? null);
        $this->assertNotSame('', (string) ($sp['reviewer_principal_hash'] ?? ''));
        $this->assertNotSame($sp['reviewer_principal_hash'] ?? 'a', $gp['reviewer_principal_hash'] ?? 'a');
        $this->assertNotSame('', (string) ($sp['observation_timestamp'] ?? ''));
        $this->assertSame($specId, (string) ($gp['parent_event_ref'] ?? ''));
    }

    private function recordReview(
        AtlasEvidenceLedger $ledger,
        string $role,
        string $eventId,
        string $reviewBasisSha,
        string $correlationId,
        ?string $parent,
    ): ?\App\Models\AtlasLedgerEvent {
        $now = CarbonImmutable::now()->utc()->toIso8601String();
        $principal = hash('sha256', 'aaeos.mt.authenticated_review_runtime|'.$role.'|v1');

        return $ledger->record(LedgerEventType::GateEvaluated, [
            'event_name' => 'aaeos.mt.master_amendment_review',
            'schema' => 'atlas.aaeos.mt.gate_evaluated_review.v1',
            'verdict' => 'APPROVED',
            'reviewer_role' => $role,
            'reviewer_principal_hash' => $principal,
            'review_basis_sha256' => $reviewBasisSha,
            'unresolved_critical' => 0,
            'unresolved_important' => 0,
            'observation_timestamp' => $now,
            'slice' => 'P3b',
            'amendment_id' => 'p3b-trihygiene-aliases-delete-v1',
            'parent_event_ref' => $parent,
            'operator' => [
                'tenant_id' => 'default',
                'operator_id' => 'aaeos_mt_review_runtime',
            ],
        ], [
            'event_id' => $eventId,
            'tenant_id' => 'default',
            'operator_id' => 'aaeos_mt_review_runtime',
            'correlation_id' => $correlationId,
            'scope_type' => 'aaeos_mt_amendment',
            'scope_id' => 'p3b-trihygiene-aliases',
            'emitter_stage' => 'atlas.aaeos.mt.controller',
            'emitter_version' => 'vFINAL-COOKBOOK',
            'envelope_id' => 'aaeos-mt-p3b-amend-test',
        ]);
    }
}
