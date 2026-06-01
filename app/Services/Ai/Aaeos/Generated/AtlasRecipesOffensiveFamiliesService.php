<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Cyber Offensive Recipe Families — pure, deterministic family decider.
 *
 * The offensive-families doc is a focused taxonomy of proposed offensive cyber
 * recipe families and their tool candidates. The candidates are NOT active
 * runtime authority; promotion requires the promotion runbook and the policy
 * gates. This service encodes that taxonomy as a single source of truth and
 * answers the one concrete question the doc makes decidable: given a candidate
 * tool slug, which family does it belong to, what is its declared risk tier,
 * and what promotion gates MUST be satisfied before it can be copied into an
 * AP/migration and revalidated. It never executes a tool, connects to anything,
 * touches a database or mutates state.
 *
 * Encoded contract (directly from the doc tables):
 *
 *   Eight families, in doc order:
 *     recon, webapp_api, mobile, cloud_kubernetes, red_team_c2,
 *     network_ad, ai_ml_supply_chain.
 *
 *   Per-tool declared risk tier (T0 < T1 < T2), e.g.:
 *     subfinder/httpx/cve-monitor = T0; nuclei/ffuf/amass/bbot = T1;
 *     axiom = T2.
 *
 *   Per-tool/per-family gates surfaced from the "Risk" / "Candidate use" columns
 *   and the Red Team C2 clause:
 *     - "approval required" for exploit-capable webapp tools (sqlmap,
 *       smuggler-custom) and for pacu (cloud exploitation simulation).
 *     - "cost gate" + "extra approval" for axiom (distributed recon, T2).
 *     - "scope only" for bbot; "inbox proposal only" for cve-monitor.
 *     - "dry-run default" for nuclei.
 *     - "human in the loop" for caido.
 *     - "privacy sensitive" for mitmproxy.
 *     - Red Team C2 (sliver, havoc, invisibility-cloak) ALWAYS require the
 *       dedicated `red-team-c2` clause, a VM sandbox, explicit approval AND a
 *       strict kill-switch.
 *
 *   Hard rule from the body + frontmatter: a candidate is never "promoted"
 *   straight from this list. Promotion ALWAYS routes through the runbook and the
 *   policy/scope/sandbox/evidence revalidation. So every classification carries
 *   `promotion_ready => false` and the canonical pre-promotion obligations.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-offensive-families.md
 */
final class AtlasRecipesOffensiveFamiliesService
{
    /** Stable receipt schema id this decider emits. */
    public const SCHEMA = 'atlas.cyber.recipes_offensive_families.v1';

    /** Families, in doc order (closed set). */
    public const FAMILY_RECON = 'recon';
    public const FAMILY_WEBAPP_API = 'webapp_api';
    public const FAMILY_MOBILE = 'mobile';
    public const FAMILY_CLOUD_KUBERNETES = 'cloud_kubernetes';
    public const FAMILY_RED_TEAM_C2 = 'red_team_c2';
    public const FAMILY_NETWORK_AD = 'network_ad';
    public const FAMILY_AI_ML_SUPPLY_CHAIN = 'ai_ml_supply_chain';

    /** Risk tiers, ordered low to high. */
    public const TIER_T0 = 'T0';
    public const TIER_T1 = 'T1';
    public const TIER_T2 = 'T2';

    /** Gate tokens (closed set), surfaced from the doc's Risk/use columns. */
    public const GATE_SCOPED = 'scoped';
    public const GATE_SCOPE_ONLY = 'scope_only';
    public const GATE_DRY_RUN_DEFAULT = 'dry_run_default';
    public const GATE_APPROVAL_REQUIRED = 'approval_required';
    public const GATE_EXTRA_APPROVAL = 'extra_approval';
    public const GATE_COST_GATE = 'cost_gate';
    public const GATE_INBOX_PROPOSAL_ONLY = 'inbox_proposal_only';
    public const GATE_HUMAN_IN_THE_LOOP = 'human_in_the_loop';
    public const GATE_PRIVACY_SENSITIVE = 'privacy_sensitive';
    public const GATE_DEDICATED_C2_CLAUSE = 'dedicated_red_team_c2_clause';
    public const GATE_VM_SANDBOX = 'vm_sandbox';
    public const GATE_KILL_SWITCH = 'kill_switch';

