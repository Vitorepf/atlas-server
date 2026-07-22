<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasSelfConstructionAgentDispatchReceipt;
use App\Models\AtlasSelfConstructionAgentWakeupItem;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SECURITY · A1-SC-0056 — agentDispatchReceiptWrite must NOT mint an authorization-bearing
 * signed_pending_dispatch row from caller-supplied text. Before this fix it validated only shape
 * (decision in set, non-empty signed_by, two /^[a-f0-9]{64}$/ hashes, non-empty expires_at) and then
 * updateOrCreate'd a signed receipt — never binding dispatch_envelope_hash to the server's canonical
 * preflight envelope and never proving expires_at is in the future. A future dispatch executor consumes
 * status=signed_pending_dispatch as release authority.
 *
 * These tests PROVE the two write-layer defects and pin the legitimate path so the fix does not break it.
 */
final class AtlasAiSelfConstructionAgentDispatchReceiptWriteAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dropTables();
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php'))->up();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-22T12:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->dropTables();
        parent::tearDown();
    }

    public function test_a_forged_dispatch_envelope_hash_cannot_mint_release_authority(): void
    {
        $this->claimWakeup();
        $service = app(AtlasSelfConstructionReadinessService::class);

        // Forged envelope hash (not bound to the server preflight) + a valid FUTURE expiry.
        $result = $service->agentDispatchReceiptWrite($this->writeOptions([
            'dispatch_envelope_hash' => str_repeat('b', 64),
            'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601String(),
        ]));

        $this->assertNotSame('agent_dispatch_receipt_write_ready', $result['status'], 'a receipt whose envelope hash is not bound to the preflight must be refused');
        $this->assertSame(0, AtlasSelfConstructionAgentDispatchReceipt::query()->where('status', 'signed_pending_dispatch')->count(), 'no release-authority row may be minted from a forged envelope hash');
    }

    public function test_a_past_expiry_cannot_mint_release_authority(): void
    {
        $this->claimWakeup();
        $service = app(AtlasSelfConstructionReadinessService::class);

        // Correct (bound) envelope hash, but an already-expired receipt must still be refused at write time.
        $result = $service->agentDispatchReceiptWrite($this->writeOptions([
            'dispatch_envelope_hash' => $this->canonicalEnvelopeHash($service),
            'expires_at' => '2020-01-01T00:00:00Z',
        ]));

        $this->assertNotSame('agent_dispatch_receipt_write_ready', $result['status'], 'an already-expired receipt must be refused at write time, not just downstream');
        $this->assertSame(0, AtlasSelfConstructionAgentDispatchReceipt::query()->where('status', 'signed_pending_dispatch')->count(), 'no release-authority row may be minted with a past expiry');
    }

    public function test_a_bound_envelope_and_future_expiry_still_writes(): void
    {
        // Positive control: the legitimate path (envelope bound to the preflight + a real future single-use
        // expiry) still persists the signed receipt — the fix is not over-tight.
        $this->claimWakeup();
        $service = app(AtlasSelfConstructionReadinessService::class);

        $result = $service->agentDispatchReceiptWrite($this->writeOptions([
            'dispatch_envelope_hash' => $this->canonicalEnvelopeHash($service),
            'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601String(),
        ]));

        $this->assertSame('agent_dispatch_receipt_write_ready', $result['status'], 'a bound, unexpired receipt is written');
        $this->assertSame(1, AtlasSelfConstructionAgentDispatchReceipt::query()->where('status', 'signed_pending_dispatch')->count());
    }

    /** The server's canonical dispatch envelope hash for the current claimed wakeup — what a real signer binds to. */
    private function canonicalEnvelopeHash(AtlasSelfConstructionReadinessService $service): string
    {
        $preflight = $service->agentDispatchPreflight(['packet' => 'AP-001']);

        return ReadinessHash::stable((array) data_get($preflight, 'dispatch_preflight.dispatch_envelope_draft', []));
    }

    private function claimWakeup(): void
    {
        AtlasSelfConstructionAgentWakeupItem::query()->create([
            'wakeup_key' => 'WAKEUP-AP-001',
            'packet_id' => 'AP-001',
            'actor' => 'codex',
            'provider' => 'codex',
            'reason' => 'dispatch_receipt_write_authority_test',
            'priority' => 1,
            'status' => 'claimed',
            'claimed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function writeOptions(array $overrides = []): array
    {
        return array_merge([
            'packet' => 'AP-001',
            'decision' => 'approve_dispatch_once',
            'signed_by' => 'attacker',
            'receipt_hash' => str_repeat('a', 64),
            'dispatch_envelope_hash' => str_repeat('b', 64),
            'expires_at' => CarbonImmutable::now()->addMinutes(10)->toIso8601String(),
        ], $overrides);
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('atlas_self_construction_agent_dispatch_receipts');
        Schema::dropIfExists('atlas_self_construction_agent_wakeup_items');
        Schema::dropIfExists('atlas_self_construction_agent_work_products');
        Schema::dropIfExists('atlas_self_construction_agent_cost_events');
        Schema::dropIfExists('atlas_self_construction_agent_heartbeats');
        Schema::dropIfExists('atlas_self_construction_agent_runs');
        Schema::dropIfExists('atlas_ledger_events');
    }
}
