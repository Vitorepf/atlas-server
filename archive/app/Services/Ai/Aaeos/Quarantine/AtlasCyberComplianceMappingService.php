<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Cyber Compliance Mapping — pure, deterministic compliance-profile decider.
 *
 * The cyber-bb-runner skill consults this mapping in its SCOPING phase to derive the
 * compliance_profile of the engagement envelope from the real jurisdiction / posture
 * signals of the Bug-Bounty program or pentest target. The resulting constraints are
 * NOT enforced by a parallel cyber code path — they are surfaced so the kernel Policy
 * Engine can inject them. This service only classifies signals and resolves the
 * cumulative cross-jurisdiction envelope; it never executes anything.
 *
 * Contract (from the doc tables "Decisao 'aplica ou nao'" and the per-regulation
 * constraint sections, plus "Cross-jurisdicao"):
 *   Entrada: target/program signals { pci_dss_scope, payment_processor, phi,
 *            healthcare, operates_eu, eu_residents, operates_br, br_citizens,
 *            dod_contractor, soc2_type2, iso_27001, ... } (booleans, default false).
 *   Saida:   { profiles[], data_residency, notification_sla_hours, must_redact[],
 *             exfiltration_proof_max_bytes, hard_refusals[], constraints_by_profile }.
 *
 * Documented invariants this code enforces:
 *   - Signal table: each documented signal activates exactly its profile; multiple
 *     signals => multiple cumulative profiles ("Multiplos sinais = multiplos
 *     compliance_profiles cumulativos").
 *   - Cross-jurisdiction "mais restritivo vence": data residency resolves to the most
 *     restrictive region (UE+BR -> UE; DFARS US-only is hardest).
 *   - Notification SLA "prazo mais curto": resolves to the SHORTEST window across all
 *     active profiles (GDPR 72h vs HIPAA 60d -> 72h).
 *   - Exfil cap: minimum exfiltration_proof_max_bytes across profiles wins (LGPD 512).
 *   - Refusal Matrix "sempre cumulativa": hard refusals union across profiles
 *     (HIPAA cyber-ref-050; PCI-DSS cyber-ref-052; DFARS cyber-ref-050 + cyber-ref-051).
 *   - must_redact is the cumulative union across active profiles.
 *   - Anti-pattern guard: when NO signal is asserted the profile is empty, but the
 *     decider flags that real jurisdiction must still be verified (it does not silently
 *     assume "no compliance" just because the BB program did not mention it).
 *
 * @see docs/engineering-knowledge-base/cyber-security/compliance-mapping.md
 */
final class AtlasCyberComplianceMappingService
{
    /** Stable receipt schema id this mapping emits. */
    public const RECEIPT_KIND = 'cyber.compliance_mapping';

    /** Canonical compliance profile ids (closed set, from the doc sections). */
    public const PROFILE_LGPD = 'lgpd';
    public const PROFILE_GDPR = 'gdpr';
    public const PROFILE_HIPAA = 'hipaa';
    public const PROFILE_PCI_DSS = 'pci_dss';
    public const PROFILE_DFARS = 'dfars_nist_800_171';
    public const PROFILE_SOC2 = 'soc2';
    public const PROFILE_ISO_27001 = 'iso_27001';

    /** Data-residency regions, ordered LEAST -> MOST restrictive (index = strictness). */
    public const RESIDENCY_ANY = 'any';
    public const RESIDENCY_EU = 'eu';
    public const RESIDENCY_US_ONLY = 'us_only';

    /**
     * Residency strictness ladder. Higher index wins under "mais restritivo vence".
     * US-only (DFARS) is the hardest constraint; EU residency next; "any" is the
     * permissive default when no residency-bearing profile is active.
     *
     * @var array<string,int>
     */
    private const RESIDENCY_RANK = [
        self::RESIDENCY_ANY => 0,
        self::RESIDENCY_EU => 1,
        self::RESIDENCY_US_ONLY => 2,
    ];

    /** Sentinel for "no notification SLA constraint from any active profile". */
    public const NO_SLA = -1;

    /** Default minimal-collection exfil cap when no profile narrows it further. */
    public const DEFAULT_EXFIL_MAX_BYTES = 4096;

    /**
     * Signal -> profile mapping ("Decisao 'aplica ou nao'" table). Each entry lists
     * the boolean input signal keys that, when any is true, activate the profile.
     *
     * @var array<string, list<string>>
     */
    private const SIGNAL_MAP = [
        self::PROFILE_PCI_DSS => ['pci_dss_scope', 'payment_processor', 'touches_chd'],
        self::PROFILE_HIPAA => ['phi', 'healthcare', 'business_associate'],
        self::PROFILE_GDPR => ['operates_eu', 'eu_residents'],
        self::PROFILE_LGPD => ['operates_br', 'br_citizens'],
        self::PROFILE_DFARS => ['dod_contractor', 'military_base', 'cui'],
        self::PROFILE_SOC2 => ['soc2_type2'],
        self::PROFILE_ISO_27001 => ['iso_27001'],
    ];

    /**
     * Per-profile constraint table distilled from each regulation section of the doc.
     *
     *   residency       : the data-residency floor this profile imposes.
     *   notification_sla_hours : breach-notification window in HOURS (NO_SLA if none).
     *   exfil_max_bytes : minimal-collection exfil cap this profile imposes (or null).
     *   must_redact     : data classes this profile forces to be redacted.
     *   hard_refusals   : refusal-matrix rule ids this profile makes absolute.
     *
     * @var array<string, array{
     *     residency:string,
     *     notification_sla_hours:int,
     *     exfil_max_bytes:?int,
     *     must_redact:list<string>,
     *     hard_refusals:list<string>
     * }>
     */
    private const CONSTRAINTS = [
        // LGPD: Art.48 ANPD notification; minimal collection exfil 512; PII redaction.
        self::PROFILE_LGPD => [
            'residency' => self::RESIDENCY_ANY,
            'notification_sla_hours' => self::NO_SLA, // legal window, not a fixed hour count in the doc
            'exfil_max_bytes' => 512,
            'must_redact' => ['cpf', 'full_name', 'address', 'phone', 'personal_email'],
            'hard_refusals' => [],
        ],
        // GDPR: Art.33-34 breach notification 72h; EU data residency for EU residents.
        self::PROFILE_GDPR => [
            'residency' => self::RESIDENCY_EU,
            'notification_sla_hours' => 72,
            'exfil_max_bytes' => null,
            'must_redact' => ['special_category_data'],
            'hard_refusals' => [],
        ],
        // HIPAA: breach notification 60 days (1440h); refusal on medical equipment availability.
        self::PROFILE_HIPAA => [
            'residency' => self::RESIDENCY_ANY,
            'notification_sla_hours' => 60 * 24, // 1440h (60 days)
            'exfil_max_bytes' => null,
            'must_redact' => ['phi_identifiers'],
            'hard_refusals' => ['cyber-ref-050'],
        ],
        // PCI-DSS v4.0: never store CHD post-auth; refusal on settlement core.
        self::PROFILE_PCI_DSS => [
            'residency' => self::RESIDENCY_ANY,
            'notification_sla_hours' => self::NO_SLA,
            'exfil_max_bytes' => null,
            'must_redact' => ['card_number', 'cvv'],
            'hard_refusals' => ['cyber-ref-052'],
        ],
        // DFARS 252.204-7012: 72h DoD report; US-only residency; availability refusals hard.
        self::PROFILE_DFARS => [
            'residency' => self::RESIDENCY_US_ONLY,
            'notification_sla_hours' => 72,
            'exfil_max_bytes' => null,
            'must_redact' => ['cui'],
            'hard_refusals' => ['cyber-ref-050', 'cyber-ref-051'],
        ],
        // SOC 2: posture profile; no fixed breach hour / residency floor of its own.
        self::PROFILE_SOC2 => [
            'residency' => self::RESIDENCY_ANY,
            'notification_sla_hours' => self::NO_SLA,
            'exfil_max_bytes' => null,
            'must_redact' => [],
            'hard_refusals' => [],
        ],
        // ISO/IEC 27001:2022: posture profile; no fixed breach hour / residency floor.
        self::PROFILE_ISO_27001 => [
            'residency' => self::RESIDENCY_ANY,
            'notification_sla_hours' => self::NO_SLA,
            'exfil_max_bytes' => null,
            'must_redact' => [],
            'hard_refusals' => [],
        ],
    ];

    /**
     * Resolve the cumulative compliance envelope for a set of scoping signals.
     *
     * @param array<string,mixed> $signals boolean signal keys (see SIGNAL_MAP). Any
     *        truthy value activates the corresponding profile. Unknown keys ignored.
     *        Optional: envelope_id (string) correlation id.
     *
     * @return array<string,mixed> the resolved compliance envelope (cumulative).
     */
    public function resolve(array $signals): array
    {
        $envelopeId = $this->str($signals['envelope_id'] ?? null);
        $profiles = $this->profilesFor($signals);

        // No signal asserted. Anti-pattern guard: do NOT silently assume "no
        // compliance" — flag that real jurisdiction must still be verified.
        if ($profiles === []) {
            return [
                'receipt_kind' => self::RECEIPT_KIND,
                'schema' => self::RECEIPT_KIND,
                'envelope_id' => $envelopeId,
                'profiles' => [],
                'cumulative' => false,
                'data_residency' => self::RESIDENCY_ANY,
                'notification_sla_hours' => self::NO_SLA,
                'must_redact' => [],
                'exfiltration_proof_max_bytes' => self::DEFAULT_EXFIL_MAX_BYTES,
                'hard_refusals' => [],
                'constraints_by_profile' => [],
                'jurisdiction_verification_required' => true,
                'notes' => [
                    'no_signal_asserted_verify_real_jurisdiction',
                ],
            ];
        }

        // Cumulative resolution across all active profiles.
        $residency = self::RESIDENCY_ANY;
        $slaHours = self::NO_SLA;
        $exfilCap = self::DEFAULT_EXFIL_MAX_BYTES;
        $mustRedact = [];
        $hardRefusals = [];
        $byProfile = [];

        foreach ($profiles as $profile) {
            $c = self::CONSTRAINTS[$profile];
            $byProfile[$profile] = $c;

            // Data residency: most restrictive wins (mais restritivo vence).
            if (self::RESIDENCY_RANK[$c['residency']] > self::RESIDENCY_RANK[$residency]) {
                $residency = $c['residency'];
            }

            // Notification SLA: shortest window wins (prazo mais curto).
            if ($c['notification_sla_hours'] !== self::NO_SLA) {
                $slaHours = $slaHours === self::NO_SLA
                    ? $c['notification_sla_hours']
                    : min($slaHours, $c['notification_sla_hours']);
            }

            // Exfil cap: minimum across profiles (mais restritivo / coleta minima).
            if ($c['exfil_max_bytes'] !== null) {
                $exfilCap = min($exfilCap, $c['exfil_max_bytes']);
            }

            // must_redact + refusals: cumulative union (Refusal Matrix sempre cumulativa).
            $mustRedact = [...$mustRedact, ...$c['must_redact']];
            $hardRefusals = [...$hardRefusals, ...$c['hard_refusals']];
        }

        return [
            'receipt_kind' => self::RECEIPT_KIND,
            'schema' => self::RECEIPT_KIND,
            'envelope_id' => $envelopeId,
            'profiles' => $profiles,
            'cumulative' => count($profiles) > 1,
            'data_residency' => $residency,
            'notification_sla_hours' => $slaHours,
            'must_redact' => $this->dedupe($mustRedact),
            'exfiltration_proof_max_bytes' => $exfilCap,
            'hard_refusals' => $this->dedupe($hardRefusals),
            'constraints_by_profile' => $byProfile,
            'jurisdiction_verification_required' => false,
            'notes' => [],
        ];
    }

    /**
     * Which compliance profiles apply for these signals, in canonical (declaration)
     * order. Pure: identical signals always yield the identical ordered list.
     *
     * @param array<string,mixed> $signals
     * @return list<string>
     */
    public function profilesFor(array $signals): array
    {
        $active = [];
        foreach (self::SIGNAL_MAP as $profile => $keys) {
            foreach ($keys as $key) {
                if ($this->truthy($signals[$key] ?? null)) {
                    $active[] = $profile;
                    break;
                }
            }
        }

        return $active;
    }

    /**
     * Convenience predicate: is a given profile active for these signals?
     *
     * @param array<string,mixed> $signals
     */
    public function appliesTo(string $profile, array $signals): bool
    {
        return in_array($profile, $this->profilesFor($signals), true);
    }

    /** @return list<string> the immutable canonical profile id set. */
    public function profileIds(): array
    {
        return array_keys(self::SIGNAL_MAP);
    }

    private function truthy(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_string($v)) {
            $k = strtolower(trim($v));

            return $k === '1' || $k === 'true' || $k === 'yes' || $k === 'on';
        }

        return false;
    }

    /**
     * @param list<string> $items
     * @return list<string>
     */
    private function dedupe(array $items): array
    {
        return array_values(array_unique($items));
    }

    private function str(mixed $v): ?string
    {
        if (is_string($v) && trim($v) !== '') {
            return trim($v);
        }

        return null;
    }
}
