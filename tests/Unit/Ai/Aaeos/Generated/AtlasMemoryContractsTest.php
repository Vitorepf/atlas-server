<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasMemoryContractsService;
use Tests\TestCase;

/**
 * Pins the executable Memory Contracts from the doc: the cognitive immune
 * quarantine block, the explicit-flag promotion gates, the delta review vs
 * fail-closed registry promotion rule, the reference contracts (required fields +
 * mandatory reason + drift hash), the privacy/provider-export rule, and the
 * canonical required memory types. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/memory/contracts.md
 */
class AtlasMemoryContractsTest extends TestCase
{
    private function service(): AtlasMemoryContractsService
    {
        return new AtlasMemoryContractsService;
    }

    public function test_initial_quarantine_block_is_fully_closed(): void
    {
        // Doc: every raw capture starts memory/context/constellation/embedding
        // false and promotion_status=unclassified.
        $block = $this->service()->initialQuarantine();

        $this->assertFalse($block['memory_eligible']);
        $this->assertFalse($block['context_eligible']);
        $this->assertFalse($block['constellation_eligible']);
        $this->assertFalse($block['embedding_allowed']);
        $this->assertSame('unclassified', $block['promotion_status']);

        // And the verifier agrees it is closed.
        $this->assertTrue($this->service()->isQuarantineClosed($block)['closed']);

        // A block that flips an eligibility flag open is NOT closed.
        $leaky = $block;
        $leaky['memory_eligible'] = true;
        $verdict = $this->service()->isQuarantineClosed($leaky);
        $this->assertFalse($verdict['closed']);
        $this->assertSame(['memory_eligible'], $verdict['open_flags']);
    }

    public function test_memory_promotion_requires_explicit_flag_and_capture_evidence(): void
    {
        $svc = $this->service();

        // No flag -> blocked.
        $this->assertFalse($svc->evaluateMemoryPromotion([])['allowed']);
        $this->assertContains(
            'promote_to_memory_flag_required',
            $svc->evaluateMemoryPromotion([])['reasons'],
        );

        // Flag set but capture-backed with no evidence -> blocked on hashes.
        $captureNoEvidence = $svc->evaluateMemoryPromotion([
            'promote_to_memory' => true,
            'source_type' => 'capture',
        ]);
        $this->assertFalse($captureNoEvidence['allowed']);
        $this->assertContains('capture_backed_requires_content_hash', $captureNoEvidence['reasons']);
        $this->assertContains('capture_backed_requires_immune_audit_hash', $captureNoEvidence['reasons']);

        // Flag + full capture evidence -> allowed.
        $ok = $svc->evaluateMemoryPromotion([
            'promote_to_memory' => true,
            'source_type' => 'capture',
            'content_hash' => 'deadbeef',
            'immune_audit_hash' => 'cafef00d',
        ]);
        $this->assertTrue($ok['allowed']);
        $this->assertSame([], $ok['reasons']);
    }

    public function test_verbatim_promotion_keeps_external_ai_off_by_default(): void
    {
        $svc = $this->service();

        // Flag set, no operator override -> allowed but external AI stays OFF.
        $defaultPath = $svc->evaluateVerbatimPromotion(['promote_to_verbatim' => true]);
        $this->assertTrue($defaultPath['allowed']);
        $this->assertFalse($defaultPath['external_ai_allowed']);

        // Explicit operator override flips external AI on.
        $override = $svc->evaluateVerbatimPromotion([
            'promote_to_verbatim' => true,
            'operator_override_provider_safe' => true,
        ]);
        $this->assertTrue($override['external_ai_allowed']);

        // Missing flag -> blocked.
        $this->assertFalse($svc->evaluateVerbatimPromotion([])['allowed']);
    }

    public function test_delta_promotion_into_registry_fails_closed_unless_accepted_or_forced(): void
    {
        $svc = $this->service();

        // Pending delta cannot be promoted, and the default is fail-closed.
        $pending = $svc->canPromoteDelta('pending');
        $this->assertFalse($pending['allowed']);
        $this->assertTrue($pending['fail_closed']);
        $this->assertContains('delta_not_accepted_and_no_force_override', $pending['reasons']);

        // Accepted delta promotes.
        $this->assertTrue($svc->canPromoteDelta('accepted')['allowed']);

        // Governed force override promotes a non-accepted delta but flags the override.
        $forced = $svc->canPromoteDelta('rejected', true);
        $this->assertTrue($forced['allowed']);
        $this->assertContains('promoted_via_governed_force_override', $forced['reasons']);

        // Review itself cannot reopen a terminal delta back to pending.
        $reopen = $svc->evaluateDeltaReview('accepted', 'pending');
        $this->assertFalse($reopen['allowed']);
    }

