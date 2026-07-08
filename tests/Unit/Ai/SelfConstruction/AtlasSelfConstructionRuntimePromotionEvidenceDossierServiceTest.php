<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionReservationRepository;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionEvidenceDossierService;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionRuntimePromotionEvidenceDossierServiceTest extends TestCase
{
    private function service(): AtlasSelfConstructionRuntimePromotionEvidenceDossierService
    {
        return new AtlasSelfConstructionRuntimePromotionEvidenceDossierService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        );
    }

    private function currentQueueHealth(): array
    {
        return ['give_back_rate' => 0.1, 'claimable_depth' => 5, 'observed_at_seconds_ago' => 60];
    }

    private function currentWorkerOutcome(): array
    {
        return ['success_rate' => 0.95, 'sample_count' => 20, 'observed_at_seconds_ago' => 60];
    }

    private function currentProofReceipts(): array
    {
        return ['receipt_count' => 3, 'all_verified' => true, 'observed_at_seconds_ago' => 60];
    }

    private function currentLearningSync(): array
    {
        return ['lessons_admitted_count' => 2, 'last_sync_seconds_ago' => 60, 'observed_at_seconds_ago' => 60];
    }

    private function currentRollbackPlan(): array
    {
        return ['has_plan' => true, 'tested' => true, 'observed_at_seconds_ago' => 60];
    }

    private function currentRuntimeSoak(): array
    {
        return ['soak_hours' => 48, 'incident_count' => 0, 'observed_at_seconds_ago' => 60];
    }

    private function allCurrentFacts(): array
    {
        return [
            'queue_health' => $this->currentQueueHealth(),
            'worker_outcome' => $this->currentWorkerOutcome(),
            'proof_receipts' => $this->currentProofReceipts(),
            'learning_sync' => $this->currentLearningSync(),
            'rollback_plan' => $this->currentRollbackPlan(),
            'runtime_soak' => $this->currentRuntimeSoak(),
        ];
    }

    // ── AC1: the six required sections are present ────────────────────────────

    public function test_all_six_sections_present_in_output(): void
    {
        $result = $this->service()->evidenceSections($this->allCurrentFacts());

        foreach (['queue_health', 'worker_outcome', 'proof_receipts', 'learning_sync', 'rollback_plan', 'runtime_soak'] as $section) {
            $this->assertArrayHasKey($section, $result['sections'], "missing section: {$section}");
        }
    }

    public function test_complete_verdict_when_all_sections_current(): void
    {
        $result = $this->service()->evidenceSections($this->allCurrentFacts());

        $this->assertSame(AtlasSelfConstructionRuntimePromotionEvidenceDossierService::VERDICT_COMPLETE, $result['verdict']);
        $this->assertTrue($result['complete']);
        $this->assertSame([], $result['blocking_sections']);
    }

    // ── AC2/AC3: missing evidence blocks completion ────────────────────────────

    public function test_missing_section_is_marked_missing_with_repair_hint_and_blocks_verdict(): void
    {
        $facts = $this->allCurrentFacts();
        unset($facts['rollback_plan']);

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame(AtlasSelfConstructionRuntimePromotionEvidenceDossierService::STATE_MISSING, $result['sections']['rollback_plan']['evidence_state']);
        $this->assertNotEmpty($result['sections']['rollback_plan']['repair_hint']);
        $this->assertSame(AtlasSelfConstructionRuntimePromotionEvidenceDossierService::VERDICT_INCOMPLETE, $result['verdict']);
        $this->assertContains('rollback_plan', $result['blocking_sections']);
        $this->assertFalse($result['complete']);
    }

    // ── AC2: stale evidence is flagged and blocks completion ──────────────────

    public function test_stale_section_is_marked_stale_with_repair_hint(): void
    {
        $facts = $this->allCurrentFacts();
        $facts['queue_health']['observed_at_seconds_ago'] = 90000; // > 86400 threshold

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame(AtlasSelfConstructionRuntimePromotionEvidenceDossierService::STATE_STALE, $result['sections']['queue_health']['evidence_state']);
        $this->assertSame('refresh_queue_health_evidence', $result['sections']['queue_health']['repair_hint']);
        $this->assertContains('queue_health', $result['blocking_sections']);
    }

    // ── AC2: weak evidence per section is flagged with an actionable hint ─────

    public function test_queue_health_weak_when_give_back_rate_too_high(): void
    {
        $facts = $this->allCurrentFacts();
        $facts['queue_health']['give_back_rate'] = 0.5;

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame(AtlasSelfConstructionRuntimePromotionEvidenceDossierService::STATE_WEAK, $result['sections']['queue_health']['evidence_state']);
        $this->assertSame('give_back_rate_too_high_stabilize_queue_before_promotion', $result['sections']['queue_health']['repair_hint']);
    }

    public function test_worker_outcome_weak_when_sample_count_too_low(): void
    {
        $facts = $this->allCurrentFacts();
        $facts['worker_outcome']['sample_count'] = 1;

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame(AtlasSelfConstructionRuntimePromotionEvidenceDossierService::STATE_WEAK, $result['sections']['worker_outcome']['evidence_state']);
        $this->assertSame('insufficient_worker_outcome_sample_count', $result['sections']['worker_outcome']['repair_hint']);
    }

    public function test_worker_outcome_weak_when_success_rate_below_floor(): void
    {
        $facts = $this->allCurrentFacts();
        $facts['worker_outcome']['success_rate'] = 0.5;

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame('worker_outcome_success_rate_below_promotion_floor', $result['sections']['worker_outcome']['repair_hint']);
    }

    public function test_proof_receipts_weak_when_zero_receipts(): void
    {
        $facts = $this->allCurrentFacts();
        $facts['proof_receipts']['receipt_count'] = 0;

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame('no_proof_receipts_recorded', $result['sections']['proof_receipts']['repair_hint']);
    }

    public function test_proof_receipts_weak_when_unverified(): void
    {
        $facts = $this->allCurrentFacts();
        $facts['proof_receipts']['all_verified'] = false;

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame('unverified_proof_receipts_present', $result['sections']['proof_receipts']['repair_hint']);
    }

    public function test_learning_sync_weak_when_no_lessons_admitted(): void
    {
        $facts = $this->allCurrentFacts();
        $facts['learning_sync']['lessons_admitted_count'] = 0;

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame('no_lessons_admitted_since_last_sync', $result['sections']['learning_sync']['repair_hint']);
    }

    public function test_rollback_plan_weak_when_no_plan(): void
    {
        $facts = $this->allCurrentFacts();
        $facts['rollback_plan']['has_plan'] = false;

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame('no_rollback_plan_present', $result['sections']['rollback_plan']['repair_hint']);
    }

    public function test_rollback_plan_weak_when_untested(): void
    {
        $facts = $this->allCurrentFacts();
        $facts['rollback_plan']['tested'] = false;

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame('rollback_plan_present_but_untested', $result['sections']['rollback_plan']['repair_hint']);
    }

    public function test_runtime_soak_weak_when_duration_below_floor(): void
    {
        $facts = $this->allCurrentFacts();
        $facts['runtime_soak']['soak_hours'] = 2;

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame('runtime_soak_duration_below_24h_floor', $result['sections']['runtime_soak']['repair_hint']);
    }

    public function test_runtime_soak_weak_when_incidents_present(): void
    {
        $facts = $this->allCurrentFacts();
        $facts['runtime_soak']['incident_count'] = 1;

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame('runtime_soak_reported_incidents_unresolved', $result['sections']['runtime_soak']['repair_hint']);
    }

    // ── AC3: any single critical section failing refuses the complete verdict ──

    public function test_single_weak_section_refuses_complete_verdict_even_when_others_current(): void
    {
        $facts = $this->allCurrentFacts();
        $facts['proof_receipts']['all_verified'] = false;

        $result = $this->service()->evidenceSections($facts);

        $this->assertSame(AtlasSelfConstructionRuntimePromotionEvidenceDossierService::VERDICT_INCOMPLETE, $result['verdict']);
        $this->assertSame(['proof_receipts'], $result['blocking_sections']);
    }

    public function test_no_facts_at_all_marks_every_section_missing_and_blocks(): void
    {
        $result = $this->service()->evidenceSections([]);

        foreach (['queue_health', 'worker_outcome', 'proof_receipts', 'learning_sync', 'rollback_plan', 'runtime_soak'] as $section) {
            $this->assertSame(AtlasSelfConstructionRuntimePromotionEvidenceDossierService::STATE_MISSING, $result['sections'][$section]['evidence_state']);
        }
        $this->assertSame(AtlasSelfConstructionRuntimePromotionEvidenceDossierService::VERDICT_INCOMPLETE, $result['verdict']);
        $this->assertCount(6, $result['blocking_sections']);
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_evidence_sections_hash_is_stable_for_identical_facts(): void
    {
        $svc = $this->service();
        $facts = $this->allCurrentFacts();

        $a = $svc->evidenceSections($facts);
        $b = $svc->evidenceSections($facts);

        $this->assertSame($a['evidence_sections_hash'], $b['evidence_sections_hash']);
    }

    public function test_evidence_sections_hash_changes_when_facts_change(): void
    {
        $svc = $this->service();
        $a = $svc->evidenceSections($this->allCurrentFacts());

        $facts = $this->allCurrentFacts();
        $facts['queue_health']['give_back_rate'] = 0.9;
        $b = $svc->evidenceSections($facts);

        $this->assertNotSame($a['evidence_sections_hash'], $b['evidence_sections_hash']);
    }

    public function test_schema_version_present(): void
    {
        $result = $this->service()->evidenceSections($this->allCurrentFacts());

        $this->assertSame(AtlasSelfConstructionRuntimePromotionEvidenceDossierService::EVIDENCE_SECTIONS_SCHEMA, $result['schema_version']);
    }
}
