<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Deterministic contract map: before a compression wave merges or deletes a circuit, this
 * extractor tells the muscle exactly what must survive. It reads four fact categories —
 * CLI signatures, methods, output keys, and evidence-schema names — and turns each PUBLIC
 * entry into a preservation constraint. Private/protected/helper-only entries are never
 * promoted to a preservation constraint; only what a real caller (user, worker, or another
 * organ) can actually reach is preserved.
 *
 * Input contract (all lists optional, default []):
 *   cli_signatures:        list<array{signature: string, visibility?: string}>
 *   methods:                list<array{name: string, class?: string, visibility?: string}>
 *   output_keys:            list<array{key: string, visibility?: string}>
 *   evidence_schema_names:  list<array{name: string, visibility?: string}>
 *
 * `visibility` defaults to 'public'. Any entry explicitly marked 'private', 'protected', or
 * 'helper' is excluded from preserved_contracts — a private helper is never a public
 * preservation constraint, no matter how the fact was supplied.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainPublicContractExtractor
{
    public const SCHEMA = 'atlas.external_brain.public_contract_extractor.v1';

    public const TYPE_CLI_SIGNATURE       = 'cli_signature';
    public const TYPE_PUBLIC_METHOD       = 'public_method';
    public const TYPE_OUTPUT_KEY          = 'output_key';
    public const TYPE_EVIDENCE_SCHEMA     = 'evidence_schema_name';

    private const NON_PUBLIC_VISIBILITY = ['private', 'protected', 'helper'];

    /**
     * @param  array{
     *   cli_signatures?:       list<array<string,mixed>>,
     *   methods?:               list<array<string,mixed>>,
     *   output_keys?:           list<array<string,mixed>>,
     *   evidence_schema_names?: list<array<string,mixed>>,
     * }  $facts
     * @return array{schema:string, preserved_contracts:list<array{type:string,identifier:string}>, excluded_private_count:int}
     */
    public function extract(array $facts): array
    {
        $preserved = [];
        $excludedCount = 0;

        $preserved = array_merge($preserved, $this->extractCategory(
            $facts['cli_signatures'] ?? [],
            self::TYPE_CLI_SIGNATURE,
            'signature',
            $excludedCount,
        ));

        $preserved = array_merge($preserved, $this->extractCategory(
            $facts['methods'] ?? [],
            self::TYPE_PUBLIC_METHOD,
            'name',
            $excludedCount,
            'class',
        ));

        $preserved = array_merge($preserved, $this->extractCategory(
            $facts['output_keys'] ?? [],
            self::TYPE_OUTPUT_KEY,
            'key',
            $excludedCount,
        ));

        $preserved = array_merge($preserved, $this->extractCategory(
            $facts['evidence_schema_names'] ?? [],
            self::TYPE_EVIDENCE_SCHEMA,
            'name',
            $excludedCount,
        ));

        usort($preserved, static fn (array $a, array $b): int => $a['type'] === $b['type']
            ? strcmp($a['identifier'], $b['identifier'])
            : strcmp($a['type'], $b['type']));

        return [
            'schema'                  => self::SCHEMA,
            'preserved_contracts'     => $preserved,
            'excluded_private_count'  => $excludedCount,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $entries
     * @return list<array{type:string,identifier:string}>
     */
    private function extractCategory(
        array $entries,
        string $type,
        string $identifierKey,
        int &$excludedCount,
        ?string $qualifierKey = null,
    ): array {
        $result = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $identifier = trim((string) ($entry[$identifierKey] ?? ''));
            if ($identifier === '') {
                continue;
            }

            $visibility = strtolower(trim((string) ($entry['visibility'] ?? 'public')));
            if (in_array($visibility, self::NON_PUBLIC_VISIBILITY, true)) {
                $excludedCount++;

                continue;
            }

            $qualifier = $qualifierKey !== null ? trim((string) ($entry[$qualifierKey] ?? '')) : '';

            $result[] = [
                'type'       => $type,
                'identifier' => $qualifier !== '' ? "{$qualifier}::{$identifier}" : $identifier,
            ];
        }

        return $result;
    }
}
