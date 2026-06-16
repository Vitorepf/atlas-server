<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Rsi;

use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Governed RSI · Part A (Build-Safety) · Immutable Invariant Registry.
 *
 * The frozen, self-protecting SACRED SET. This service is the single source of
 * truth for which code paths the Recursive Self-Improvement loop may NEVER
 * touch, modify, delete or weaken. It enumerates the unmovable sacred gates that
 * protect the loop's own machinery:
 *
 *   - provider_proof_sec_001       — SEC-001: changed files require a real provider call.
 *   - no_scaffold_final_delivery   — no TODO/FIXME/placeholder/stub in product code.
 *   - measured_or_reverted         — outcome claims backed by a real, reproducible metric.
 *   - adversarial_proof_panel      — 4 independent default-skeptical verifiers, fails closed.
 *   - zero_provider_preflight      — honest cheap skip; skipped cycle never counted as spend.
 *   - honest_stop                  — blocked is never dressed as ready/success.
 *   - proposal_only_gating         — self-improvement is proposal-only + human-gated.
 *   - exhaustion_rarity_gate       — I8 eligibility; eligible only on MEASURED exhaustion.
 *   - rsi_meta_judge               — RSI Part 3 measured-or-reverted meta-judge: an
 *                                    unproven self-improvement is REVERTED, not consolidated.
 *   - immutable_invariant_registry — THIS registry + its guard protect THEMSELVES.
 *
 * The registry is READ-ONLY and DETERMINISTIC. It computes, on demand, a stable
 * content fingerprint (sha256 of the on-disk source) for each guarded path so a
 * proposal cannot silently rewrite a gate and keep the same identity. It NEVER
 * calls a provider, NEVER writes state, NEVER auto-applies anything. It is
 * consumed by RsiInvariantGuardService, which screens every self-improvement
 * proposal diff BEFORE it can reach the operator's human gate.
 *
 * Self-protection: the registry deliberately lists its OWN file AND the guard's
 * file as sacred (gate id immutable_invariant_registry). Any proposal touching
 * them is auto-rejected — the sacred set cannot edit itself open.
 */
final class ImmutableInvariantRegistryService
{
    public const REGISTRY_SCHEMA = 'atlas.foundry.rsi.immutable_invariant_registry.v1';

    public const GATE_PROVIDER_PROOF = 'provider_proof_sec_001';

    public const GATE_NO_SCAFFOLD = 'no_scaffold_final_delivery';

    public const GATE_MEASURED_OR_REVERTED = 'measured_or_reverted';

    public const GATE_ADVERSARIAL_PROOF_PANEL = 'adversarial_proof_panel';

    public const GATE_ZERO_PROVIDER_PREFLIGHT = 'zero_provider_preflight';

    public const GATE_HONEST_STOP = 'honest_stop';

    public const GATE_PROPOSAL_ONLY_GATING = 'proposal_only_gating';

    public const GATE_EXHAUSTION_RARITY = 'exhaustion_rarity_gate';

    public const GATE_RSI_META_JUDGE = 'rsi_meta_judge';

    public const GATE_REGISTRY_SELF = 'immutable_invariant_registry';

    public const GATE_EA_DEFAULT_OFF = 'earned_autonomy.default_off';

    public const GATE_EA_TIER_CEILING = 'earned_autonomy.tier_ceiling';

    public const GATE_EA_TRUST_LEDGER_APPEND_ONLY = 'earned_autonomy.trust_ledger_append_only';

    public const GATE_EA_KILL_CANNOT_DISARM = 'earned_autonomy.kill_cannot_disarm';

    public const GATE_EA_INVARIANT_TOUCH_NEVER_AUTO = 'earned_autonomy.invariant_touch_never_auto';

    public const GATE_EA_PROPOSAL_ACTUATION_SEAM = 'earned_autonomy.proposal_actuation_seam';

