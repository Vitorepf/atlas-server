<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWorkerFeedbackInbox;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainWorkerFeedbackInboxTest extends TestCase
{
    private AtlasExternalBrainWorkerFeedbackInbox $inbox;

    protected function setUp(): void
    {
        $this->inbox = new AtlasExternalBrainWorkerFeedbackInbox;
    }

    private function norm(array $overrides = []): array
    {
        return $this->inbox->normalize(array_merge([
            'task_id'      => 'task-1',
            'outcome_type' => 'success',
            'note'         => 'All 16 tests green, feature verified.',
            'evidence'     => '/opt/homebrew/bin/php vendor/bin/phpunit tests/Feature/SomeTest.php',
            'task_family'  => 'infra',
            'worker_id'    => 'w1',
            'model_tier'   => 'fast',
        ], $overrides));
    }

    // ── AC2: verified success → compounding, no review ───────────────────────

    public function test_runnable_evidence_success_routes_to_compounding(): void
    {
        $r = $this->norm();

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_COMPOUNDING, $r['routing_signal']);
        $this->assertFalse($r['needs_review']);
    }

    public function test_verified_success_has_high_confidence(): void
    {
        $r = $this->norm();

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::CONFIDENCE_HIGH, $r['confidence']);
    }

    public function test_verified_success_evidence_status_is_verified(): void
    {
        $r = $this->norm();

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::EVIDENCE_VERIFIED, $r['evidence_status']);
    }

    public function test_verified_success_has_no_root_cause_hint(): void
    {
        $r = $this->norm();

        $this->assertNull($r['root_cause_hint']);
    }

    public function test_success_without_runnable_evidence_routes_to_review(): void
    {
        $r = $this->norm(['evidence' => '']);

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_REVIEW_QUEUE, $r['routing_signal']);
        $this->assertTrue($r['needs_review']);
    }

    public function test_success_without_evidence_cannot_feed_green_learning(): void
    {
        $r = $this->norm(['evidence' => '']);

        // Cannot be treated as verified → not routed to compounding
        $this->assertNotSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_COMPOUNDING, $r['routing_signal']);
    }

    // ── AC3: give_back and blocked → requires_action + root_cause_hint ────────

    public function test_give_back_requires_action(): void
    {
        $r = $this->norm([
            'outcome_type' => 'give_back',
            'note'         => 'Scope too wide, cannot write to that file.',
        ]);

        $this->assertTrue($r['requires_action']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_GIVE_BACK_REPAIR, $r['routing_signal']);
    }

    public function test_give_back_scope_note_produces_scope_hint(): void
    {
        $r = $this->norm([
            'outcome_type' => 'give_back',
            'note'         => 'Scope too wide for allowed_files.',
        ]);

        $this->assertSame('scope_too_wide', $r['root_cause_hint']);
    }

    public function test_give_back_forbidden_note_produces_forbidden_hint(): void
    {
        $r = $this->norm([
            'outcome_type' => 'give_back',
            'note'         => 'File is forbidden by the workspace guard.',
        ]);

        $this->assertSame('forbidden_files', $r['root_cause_hint']);
    }

    public function test_give_back_dependency_note_produces_dependency_hint(): void
    {
        $r = $this->norm([
            'outcome_type' => 'give_back',
            'note'         => 'Missing dependency in the spec.',
        ]);

        $this->assertSame('dependency_missing', $r['root_cause_hint']);
    }

    public function test_blocked_requires_action(): void
    {
        $r = $this->norm([
            'outcome_type' => 'blocked',
            'note'         => 'CI environment unreachable at test time.',
        ]);

        $this->assertTrue($r['requires_action']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_BLOCKER_RESOLUTION, $r['routing_signal']);
    }

    public function test_blocked_environment_note_produces_environment_hint(): void
    {
        $r = $this->norm([
            'outcome_type' => 'blocked',
            'note'         => 'CI environment is down.',
        ]);

        $this->assertSame('environment_error', $r['root_cause_hint']);
    }

    // ── AC4: unknown/terse → needs_review, cannot feed green learning ─────────

    public function test_ambiguous_outcome_needs_review(): void
    {
        $r = $this->norm([
            'outcome_type' => 'ambiguous',
            'note'         => 'Something happened here.',
        ]);

        $this->assertTrue($r['needs_review']);
        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_TRIAGE, $r['routing_signal']);
        $this->assertNotSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_COMPOUNDING, $r['routing_signal']);
    }

    public function test_unknown_outcome_type_needs_review(): void
    {
        $r = $this->norm(['outcome_type' => 'mystery']);

        $this->assertTrue($r['needs_review']);
        $this->assertNotSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_COMPOUNDING, $r['routing_signal']);
    }

    public function test_terse_note_forces_needs_review_on_success(): void
    {
        // Note under 10 chars
        $r = $this->norm(['note' => 'ok']);

        $this->assertTrue($r['needs_review']);
        $this->assertNotSame(AtlasExternalBrainWorkerFeedbackInbox::ROUTING_COMPOUNDING, $r['routing_signal']);
    }

    public function test_ambiguous_confidence_is_low(): void
    {
        $r = $this->norm(['outcome_type' => 'ambiguous']);

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::CONFIDENCE_LOW, $r['confidence']);
    }

    // ── deterministic ────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $note = ['outcome_type' => 'give_back', 'note' => 'Scope is too wide for this task.'];

        $this->assertSame(json_encode($this->norm($note)), json_encode($this->norm($note)));
    }

    public function test_schema_is_set(): void
    {
        $r = $this->norm();

        $this->assertSame(AtlasExternalBrainWorkerFeedbackInbox::SCHEMA, $r['schema']);
    }
}
