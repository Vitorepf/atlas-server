<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Receipt;

use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components\SchemaContractAssertions;

/**
 * Canonical contract tests for `atlas.dev_to_forge.escalation_packet.v1`.
 *
 * Asserts the audit-mandated invariants from
 * `docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md`
 * and the canonical schema in `atlas-dual-core-engineering-system.md` §270-301.
 */
final class EscalationPacketTest extends TestCase
{
    use SchemaContractAssertions;

    /* -------------------------------------------------------------------------
     * Happy path · valid packet
     * ---------------------------------------------------------------------- */

    public function test_valid_packet_passes_contract_surface(): void
    {
        $packet = $this->validPacket();

        $this->assertContractSurface($packet);
        $this->assertSame(EscalationPacket::SCHEMA_VERSION, $packet->schemaVersion());
        $this->assertSame('atlas_dev', $packet->toCanonicalArray()['source_core']);
        $this->assertSame('atlas_forge', $packet->toCanonicalArray()['target_core']);
    }

    public function test_packet_id_is_stable_in_hash_and_json(): void
    {
        $packet = $this->validPacket();
        $payload = $packet->toCanonicalArray();

        $this->assertSame($packet->packetId, $payload['packet_id']);
        $this->assertSame($packet->packetHash, $payload['packet_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['packet_hash']);
    }

    /* -------------------------------------------------------------------------
     * Required fields · audit invariants
     * ---------------------------------------------------------------------- */

    public function test_missing_original_user_intent_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/original_user_intent must not be empty/');
        $this->validPacket(originalUserIntent: '   ');
    }

    public function test_missing_promotion_reason_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/promotion_reason must not be empty/');
        $this->validPacket(promotionReason: '');
    }

