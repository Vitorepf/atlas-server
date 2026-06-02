<?php

declare(strict_types=1);

namespace App\Services\Ai\Evidence\Replay;

final class EvidenceReplayCompletenessVerdict
{
    private const SCHEMA_VERSION = 'atlas.evidence.replay_completeness.v1';

    /**
     * @param  list<string>  $requiredComponents
     * @param  list<string>  $presentComponents
     * @return array{
     *     schema_version: string,
     *     verdict: string,
     *     replay_green: bool,
     *     completeness_ratio: float,
     *     missing_components: list<string>,
     *     reasons: list<string>
     * }
     */
    public function decide(
        array $requiredComponents,
        array $presentComponents,
        int $chainLength,
        int $chainGapCount,
        int $brokenRefCount,
    ): array {
        $chainLength = max(0, $chainLength);
        $chainGapCount = max(0, $chainGapCount);
        $brokenRefCount = max(0, $brokenRefCount);

        $present = array_fill_keys(array_values($presentComponents), true);
        $missingComponents = [];
        $seenMissing = [];
        foreach ($requiredComponents as $component) {
            if (isset($present[$component]) || isset($seenMissing[$component])) {
                continue;
            }
            $seenMissing[$component] = true;
            $missingComponents[] = $component;
        }
        sort($missingComponents, SORT_STRING);
        $missingComponents = array_values($missingComponents);

        $requiredCount = count($requiredComponents);
        $completenessRatio = $requiredCount === 0
            ? 1.0
            : round(($requiredCount - count($missingComponents)) / $requiredCount, 2);

        $brokenRefs = $brokenRefCount > 0;
        $chainDiscontinuous = $chainGapCount > 0 || $chainLength <= 0;
        $componentsMissing = $missingComponents !== [];

        $reasons = [];
        if ($brokenRefs) {
            $reasons[] = 'broken_evidence_refs';
        }
        if ($chainDiscontinuous) {
            $reasons[] = 'hash_chain_discontinuous';
        }
        if ($componentsMissing) {
            $reasons[] = 'required_components_missing';
        }

        if ($brokenRefs || $chainDiscontinuous) {
            $verdict = 'incomplete_unreplayable';
            $replayGreen = false;
        } elseif ($componentsMissing) {
            $verdict = 'incomplete_missing_components';
            $replayGreen = false;
        } elseif ($reasons === [] && $completenessRatio === 1.0) {
            $verdict = 'complete';
            $replayGreen = true;
        } else {
            $verdict = 'incomplete_missing_components';
            $replayGreen = false;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'replay_green' => $replayGreen,
            'completeness_ratio' => $completenessRatio,
            'missing_components' => $missingComponents,
            'reasons' => $reasons,
        ];
    }
}
