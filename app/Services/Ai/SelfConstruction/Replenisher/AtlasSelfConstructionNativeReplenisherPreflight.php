<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Replenisher;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;

/**
 * Runs {@see AtlasTaskPacketQualityInspector} over every drafted packet and separates results into:
 *   - accepted   : self_sufficient packets ready for the queue
 *   - rejected   : packets with HARD blockers (forbidden self-target, missing acceptance/evidence)
 *   - repairable : packets where deficiencies have an obvious template-fix hint
 *
 * Emits exact blocking_deficiencies AND repair_hints for non-accepted packets. NEVER enqueues.
 *
 * INVARIANTS:
 *   - DETERMINISTIC envelope.
 *   - PURE.
 */
final class AtlasSelfConstructionNativeReplenisherPreflight
{
    public const SCHEMA = 'atlas.replenisher.preflight.v1';

    /** Deficiencies that ALWAYS reject (no auto-repair). */
    public const HARD_REJECT_DEFICIENCIES = [
        'forbidden_self_target_in_allowed_files',
        'simplicity_contract_violation',
        'isolation_violation',
    ];

    /** @param null|AtlasTaskPacketQualityInspector|object{inspect:callable} $inspector */
    public function __construct(private readonly ?object $inspector = null) {}

    /**
     * @param  list<array<string,mixed>>  $packetDrafts
     * @return array{schema:string, accepted:list<array<string,mixed>>, rejected:list<array<string,mixed>>, repairable:list<array<string,mixed>>}
     */
    public function preflight(array $packetDrafts): array
    {
        $inspector = $this->inspector ?? $this->makeInspector();
        $accepted = [];
        $rejected = [];
        $repairable = [];

        foreach ($packetDrafts as $packet) {
            if (! is_array($packet)) {
                continue;
            }
            $inspection = $inspector !== null ? $inspector->inspect($packet) : ['self_sufficient' => null, 'blocking_deficiencies' => []];
            $deficiencies = is_array($inspection['blocking_deficiencies'] ?? null) ? array_values(array_map('strval', $inspection['blocking_deficiencies'])) : [];

            if ($deficiencies === [] && ($inspection['self_sufficient'] ?? null) === true) {
                $accepted[] = ['packet' => $packet, 'inspection' => $inspection];

                continue;
            }

            $hard = array_intersect(self::HARD_REJECT_DEFICIENCIES, $deficiencies);
            if ($hard !== []) {
                $rejected[] = [
                    'packet' => $packet,
                    'blocking_deficiencies' => $deficiencies,
                    'rejection_reasons' => array_values($hard),
                ];

                continue;
            }

            // Repairable: known deficiencies with hint templates.
            $hints = [];
            if (in_array('missing_acceptance_criteria', $deficiencies, true)) {
                $hints[] = 'add_at_least_one_acceptance_criterion';
            }
            if (in_array('missing_required_evidence', $deficiencies, true)) {
                $hints[] = 'add_required_evidence_list';
            }
            if (in_array('bare_directory_in_allowed_files', $deficiencies, true)) {
                $hints[] = 'replace_bare_directory_with_concrete_file_paths';
            }
            if (in_array('scope_incoherent', $deficiencies, true)) {
                $hints[] = 'narrow_scope_in_to_match_allowed_files';
            }
            if ($hints === []) {
                $hints[] = 'unknown_deficiency:see_blocking_deficiencies';
            }
            $repairable[] = [
                'packet' => $packet,
                'blocking_deficiencies' => $deficiencies,
                'repair_hints' => $hints,
            ];
        }

        usort($accepted, static fn (array $a, array $b): int => strcmp((string) ($a['packet']['frontier_id'] ?? ''), (string) ($b['packet']['frontier_id'] ?? '')));
        usort($rejected, static fn (array $a, array $b): int => strcmp((string) ($a['packet']['frontier_id'] ?? ''), (string) ($b['packet']['frontier_id'] ?? '')));
        usort($repairable, static fn (array $a, array $b): int => strcmp((string) ($a['packet']['frontier_id'] ?? ''), (string) ($b['packet']['frontier_id'] ?? '')));

        return [
            'schema' => self::SCHEMA,
            'accepted' => $accepted,
            'rejected' => $rejected,
            'repairable' => $repairable,
        ];
    }

    private function makeInspector(): ?AtlasTaskPacketQualityInspector
    {
        try {
            return app(AtlasTaskPacketQualityInspector::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
