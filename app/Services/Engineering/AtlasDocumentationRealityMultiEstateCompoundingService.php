<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Services\Ai\AtlasDomainProfileRegistry;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;

/**
 * L2-O3 — Multi-estate Compounding (first increment, the LAST L2 capability): the
 * CROSS-ESTATE IMMUNITY PROPAGATION PROPOSER.
 *
 * L0/L1 verify EXECUTION ("does the code match the doc?"). O1 grades the OUTCOME
 * ("did it work in the world?"). O2 asks "is this the RIGHT thing to build?". O3
 * makes a learning COMPOUND across the operator's whole estate: when P3 synthesises
 * an antibody (a detector/gate pattern) from a rot that escaped in ONE project, O3
 * proposes immunising the operator's OTHER estates/projects with the same antibody —
 * so a rot caught once protects all.
 *
 * THE CARDINAL RULE (ABSOLUTE — local-first sovereignty;
 * atlas-documentation-reality-outcome-grounded-leap.md:81, :169, :192 — "Vazar
 * verdade canonica sensivel entre projetos; classes sensitive/secret/cyber nao saem
 * da maquina"): the sovereignty data classes sensitive, secret, cyber NEVER cross an
 * estate boundary. Only the ABSTRACT, domain-agnostic PATTERN (the detector logic —
 * a public/internal class) may be proposed for propagation; any sensitive/secret/
 * cyber specifics STAY LOCAL. The crossing payload carries ONLY domain-agnostic
 * pattern fields — never raw failure content, paths, secrets, or evidence excerpts.
 *
 * The blocked-class list is NOT invented here: it is the Constitutional Kernel's
 * authoritative {@see AtlasConstitutionalKernelService::SENSITIVE_CLASSES}
 * (sensitive/secret/cyber), enforced by the petreo invariant `sovereignty_local_first`
 * ("sensitive/secret/cyber data never leaves the machine"). O3 reuses that single
 * source of truth so the sovereignty boundary cannot drift from the kernel.
 *
 * CRITICAL SAFETY: like P2/P3 this is strictly READ-ONLY and a PROPOSER. It NEVER
 * auto-propagates, installs, transmits, or writes anything; it NEVER sends an
 * antibody to another machine or estate. It only PRODUCES a proposal that a HUMAN
 * approves per cross-estate move. There is deliberately NO transmission, no network
 * call, no file write — proposing is the whole job.
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-multi-estate-compounding.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-outcome-grounded-leap.md
 */
class AtlasDocumentationRealityMultiEstateCompoundingService
{
    public const SCHEMA = 'atlas.documentation_reality.multi_estate.v1';

    /**
     * The default antibody source data class when NONE is stated. FAIL-CLOSED: an
     * unstated origin class is NOT crossable — the caller must EXPLICITLY declare
     * public/internal for even the abstract pattern to cross. A blank/missing class
     * must never silently default to a crossable one.
     */
    public const DEFAULT_DATA_CLASS = 'unstated';

    /**
     * The detector kinds whose NAME is safe to cross (a fixed vocabulary). A proposed
     * detector kind outside this set is mapped to `unclassified_detector`, so no
     * caller free-text (which could embed a secret/path/token) ever crosses verbatim.
     *
     * @var array<int,string>
     */
    private const ALLOWED_DETECTOR_KINDS = [
        'gate_check', 'frontmatter_rule', 'drift_rule', 'static_scan', 'contract_check', 'reality_check',
    ];

    /**
     * The single reason a propagation is blocked: the antibody's source data class is
     * one the Constitutional Kernel forbids from ever leaving the machine.
     */
    public const BLOCKED_REASON = 'sovereignty_class_must_not_leave_machine';

    /**
     * The only data classes whose ABSTRACT pattern is allowed to cross an estate
     * boundary. Everything else (including any unrecognised class) is treated
     * conservatively as non-crossing. `public`/`internal` mirror the provider-safe
     * classes used across the privacy/trust layer; the kernel's sensitive/secret/
     * cyber are NEVER in this list.
     */
    private const CROSSABLE_CLASSES = ['public', 'internal'];

