<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Contract Schema Registry decider.
 *
 * Pure, deterministic runtime for the governance rules documented in the
 * Contract Schema Registry doc. The doc is the unified canonical index of every
 * `atlas.<namespace>.<name>.v<int>` schema. It is NOT a schema definer; it
 * registers existing schemas and gates their use. This service enforces the
 * concrete rules the doc states:
 *
 *   1. schema_id shape (`Contratos` → Schema entry): a schema id MUST be
 *      `atlas.<namespace>.<name>.v<int>`. `parseSchemaId()` rejects anything
 *      else (no `atlas.` prefix, missing version, non-integer version, v0/empty).
 *
 *   2. Canonicality (`Regras para IA`): "Schema sem registro nao e canonico;
 *      bloquear uso em runtime." `isCanonical()` returns false for any id that
 *      is not an exact registered entry — an unregistered schema is treated as
 *      non-existent.
 *
 *   3. Entry quality gates (`quality_gates`): every entry MUST have an owner and
 *      a canonical_doc; a deprecated entry MUST carry `deprecated_at`.
 *      `validateEntry()` returns the documented `atlas.schema_registry.entry.v1`
 *      verdict with the precise violation reasons.
 *
 *   4. Breaking-change policy (`Breaking change policy` / `Antipadroes`): a
 *      breaking change (remove field / change type / rename without alias) is
 *      legal ONLY with (a) a decision receipt id, (b) a deprecation lane of at
 *      least 60 days, and (c) a single integer version bump `v_n -> v_{n+1}`
 *      where both coexist. `evaluateBreakingChange()` caps the lane floor at 60,
 *      requires the receipt, and rejects skipped/zero/negative version bumps.
 *
 *   5. Duplicate detection (`failure_modes` / `Antipadroes`): the same schema id
 *      (or the same namespace.name pair) declared by two different docs is a
 *      silent duplication and is rejected. `validateRegistry()` aggregates the
 *      whole snapshot and flips the gate to `block` on any orphan/duplicate/
 *      missing-owner/missing-doc, else `active`.
 *
 * The service NEVER reads a doc, writes the registry, issues a receipt or
 * touches a database. It only decides whether an id / entry / change / snapshot
 * is registry-legal. Callers enforce.
 *
 * @see docs/engineering-knowledge-base/atlas-contract-schema-registry.md
 */
final class AtlasContractSchemaRegistryService
{
    /** Stable schema id for the verdict envelopes this service emits. */
    public const SCHEMA = 'atlas.schema_registry.entry.v1';

    /** Documented minimum deprecation lane for a breaking change (days). */
    public const MIN_DEPRECATION_WINDOW_DAYS = 60;

    /**
     * Canonical id shape: atlas.<namespace>.<name>.v<int>.
     *
     * namespace/name are dot-separated lowercase segments (the snapshot uses
     * e.g. `atlas.aaeos.cross_dept.handoff.v1`, so there may be more than one
     * middle segment); the version is the final `.v<positive int>` segment.
     */
    private const SCHEMA_ID_PATTERN = '/^atlas((?:\.[a-z0-9][a-z0-9_]*)+)\.v([1-9][0-9]*)$/';

    /**
     * Parse and validate a schema id against the canonical shape.
     *
     * @return array{
     *     schema:string, schema_id:string, valid:bool, namespace_name:string,
     *     version:int|null, reasons:list<string>
     * }
     */
    public function parseSchemaId(string $schemaId): array
    {
        $id = trim($schemaId);
        $reasons = [];

        $namespaceName = '';
        $version = null;

        if ($id === '') {
            $reasons[] = 'empty_schema_id';
        } elseif (! str_starts_with($id, 'atlas.')) {
            $reasons[] = 'missing_atlas_prefix';
        } elseif (! preg_match('/\.v[0-9]+$/', $id)) {
            $reasons[] = 'missing_version_suffix';
        } elseif (preg_match('/\.v0+$/', $id)) {
            $reasons[] = 'invalid_version_v0';
        } elseif (preg_match(self::SCHEMA_ID_PATTERN, $id, $m) === 1) {
            $namespaceName = ltrim($m[1], '.');
            $version = (int) $m[2];
        } else {
            $reasons[] = 'malformed_schema_id';
        }

        return [
            'schema' => self::SCHEMA,
            'schema_id' => $id,
            'valid' => $reasons === [],
            'namespace_name' => $namespaceName,
            'version' => $version,
            'reasons' => $reasons,
        ];
    }

