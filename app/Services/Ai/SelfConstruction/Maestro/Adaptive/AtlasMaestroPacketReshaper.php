<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Adaptive;

final class AtlasMaestroPacketReshaper
{
    public const SCHEMA = 'atlas.maestro.adaptive.packet_reshaper.v1';

    public function __construct(private readonly ?AtlasMaestroGiveBackPatternMiner $miner = null)
    {
    }

    /**
     * Reshape actions mined poison patterns can resolve to, per pattern type. Every proposal
     * carries this alongside the legacy 'action' verb (repair/retire/hold_for_evidence/split) so
     * callers can route on the coarser action verb or the finer reshape_action.
     */
    private const RESHAPE_ACTION_FIX_SCOPE           = 'fix_scope';
    private const RESHAPE_ACTION_STRENGTHEN_ACCEPTANCE = 'strengthen_acceptance';
    private const RESHAPE_ACTION_SPLIT_PACKET        = 'split_packet';
    private const RESHAPE_ACTION_CANCEL_DUPLICATE    = 'cancel_duplicate';
    private const RESHAPE_ACTION_OPERATOR_ONLY       = 'operator_only';

    /**
     * Convert mined give_back poison patterns into safe respec proposals.
     *
     * Supported pattern types:
     *   missing_impl_file        — adds missing files to allowed_files (fix_scope)
     *                              OR operator_only if repair would leave no runnable acceptance
     *   contradictory_acceptance — always operator_only; unresolvable by file edits alone
     *   forbidden_target         — always operator_only; unresolvable by file edits alone
     *   schema_mismatch          — corrects schema (fix_scope)
     *   duplicate_or_noop        — replaces acceptance with a behavior-proof requirement (strengthen_acceptance)
     *   scope_gap                — adds missing scope_in roots (fix_scope)
     *   duplicate_capability     — cancels outright; the capability already exists (cancel_duplicate)
     *   over_broad_scope         — splits allowed_files into two narrower respecs (split_packet)
     *
     * Root-cause guard (AC3): when a pattern carries unchanged_since_last_attempt=true, the
     * packet was already reshaped for this exact root cause and remains structurally unchanged —
     * proposing the same fix again would be a blind retry, so this always yields operator_only
     * regardless of pattern type.
     *
     * @param  array<string,mixed>          $packet
     * @param  list<array<string,mixed>>    $poisonPatterns  [{type, ...}]
     * @return array{schema:string, proposals:list<array<string,mixed>>}
     */
    public function propose(array $packet, array $poisonPatterns): array
    {
        $proposals = [];
        foreach ($poisonPatterns as $pattern) {
            $type = (string) ($pattern['type'] ?? '');

            if ((bool) ($pattern['unchanged_since_last_attempt'] ?? false)) {
                $proposals[] = $this->refuseBlindRetry($packet, $pattern, $type);

                continue;
            }

            // A repair proposal is only as trustworthy as the mined pattern behind it. When the
            // repair_confidence for a repairable pattern is anything below 'high', hold for more
            // evidence instead of auto-repairing on a weak/mixed signal.
            if (in_array($type, ['missing_impl_file', 'schema_mismatch'], true)
                && (string) ($pattern['repair_confidence'] ?? 'high') !== 'high') {
                $proposals[] = $this->holdForEvidence($packet, $pattern, $type);

                continue;
            }

            $proposals[] = match ($type) {
                'missing_impl_file'        => $this->repairMissingImplFile($packet, $pattern),
                'contradictory_acceptance' => $this->retireContradictory($packet, $pattern),
                'forbidden_target'         => $this->retireForbiddenTarget($packet, $pattern),
                'schema_mismatch'          => $this->repairSchemaMismatch($packet, $pattern),
                'duplicate_or_noop'        => $this->repairDuplicateOrNoop($packet, $pattern),
                'scope_gap'                => $this->fixScopeGap($packet, $pattern),
                'duplicate_capability'     => $this->cancelDuplicateCapability($packet, $pattern),
                'over_broad_scope'         => $this->splitOverBroadScope($packet, $pattern),
                default                    => $this->retireUnknown($packet, $pattern),
            };
        }

        return ['schema' => self::SCHEMA, 'proposals' => $proposals];
    }