    public function __construct(
        private readonly AtlasDomainProfileRegistry $domains,
    ) {}

    /**
     * Propose cross-estate propagation of ONE antibody. Given an antibody (the P3
     * shape, or a plain {failure_kind, reproducing_test_outline, proposed_detector})
     * + a target estate/domain list + the antibody's SOURCE data class, return a
     * read-only propagation proposal.
     *
     * THE SOVEREIGNTY GATE (the heart): classify the source data class.
     *   - sensitive|secret|cyber => cross_estate_allowed=false, the antibody STAYS
     *     LOCAL, blocked_reason=sovereignty_class_must_not_leave_machine,
     *     target_estates=[], what_crosses=null (NOTHING crosses).
     *   - public|internal => only the ABSTRACT pattern (detector kind/description +
     *     the reproducing-test SHAPE, with NO sensitive specifics) is proposed to
     *     cross to the applicable estates.
     *
     * In ALL cases this only PROPOSES; it transmits nothing, writes nothing, and a
     * human approves each move.
     *
     * @param  array<string,mixed>  $antibody
     * @param  array<int,mixed>  $estates
     * @return array<string,mixed>
     */
    public function proposePropagation(array $antibody, array $estates = [], string $dataClass = self::DEFAULT_DATA_CLASS): array
    {
        $sourceDataClass = $this->classify($dataClass);
        $crossAllowed = $this->isCrossable($sourceDataClass);

        $abstractPattern = $this->abstractPattern($antibody);
        $requestedEstates = $this->normalizeEstates($estates);

        // The labels (never the content) of what is kept on the machine. This is the
        // same list whether or not the class crosses: for a blocked class the WHOLE
        // antibody (these specifics included) stays local; for a crossable class only
        // the abstract pattern leaves and these specifics still stay local.
        $whatStaysLocal = $this->whatStaysLocalLabels();

        if (! $crossAllowed) {
            // SOVEREIGNTY GATE — blocked. Nothing crosses. The antibody stays local.
            $envelope = [
                'schema_version' => self::SCHEMA,
                'mode' => 'cross_estate_immunity_propagation_proposer',
                'level' => 'L2-O3',
                'increment' => 'cross_estate_propagation_proposer_read_only',
                'antibody_ref' => $abstractPattern['antibody_ref'],
                'source_data_class' => $sourceDataClass,
                'cross_estate_allowed' => false,
                'what_crosses' => null,
                'what_stays_local' => $whatStaysLocal,
                'target_estates' => [],
                'requested_estates' => $requestedEstates,
                'blocked_reason' => self::BLOCKED_REASON,
                'sovereignty' => $this->sovereignty(),
                'writes' => false,
                'claim_policy' => $this->claimPolicy(),
            ];

            return $this->finalize($envelope);
        }

        // SOVEREIGNTY GATE — allowed. ONLY the abstract, domain-agnostic pattern is
        // proposed to cross. The sensitive specifics still stay local (labels only).
        $targetEstates = $this->applicableEstates($requestedEstates);

        $envelope = [
            'schema_version' => self::SCHEMA,
            'mode' => 'cross_estate_immunity_propagation_proposer',
            'level' => 'L2-O3',
            'increment' => 'cross_estate_propagation_proposer_read_only',
            'antibody_ref' => $abstractPattern['antibody_ref'],
            'source_data_class' => $sourceDataClass,
            'cross_estate_allowed' => true,
            'what_crosses' => $abstractPattern,
            'what_stays_local' => $whatStaysLocal,
            'target_estates' => $targetEstates,
            'requested_estates' => $requestedEstates,
            'blocked_reason' => null,
            'sovereignty' => $this->sovereignty(),
            'writes' => false,
            'claim_policy' => $this->claimPolicy(),
        ];

        return $this->finalize($envelope);
    }

