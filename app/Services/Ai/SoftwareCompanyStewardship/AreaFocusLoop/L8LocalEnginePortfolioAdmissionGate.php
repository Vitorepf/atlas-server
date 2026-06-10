<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class L8LocalEnginePortfolioAdmissionGate
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.local_engine_portfolio_admission.v1';

    /**
     * Privacy classes that must never leave the machine and therefore demand an
     * explicit local-first commitment from the candidate before admission.
     */
    private const LOCAL_FIRST_PRIVACY_CLASSES = ['sensitive', 'secret', 'cyber'];

    /**
     * Admit a local distilled engine candidate as one governed provider port,
     * never as new authority. The candidate only joins the portfolio when its
     * measured quality is at least the external path, a fallback exists, and any
     * sensitive privacy class carries an explicit local-first flag.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $evaluation
     * @return array{
     *     schema_version: string,
     *     admitted: bool,
     *     provider_port_contract_required: bool,
     *     quality_delta: float,
     *     privacy_gate_status: string,
     *     fallback_required: bool,
     *     blockers: list<string>
     * }
     */
    public function admit(array $candidate, array $evaluation): array
    {
        $privacyClass = $this->privacyClass($candidate);
        $localFirstRequired = $this->requiresLocalFirst($privacyClass);

        $localQuality = AreaFocusScalarNormalizer::payloadFiniteFloat($evaluation, 'local_quality_score', 0.0);
        $externalQuality = AreaFocusScalarNormalizer::payloadFiniteFloat($evaluation, 'external_path_quality_score', 0.0);
        $qualityDelta = $this->roundDelta($localQuality - $externalQuality);

        $hasFallback = $this->hasFallback($candidate, $evaluation);
        $hasLocalFirstFlag = $this->hasLocalFirstFlag($candidate);

        $blockers = [];

        if ($qualityDelta < 0.0) {
            $blockers[] = 'quality_below_external_path';
        }

        if (! $hasFallback) {
            $blockers[] = 'fallback_required';
        }

        if ($localFirstRequired && ! $hasLocalFirstFlag) {
            $blockers[] = 'sensitive_class_requires_local_first';
        }

        $admitted = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'admitted' => $admitted,
            'provider_port_contract_required' => true,
            'quality_delta' => $qualityDelta,
            'privacy_gate_status' => $this->privacyGateStatus(
                $localFirstRequired,
                $hasLocalFirstFlag,
            ),
            'fallback_required' => ! $hasFallback,
            'blockers' => $blockers,
        ];
    }

    private function privacyClass(array $candidate): string
    {
        $value = $candidate['privacy_class'] ?? 'general';

        if (! is_string($value)) {
            return 'general';
        }

        $normalized = strtolower(trim($value));

        return $normalized === '' ? 'general' : $normalized;
    }

    private function requiresLocalFirst(string $privacyClass): bool
    {
        return in_array($privacyClass, self::LOCAL_FIRST_PRIVACY_CLASSES, true);
    }

    private function hasLocalFirstFlag(array $candidate): bool
    {
        return ($candidate['local_first'] ?? false) === true
            || ($candidate['local_first_required'] ?? false) === true;
    }

    private function hasFallback(array $candidate, array $evaluation): bool
    {
        if (($candidate['fallback_provider'] ?? null) !== null
            && $candidate['fallback_provider'] !== '') {
            return true;
        }

        if (($evaluation['fallback_provider'] ?? null) !== null
            && $evaluation['fallback_provider'] !== '') {
            return true;
        }

        return ($candidate['fallback_available'] ?? false) === true
            || ($evaluation['fallback_available'] ?? false) === true;
    }

    private function privacyGateStatus(bool $localFirstRequired, bool $hasLocalFirstFlag): string
    {
        if (! $localFirstRequired) {
            return 'not_required';
        }

        return $hasLocalFirstFlag ? 'satisfied' : 'blocked';
    }

    private function roundDelta(float $delta): float
    {
        return round($delta, 6);
    }
}