    /** @param  array<string,mixed>  $packet  @param  array<string,mixed>  $pattern */
    private function repairMissingImplFile(array $packet, array $pattern): array
    {
        $id           = (string) ($packet['task_packet_id'] ?? $packet['label'] ?? '');
        $missingFiles = array_values(array_filter((array) ($pattern['missing_files'] ?? []), 'is_string'));
        $currentFiles = array_values(array_filter((array) ($packet['allowed_files'] ?? []), 'is_string'));
        $repairedFiles = array_values(array_unique(array_merge($currentFiles, $missingFiles)));

        $repairedPacket = $packet;
        $repairedPacket['allowed_files'] = $repairedFiles;

        // Never emit a repair without at least one non-empty runnable acceptance criterion.
        $hasAcceptance = array_values(array_filter(
            (array) ($repairedPacket['acceptance_criteria'] ?? []),
            static fn (mixed $v): bool => is_string($v) && trim($v) !== '',
        )) !== [];

        if (! $hasAcceptance) {
            return [
                'action'             => 'retire',
                'reshape_action'     => self::RESHAPE_ACTION_OPERATOR_ONLY,
                'reason'             => 'repair_would_leave_no_runnable_acceptance',
                'pattern'            => 'missing_impl_file',
                'original_packet_id' => $id,
            ];
        }

        return [
            'action'             => 'repair',
            'reshape_action'     => self::RESHAPE_ACTION_FIX_SCOPE,
            'reason'             => 'missing_impl_file_added',
            'pattern'            => 'missing_impl_file',
            'original_packet_id' => $id,
            'added_files'        => $missingFiles,
            'respec'             => $repairedPacket,
        ];
    }

    /** @param  array<string,mixed>  $packet  @param  array<string,mixed>  $pattern */
    private function holdForEvidence(array $packet, array $pattern, string $type): array
    {
        return [
            'action'             => 'hold_for_evidence',
            'reshape_action'     => self::RESHAPE_ACTION_OPERATOR_ONLY,
            'reason'             => 'repair_confidence_below_high',
            'pattern'            => $type,
            'original_packet_id' => (string) ($packet['task_packet_id'] ?? $packet['label'] ?? ''),
            'repair_confidence'  => (string) ($pattern['repair_confidence'] ?? 'low'),
        ];
    }

    /** @param  array<string,mixed>  $packet  @param  array<string,mixed>  $pattern */
    private function retireContradictory(array $packet, array $pattern): array
    {
        return [
            'action'             => 'retire',
            'reshape_action'     => self::RESHAPE_ACTION_OPERATOR_ONLY,
            'reason'             => 'contradictory_acceptance_unrepairable',
            'pattern'            => 'contradictory_acceptance',
            'original_packet_id' => (string) ($packet['task_packet_id'] ?? $packet['label'] ?? ''),
            'detail'             => (string) ($pattern['reason'] ?? 'acceptance criteria contradict each other'),
        ];
    }

    /** @param  array<string,mixed>  $packet  @param  array<string,mixed>  $pattern */
    private function retireForbiddenTarget(array $packet, array $pattern): array
    {
        return [
            'action'             => 'retire',
            'reshape_action'     => self::RESHAPE_ACTION_OPERATOR_ONLY,
            'reason'             => 'forbidden_target_unrepairable',
            'pattern'            => 'forbidden_target',
            'original_packet_id' => (string) ($packet['task_packet_id'] ?? $packet['label'] ?? ''),
            'detail'             => (string) ($pattern['detail'] ?? 'target is forbidden and cannot be repaired by file edits alone'),
        ];
    }

