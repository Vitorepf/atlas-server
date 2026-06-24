<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Autopoiesis\ScopeOrigination;

use Throwable;

final class AtlasLoopScopeOriginationAuditor
{
    private const REQUIRED_SOURCES = [
        'cortex_meaning',
        'loop_telemetry',
        'maestro_outcomes',
        'operator_intent',
    ];

    private const FORBIDDEN_PATH_MARKERS = [
        'aa e os',
        'aaeos',
        'desktop',
        'forge',
        'marketingdomain',
        'marketing_domain',
    ];

    private const ALLOWED_SCOPE_MARKERS = [
        'autopoiesis',
        'cortex',
        'loop',
        'maestro',
    ];

    public function audit(ScopeProposal $p): ScopeOriginationVerdict
    {
        $coherence = $this->coherence($p);
        $scopeViolation = $this->scopeViolation($p);
        if ($scopeViolation !== null) {
            return ScopeOriginationVerdict::rejected('scope_guard', 'scope_violation', null, $coherence);
        }

        $threshold = $this->autoApproveMinCoherence();
        if ($threshold === INF) {
            return ScopeOriginationVerdict::pending('auto_approve_off', $coherence);
        }

        if (! $this->hasAllRequiredSources($p)) {
            return ScopeOriginationVerdict::pending('missing_living_signal_source', $coherence);
        }

        if ($coherence === null || $coherence < $threshold) {
            return ScopeOriginationVerdict::pending('coherence_below_floor', $coherence);
        }

        return ScopeOriginationVerdict::autoApproved($coherence);
    }

    public function approve(ScopeProposal $p, string $reason, string $timestamp): ScopeOriginationVerdict
    {
        $coherence = $this->coherence($p);
        if ($this->scopeViolation($p) !== null) {
            return ScopeOriginationVerdict::rejected('scope_guard', 'scope_violation', $timestamp, $coherence);
        }

        return ScopeOriginationVerdict::operatorApproved($reason, $timestamp, $coherence);
    }

    public function reject(ScopeProposal $p, string $reason, string $timestamp): ScopeOriginationVerdict
    {
        return ScopeOriginationVerdict::operatorRejected($reason, $timestamp, $this->coherence($p));
    }

    private function autoApproveMinCoherence(): float
    {
        if (! function_exists('config')) {
            return INF;
        }

        try {
            $configured = config('atlas.autopoiesis.auto_approve_min_coherence', INF);
        } catch (Throwable) {
            return INF;
        }

        if ($configured === false || $configured === null || $configured === '' || strtoupper((string) $configured) === 'OFF') {
            return INF;
        }

        return is_numeric($configured) ? (float) $configured : INF;
    }

    private function hasAllRequiredSources(ScopeProposal $p): bool
    {
        $sources = [];
        foreach ($p->toArray()['fact_refs'] as $factRef) {
            $source = trim((string) ($factRef['source'] ?? ''));
            if ($source !== '') {
                $sources[$source] = true;
            }
        }

        foreach (self::REQUIRED_SOURCES as $source) {
            if (! isset($sources[$source])) {
                return false;
            }
        }

        return true;
    }

    private function coherence(ScopeProposal $p): ?float
    {
        $signal = (string) ($p->toArray()['expected_leverage_signal'] ?? '');
        if (! str_starts_with($signal, 'coherence:')) {
            return null;
        }

        $value = substr($signal, strlen('coherence:'));

        return is_numeric($value) ? (float) $value : null;
    }

    private function scopeViolation(ScopeProposal $p): ?string
    {
        foreach ($p->toArray()['target_paths'] as $path) {
            $normalized = strtolower(str_replace('\\', '/', (string) $path));

            foreach (self::FORBIDDEN_PATH_MARKERS as $marker) {
                if (str_contains($normalized, $marker)) {
                    return (string) $path;
                }
            }

            $allowed = false;
            foreach (self::ALLOWED_SCOPE_MARKERS as $marker) {
                if (str_contains($normalized, $marker)) {
                    $allowed = true;
                    break;
                }
            }

            if (! $allowed) {
                return (string) $path;
            }
        }

        return null;
    }
}
