<?php

declare(strict_types=1);

namespace App\Services\Ai\Product;

/** Deterministic objections only; this probe can never admit an intent. */
final class ProductIntentFalsificationProbe
{
    /** @return list<string> */
    public function objections(ProductIntentCase $case): array
    {
        $data = $case->data;
        $objections = [];
        $metric = strtolower((string) ($data['metric'] ?? ''));
        $request = strtolower((string) ($data['human_request'] ?? ''));
        $window = strtolower((string) ($data['observation_window'] ?? ''));
        $risk = (int) substr((string) ($data['risk_class'] ?? 'R0'), 1);

        $sourceClaims = [];
        foreach ((array) ($data['source_refs'] ?? []) as $sourceRef) {
            if (preg_match('/^source:([^:]+):(supports|contradicts)$/i', (string) $sourceRef, $match) === 1) {
                $sourceClaims[strtolower($match[1])][] = strtolower($match[2]);
            }
        }
        foreach ($sourceClaims as $claims) {
            if (in_array('supports', $claims, true) && in_array('contradicts', $claims, true)) {
                $objections[] = 'contradictory_sources';
                break;
            }
        }

        if ($metric !== '' && preg_match('/\b(downloads?|likes?|impressions?|pageviews?|followers?)\b/', $metric) === 1) {
            $objections[] = 'metric_vanity_or_proxy';
        }
        if ($window !== '' && (preg_match('/^(?:0|[0-9]+(?:\.[0-9]+)?)\s*(?:h|d|w|m)$/', $window) !== 1
            || (float) preg_replace('/[^0-9.]/', '', $window) <= 0)) {
            $objections[] = 'observation_window_impossible';
        }
        $metricUser = trim((string) ($data['metric_user'] ?? ''));
        $user = strtolower(trim((string) ($data['user'] ?? '')));
        if ($metricUser !== '' && strtolower($metricUser) !== $user) {
            $objections[] = 'proxy_user_mismatch';
        }
        if ($risk >= 3 && ($data['non_goals'] ?? []) === []) {
            $objections[] = 'non_goal_missing';
        }
        if (($data['hidden_non_goals'] ?? []) !== []) {
            $objections[] = 'hidden_non_goal';
        }
        if ($risk >= 3 && preg_match('/\b(password|senha|token|secret|personal data|dados pessoais|privacy|privacidade|security|seguran)\b/', $request) === 1) {
            $constraints = strtolower(implode(' ', array_map('strval', (array) ($data['constraints'] ?? []))));
            $acceptance = strtolower(implode(' ', array_map('strval', (array) ($data['acceptance'] ?? []))));
            if (preg_match('/\b(security|seguran|privacy|privacidade|secret|secrets|personal data|dados pessoais)\b/', $constraints.' '.$acceptance) !== 1) {
                $objections[] = 'privacy_security_omission';
            }
        }
        if ($risk >= 3 && ($data['alternatives'] ?? []) === []) {
            $objections[] = 'alternatives_missing';
        }
        foreach ((array) ($data['alternatives'] ?? []) as $alternative) {
            if (preg_match('/^(?:alternative:)?dominates(?::|\s)/i', (string) $alternative) === 1) {
                $objections[] = 'alternative_dominates';
                break;
            }
        }

        sort($objections, SORT_STRING);

        return array_values(array_unique($objections));
    }

    /** Provider/model findings may object, but can never turn a case into admitted. */
    public function mergeExternalObjections(array $deterministic, array $external): array
    {
        $all = array_values(array_filter(array_merge($deterministic, array_map('strval', $external))));
        sort($all, SORT_STRING);

        return array_values(array_unique($all));
    }
}
