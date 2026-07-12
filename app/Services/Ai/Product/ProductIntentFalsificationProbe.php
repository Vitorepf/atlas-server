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

        if ($metric !== '' && preg_match('/\b(downloads?|likes?|impressions?|pageviews?|followers?)\b/', $metric) === 1) {
            $objections[] = 'metric_vanity_or_proxy';
        }
        if ($window !== '' && (preg_match('/^(?:0|[0-9]+(?:\.[0-9]+)?)\s*(?:h|d|w|m)$/', $window) !== 1
            || (float) preg_replace('/[^0-9.]/', '', $window) <= 0)) {
            $objections[] = 'observation_window_impossible';
        }
        if ($risk >= 3 && ($data['non_goals'] ?? []) === []) {
            $objections[] = 'non_goal_missing';
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