    /** @param  array<string,mixed>  $packet  @param  array<string,mixed>  $pattern */
    private function repairSchemaMismatch(array $packet, array $pattern): array
    {
        $id = (string) ($packet['task_packet_id'] ?? $packet['label'] ?? '');
        $expectedSchema = (string) ($pattern['expected_schema'] ?? '');
        $repairedPacket = $packet;
        if ($expectedSchema !== '') {
            $repairedPacket['schema'] = $expectedSchema;
        }

        return [
            'action'             => 'repair',
            'reshape_action'     => self::RESHAPE_ACTION_FIX_SCOPE,
            'reason'             => 'schema_mismatch_corrected',
            'pattern'            => 'schema_mismatch',
            'original_packet_id' => $id,
            'expected_schema'    => $expectedSchema,
            'respec'             => $repairedPacket,
        ];
    }

    /** @param  array<string,mixed>  $packet  @param  array<string,mixed>  $pattern */
    private function repairDuplicateOrNoop(array $packet, array $pattern): array
    {
        $id = (string) ($packet['task_packet_id'] ?? $packet['label'] ?? '');

        // Emit a replacement objective/acceptance that demands behavior proof, not a cosmetic rename.
        $repairedPacket = $packet;
        $repairedPacket['objective'] = 'Re-implement with measurable behavior change: '.$id;
        $repairedPacket['acceptance_criteria'] = [
            '/opt/homebrew/bin/php artisan test --filter=<TestClass> must exit 0 proving real behavior change',
        ];
        $repairedPacket['required_evidence'] = ['tests_or_gates_result', 'implementation_notes'];

        return [
            'action'             => 'repair',
            'reshape_action'     => self::RESHAPE_ACTION_STRENGTHEN_ACCEPTANCE,
            'reason'             => 'duplicate_or_noop_replaced_with_behavior_proof',
            'pattern'            => 'duplicate_or_noop',
            'original_packet_id' => $id,
            'respec'             => $repairedPacket,
        ];
    }

    /** @param  array<string,mixed>  $packet  @param  array<string,mixed>  $pattern */
    private function retireUnknown(array $packet, array $pattern): array
    {
        return [
            'action'             => 'retire',
            'reshape_action'     => self::RESHAPE_ACTION_OPERATOR_ONLY,
            'reason'             => 'unknown_poison_pattern',
            'pattern'            => (string) ($pattern['type'] ?? 'unknown'),
            'original_packet_id' => (string) ($packet['task_packet_id'] ?? $packet['label'] ?? ''),
        ];
    }

    /** @param  array<string,mixed>  $packet  @param  array<string,mixed>  $pattern */
    private function fixScopeGap(array $packet, array $pattern): array
    {
        $id = (string) ($packet['task_packet_id'] ?? $packet['label'] ?? '');
        $missingRoots = array_values(array_filter((array) ($pattern['missing_scope_roots'] ?? []), 'is_string'));
        $currentScope = array_values(array_filter((array) ($packet['scope_in'] ?? []), 'is_string'));

        $repairedPacket = $packet;
        $repairedPacket['scope_in'] = array_values(array_unique(array_merge($currentScope, $missingRoots)));

        return [
            'action'             => 'repair',
            'reshape_action'     => self::RESHAPE_ACTION_FIX_SCOPE,
            'reason'             => 'scope_gap_closed',
            'pattern'            => 'scope_gap',
            'original_packet_id' => $id,
            'added_scope_roots'  => $missingRoots,
            'respec'             => $repairedPacket,
        ];
    }

    /** @param  array<string,mixed>  $packet  @param  array<string,mixed>  $pattern */
    private function cancelDuplicateCapability(array $packet, array $pattern): array
    {
        return [
            'action'             => 'retire',
            'reshape_action'     => self::RESHAPE_ACTION_CANCEL_DUPLICATE,
            'reason'             => 'duplicate_capability_already_exists',
            'pattern'            => 'duplicate_capability',
            'original_packet_id' => (string) ($packet['task_packet_id'] ?? $packet['label'] ?? ''),
            'detail'             => (string) ($pattern['existing_capability_ref'] ?? 'the requested capability is already implemented elsewhere'),
        ];
    }