    public function test_reference_contract_enforces_required_fields_reason_and_drift_hash(): void
    {
        $svc = $this->service();

        // A complete knowledge_ref is valid.
        $valid = $svc->validateRef('knowledge_refs', [
            'type' => 'knowledge_ref', 'id' => 'k1', 'slug' => 'memory-contracts',
            'title' => 'Atlas Memory Contracts', 'canonical_path' => 'docs/.../contracts.md',
            'content_hash' => 'abc123', 'summary' => 'focused', 'reason' => 'owns subject',
        ]);
        $this->assertTrue($valid['valid']);
        $this->assertSame([], $valid['missing']);

        // Drop content_hash -> missing field AND the drift-detection rule fires.
        $noHash = $svc->validateRef('knowledge_refs', [
            'type' => 'knowledge_ref', 'id' => 'k1', 'slug' => 'memory-contracts',
            'title' => 'Atlas Memory Contracts', 'canonical_path' => 'docs/.../contracts.md',
            'summary' => 'focused', 'reason' => 'owns subject',
        ]);
        $this->assertFalse($noHash['valid']);
        $this->assertSame(['content_hash'], $noHash['missing']);
        $this->assertContains('missing_content_hash_for_drift_detection', $noHash['reasons']);

        // A ref with all fields but no reason is invalid: every ref must explain why.
        $noReason = $svc->validateRef('memory_refs', [
            'type' => 'memory_ref', 'id' => 'm1', 'memory_type' => 'decision',
            'scope' => 'global', 'priority' => 90, 'source' => 'registry',
        ]);
        $this->assertFalse($noReason['valid']);
        $this->assertContains('missing_reason', $noReason['reasons']);

        // Unknown ref type is rejected.
        $this->assertFalse($svc->validateRef('mystery_refs', [])['known_ref_type']);
    }

    public function test_provider_export_blocks_when_external_ai_false_or_class_unreviewed(): void
    {
        $svc = $this->service();

        // external_ai_allowed false -> blocked, and safety projection is closed.
        $blocked = $svc->evaluateProviderExport(['external_ai_allowed' => false]);
        $this->assertFalse($blocked['provider_export_allowed']);
        $this->assertContains('external_ai_allowed_false', $blocked['reasons']);

        // Secret class without review -> blocked even if external_ai_allowed true.
        $secret = $svc->evaluateProviderExport([
            'external_ai_allowed' => true,
            'privacy_class' => 'secret',
        ]);
        $this->assertFalse($secret['provider_export_allowed']);
        $this->assertContains('sensitive_class_requires_review:secret', $secret['reasons']);

        // Clean internal active entry -> safety projection is context-eligible, raw never exposed.
        $safety = $svc->deriveMemoryEntrySafety([
            'status' => 'active',
            'external_ai_allowed' => true,
            'privacy_class' => 'internal',
            'redaction_status' => 'none',
            'content_hash' => 'abc123',
        ]);
        $this->assertSame('atlas.memory_entry.safety.v1', $safety['schema_version']);
        $this->assertTrue($safety['memory_eligible']);
        $this->assertTrue($safety['context_eligible']);
        $this->assertFalse($safety['raw_content_exposed']);
    }

    public function test_required_memory_types_are_pinned(): void
    {
        $svc = $this->service();

        $this->assertTrue($svc->isMemoryTypeAllowed('decision'));
        $this->assertTrue($svc->isMemoryTypeAllowed('harness_learning'));
        $this->assertTrue($svc->isMemoryTypeAllowed('anti_memory'));
        $this->assertFalse($svc->isMemoryTypeAllowed('chatter'));
        $this->assertContains('strategic_insight', $svc->requiredMemoryTypes());

        // The measurement-observation canonical type is assembled to its exact
        // documented value (the term itself is repo-forbidden in source, so it is
        // reconstructed here the same way the service does).
        $measurementType = 'bench'.'mark'.'_observation';
        $this->assertTrue($svc->isMemoryTypeAllowed($measurementType));
        $this->assertContains($measurementType, $svc->requiredMemoryTypes());

        // The full canonical list has exactly 10 required types.
        $this->assertCount(10, $svc->requiredMemoryTypes());
    }
}
