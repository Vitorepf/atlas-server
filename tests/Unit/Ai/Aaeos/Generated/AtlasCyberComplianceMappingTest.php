<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCyberComplianceMappingService;
use Tests\TestCase;

/**
 * Pins the documented Cyber Compliance Mapping rules: the signal -> profile table,
 * cumulative profiles, and the cross-jurisdiction resolution (most restrictive
 * residency, shortest notification SLA, minimum exfil cap, cumulative refusals +
 * must_redact), plus the anti-pattern guard for "no signal asserted".
 *
 * @see docs/engineering-knowledge-base/cyber-security/compliance-mapping.md
 */
class AtlasCyberComplianceMappingTest extends TestCase
{
    private function service(): AtlasCyberComplianceMappingService
    {
        return new AtlasCyberComplianceMappingService();
    }

    /**
     * Signal table: a PCI-DSS-scope signal activates exactly the PCI-DSS profile,
     * forces card_number/cvv redaction, and makes the settlement-core refusal
     * (cyber-ref-052) absolute. A single signal is not "cumulative".
     */
    public function test_pci_signal_maps_to_pci_profile_with_redaction_and_hard_refusal(): void
    {
        $r = $this->service()->resolve(['pci_dss_scope' => true]);

        $this->assertSame([AtlasCyberComplianceMappingService::PROFILE_PCI_DSS], $r['profiles']);
        $this->assertFalse($r['cumulative']);
        $this->assertContains('card_number', $r['must_redact']);
        $this->assertContains('cvv', $r['must_redact']);
        $this->assertContains('cyber-ref-052', $r['hard_refusals']);
        $this->assertSame(AtlasCyberComplianceMappingService::RECEIPT_KIND, $r['receipt_kind']);
    }

    /**
     * LGPD imposes the documented minimal-collection exfil cap of 512 bytes and the
     * Brazilian-PII redaction set (CPF, name, address, phone, personal email).
     */
    public function test_lgpd_sets_low_exfil_cap_and_brazilian_pii_redaction(): void
    {
        $r = $this->service()->resolve(['br_citizens' => true]);

        $this->assertSame([AtlasCyberComplianceMappingService::PROFILE_LGPD], $r['profiles']);
        $this->assertSame(512, $r['exfiltration_proof_max_bytes']);
        $this->assertContains('cpf', $r['must_redact']);
        $this->assertContains('personal_email', $r['must_redact']);
    }

    /**
     * Cross-jurisdiction "mais restritivo vence" for data residency: EU + BR data
     * must store in the EU region (GDPR EU residency beats LGPD's permissive default),
     * and both profiles are present cumulatively. Exfil cap inherits LGPD's 512 floor.
     */
    public function test_eu_plus_br_resolves_residency_to_eu_and_is_cumulative(): void
    {
        $r = $this->service()->resolve(['operates_eu' => true, 'br_citizens' => true]);

        $this->assertContains(AtlasCyberComplianceMappingService::PROFILE_GDPR, $r['profiles']);
        $this->assertContains(AtlasCyberComplianceMappingService::PROFILE_LGPD, $r['profiles']);
        $this->assertTrue($r['cumulative']);
        $this->assertSame(AtlasCyberComplianceMappingService::RESIDENCY_EU, $r['data_residency']);
        // LGPD's minimal-collection cap still applies cumulatively.
        $this->assertSame(512, $r['exfiltration_proof_max_bytes']);
    }

    /**
     * Notification SLA "prazo mais curto": GDPR (72h) vs HIPAA (60 days = 1440h) ->
     * the engagement adopts the SHORTEST window, 72h. Refusals are cumulative:
     * HIPAA's life-safety refusal (cyber-ref-050) is carried into the envelope.
     */
    public function test_gdpr_plus_hipaa_resolves_shortest_sla_and_unions_refusals(): void
    {
        $r = $this->service()->resolve(['operates_eu' => true, 'phi' => true]);

        $this->assertSame(72, $r['notification_sla_hours']);   // 72h beats 1440h
        $this->assertContains('cyber-ref-050', $r['hard_refusals']); // HIPAA life-safety
        $this->assertContains(AtlasCyberComplianceMappingService::PROFILE_GDPR, $r['profiles']);
        $this->assertContains(AtlasCyberComplianceMappingService::PROFILE_HIPAA, $r['profiles']);
    }

    /**
     * DFARS / NIST 800-171 is the hardest residency floor: US-only wins even over EU.
     * Its availability refusals (cyber-ref-050 + cyber-ref-051) are both carried, and
     * deduplicated against any overlap with other profiles.
     */
    public function test_dfars_forces_us_only_residency_over_eu_and_carries_both_refusals(): void
    {
        $r = $this->service()->resolve(['dod_contractor' => true, 'operates_eu' => true]);

        $this->assertSame(AtlasCyberComplianceMappingService::RESIDENCY_US_ONLY, $r['data_residency']);
        $this->assertContains('cyber-ref-050', $r['hard_refusals']);
        $this->assertContains('cyber-ref-051', $r['hard_refusals']);
        // dedupe: cyber-ref-050 appears once even though >1 profile asserts it.
        $this->assertSame(
            array_values(array_unique($r['hard_refusals'])),
            $r['hard_refusals'],
        );
    }

    /**
     * Anti-pattern guard: NO signal asserted does NOT silently mean "no compliance".
     * The decider returns an empty profile set but flags that the real jurisdiction
     * must still be verified (the doc's "verifica jurisdicao real" anti-pattern).
     */
    public function test_no_signal_flags_jurisdiction_verification_required(): void
    {
        $r = $this->service()->resolve([]);

        $this->assertSame([], $r['profiles']);
        $this->assertTrue($r['jurisdiction_verification_required']);
        $this->assertContains('no_signal_asserted_verify_real_jurisdiction', $r['notes']);
        // permissive defaults, but never a fabricated profile.
        $this->assertSame(AtlasCyberComplianceMappingService::RESIDENCY_ANY, $r['data_residency']);
        $this->assertSame(AtlasCyberComplianceMappingService::NO_SLA, $r['notification_sla_hours']);
    }

    /**
     * The mapping exposes its immutable canonical profile id set and a stable
     * predicate. profilesFor is deterministic and in canonical declaration order.
     */
    public function test_profile_ids_are_canonical_and_predicate_is_stable(): void
    {
        $ids = $this->service()->profileIds();

        $this->assertContains(AtlasCyberComplianceMappingService::PROFILE_PCI_DSS, $ids);
        $this->assertContains(AtlasCyberComplianceMappingService::PROFILE_DFARS, $ids);
        $this->assertContains(AtlasCyberComplianceMappingService::PROFILE_ISO_27001, $ids);
        $this->assertCount(7, $ids);

        $this->assertTrue($this->service()->appliesTo(
            AtlasCyberComplianceMappingService::PROFILE_HIPAA,
            ['healthcare' => true],
        ));
        $this->assertFalse($this->service()->appliesTo(
            AtlasCyberComplianceMappingService::PROFILE_HIPAA,
            ['operates_br' => true],
        ));
    }
}
