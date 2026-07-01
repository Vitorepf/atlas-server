<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimePromotionDraftHashFinalizerServiceTest extends TestCase
{
    private function svc(): AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService
    {
        return new AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService;
    }

    private function draft(array $overrides = []): array
    {
        return array_merge([
            'evidence_refs' => ['mutop:receipt-1', 'test:GreenSuite'],
            'verdict' => 'promote',
            'blockers' => [],
            'rollback_plan' => 'revert commit and re-run the certification gate',
            'runtime_target' => 'adapter_execution_runtime',
        ], $overrides);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->svc()->computeDraftHash($this->draft());

        $this->assertSame(AtlasSelfConstructionRuntimePromotionDraftHashFinalizerService::DRAFT_HASH_SCHEMA_VERSION, $r['schema_version']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r['draft_hash']);
        $this->assertIsArray($r['excluded_field_paths']);
    }

    // ── AC2: stable despite key order and volatile timestamp differences ───────

    public function test_hash_stable_across_key_order(): void
    {
        $a = $this->svc()->computeDraftHash($this->draft());
        $reordered = array_reverse($this->draft(), true);
        $b = $this->svc()->computeDraftHash($reordered);

        $this->assertSame($a['draft_hash'], $b['draft_hash']);
    }

    public function test_hash_stable_across_volatile_timestamp_differences(): void
    {
        $a = $this->svc()->computeDraftHash($this->draft(['generated_at' => '2026-01-01T00:00:00Z']));
        $b = $this->svc()->computeDraftHash($this->draft(['generated_at' => '2026-06-30T23:59:59Z']));

        $this->assertSame($a['draft_hash'], $b['draft_hash']);
    }

    public function test_hash_stable_for_nested_key_order_and_timestamps(): void
    {
        $a = $this->svc()->computeDraftHash($this->draft([
            'metadata' => ['b' => 2, 'a' => 1, 'computed_at' => 'x'],
        ]));
        $b = $this->svc()->computeDraftHash($this->draft([
            'metadata' => ['a' => 1, 'computed_at' => 'y', 'b' => 2],
        ]));

        $this->assertSame($a['draft_hash'], $b['draft_hash']);
    }

    // ── AC3: hash changes when evidence refs, verdict, blockers, rollback plan or runtime target change ──

    public function test_hash_changes_when_evidence_refs_change(): void
    {
        $a = $this->svc()->computeDraftHash($this->draft());
        $b = $this->svc()->computeDraftHash($this->draft(['evidence_refs' => ['mutop:receipt-2']]));

        $this->assertNotSame($a['draft_hash'], $b['draft_hash']);
    }

    public function test_hash_changes_when_verdict_changes(): void
    {
        $a = $this->svc()->computeDraftHash($this->draft());
        $b = $this->svc()->computeDraftHash($this->draft(['verdict' => 'reject']));

        $this->assertNotSame($a['draft_hash'], $b['draft_hash']);
    }

    public function test_hash_changes_when_blockers_change(): void
    {
        $a = $this->svc()->computeDraftHash($this->draft());
        $b = $this->svc()->computeDraftHash($this->draft(['blockers' => ['missing_evidence']]));

        $this->assertNotSame($a['draft_hash'], $b['draft_hash']);
    }

    public function test_hash_changes_when_rollback_plan_changes(): void
    {
        $a = $this->svc()->computeDraftHash($this->draft());
        $b = $this->svc()->computeDraftHash($this->draft(['rollback_plan' => 'a completely different rollback procedure']));

        $this->assertNotSame($a['draft_hash'], $b['draft_hash']);
    }

    public function test_hash_changes_when_runtime_target_changes(): void
    {
        $a = $this->svc()->computeDraftHash($this->draft());
        $b = $this->svc()->computeDraftHash($this->draft(['runtime_target' => 'automatic_cost_import_runtime']));

        $this->assertNotSame($a['draft_hash'], $b['draft_hash']);
    }

    // ── AC4: excludes provider-sensitive fields and reports excluded field paths ──

    public function test_provider_sensitive_fields_excluded_from_hash_and_reported(): void
    {
        $withSecret = $this->svc()->computeDraftHash($this->draft([
            'api_key' => 'sk-should-never-affect-the-hash',
            'session_id' => 'sess-123',
        ]));
        $withoutSecret = $this->svc()->computeDraftHash($this->draft());

        $this->assertSame($withoutSecret['draft_hash'], $withSecret['draft_hash']);
        $this->assertContains('api_key', $withSecret['excluded_field_paths']);
        $this->assertContains('session_id', $withSecret['excluded_field_paths']);
    }

    public function test_nested_provider_sensitive_field_reported_with_dotted_path(): void
    {
        $r = $this->svc()->computeDraftHash($this->draft([
            'provider_metadata' => ['provider_transcript' => 'raw transcript text', 'model' => 'x'],
        ]));

        $this->assertContains('provider_metadata.provider_transcript', $r['excluded_field_paths']);
    }

    public function test_excluded_field_paths_empty_when_nothing_to_exclude(): void
    {
        $r = $this->svc()->computeDraftHash($this->draft());

        $this->assertSame([], $r['excluded_field_paths']);
    }

    public function test_changing_only_an_excluded_field_never_changes_the_hash(): void
    {
        $a = $this->svc()->computeDraftHash($this->draft(['request_id' => 'req-aaa']));
        $b = $this->svc()->computeDraftHash($this->draft(['request_id' => 'req-bbb']));

        $this->assertSame($a['draft_hash'], $b['draft_hash']);
    }
}