    /**
     * Build the ABSTRACT, domain-agnostic pattern — the ONLY thing allowed to cross.
     * It carries the detector KIND + a generalised description and the reproducing-
     * test SHAPE (its structure, not its content). It deliberately strips every
     * concrete specific: no raw failure content, no file paths, no error excerpts, no
     * secrets, no evidence. What lands here is safe to propose for any estate because
     * it describes a CLASS of escape, not a single project's data.
     *
     * @param  array<string,mixed>  $antibody
     * @return array<string,mixed>
     */
    private function abstractPattern(array $antibody): array
    {
        $detector = $this->arr($antibody['proposed_detector'] ?? []);
        $reproducing = $this->arr($antibody['reproducing_test_outline'] ?? []);

        // SANITIZE every crossing field to a fixed vocabulary / strict slug. Caller
        // free-text is NEVER echoed across an estate boundary, so a secret, path, or
        // token hidden in failure_kind, detector.kind or detector.where cannot leak.
        $detectorKind = $this->vocab($detector['kind'] ?? null, self::ALLOWED_DETECTOR_KINDS, 'unclassified_detector');
        $failureKind = $this->slugKind($antibody['failure_kind'] ?? ($antibody['failure_class'] ?? null), 'unclassified_failure');
        // detector.where is free text — it is NEVER echoed; the plug-in point is
        // SYNTHESIZED from the already-sanitized detector kind.
        $plugInPoint = $this->plugInPointFor($detectorKind);

        $pattern = [
            // Content-free ref: a hash of the SANITIZED abstract shape only.
            'antibody_ref' => 'antibody:'.substr(hash('sha256', $failureKind.'|'.$detectorKind.'|'.$plugInPoint), 0, 16),
            'detector_kind' => $detectorKind,
            'failure_kind' => $failureKind,
            'pattern_description' => $this->abstractDetectorDescription($detectorKind, $failureKind),
            'plug_in_point' => $plugInPoint,
            // The reproducing-test SHAPE only: the structural skeleton generalised to
            // "this CLASS of escape", with NO reproducing specifics.
            'reproducing_test_shape' => $this->reproducingTestShape($reproducing, $failureKind),
            'is_abstract_pattern_only' => true,
        ];

        // EARNED guarantee (not a constant): scrub the assembled crossing payload for
        // any residual secret-shaped content. If a sanitizer ever let something
        // through, it is redacted and the flag is set false — a reviewer is never
        // falsely reassured by an always-true marker.
        [$pattern, $clean] = $this->scrubResidualSecrets($pattern);
        $pattern['carries_no_sensitive_specifics'] = $clean;

        return $pattern;
    }

    /**
     * Map a value to a fixed vocabulary, or a safe default — never echoes free-text.
     *
     * @param  array<int,string>  $allowed
     */
    private function vocab(mixed $value, array $allowed, string $default): string
    {
        $v = strtolower(trim((string) $value));

        return in_array($v, $allowed, true) ? $v : $default;
    }

    /**
     * Keep a value ONLY if it is a short snake_case identifier (a real "kind"); reject
     * anything with spaces, dashes, slashes, '=' or other secret/path/token shapes to
     * the safe default. A leaked secret can never survive this as a "kind".
     */
    private function slugKind(mixed $value, string $default): string
    {
        $v = strtolower(trim((string) $value));

        return preg_match('/^[a-z][a-z0-9_]{1,39}$/', $v) === 1 ? $v : $default;
    }

    /**
     * Defense-in-depth: redact any residual secret-shaped content (api keys, tokens,
     * PASSWORD=/SECRET=, file paths, emails, long hex) from the crossing payload.
     * Returns [scrubbed_payload, was_already_clean].
     *
     * @param  array<string,mixed>  $pattern
     * @return array{0: array<string,mixed>, 1: bool}
     */
    private function scrubResidualSecrets(array $pattern): array
    {
        $res = [
            '/sk-[a-z0-9_-]{8,}/i',
            '/AKIA[0-9A-Z]{8,}/',
            '/[A-Za-z0-9_]*(?:password|secret|token|api[_-]?key)[A-Za-z0-9_]*\s*[=:]\s*\S+/i',
            '#(?:/[A-Za-z0-9._-]+){2,}#',
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/',
            '/\b[A-Fa-f0-9]{32,}\b/',
        ];
        $clean = true;
        $scrub = function (mixed $v) use (&$clean, $res, &$scrub): mixed {
            if (is_array($v)) {
                return array_map($scrub, $v);
            }
            if (! is_string($v)) {
                return $v;
            }
            $out = $v;
            foreach ($res as $re) {
                $out = preg_replace($re, '[redacted]', $out) ?? $out;
            }
            if ($out !== $v) {
                $clean = false;
            }

            return $out;
        };

        /** @var array<string,mixed> $scrubbed */
        $scrubbed = $scrub($pattern);

        return [$scrubbed, $clean];
    }