    /**
     * Is $schemaId an exact registered entry in $registry?
     *
     * Regras para IA: "Schema sem entry e tratado como nao-existente." An id
     * that does not appear (by exact schema_id) in the registry is NOT canonical,
     * even if it is well formed.
     *
     * @param  list<array<string,mixed>>  $registry
     */
    public function isCanonical(string $schemaId, array $registry): bool
    {
        $id = trim($schemaId);
        if ($id === '' || ! $this->parseSchemaId($id)['valid']) {
            return false;
        }
        foreach ($registry as $entry) {
            if (($entry['schema_id'] ?? null) === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate a single registry entry against the documented quality gates.
     *
     * Gates: all-schemas-have-owner, all-schemas-have-doc, and (for deprecated
     * entries) a deprecation timestamp. The schema_id must also be well formed.
     *
     * @param  array<string,mixed>  $entry
     * @return array{
     *     schema:string, schema_id:string, valid:bool, owner:string,
     *     canonical_doc:string, deprecated:bool, version:int|null,
     *     reasons:list<string>
     * }
     */
    public function validateEntry(array $entry): array
    {
        $schemaId = is_string($entry['schema_id'] ?? null) ? trim((string) $entry['schema_id']) : '';
        $owner = is_string($entry['owner'] ?? null) ? trim((string) $entry['owner']) : '';
        $doc = is_string($entry['canonical_doc'] ?? null) ? trim((string) $entry['canonical_doc']) : '';
        $deprecatedRaw = $entry['deprecated'] ?? false;
        $deprecated = is_string($deprecatedRaw) && strtolower(trim($deprecatedRaw)) === 'false' ? false : (bool) $deprecatedRaw;
        $deprecatedAt = is_string($entry['deprecated_at'] ?? null) ? trim((string) $entry['deprecated_at']) : '';

        $reasons = [];

        $parsed = $this->parseSchemaId($schemaId);
        if (! $parsed['valid']) {
            foreach ($parsed['reasons'] as $r) {
                $reasons[] = 'schema_id:' . $r;
            }
        }
        if ($owner === '') {
            $reasons[] = 'missing_owner';
        }
        if ($doc === '') {
            $reasons[] = 'missing_canonical_doc';
        }
        if ($deprecated && $deprecatedAt === '') {
            $reasons[] = 'deprecated_without_timestamp';
        }

        return [
            'schema' => self::SCHEMA,
            'schema_id' => $schemaId,
            'valid' => $reasons === [],
            'owner' => $owner,
            'canonical_doc' => $doc,
            'deprecated' => $deprecated,
            'version' => $parsed['version'],
            'reasons' => $reasons,
        ];
    }

    /**
     * Evaluate a proposed breaking change against the documented policy.
     *
     * Breaking change = remove field / change type / rename without alias. It is
     * allowed ONLY with all of:
     *   1. a decision receipt id (rationale carrier),
     *   2. a deprecation lane of at least 60 days,
     *   3. a single integer version bump v_n -> v_{n+1} (both coexist on the lane).
     *
     * @param  array{
     *     from_version?:string|int, to_version?:string|int, receipt_id?:string,
     *     deprecation_window_days?:int
     * }  $change
     * @return array{
     *     schema:string, allowed:bool, from_version:int|null, to_version:int|null,
     *     receipt_id:string, deprecation_window_days:int,
     *     min_required_window_days:int, violations:list<string>
     * }
     */
    public function evaluateBreakingChange(array $change): array
    {
        $from = $this->versionToInt($change['from_version'] ?? null);
        $to = $this->versionToInt($change['to_version'] ?? null);
        $receipt = is_string($change['receipt_id'] ?? null) ? trim((string) $change['receipt_id']) : '';
        $window = (int) ($change['deprecation_window_days'] ?? 0);

        $violations = [];

        if ($receipt === '') {
            $violations[] = 'missing_decision_receipt';
        }

        if ($window < self::MIN_DEPRECATION_WINDOW_DAYS) {
            $violations[] = 'deprecation_window_below_min';
        }

        if ($from === null || $to === null) {
            $violations[] = 'unparseable_version';
        } elseif ($to <= $from) {
            $violations[] = 'version_not_incremented';
        } elseif ($to !== $from + 1) {
            $violations[] = 'version_bump_must_be_single_step';
        }

        return [
            'schema' => self::SCHEMA,
            'allowed' => $violations === [],
            'from_version' => $from,
            'to_version' => $to,
            'receipt_id' => $receipt,
            'deprecation_window_days' => $window,
            'min_required_window_days' => self::MIN_DEPRECATION_WINDOW_DAYS,
            'violations' => $violations,
        ];
    }

    /**
     * Validate a whole registry snapshot: per-entry gates plus cross-entry
     * duplicate detection. Flips the gate to `block` on any violation.
     *
     * Duplicate = the same schema_id, OR the same namespace.name declared by a
     * DIFFERENT canonical_doc (silent contract duplication across docs).
     *
     * @param  list<array<string,mixed>>  $registry
     * @return array{
     *     schema:string, gate:string, total:int, valid:int,
     *     invalid_entries:list<array{schema_id:string,reasons:list<string>}>,
     *     duplicates:list<string>, orphan_no_owner:list<string>,
     *     orphan_no_doc:list<string>, metrics:array{schema_count_total:int,schema_orphan_count:int,schema_duplicate_count:int}
     * }
     */
    public function validateRegistry(array $registry): array
    {
        $total = count($registry);
        $validCount = 0;
        $invalidEntries = [];
        $orphanNoOwner = [];
        $orphanNoDoc = [];

        // Track ids and namespace.name -> set of docs for duplicate detection.
        $idCounts = [];
        /** @var array<string,array<string,bool>> $nameToDocs */
        $nameToDocs = [];

        foreach ($registry as $entry) {
            $verdict = $this->validateEntry($entry);
            $id = $verdict['schema_id'];

            if ($verdict['valid']) {
                $validCount++;
            } else {
                $invalidEntries[] = ['schema_id' => $id, 'reasons' => $verdict['reasons']];
            }

            if (in_array('missing_owner', $verdict['reasons'], true)) {
                $orphanNoOwner[] = $id;
            }
            if (in_array('missing_canonical_doc', $verdict['reasons'], true)) {
                $orphanNoDoc[] = $id;
            }

            if ($id !== '') {
                $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;

                $parsed = $this->parseSchemaId($id);
                $doc = $verdict['canonical_doc'];
                if ($parsed['valid'] && $doc !== '') {
                    $nameToDocs[$parsed['namespace_name']][$doc] = true;
                }
            }
        }

        $duplicates = [];
        foreach ($idCounts as $id => $count) {
            if ($count > 1) {
                $duplicates[] = $id;
            }
        }
        foreach ($nameToDocs as $name => $docs) {
            // Same namespace.name owned by >1 distinct doc = cross-doc duplication.
            if (count($docs) > 1) {
                $duplicates[] = 'atlas.' . $name . '.v*';
            }
        }
        $duplicates = array_values(array_unique($duplicates));

        $blocked = $invalidEntries !== [] || $duplicates !== [];

        return [
            'schema' => self::SCHEMA,
            'gate' => $blocked ? 'block' : 'active',
            'total' => $total,
            'valid' => $validCount,
            'invalid_entries' => $invalidEntries,
            'duplicates' => $duplicates,
            'orphan_no_owner' => array_values(array_unique(array_filter($orphanNoOwner, static fn ($v) => $v !== ''))),
            'orphan_no_doc' => array_values(array_unique(array_filter($orphanNoDoc, static fn ($v) => $v !== ''))),
            'metrics' => [
                'schema_count_total' => $total,
                'schema_orphan_count' => count(array_unique(array_merge($orphanNoOwner, $orphanNoDoc))),
                'schema_duplicate_count' => count($duplicates),
            ],
        ];
    }

    /**
     * Coerce a version token (`v3`, `3`, 3) to its integer, or null if invalid.
     *
     * @param  mixed  $value
     */
    private function versionToInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value)) {
            $v = trim($value);
            if (preg_match('/^v?([1-9][0-9]*)$/', $v, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }
}