    /**
     * Canonical obligations the Promotion Note mandates before ANY candidate is
     * copied into an AP/migration. These run regardless of family.
     *
     * @var list<string>
     */
    public const PROMOTION_OBLIGATIONS = [
        'promotion_runbook',
        'policy_revalidation',
        'scope_revalidation',
        'sandbox_revalidation',
        'evidence_revalidation',
    ];

    /**
     * The taxonomy: tool_slug => [family, tier, gates].
     *
     * Transcribed straight from the doc tables. `tier` defaults to T1 for
     * families whose tables omit an explicit Risk column (mobile, cloud, network
     * AD, AI/ML) because the doc treats every entry here as an offensive
     * candidate gated behind promotion — never T0-free; the explicit T0/T1/T2
     * markers in the Recon/Webapp tables are honored verbatim.
     *
     * @var array<string,array{family:string,tier:string,gates:list<string>}>
     */
    private const TAXONOMY = [
        // --- Recon ---
        'nuclei' => ['family' => self::FAMILY_RECON, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED, self::GATE_DRY_RUN_DEFAULT]],
        'ffuf' => ['family' => self::FAMILY_RECON, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'amass' => ['family' => self::FAMILY_RECON, 'tier' => self::TIER_T1, 'gates' => []],
        'subfinder' => ['family' => self::FAMILY_RECON, 'tier' => self::TIER_T0, 'gates' => []],
        'httpx' => ['family' => self::FAMILY_RECON, 'tier' => self::TIER_T0, 'gates' => []],
        'bbot' => ['family' => self::FAMILY_RECON, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPE_ONLY]],
        'cve-monitor' => ['family' => self::FAMILY_RECON, 'tier' => self::TIER_T0, 'gates' => [self::GATE_INBOX_PROPOSAL_ONLY]],
        'axiom' => ['family' => self::FAMILY_RECON, 'tier' => self::TIER_T2, 'gates' => [self::GATE_COST_GATE, self::GATE_EXTRA_APPROVAL]],