    /**
     * A generalised, domain-agnostic description of the detector pattern. It names
     * the CLASS of escape and the kind of gate, never the original project's data.
     */
    private function abstractDetectorDescription(string $detectorKind, string $failureKind): string
    {
        return "Domain-agnostic {$detectorKind} pattern that catches the '{$failureKind}' class of escape before it ships. "
            .'This describes the detector LOGIC only; it carries no project-specific content, path, secret, or evidence.';
    }

    /**
     * The reproducing-test SHAPE: the structure a reproducing test for this class of
     * escape would take, generalised. We deliberately keep ONLY the skeleton (arrange/
     * act/assert intent + the must-fail-before-fix invariant) and drop every concrete
     * step, fixture, path, or excerpt that the source antibody's outline may contain.
     *
     * @param  array<string,mixed>  $reproducing
     * @return array<string,mixed>
     */
    private function reproducingTestShape(array $reproducing, string $failureKind): array
    {
        return [
            'description' => "Reproduce the '{$failureKind}' class of escape FIRST so the antibody fails on the unpatched system, then add the detector.",
            'skeleton' => [
                'arrange' => 'set up the inputs that reproduce THIS class of escape in the target estate (estate-local specifics supplied locally)',
                'act' => 'exercise the same class of trigger that slips past the gates',
                'assert' => 'assert the failing behaviour is caught (RED before fix, GREEN after) — this is the regression lock',
            ],
            'must_fail_before_fix' => ($reproducing['must_fail_before_fix'] ?? true) === true,
            // Proof, in the shape itself, that no concrete reproducing content crossed.
            'carries_no_reproducing_specifics' => true,
        ];
    }

    /**
     * The LABELS (never the content) of the specifics that stay on the machine. This
     * is the load-bearing privacy guarantee: we enumerate WHICH kinds of specifics are
     * withheld, but never the specifics themselves. True for both branches — for a
     * blocked class the whole antibody stays local; for a crossable class these stay
     * local while only the abstract pattern leaves.
     *
     * @return array<int,string>
     */
    private function whatStaysLocalLabels(): array
    {
        return [
            'raw_failure_content',
            'file_paths',
            'error_excerpts',
            'secrets_and_credentials',
            'evidence_excerpts',
            'reproducing_test_concrete_steps',
            'project_or_estate_identifying_detail',
        ];
    }

    /**
     * Resolve the applicable target estates from the operator's domain/estate registry.
     * When the caller names estates we keep the ones that resolve to a real domain in
     * the registry catalog; when the caller names none we propose the full active
     * estate set (every domain is a candidate to immunise). This only PROPOSES targets
     * — nothing is sent to any of them.
     *
     * @param  array<int,string>  $requestedEstates
     * @return array<int,array<string,string>>
     */
    private function applicableEstates(array $requestedEstates): array
    {
        $catalogIds = $this->catalogEstateIds();

        $resolved = [];
        if ($requestedEstates !== []) {
            foreach ($requestedEstates as $estate) {
                $resolved[$estate] = [
                    'estate' => $estate,
                    'resolved' => in_array($estate, $catalogIds, true) ? 'true' : 'false',
                ];
            }

            return array_values($resolved);
        }

        // No estates named: propose every active estate from the registry as a
        // candidate. Degrade-safe — if the catalog is empty we propose nothing rather
        // than fabricate a target.
        foreach ($catalogIds as $estate) {
            $resolved[$estate] = ['estate' => $estate, 'resolved' => 'true'];
        }

        return array_values($resolved);
    }

