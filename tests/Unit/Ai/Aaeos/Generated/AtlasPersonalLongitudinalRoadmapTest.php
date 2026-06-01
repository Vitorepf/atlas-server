<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasPersonalLongitudinalRoadmapService;
use Tests\TestCase;

/**
 * Pins the documented Memory Classes table, persistence gate, provider-egress
 * redaction rule and Curator authority limits.
 *
 * @see docs/engineering-knowledge-base/evolution/personal-longitudinal-roadmap.md
 */
class AtlasPersonalLongitudinalRoadmapTest extends TestCase
{
    private function service(): AtlasPersonalLongitudinalRoadmapService
    {
        return new AtlasPersonalLongitudinalRoadmapService();
    }

    /** Memory Classes table: each class maps to its exact documented default handling. */
    public function test_memory_classes_match_the_doc_table(): void
    {
        $svc = $this->service();

        $this->assertSame('local_projection', $svc->memoryClass('work_pattern')['default_handling']);
        $this->assertSame('local_projection', $svc->memoryClass('cognitive_pattern')['default_handling']);
        $this->assertSame('explicit_opt_in', $svc->memoryClass('health_signal')['default_handling']);
        $this->assertSame('human_reviewed', $svc->memoryClass('personal_values')['default_handling']);
        $this->assertSame('do_not_persist_raw', $svc->memoryClass('sensitive_raw')['default_handling']);

        // Unknown class is not guessed.
        $unknown = $svc->memoryClass('made_up');
        $this->assertFalse($unknown['known']);
        $this->assertSame('unknown_memory_class', $unknown['reason']);
    }

    /** HARD RULE: sensitive raw data is never persisted raw; only a redacted/derived form may be stored. */
    public function test_sensitive_raw_is_never_persisted_raw(): void
    {
        $svc = $this->service();

        $raw = $svc->persistenceDecision('sensitive_raw', redacted: false);
        $this->assertFalse($raw['persist']);
        $this->assertSame('none', $raw['persist_as']);
        $this->assertContains('sensitive_raw_must_not_persist_raw', $raw['reasons']);

        $redacted = $svc->persistenceDecision('sensitive_raw', redacted: true);
        $this->assertTrue($redacted['persist']);
        $this->assertSame('redacted_derived', $redacted['persist_as']);
    }

    /** Health signal needs explicit opt-in; personal values need human review before durable persistence. */
    public function test_health_opt_in_and_values_human_review(): void
    {
        $svc = $this->service();

        $healthDenied = $svc->persistenceDecision('health_signal', explicitOptIn: false);
        $this->assertFalse($healthDenied['persist']);
        $this->assertContains('health_signal_requires_explicit_opt_in', $healthDenied['reasons']);

        $healthGranted = $svc->persistenceDecision('health_signal', explicitOptIn: true);
        $this->assertTrue($healthGranted['persist']);

        $valuesPending = $svc->persistenceDecision('personal_values', humanReviewed: false);
        $this->assertFalse($valuesPending['persist']);
        $this->assertSame('pending_human_review', $valuesPending['persist_as']);

        $valuesReviewed = $svc->persistenceDecision('personal_values', humanReviewed: true);
        $this->assertTrue($valuesReviewed['persist']);

        // Work pattern persists locally by default with no extra gate.
        $this->assertTrue($svc->persistenceDecision('work_pattern')['persist']);
    }

    /** Provider egress: personal memory needs redaction; a raw sensitive payload never egresses. */
    public function test_provider_egress_requires_redaction_and_blocks_raw(): void
    {
        $svc = $this->service();

        $unredacted = $svc->providerEgressDecision('work_pattern', redacted: false);
        $this->assertFalse($unredacted['allow_provider_egress']);
        $this->assertContains('personal_memory_requires_redaction_before_provider', $unredacted['reasons']);

        $redacted = $svc->providerEgressDecision('work_pattern', redacted: true);
        $this->assertTrue($redacted['allow_provider_egress']);

        // Raw sensitive payload is blocked even if redaction is claimed.
        $rawSensitive = $svc->providerEgressDecision('sensitive_raw', redacted: true, isRawPayload: true);
        $this->assertFalse($rawSensitive['allow_provider_egress']);
        $this->assertContains('raw_sensitive_payload_never_egresses_to_provider', $rawSensitive['reasons']);
    }

    /** Curator may NOT auto-change protected surfaces; auto_apply is forced to propose + human review. */
    public function test_curator_cannot_auto_change_protected_targets(): void
    {
        $svc = $this->service();

        foreach (['calendar', 'health_plan', 'identity_document', 'active_curriculum'] as $target) {
            $decision = $svc->curatorAuthority('schedule_recovery_adjustment', $target, 'auto_apply');
            $this->assertFalse($decision['auto_apply_allowed'], "auto_apply must be denied for {$target}");
            $this->assertSame('propose', $decision['effective_mode']);
            $this->assertTrue($decision['requires_human_review']);
            $this->assertContains('curator_may_not_auto_change_protected_surface', $decision['reasons']);
        }

        // A non-protected target with an in-scope kind may auto-apply.
        $allowed = $svc->curatorAuthority('knowledge_decay', 'memory_projection', 'auto_apply');
        $this->assertTrue($allowed['auto_apply_allowed']);
        $this->assertSame('auto_apply', $allowed['effective_mode']);

        // A proposal kind outside the documented five is rejected.
        $outOfScope = $svc->curatorAuthority('rewrite_identity', 'memory_projection', 'auto_apply');
        $this->assertFalse($outOfScope['kind_allowed']);
        $this->assertFalse($outOfScope['auto_apply_allowed']);
    }

    /** Roadmap snapshot proves the two table-wide invariants. */
    public function test_roadmap_snapshot_invariants(): void
    {
        $snapshot = $this->service()->roadmap();

        $this->assertSame(5, $snapshot['memory_class_count']);
        $this->assertTrue($snapshot['sensitive_raw_never_persists_raw']);
        $this->assertTrue($snapshot['all_protected_targets_non_auto']);
        $this->assertCount(5, $snapshot['curator_proposal_kinds']);
        $this->assertCount(4, $snapshot['curator_protected_targets']);
    }
}
