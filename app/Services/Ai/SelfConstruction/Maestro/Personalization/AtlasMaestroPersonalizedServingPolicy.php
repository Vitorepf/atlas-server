<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Personalization;

/**
 * ADVISORY-only personalised serving policy.
 *
 * Computes a structured shape_match record between a candidate task packet (file count, LOC
 * budget, declared tier hint) and the declared preferences from
 * {@see AtlasMaestroWorkerPreferenceRegistry}.
 *
 * STRICT advisory contract:
 *   - decide() always returns `advisory => true` — even on 0.0 score.
 *   - decide() NEVER throws.
 *   - decide() NEVER mutates queue or filters packets.
 *
 * The caller decides; this is just a sidecar opinion.
 */
final class AtlasMaestroPersonalizedServingPolicy
{
    public function __construct(private readonly AtlasMaestroWorkerPreferenceRegistry $registry) {}

    /**
     * @param  array<string,mixed>  $packet
     * @return array{advisory:true, shape_match:float, reasons:list<string>, client_id:string, packet_id:string}
     */
    public function decide(string $clientId, array $packet): array
    {
        $prefs = $this->registry->inspect($clientId);
        $packetId = (string) ($packet['task_packet_id'] ?? '');
        $allowedFiles = array_values((array) ($packet['allowed_files'] ?? []));
        $scopeIn = array_values((array) ($packet['scope_in'] ?? []));
        $declaredTier = (string) ($packet['tier_hint'] ?? '');

        $fileCount = count(array_unique(array_merge($allowedFiles, $scopeIn)));
        $locBudget = (int) ($packet['loc_estimate'] ?? 0);

        $reasons = [];

        // File-count fit (0..1, linearly degrading once over the cap).
        $maxFiles = max(1, (int) $prefs['max_files']);
        if ($fileCount === 0) {
            $fileScore = 0.5;
            $reasons[] = 'no_file_signal';
        } elseif ($fileCount <= $maxFiles) {
            $fileScore = 1.0;
        } else {
            $overshoot = ($fileCount - $maxFiles) / $maxFiles;
            $fileScore = max(0.0, 1.0 - $overshoot);
            $reasons[] = sprintf('file_overshoot:%d>%d', $fileCount, $maxFiles);
        }

        // LOC fit (when supplied).
        $maxLoc = max(1, (int) $prefs['max_loc']);
        if ($locBudget <= 0) {
            $locScore = 0.5;
            $reasons[] = 'no_loc_signal';
        } elseif ($locBudget <= $maxLoc) {
            $locScore = 1.0;
        } else {
            $overshoot = ($locBudget - $maxLoc) / $maxLoc;
            $locScore = max(0.0, 1.0 - $overshoot);
            $reasons[] = sprintf('loc_overshoot:%d>%d', $locBudget, $maxLoc);
        }

        // Tier match bonus.
        if ($declaredTier !== '' && $declaredTier === (string) $prefs['tier']) {
            $tierScore = 1.0;
            $reasons[] = 'tier_match:'.$declaredTier;
        } elseif ($declaredTier !== '') {
            $tierScore = 0.4;
            $reasons[] = 'tier_mismatch:'.$declaredTier.'_vs_'.$prefs['tier'];
        } else {
            $tierScore = 0.6;
            $reasons[] = 'no_tier_signal';
        }

        // Weighted blend (file 40% / loc 40% / tier 20%).
        $shapeMatch = ($fileScore * 0.4) + ($locScore * 0.4) + ($tierScore * 0.2);
        $shapeMatch = max(0.0, min(1.0, $shapeMatch));

        return [
            'advisory' => true,
            'shape_match' => $shapeMatch,
            'reasons' => $reasons,
            'client_id' => $clientId,
            'packet_id' => $packetId,
        ];
    }
}