    /**
     * The active estate/domain ids from the operator's registry catalog.
     *
     * @return array<int,string>
     */
    private function catalogEstateIds(): array
    {
        $catalog = $this->domains->catalog();
        $ids = [];
        foreach ((array) ($catalog['domains'] ?? []) as $domain) {
            $id = $this->str(is_array($domain) ? ($domain['id'] ?? null) : null);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Classify the antibody's source data class into the canonical sovereignty
     * vocabulary. Unknown/empty input is treated conservatively: it is NOT forced to a
     * crossable class — it falls through as-is, and only public/internal are ever
     * allowed to cross, so anything unrecognised cannot leak.
     */
    private function classify(string $dataClass): string
    {
        $dataClass = strtolower(trim($dataClass));

        return $dataClass !== '' ? $dataClass : self::DEFAULT_DATA_CLASS;
    }

    /**
     * Is this source data class allowed to have its ABSTRACT pattern cross an estate
     * boundary? ONLY public/internal are. The Constitutional Kernel's
     * SENSITIVE_CLASSES (sensitive/secret/cyber) are the authoritative blocked set and
     * are NEVER crossable; any class not explicitly in CROSSABLE_CLASSES is also
     * treated as non-crossing (fail-closed).
     */
    private function isCrossable(string $sourceDataClass): bool
    {
        // Fail-closed against the kernel's authoritative blocked list first.
        if (in_array($sourceDataClass, AtlasConstitutionalKernelService::SENSITIVE_CLASSES, true)) {
            return false;
        }

        // Then allow ONLY the explicit crossable classes; everything else is withheld.
        return in_array($sourceDataClass, self::CROSSABLE_CLASSES, true);
    }

    /**
     * The mandatory sovereignty block, present on EVERY response. These flags are the
     * load-bearing guarantees the doc names as ABSOLUTE: sensitive/secret/cyber never
     * cross, only the abstract pattern crosses, read-only, never auto-propagates,
     * human-gated.
     *
     * @return array<string,bool>
     */
    private function sovereignty(): array
    {
        return [
            'sensitive_secret_cyber_never_cross' => true,
            'only_abstract_pattern_crosses' => true,
            'read_only' => true,
            'auto_propagates' => false,
            'human_gated' => true,
        ];
    }

    /**
     * claim_policy MIRRORS the sovereignty guarantees (plus the read-only/no-transmit
     * ones), so a reader checking either block reaches the same conclusion: this never
     * crosses sensitive data, never auto-propagates, never transmits, never writes.
     *
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'writes' => false,
            'executes' => false,
            'mutates' => false,
            'auto_propagates' => false,
            'installs_antibody' => false,
            'transmits_cross_machine' => false,
            'sensitive_secret_cyber_never_cross' => true,
            'only_abstract_pattern_crosses' => true,
            'human_gated' => true,
            'reuses_kernel_sovereignty_classes' => true,
        ];
    }

    /**
     * Hash the read-only envelope for tamper-evidence, excluding the volatile
     * generated_at the command adds and the hash field itself.
     *
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function finalize(array $envelope): array
    {
        $hashPayload = $envelope;
        unset($hashPayload['generated_at'], $hashPayload['propagation_hash']);
        $envelope['propagation_hash'] = hash(
            'sha256',
            json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $envelope;
    }

    private function plugInPointFor(string $detectorKind): string
    {
        return match ($detectorKind) {
            'frontmatter_rule' => 'docs-health frontmatter validation',
            'drift_rule' => 'the maturity/drift ledger',
            'static_scan' => 'the static-analysis / architecture-validate layer',
            default => 'the gate that lets this class of escape through',
        };
    }

    /**
     * @param  array<int,mixed>  $estates
     * @return array<int,string>
     */
    private function normalizeEstates(array $estates): array
    {
        $out = [];
        foreach ($estates as $estate) {
            $value = $this->str($estate);
            if ($value !== null) {
                $out[] = strtolower($value);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<string,mixed>
     */
    private function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function str(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
