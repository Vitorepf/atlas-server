<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

/**
 * MULTJ-06 — pack-injection preference helper: higher `abstraction_level`
 * wins, with fallback to tactical (level 1).
 *
 * The selector is READY but NOT wired into `packFor` yet — the AOBG pack
 * seam for lesson injection by abstraction level does not exist in this slice.
 * Callers (or a follow-up slice) can hook this without duplicating priority
 * logic. Flag default-OFF; enabling the flag alone does not mutate packs.
 */
final class AtlasLearningAbstractionPackSelector
{
    public const SCHEMA_VERSION = 'atlas.ai.learning_abstraction_pack_selector.v1';

    /**
     * @param  list<array<string,mixed>>  $matches
     * @return array<string,mixed>|null
     */
    public function selectBestMatch(array $matches): ?array
    {
        if ($matches === []) {
            return null;
        }

        $ranked = $matches;
        usort($ranked, static function (array $a, array $b): int {
            $levelA = (int) ($a['abstraction_level'] ?? 1);
            $levelB = (int) ($b['abstraction_level'] ?? 1);
            if ($levelA !== $levelB) {
                return $levelB <=> $levelA;
            }

            return ((int) ($b['case_count'] ?? 0)) <=> ((int) ($a['case_count'] ?? 0));
        });

        return $ranked[0];
    }

    /**
     * @return array<string,mixed>
     */
    public function injectionMeta(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'wired_into_packfor' => false,
            'wiring_status' => 'pending',
            'flag' => 'atlas.ai.abstraction_ladder.pack_injection_enabled',
            'flag_default' => 'off',
            'preference' => 'highest_abstraction_level_with_tactical_fallback',
        ];
    }
}
