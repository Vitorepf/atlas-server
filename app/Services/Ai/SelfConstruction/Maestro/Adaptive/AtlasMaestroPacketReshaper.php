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
     * Convert mined give_back poison patterns into safe respec proposals.
     *
     * Supported pattern types:
     *   missing_impl_file       — adds missing files to allowed_files (repair)
     *                             OR retires if repair would leave no runnable acceptance
     *   contradictory_acceptance — always retires; unresolvable by file edits alone
     *
     * @param  array<string,mixed>          $packet
     * @param  list<array<string,mixed>>    $poisonPatterns  [{type, ...}]
     * @return array{schema:string, proposals:list<array<string,mixed>>}
     */
    public function propose(array $packet, array $poisonPatterns): array
    {
        $proposals = [];
        foreach ($poisonPatterns as $pattern) {
            $proposals[] = match ((string) ($pattern['type'] ?? '')) {
                'missing_impl_file'        => $this->repairMissingImplFile($packet, $pattern),
                'contradictory_acceptance' => $this->retireContradictory($packet, $pattern),
                'forbidden_target'         => $this->retireForbiddenTarget($packet, $pattern),
                'schema_mismatch'          => $this->repairSchemaMismatch($packet, $pattern),
                'duplicate_or_noop'        => $this->repairDuplicateOrNoop($packet, $pattern),
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
                'reason'             => 'repair_would_leave_no_runnable_acceptance',
                'pattern'            => 'missing_impl_file',
                'original_packet_id' => $id,
            ];
        }

        return [
            'action'             => 'repair',
            'reason'             => 'missing_impl_file_added',
            'pattern'            => 'missing_impl_file',
            'original_packet_id' => $id,
            'added_files'        => $missingFiles,
            'respec'             => $repairedPacket,
        ];
    }

    /** @param  array<string,mixed>  $packet  @param  array<string,mixed>  $pattern */
    private function retireContradictory(array $packet, array $pattern): array
    {
        return [
            'action'             => 'retire',
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
            'reason'             => 'unknown_poison_pattern',
            'pattern'            => (string) ($pattern['type'] ?? 'unknown'),
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