    /** @param  array<string,mixed>  $packet  @param  array<string,mixed>  $pattern */
    private function splitOverBroadScope(array $packet, array $pattern): array
    {
        $id = (string) ($packet['task_packet_id'] ?? $packet['label'] ?? '');
        $allowedFiles = array_values(array_filter((array) ($packet['allowed_files'] ?? []), 'is_string'));

        $half = (int) ceil(count($allowedFiles) / 2);
        $firstHalf = array_slice($allowedFiles, 0, $half);
        $secondHalf = array_slice($allowedFiles, $half);

        $splitProposals = [];
        foreach ([$firstHalf, $secondHalf] as $i => $files) {
            if ($files === []) {
                continue;
            }
            $splitPacket = $packet;
            $splitPacket['allowed_files'] = array_values($files);
            $splitPacket['task_packet_id'] = $id !== '' ? sprintf('%s-split-%d', $id, $i + 1) : '';
            $splitProposals[] = $splitPacket;
        }

        return [
            'action'             => 'split',
            'reshape_action'     => self::RESHAPE_ACTION_SPLIT_PACKET,
            'reason'             => 'over_broad_scope_split_into_narrower_packets',
            'pattern'            => 'over_broad_scope',
            'original_packet_id' => $id,
            'split_proposals'    => $splitProposals,
        ];
    }

    /** @param  array<string,mixed>  $packet  @param  array<string,mixed>  $pattern */
    private function refuseBlindRetry(array $packet, array $pattern, string $type): array
    {
        return [
            'action'             => 'operator_only',
            'reshape_action'     => self::RESHAPE_ACTION_OPERATOR_ONLY,
            'reason'             => 'root_cause_unchanged_since_last_attempt_refusing_blind_retry',
            'pattern'            => $type !== '' ? $type : 'unknown',
            'original_packet_id' => (string) ($packet['task_packet_id'] ?? $packet['label'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    public function reshape(array $packet): array
    {
        $originalHash = $this->hash($packet);

        if (! (bool) config('atlas.maestro.adaptive.reshape_enabled', false)) {
            return $packet + [
                'reshape_receipt' => $this->receipt('passthrough', $originalHash, $originalHash, []),
            ];
        }

        $facts = $this->miner?->mineGiveBackShapes()['rows'] ?? [];
        $reshaped = $packet;
        $reshaped['allowed_files'] = $this->narrowAllowedFiles($packet, $facts);
        $reshapedHash = $this->hash($reshaped);
        $reshaped['reshape_receipt'] = $this->receipt('reshaped', $originalHash, $reshapedHash, $facts);

        return $reshaped;
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  list<array<string,mixed>>  $facts
     * @return list<string>
     */
    private function narrowAllowedFiles(array $packet, array $facts): array
    {
        $allowedFiles = array_values(array_filter((array) ($packet['allowed_files'] ?? []), 'is_string'));
        if (count($allowedFiles) <= 1 || $facts === []) {
            return $allowedFiles;
        }

        $preferredRoot = $this->firstScopeRoot($packet);
        $sameRoot = array_values(array_filter(
            $allowedFiles,
            static fn (string $path): bool => str_starts_with($path, $preferredRoot),
        ));

        $candidate = $sameRoot !== [] ? $sameRoot : [$allowedFiles[0]];

        return array_values(array_slice($candidate, 0, max(1, count($candidate) - 1)));
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function firstScopeRoot(array $packet): string
    {
        $scope = array_values(array_filter((array) ($packet['scope_in'] ?? []), 'is_string'));
        $first = (string) ($scope[0] ?? ($packet['allowed_files'][0] ?? ''));
        $parts = explode('/', $first);

        return implode('/', array_slice($parts, 0, min(4, count($parts)))) ?: $first;
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return array{schema:string,kind:string,original_hash:string,reshaped_hash:string,miner_facts_used:list<array<string,mixed>>}
     */
    private function receipt(string $kind, string $originalHash, string $reshapedHash, array $facts): array
    {
        return [
            'schema' => self::SCHEMA,
            'kind' => $kind,
            'original_hash' => $originalHash,
            'reshaped_hash' => $reshapedHash,
            'miner_facts_used' => $facts,
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function hash(array $packet): string
    {
        unset($packet['reshape_receipt']);
        ksort($packet);

        return hash('sha256', json_encode($packet, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
