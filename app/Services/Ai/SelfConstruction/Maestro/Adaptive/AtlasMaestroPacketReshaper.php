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