    /**
     * Sacred set definition. Each gate lists the repo-relative source paths it
     * owns (the files an RSI diff may NEVER add/modify/delete) and the
     * structural weakening signatures that, if a diff REMOVES them from a guarded
     * file, prove the gate is being weakened.
     *
     * Paths are relative to the Laravel base_path() (the atlas-server root). The
     * registry path itself and the guard path are deliberately included under
     * GATE_REGISTRY_SELF so the sacred set protects itself.
     *
     * @var array<string,array{title:string,paths:list<string>,weakening_signatures:list<string>}>
     */
    private const SACRED_GATES = [
        self::GATE_PROVIDER_PROOF => [
            'title' => 'SEC-001 Provider-Proof: changed files require a real provider call',
            'paths' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
            ],
            'weakening_signatures' => [
                'providerCalls > 0',
                'provider_proof',
            ],
        ],
        self::GATE_NO_SCAFFOLD => [
            'title' => 'No-Scaffold / Final-Delivery: no incompleteness markers in product code',
            'paths' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AdversarialProofPanelService.php',
            ],
            'weakening_signatures' => [
                'INCOMPLETENESS_MARKERS',
            ],
        ],
        self::GATE_MEASURED_OR_REVERTED => [
            'title' => 'Measured-or-Reverted: outcome claims backed by a real reproducible metric',
            'paths' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/MetricLedgerService.php',
                'app/Services/Ai/Foundry/Frontier/Outcome/FoundryEvolutionOutcomeMaterializerService.php',
            ],
            'weakening_signatures' => [
                'normalizeContract',
                'refuted_by_reality',
            ],
        ],
        self::GATE_ADVERSARIAL_PROOF_PANEL => [
            'title' => 'Adversarial-Proof Panel: 4 independent default-skeptical verifiers, fails closed',
            'paths' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AdversarialProofPanelService.php',
            ],
            'weakening_signatures' => [
                'refuted_count',
                'function refute',
            ],
        ],
        self::GATE_ZERO_PROVIDER_PREFLIGHT => [
            'title' => 'Zero-Provider Preflight: honest cheap skip never counted as provider spend',
            'paths' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/ZeroProviderPreflightGate.php',
            ],
            'weakening_signatures' => [
                'token_spending_cycle',
            ],
        ],
        self::GATE_HONEST_STOP => [
            'title' => 'Honest-Stop: a blocked cycle is never dressed up as ready/success',
            'paths' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopInvariantHarnessService.php',
            ],
            'weakening_signatures' => [
                'blocked_never_dressed_as_ready',
            ],
        ],
        self::GATE_PROPOSAL_ONLY_GATING => [
            'title' => 'Proposal-Only Gating: self-improvement is proposal-only + human-gated',
            'paths' => [
                'app/Services/Ai/SelfImprovement/AtlasSelfImprovementInvariantLockService.php',
            ],
            'weakening_signatures' => [
                'completion_requires_evidence_and_review',
                'provider_never_called_without_approval',
            ],
        ],
        self::GATE_EXHAUSTION_RARITY => [
            'title' => 'Exhaustion & Rarity Gate (I8): eligible only on MEASURED exhaustion',
            'paths' => [
                'app/Services/Ai/Foundry/FoundryExhaustionRarityGateService.php',
            ],
            'weakening_signatures' => [
                'consecutiveMeasuredZeroAdmissible',
                'fallback_is_honest_stop',
            ],
        ],
        self::GATE_RSI_META_JUDGE => [
            'title' => 'RSI Meta-Judge (Part 3): measured-or-reverted — an unproven self-improvement is reverted, never auto-consolidated',
            'paths' => [
                'app/Services/Ai/Rsi/RsiOutcomeMaterializerService.php',
                'app/Services/Ai/Rsi/GroundTruthValueAdapterService.php',
                'app/Services/Ai/Rsi/RsiGitRevertPort.php',
                'app/Services/Ai/Rsi/RealRsiGitRevertPort.php',
                'app/Services/Ai/Rsi/OperatorAcceptanceSignalPort.php',
                'app/Services/Ai/Rsi/RealOperatorAcceptanceSignalPort.php',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            ],
            'weakening_signatures' => [
                'refuted_by_reality',
                'auto_canonized',
                'operator_accepted',
                'git revert --no-edit',
                'applyMetaOutcomeForSelfImprovement',
                'extractSelfImprovement',
                'rsiOutcomeMaterializer',
            ],
        ],
        self::GATE_REGISTRY_SELF => [
            'title' => 'Immutable Invariant Registry: the sacred set and its guard protect themselves',
            'paths' => [
                'app/Services/Ai/Foundry/Rsi/ImmutableInvariantRegistryService.php',
                'app/Services/Ai/Foundry/Rsi/RsiInvariantGuardService.php',
            ],
            'weakening_signatures' => [
                'SACRED_GATES',
                'isSacredPath',
            ],
        ],
        self::GATE_EA_DEFAULT_OFF => [
            'title' => 'Earned Autonomy Default-Off: kill disarmed + flag off => always human_gate',
            'paths' => [
                'app/Services/Ai/Foundry/Rsi/EarnedAutonomy/KillAuthorityService.php',
                'app/Services/Ai/Foundry/Rsi/EarnedAutonomy/EarnedAutonomyGateService.php',
            ],
            'weakening_signatures' => [
                'isArmed',
                'DECISION_HUMAN_GATE',
                'kill_disarmed_or_flag_off',
            ],
        ],
        self::GATE_EA_TIER_CEILING => [
            'title' => 'Earned Autonomy Tier Ceiling: auto_apply only iff risk_rank <= earned max-auto-rank; tier never unlocks gate_or_invariant_touch',
            'paths' => [
                'app/Services/Ai/Foundry/Rsi/EarnedAutonomy/EarnedAutonomyGateService.php',
                'app/Services/Ai/Foundry/Rsi/EarnedAutonomy/RiskClassifierService.php',
            ],
            'weakening_signatures' => [
                'riskRank <= ',
                'RISK_CLASS_GATE_OR_INVARIANT_TOUCH',
                'invariant_touch_never_auto',
            ],
        ],
        self::GATE_EA_TRUST_LEDGER_APPEND_ONLY => [
            'title' => 'Earned Autonomy Trust Ledger: append-only; earnedTier is a pure fold; a revocation resets tier to 0',
            'paths' => [
                'app/Services/Ai/Foundry/Rsi/EarnedAutonomy/TrustLedgerService.php',
            ],
            'weakening_signatures' => [
                'recordRevocation',
                'File::append',
                'earnedTier',
            ],
        ],
        self::GATE_EA_KILL_CANNOT_DISARM => [
            'title' => 'Earned Autonomy Kill Authority: only an explicit operator actor may arm/disarm; the loop/composer never self-arms',
            'paths' => [
                'app/Services/Ai/Foundry/Rsi/EarnedAutonomy/KillAuthorityService.php',
            ],
            'weakening_signatures' => [
                '$actor',
                'EVENT_DISARM',
                'STATE_DISARMED',
            ],
        ],
        self::GATE_EA_INVARIANT_TOUCH_NEVER_AUTO => [
            'title' => 'Earned Autonomy: a gate_or_invariant_touch proposal can NEVER auto_apply at any tier; drift/red-team breach revokes to tier 0',
            'paths' => [
                'app/Services/Ai/Foundry/Rsi/EarnedAutonomy/EarnedAutonomyGateService.php',
                'app/Services/Ai/Foundry/Rsi/EarnedAutonomy/DriftAnomalyDetectorService.php',
                'app/Services/Ai/Foundry/Rsi/EarnedAutonomy/StandingRedTeamService.php',
            ],
            'weakening_signatures' => [
                'attack_blocked',
                'drift_detected',
                'revoke_to_tier_0',
            ],
        ],
        // ACDE S2 — the auto-apply ACTUATION SEAM. The adversarial pass found the proposal gate (which sets
        // STATUS_AUTO_APPLIED_EARNED) and the self-target selector (the only admit() caller) were NOT sacred —
        // a future RSI diff could wire a live actuator into them and be caught ONLY by the substring backstop.
        // Registering them as sacred makes the seam sacred-path-protected: an RSI proposal can NEVER add/modify/
        // delete the door's actuation wiring. Pays the registry debt the S2 actuator was blocked on.
        self::GATE_EA_PROPOSAL_ACTUATION_SEAM => [
            'title' => 'Earned Autonomy Actuation Seam: the proposal-gate auto-apply decision + the selector that consumes it are sacred — no RSI diff may wire a live actuator',
            'paths' => [
                'app/Services/Ai/Foundry/Rsi/RsiSelfImprovementProposalGate.php',
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/Rsi/SelfTargetSelectorService.php',
                // The actuator itself — the FIRST code that turns the auto_apply signal into a real git apply.
                'app/Services/Ai/Foundry/Rsi/RsiSelfImprovementApplyActuatorService.php',
            ],
            'weakening_signatures' => [
                'STATUS_AUTO_APPLIED_EARNED',
                'auto_applied',
                'routed_to_human_gate',
                'isAutonomyKilled',
                'self_improvement_apply_enabled',
            ],
        ],
    ];

    private ?string $baseDirOverride = null;

    /**
     * Test seam: point the registry at a sandbox repo root so source fingerprints
     * can be computed deterministically against fixtures. Mirrors the
     * setRepoRootForTesting / setOutcomesStorageDirForTesting convention.
     */
    public function setBaseDirForTesting(?string $dir): void
    {
        $this->baseDirOverride = $dir;
    }

    /**
     * The complete, frozen sacred set with a live content fingerprint per path.
     * Deterministic, read-only. Returns exactly the registry schema shape.
     *
     * @return array<string,mixed>
     */
    public function registry(): array
    {
        $gates = [];
        foreach (self::SACRED_GATES as $gateId => $def) {
            $paths = [];
            foreach ($def['paths'] as $relPath) {
                $paths[] = [
                    'path' => $relPath,
                    'content_fingerprint' => $this->fingerprint($relPath),
                ];
            }

            $gates[] = [
                'gate_id' => $gateId,
                'title' => $def['title'],
                'paths' => $paths,
                'weakening_signatures' => array_values($def['weakening_signatures']),
                'self_protecting' => $gateId === self::GATE_REGISTRY_SELF,
            ];
        }

        $payload = [
            'schema_version' => self::REGISTRY_SCHEMA,
            'gates' => $gates,
            'sacred_path_count' => count($this->sacredPaths()),
            'frozen' => true,
            'read_only' => true,
            'provider_invoked' => false,
        ];

        $payload['registry_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * Flat, normalized list of every sacred path. The guard compares proposal
     * diff paths against this set.
     *
     * @return list<string>
     */
    public function sacredPaths(): array
    {
        $paths = [];
        foreach (self::SACRED_GATES as $def) {
            foreach ($def['paths'] as $relPath) {
                $paths[] = $this->normalize($relPath);
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Does the given (repo-relative or absolute) path point at a sacred file?
     * Normalizes both sides so an absolute base_path()-prefixed path matches the
     * repo-relative registry entry.
     */
    public function isSacredPath(string $candidate): bool
    {
        $normalized = $this->normalize($candidate);
        foreach ($this->sacredPaths() as $sacred) {
            if ($normalized === $sacred) {
                return true;
            }
            // An absolute path that ends with the sacred repo-relative path is sacred too.
            if (str_ends_with($normalized, '/'.$sacred)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve which sacred gate(s) own a given path. Empty when the path is not
     * sacred. A single file can belong to more than one gate
     * (AdversarialProofPanel is both no-scaffold and the proof panel).
     *
     * @return list<string>
     */
    public function gatesForPath(string $candidate): array
    {
        $normalized = $this->normalize($candidate);
        $gates = [];
        foreach (self::SACRED_GATES as $gateId => $def) {
            foreach ($def['paths'] as $relPath) {
                $sacred = $this->normalize($relPath);
                if ($normalized === $sacred || str_ends_with($normalized, '/'.$sacred)) {
                    $gates[] = $gateId;
                    break;
                }
            }
        }

        return array_values(array_unique($gates));
    }

    /**
     * Weakening signatures defined for a gate. Used by the guard to detect a diff
     * that REMOVES a sacred check from a guarded file.
     *
     * @return list<string>
     */
    public function weakeningSignaturesFor(string $gateId): array
    {
        $def = self::SACRED_GATES[$gateId] ?? null;

        return $def === null ? [] : array_values($def['weakening_signatures']);
    }

    /**
     * Deterministic sha256 of the on-disk source for a sacred path, or the
     * literal 'missing' sentinel when the file is absent (never throws — a
     * read-only registry must never crash the screening path).
     */
    private function fingerprint(string $relPath): string
    {
        $abs = $this->baseDir().DIRECTORY_SEPARATOR.$relPath;
        if (! is_file($abs) || ! is_readable($abs)) {
            return 'missing';
        }
        $contents = @file_get_contents($abs);
        if ($contents === false) {
            return 'unreadable';
        }

        return hash('sha256', $contents);
    }

    private function baseDir(): string
    {
        if ($this->baseDirOverride !== null) {
            return rtrim($this->baseDirOverride, DIRECTORY_SEPARATOR);
        }

        if (function_exists('base_path')) {
            return rtrim(base_path(), DIRECTORY_SEPARATOR);
        }

        return getcwd() ?: '.';
    }

    /**
     * Normalize a path for comparison: forward slashes, no leading ./, no
     * surrounding whitespace, trimmed trailing slash.
     */
    private function normalize(string $path): string
    {
        $p = trim($path);
        $p = str_replace('\\', '/', $p);
        $p = preg_replace('#^\./#', '', $p) ?? $p;

        return rtrim($p, '/');
    }
}
