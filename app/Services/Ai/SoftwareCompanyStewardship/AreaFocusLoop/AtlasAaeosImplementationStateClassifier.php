<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Derives the AAEOS implementation_state of a documented area from its evidence
 * signals, never from the self-declared frontmatter string.
 *
 * The decision tree mirrors the Implementation Reality contract table:
 * runtime_verified requires code + command/route + green test + receipt/AP/ledger
 * with no open blocker; any partial runtime evidence is implemented_partial; the
 * absence of every runtime signal collapses to spec_only (or north_star when the
 * area only declares strategic direction).
 */
final class AtlasAaeosImplementationStateClassifier
{
    public const SCHEMA = 'atlas.software_company_stewardship.aaeos_implementation_state.v1';

    public const IMPLEMENTATION_REALITY_CANONICAL = 'docs/engineering-knowledge-base/atlas-agentic-engineering-os-implementation-reality.md';

    public const EVIDENCE_REF = 'atlas-agentic-engineering-os-implementation-reality.md:157';

    public const STATE_RUNTIME_VERIFIED = 'runtime_verified';

    public const STATE_IMPLEMENTED_PARTIAL = 'implemented_partial';

    public const STATE_SPEC_ONLY = 'spec_only';

    public const STATE_NORTH_STAR = 'north_star';

    private const VERDICT_ADMIT_IN_SCOPE = 'admit_in_scope';

    private const VERDICT_ADMIT_WITH_CAVEATS = 'admit_with_caveats';

    private const VERDICT_REJECT = 'reject';

    private function __construct(
        private readonly bool $hasCodePath,
        private readonly bool $hasCommandOrRoute,
        private readonly bool $hasGreenTest,
        private readonly bool $hasReceiptOrApOrLedger,
        private readonly bool $hasOpenBlocker,
        private readonly ?string $declaredState,
    ) {
    }

    /**
     * @param array{
     *     has_code_path?: bool,
     *     has_command_or_route?: bool,
     *     has_green_test?: bool,
     *     has_receipt_or_ap_or_ledger?: bool,
     *     has_open_blocker?: bool,
     *     declared_state?: string|null
     * } $signals
     */
    public static function fromArray(array $signals): self
    {
        return new self(
            self::boolSignal($signals, 'has_code_path'),
            self::boolSignal($signals, 'has_command_or_route'),
            self::boolSignal($signals, 'has_green_test'),
            self::boolSignal($signals, 'has_receipt_or_ap_or_ledger'),
            self::boolSignal($signals, 'has_open_blocker'),
            self::declaredState($signals),
        );
    }

    /**
     * @return array{
     *     schema_version: string,
     *     implementation_reality_canonical: string,
     *     evidence_ref: string,
     *     inputs: array{
     *         has_code_path: bool,
     *         has_command_or_route: bool,
     *         has_green_test: bool,
     *         has_receipt_or_ap_or_ledger: bool,
     *         has_open_blocker: bool,
     *         declared_state: string|null
     *     },
     *     outputs: array{
     *         computed_state: string,
     *         can_be_called_ready: bool,
     *         loop_admissible: bool,
     *         loop_verdict: string,
     *         reason_code: string,
     *         declared_vs_computed_drift: bool
     *     }
     * }
     */
    public function toArray(): array
    {
        $computedState = $this->computeState();

        return [
            'schema_version' => self::SCHEMA,
            'implementation_reality_canonical' => self::IMPLEMENTATION_REALITY_CANONICAL,
            'evidence_ref' => self::EVIDENCE_REF,
            'inputs' => [
                'has_code_path' => $this->hasCodePath,
                'has_command_or_route' => $this->hasCommandOrRoute,
                'has_green_test' => $this->hasGreenTest,
                'has_receipt_or_ap_or_ledger' => $this->hasReceiptOrApOrLedger,
                'has_open_blocker' => $this->hasOpenBlocker,
                'declared_state' => $this->declaredState,
            ],
            'outputs' => [
                'computed_state' => $computedState,
                'can_be_called_ready' => $computedState === self::STATE_RUNTIME_VERIFIED,
                'loop_admissible' => $computedState === self::STATE_IMPLEMENTED_PARTIAL,
                'loop_verdict' => $this->loopVerdictFor($computedState),
                'reason_code' => $this->reasonCodeFor($computedState),
                'declared_vs_computed_drift' => $this->declaredState !== null
                    && $this->declaredState !== $computedState,
            ],
        ];
    }

    private function computeState(): string
    {
        if ($this->hasFullRuntimeProof()) {
            return self::STATE_RUNTIME_VERIFIED;
        }

        if ($this->hasAnyRuntimeEvidence()) {
            return self::STATE_IMPLEMENTED_PARTIAL;
        }

        if ($this->declaredState === self::STATE_NORTH_STAR) {
            return self::STATE_NORTH_STAR;
        }

        return self::STATE_SPEC_ONLY;
    }

    private function hasFullRuntimeProof(): bool
    {
        return $this->hasCodePath
            && $this->hasCommandOrRoute
            && $this->hasGreenTest
            && $this->hasReceiptOrApOrLedger
            && ! $this->hasOpenBlocker;
    }

    private function hasAnyRuntimeEvidence(): bool
    {
        return $this->hasCodePath
            || $this->hasCommandOrRoute
            || $this->hasGreenTest
            || $this->hasReceiptOrApOrLedger;
    }

    private function loopVerdictFor(string $computedState): string
    {
        return match ($computedState) {
            self::STATE_RUNTIME_VERIFIED => self::VERDICT_ADMIT_IN_SCOPE,
            self::STATE_IMPLEMENTED_PARTIAL => self::VERDICT_ADMIT_WITH_CAVEATS,
            default => self::VERDICT_REJECT,
        };
    }

    private function reasonCodeFor(string $computedState): string
    {
        return match ($computedState) {
            self::STATE_RUNTIME_VERIFIED => 'full_runtime_evidence_no_blocker',
            self::STATE_IMPLEMENTED_PARTIAL => $this->hasOpenBlocker
                ? 'partial_runtime_open_blocker'
                : 'partial_runtime_incomplete_proof',
            self::STATE_NORTH_STAR => 'no_runtime_strategic_direction',
            default => 'no_runtime_spec_governs_future',
        };
    }

    /**
     * @param array<string, mixed> $signals
     */
    private static function boolSignal(array $signals, string $key): bool
    {
        return ($signals[$key] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $signals
     */
    private static function declaredState(array $signals): ?string
    {
        $declared = $signals['declared_state'] ?? null;

        return is_string($declared) ? $declared : null;
    }
}