        // --- Webapp And API ---
        'sqlmap' => ['family' => self::FAMILY_WEBAPP_API, 'tier' => self::TIER_T2, 'gates' => [self::GATE_APPROVAL_REQUIRED]],
        'zap' => ['family' => self::FAMILY_WEBAPP_API, 'tier' => self::TIER_T2, 'gates' => [self::GATE_SCOPED]],
        'wapiti' => ['family' => self::FAMILY_WEBAPP_API, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'mitmproxy' => ['family' => self::FAMILY_WEBAPP_API, 'tier' => self::TIER_T1, 'gates' => [self::GATE_PRIVACY_SENSITIVE]],
        'smuggler-custom' => ['family' => self::FAMILY_WEBAPP_API, 'tier' => self::TIER_T2, 'gates' => [self::GATE_APPROVAL_REQUIRED]],
        'js-dynamic-analyzer' => ['family' => self::FAMILY_WEBAPP_API, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'caido' => ['family' => self::FAMILY_WEBAPP_API, 'tier' => self::TIER_T1, 'gates' => [self::GATE_HUMAN_IN_THE_LOOP]],

        // --- Mobile ---
        'mobsf' => ['family' => self::FAMILY_MOBILE, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'frida' => ['family' => self::FAMILY_MOBILE, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'objection' => ['family' => self::FAMILY_MOBILE, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],

        // --- Cloud And Kubernetes ---
        'prowler' => ['family' => self::FAMILY_CLOUD_KUBERNETES, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'scout-suite' => ['family' => self::FAMILY_CLOUD_KUBERNETES, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'kube-bench' => ['family' => self::FAMILY_CLOUD_KUBERNETES, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'kube-hunter' => ['family' => self::FAMILY_CLOUD_KUBERNETES, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'cloudsplaining' => ['family' => self::FAMILY_CLOUD_KUBERNETES, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'pacu' => ['family' => self::FAMILY_CLOUD_KUBERNETES, 'tier' => self::TIER_T2, 'gates' => [self::GATE_APPROVAL_REQUIRED]],

        // --- Red Team C2 (always the full clause) ---
        'sliver' => ['family' => self::FAMILY_RED_TEAM_C2, 'tier' => self::TIER_T2, 'gates' => [self::GATE_DEDICATED_C2_CLAUSE, self::GATE_VM_SANDBOX, self::GATE_APPROVAL_REQUIRED, self::GATE_KILL_SWITCH]],
        'havoc' => ['family' => self::FAMILY_RED_TEAM_C2, 'tier' => self::TIER_T2, 'gates' => [self::GATE_DEDICATED_C2_CLAUSE, self::GATE_VM_SANDBOX, self::GATE_APPROVAL_REQUIRED, self::GATE_KILL_SWITCH]],
        'invisibility-cloak' => ['family' => self::FAMILY_RED_TEAM_C2, 'tier' => self::TIER_T2, 'gates' => [self::GATE_DEDICATED_C2_CLAUSE, self::GATE_VM_SANDBOX, self::GATE_APPROVAL_REQUIRED, self::GATE_KILL_SWITCH]],

        // --- Network And AD ---
        'nmap' => ['family' => self::FAMILY_NETWORK_AD, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'bloodhound' => ['family' => self::FAMILY_NETWORK_AD, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'impacket' => ['family' => self::FAMILY_NETWORK_AD, 'tier' => self::TIER_T2, 'gates' => [self::GATE_APPROVAL_REQUIRED]],
        'crackmapexec' => ['family' => self::FAMILY_NETWORK_AD, 'tier' => self::TIER_T2, 'gates' => [self::GATE_APPROVAL_REQUIRED]],
        'certipy-ad' => ['family' => self::FAMILY_NETWORK_AD, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'gpp-decrypt' => ['family' => self::FAMILY_NETWORK_AD, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],

        // --- AI/ML And Supply Chain ---
        'promptfoo' => ['family' => self::FAMILY_AI_ML_SUPPLY_CHAIN, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'garak' => ['family' => self::FAMILY_AI_ML_SUPPLY_CHAIN, 'tier' => self::TIER_T1, 'gates' => [self::GATE_SCOPED]],
        'lakera' => ['family' => self::FAMILY_AI_ML_SUPPLY_CHAIN, 'tier' => self::TIER_T0, 'gates' => []],
        'cosign' => ['family' => self::FAMILY_AI_ML_SUPPLY_CHAIN, 'tier' => self::TIER_T0, 'gates' => []],
        'grype' => ['family' => self::FAMILY_AI_ML_SUPPLY_CHAIN, 'tier' => self::TIER_T0, 'gates' => []],
    ];

    /** Numeric ordering for tiers, so callers can compare severity. */
    private const TIER_RANK = [
        self::TIER_T0 => 0,
        self::TIER_T1 => 1,
        self::TIER_T2 => 2,
    ];

    /**
     * Classify a single candidate tool slug against the offensive-families
     * taxonomy. Pure: same slug always yields the same verdict.
     *
     * @return array{
     *   schema:string,
     *   tool:string,
     *   known:bool,
     *   family:?string,
     *   tier:?string,
     *   tier_rank:?int,
     *   gates:list<string>,
     *   requires_approval:bool,
     *   requires_kill_switch:bool,
     *   promotion_ready:bool,
     *   promotion_obligations:list<string>,
     *   reason:string
     * }
     */
    public function classify(string $toolSlug): array
    {
        $slug = strtolower(trim($toolSlug));

        if (! isset(self::TAXONOMY[$slug])) {
            return [
                'schema' => self::SCHEMA,
                'tool' => $slug,
                'known' => false,
                'family' => null,
                'tier' => null,
                'tier_rank' => null,
                'gates' => [],
                'requires_approval' => false,
                'requires_kill_switch' => false,
                // Unknown candidates are never promotion-ready: they are not even
                // proposed in the doc taxonomy.
                'promotion_ready' => false,
                'promotion_obligations' => self::PROMOTION_OBLIGATIONS,
                'reason' => 'tool_not_in_offensive_families_taxonomy',
            ];
        }

        $entry = self::TAXONOMY[$slug];
        $gates = $entry['gates'];

        $requiresApproval = in_array(self::GATE_APPROVAL_REQUIRED, $gates, true)
            || in_array(self::GATE_EXTRA_APPROVAL, $gates, true);
        $requiresKillSwitch = in_array(self::GATE_KILL_SWITCH, $gates, true);

        return [
            'schema' => self::SCHEMA,
            'tool' => $slug,
            'known' => true,
            'family' => $entry['family'],
            'tier' => $entry['tier'],
            'tier_rank' => self::TIER_RANK[$entry['tier']],
            'gates' => $gates,
            'requires_approval' => $requiresApproval,
            'requires_kill_switch' => $requiresKillSwitch,
            // Hard rule: listing here is a PROPOSAL, never runtime authority.
            // Promotion always routes through the runbook + revalidation.
            'promotion_ready' => false,
            'promotion_obligations' => self::PROMOTION_OBLIGATIONS,
            'reason' => 'candidate_proposal_requires_promotion_runbook',
        ];
    }

    /**
     * Group every known candidate under its family, in doc order, each with its
     * tier and gates. Deterministic snapshot of the taxonomy.
     *
     * @return array{
     *   schema:string,
     *   families:array<string,list<array{tool:string,tier:string,gates:list<string>}>>,
     *   family_order:list<string>,
     *   total_candidates:int
     * }
     */
    public function families(): array
    {
        $families = [];
        foreach (self::familyOrder() as $family) {
            $families[$family] = [];
        }

        foreach (self::TAXONOMY as $tool => $entry) {
            $families[$entry['family']][] = [
                'tool' => $tool,
                'tier' => $entry['tier'],
                'gates' => $entry['gates'],
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'families' => $families,
            'family_order' => self::familyOrder(),
            'total_candidates' => count(self::TAXONOMY),
        ];
    }

    /**
     * Ordered list of every gate that must be cleared for a family before any of
     * its tools may run, derived from the union of its tools' gates plus the
     * fixed family-level rule (Red Team C2 always carries the full clause).
     *
     * @return array{
     *   schema:string,
     *   family:string,
     *   known:bool,
     *   highest_tier:?string,
     *   gates:list<string>,
     *   requires_approval:bool,
     *   requires_kill_switch:bool
     * }
     */
    public function familyGate(string $family): array
    {
        $family = strtolower(trim($family));

        if (! in_array($family, self::familyOrder(), true)) {
            return [
                'schema' => self::SCHEMA,
                'family' => $family,
                'known' => false,
                'highest_tier' => null,
                'gates' => [],
                'requires_approval' => false,
                'requires_kill_switch' => false,
            ];
        }

        $gates = [];
        $highestRank = -1;
        $highestTier = null;

        foreach (self::TAXONOMY as $entry) {
            if ($entry['family'] !== $family) {
                continue;
            }
            foreach ($entry['gates'] as $gate) {
                if (! in_array($gate, $gates, true)) {
                    $gates[] = $gate;
                }
            }
            $rank = self::TIER_RANK[$entry['tier']];
            if ($rank > $highestRank) {
                $highestRank = $rank;
                $highestTier = $entry['tier'];
            }
        }

        // Red Team C2 always carries the dedicated clause + VM sandbox + approval
        // + kill-switch, even if a future entry forgot to declare one of them.
        if ($family === self::FAMILY_RED_TEAM_C2) {
            foreach ([self::GATE_DEDICATED_C2_CLAUSE, self::GATE_VM_SANDBOX, self::GATE_APPROVAL_REQUIRED, self::GATE_KILL_SWITCH] as $gate) {
                if (! in_array($gate, $gates, true)) {
                    $gates[] = $gate;
                }
            }
        }

        return [
            'schema' => self::SCHEMA,
            'family' => $family,
            'known' => true,
            'highest_tier' => $highestTier,
            'gates' => $gates,
            'requires_approval' => in_array(self::GATE_APPROVAL_REQUIRED, $gates, true)
                || in_array(self::GATE_EXTRA_APPROVAL, $gates, true),
            'requires_kill_switch' => in_array(self::GATE_KILL_SWITCH, $gates, true),
        ];
    }

    /**
     * Families in canonical doc order.
     *
     * @return list<string>
     */
    public static function familyOrder(): array
    {
        return [
            self::FAMILY_RECON,
            self::FAMILY_WEBAPP_API,
            self::FAMILY_MOBILE,
            self::FAMILY_CLOUD_KUBERNETES,
            self::FAMILY_RED_TEAM_C2,
            self::FAMILY_NETWORK_AD,
            self::FAMILY_AI_ML_SUPPLY_CHAIN,
        ];
    }
}