    public function test_empty_promotion_triggers_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/promotion_triggers must contain at least one canonical trigger/');
        $this->validPacket(promotionTriggers: []);
    }

    public function test_empty_suggested_work_packets_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/suggested_work_packets must contain at least one work packet hint/');
        $this->validPacket(suggestedWorkPackets: []);
    }

    public function test_invalid_recommended_forge_mode_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/recommended_forge_mode must be one of/');
        $this->validPacket(recommendedForgeMode: 'totally_made_up_mode');
    }

    public function test_invalid_suggested_work_packet_shape_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/suggested_work_packets\[0\].title must be a non-empty string/');
        $this->validPacket(suggestedWorkPackets: [[
            'id' => 'wp_1',
            // missing title
        ]]);
    }

    public function test_evidence_refs_must_be_strings_or_arrays(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/evidence_refs\[plan\] must be a string or null/');
        $this->validPacket(evidenceRefs: [
            'plan' => 42, // not a string
            'failure_capsules' => [],
        ]);
    }

    /* -------------------------------------------------------------------------
     * Canonical evidence_refs shape
     * ---------------------------------------------------------------------- */

    public function test_evidence_refs_always_emit_canonical_six_slots(): void
    {
        $packet = $this->validPacket(evidenceRefs: []);
        $canonical = $packet->toCanonicalArray()['evidence_refs'];

        foreach (EscalationPacket::EVIDENCE_REF_SLOTS as $slot) {
            $this->assertArrayHasKey($slot, $canonical, "evidence_refs must always include canonical slot '{$slot}' even when empty.");
        }
        $this->assertNull($canonical['plan']);
        $this->assertSame([], $canonical['failure_capsules']);
    }

    public function test_evidence_refs_supplied_values_are_preserved(): void
    {
        $packet = $this->validPacket(evidenceRefs: [
            'plan' => 'storage/atlas-dev/run-1/plan.json',
            'verification_receipt' => 'storage/atlas-dev/run-1/verification_receipt.json',
            'failure_capsules' => ['storage/atlas-dev/run-1/capsule_1.json'],
        ]);
        $refs = $packet->toCanonicalArray()['evidence_refs'];

        $this->assertSame('storage/atlas-dev/run-1/plan.json', $refs['plan']);
        $this->assertSame('storage/atlas-dev/run-1/verification_receipt.json', $refs['verification_receipt']);
        $this->assertSame(['storage/atlas-dev/run-1/capsule_1.json'], $refs['failure_capsules']);
    }

    /* -------------------------------------------------------------------------
     * JSON stability + canonical alphabetical keys
     * ---------------------------------------------------------------------- */

    public function test_two_packets_with_identical_content_share_hash_and_json(): void
    {
        $a = $this->validPacket();
        $b = $this->validPacket(); // same packet_id + same createdAt → same hash

        $this->assertHashStable($a, $b);
    }

    public function test_changing_promotion_reason_changes_hash(): void
    {
        $a = $this->validPacket(promotionReason: 'scope explosion');
        $b = $this->validPacket(promotionReason: 'sdd required');

        $this->assertHashDiffers($a, $b);
    }

    public function test_canonical_array_keys_are_alphabetically_sorted_at_every_depth(): void
    {
        $packet = $this->validPacket();
        $this->assertCanonicalArrayKeysSorted($packet);
    }

    /* -------------------------------------------------------------------------
     * Roundtrip + Forge intake compatibility
     * ---------------------------------------------------------------------- */

    public function test_from_array_round_trips_the_canonical_payload(): void
    {
        $original = $this->validPacket();
        $hydrated = EscalationPacket::fromArray($original->toCanonicalArray());

        $this->assertSame($original->toCanonicalArray(), $hydrated->toCanonicalArray());
        $this->assertSame($original->hash(), $hydrated->hash());
    }

    public function test_from_array_rejects_wrong_source_core(): void
    {
        $payload = $this->validPacket()->toCanonicalArray();
        $payload['source_core'] = 'atlas_forge'; // wrong

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/source_core must be 'atlas_dev'/");
        EscalationPacket::fromArray($payload);
    }

    public function test_recommended_forge_mode_is_one_of_documented_modes(): void
    {
        // Forge intake compatibility: the documented modes in
        // atlas-dual-core-engineering-system.md §299 are exactly these.
        $this->assertSame(
            ['architecture_review', 'long_run', 'obra_intake', 'sdd_intake'],
            $this->sorted(EscalationPacket::ALLOWED_RECOMMENDED_FORGE_MODES),
        );
    }

    /* -------------------------------------------------------------------------
     * Helpers
     * ---------------------------------------------------------------------- */

    /**
     * Builds a canonical packet using deterministic values so hashes are
     * reproducible across calls. Optional overrides exercise invariants.
     *
     * @param  list<string>  $promotionTriggers
     * @param  list<array<string,mixed>>  $suggestedWorkPackets
     * @param  array<string,mixed>  $evidenceRefs
     */
    private function validPacket(
        string $originalUserIntent = 'Refactor the auth subsystem to support SSO',
        string $promotionReason = 'scope_too_large + sdd_required',
        array $promotionTriggers = ['scope_too_large', 'sdd_required'],
        array $suggestedWorkPackets = [[
            'id' => 'wp_sdd_intake',
            'title' => 'Forge SDD intake from Dev escalation',
            'capability' => 'programming.forge',
            'why' => 'Dev escalation reasons: scope_too_large, sdd_required',
        ]],
        string $recommendedForgeMode = EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
        array $evidenceRefs = [
            'plan' => 'storage/atlas-dev/run-1/plan.json',
            'verification_receipt' => null,
            'failure_capsules' => [],
        ],
    ): EscalationPacket {
        return EscalationPacket::issue(
            packetId: 'pkt-deterministic-1',
            originalUserIntent: $originalUserIntent,
            normalizedIntent: 'refactor auth subsystem for SSO',
            promotionReason: $promotionReason,
            promotionTriggers: $promotionTriggers,
            scopeAssessment: 'Dev escalation score 8/10; risk R4.',
            riskAssessment: 'risk_level=R4 declared by EscalationDecisionEngine; human approval required=true.',
            ambiguityAssessment: 'ambiguity_score=medium.',
            currentDevFindings: ['auth module spans 14 files', 'no SSO provider configured'],
            completedDevActions: ['mapped affected files', 'drafted partial spec'],
            incompleteDevActions: ['design SSO provider abstraction', 'migrate session storage'],
            recommendedForgeMode: $recommendedForgeMode,
            suggestedWorkPackets: $suggestedWorkPackets,
            definitionOfDone: ['SSO login E2E passes', 'all 14 auth tests green', 'security review certified'],
            requiredEvidence: ['spec_pack', 'patch_set', 'certification', 'evidence_pack'],
            evidenceRefs: $evidenceRefs,
            contextRefs: ['app/Auth/Login.php', 'docs/auth.md#sha256:abc'],
            contextPackHash: 'sha256:ws',
            constraints: ['no breaking changes to /auth/login API'],
            nonGoals: ['rewrite user model', 'change UI framework'],
            createdAt: '2026-05-18T10:00:00Z',
        );
    }

    /**
     * @param  array<int,string>  $list
     * @return list<string>
     */
    private function sorted(array $list): array
    {
        $copy = array_values($list);
        sort($copy, SORT_STRING);

        return $copy;
    }
}
