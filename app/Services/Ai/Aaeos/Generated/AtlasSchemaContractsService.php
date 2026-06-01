<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Engineering Blueprint Schema Contracts decider.
 *
 * Pure, deterministic runtime for the invariants documented in the Engineering
 * Blueprint Schema Contracts doc. The doc defines the schema FAMILIES that carry
 * Engineering Blueprint payloads and the cross-cutting INVARIANTS every record
 * of those families must satisfy. This service does not define the payload
 * fields of any one family — it gates the family taxonomy, the record-level
 * invariants and the schema-evolution compatibility policy. It enforces exactly
 * what the doc states:
 *
 *   1. Schema family taxonomy (`Schema Families`): the canonical families are
 *      `atlas.engineering.project_blueprint.v1`, `atlas.engineering.blueprint.v1`
 *      and `atlas.engineering.task_contract.v1`, plus the payload kinds inventory,
 *      scenario, qa_evidence, review_finding and postgres_review.
 *      `isKnownFamily()` / `classifyFamily()` reject anything outside the set.
 *
 *   2. Record invariants (`Invariants`): a blueprint record is contract-valid
 *      only when (a) `schema_version` is present and a known family, (b)
 *      `content_hash` is present, lowercase hex and matches the deterministic
 *      hash of the record's `payload` (recomputed here — a hash that does not
 *      match its own content is rejected), (c) a `frozen` record is immutable
 *      (its recomputed hash MUST still equal the stored one — drift = mutation
 *      of a frozen record = violation), (d) a `superseded` record MUST point to
 *      a newer record via `superseded_by`. `validateRecord()` returns the
 *      verdict + precise violation reasons.
 *
 *   3. Deterministic content hash (`Invariants` → "`content_hash` is
 *      deterministic"): `contentHash()` canonicalises by recursively ksort-ing
 *      keys so two semantically-equal payloads with different key order hash
 *      identically.
 *
 *   4. Supersede ordering (`Invariants` → "Supersede points to the newer
 *      version"): `isValidSupersede()` requires the successor version integer to
 *      be strictly greater than the predecessor's (a record may never be
 *      superseded by an equal or older version).
 *
 *   5. Staleness surfacing (`Invariants` → "Stale task blueprints are visible
 *      when upstream contract/project blueprint changes"): `isStale()` flips a
 *      task blueprint to stale when the upstream content_hash it was built
 *      against no longer equals the current upstream content_hash.
 *
 *   6. Gate / evidence exposure (`Invariants` → "Blocking gates and missing
 *      evidence are directly exposed to surfaces"): `surfaceState()` aggregates
 *      a record's gates + evidence and flips to `block` when any gate blocks OR
 *      any acceptance criterion has no evidence ref — never silently `ready`.
 *
 *   7. Compatibility policy (`Compatibility`): schema evolution must be additive;
 *      a non-additive change (removed/retyped field) is legal ONLY when a
 *      migration AND a compatibility adapter ship WITH tests, and only across a
 *      single integer version bump. `evaluateEvolution()` enforces this and
 *      additionally requires app types / API resources / CLI output to be
 *      declared as evolving in the same wave.
 *
 * The service NEVER reads a doc, persists a record, freezes anything or touches
 * a database. It only decides whether a family / record / supersede / evolution
 * is contract-legal. Callers enforce.
 *
 * @see docs/engineering-knowledge-base/engineering-blueprint/schema-contracts.md
 */
final class AtlasSchemaContractsService
{
    /** Stable schema id for the verdict envelopes this service emits. */
    public const SCHEMA = 'atlas.engineering.schema_contract_verdict.v1';

    /**
     * The three versioned top-level schema families named in `Schema Families`.
     */
    public const SCHEMA_FAMILIES = [
        'atlas.engineering.project_blueprint.v1',
        'atlas.engineering.blueprint.v1',
        'atlas.engineering.task_contract.v1',
    ];

    /**
     * The payload kinds named in `Schema Families` that ride inside / alongside
     * the top-level families (Inventory, Scenario, QA evidence, Review finding,
     * Postgres review).
     */
    public const PAYLOAD_KINDS = [
        'inventory',
        'scenario',
        'qa_evidence',
        'review_finding',
        'postgres_review',
    ];

    /** Documented record lifecycle statuses. */
    public const STATUS_DRAFT = 'draft';
    public const STATUS_FROZEN = 'frozen';
    public const STATUS_SUPERSEDED = 'superseded';

    /** Canonical engineering family id shape: atlas.engineering.<name>.v<int>. */
    private const FAMILY_ID_PATTERN = '/^atlas\.engineering\.([a-z0-9][a-z0-9_]*)\.v([1-9][0-9]*)$/';

    /**
     * Is $schemaVersion one of the canonical top-level schema families?
     */
    public function isKnownFamily(string $schemaVersion): bool
    {
        return in_array(trim($schemaVersion), self::SCHEMA_FAMILIES, true);
    }

    /**
     * Classify a schema_version / payload kind against the doc taxonomy.
     *
     * @return array{
     *     schema:string, input:string, valid:bool, kind:string,
     *     family:string|null, name:string|null, version:int|null,
     *     reasons:list<string>
     * }
     */
    public function classifyFamily(string $schemaVersionOrKind): array
    {
        $value = trim($schemaVersionOrKind);
        $reasons = [];
        $kind = 'unknown';
        $family = null;
        $name = null;
        $version = null;

        if ($value === '') {
            $reasons[] = 'empty_schema_reference';
        } elseif (in_array($value, self::SCHEMA_FAMILIES, true)) {
            $kind = 'top_level_family';
            $family = $value;
            if (preg_match(self::FAMILY_ID_PATTERN, $value, $m) === 1) {
                $name = $m[1];
                $version = (int) $m[2];
            }
        } elseif (in_array($value, self::PAYLOAD_KINDS, true)) {
            $kind = 'payload_kind';
            $name = $value;
        } elseif (str_starts_with($value, 'atlas.engineering.')
            && preg_match(self::FAMILY_ID_PATTERN, $value) === 1) {
            // Well-formed engineering family id, but not in the canonical set.
            $reasons[] = 'unknown_engineering_family';
        } else {
            $reasons[] = 'not_a_schema_contract_family';
        }

        return [
            'schema' => self::SCHEMA,
            'input' => $value,
            'valid' => $reasons === [],
            'kind' => $kind,
            'family' => $family,
            'name' => $name,
            'version' => $version,
            'reasons' => $reasons,
        ];
    }

    /**
     * Deterministic content hash of a blueprint payload.
     *
     * Invariant "`content_hash` is deterministic": canonicalise by recursively
     * sorting keys before encoding, so key order never changes the hash. Lists
     * keep their order (semantically meaningful); associative maps are sorted.
     *
     * @param  array<array-key,mixed>  $payload
     */
    public function contentHash(array $payload): string
    {
        $canonical = $this->canonicalise($payload);
        $json = (string) json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', $json);
    }

    /**
     * Validate one blueprint record against the documented invariants.
     *
     * Expected record shape (only the governed keys are read):
     *   schema_version: string
     *   status: 'draft'|'frozen'|'superseded'
     *   content_hash: string (lowercase hex)
     *   payload: array  (the content the hash must cover)
     *   superseded_by: ?string  (required when status === superseded)
     *
     * @param  array<string,mixed>  $record
     * @return array{
     *     schema:string, valid:bool, status:string, family:string|null,
     *     recomputed_hash:string, frozen_immutable:bool, reasons:list<string>
     * }
     */
    public function validateRecord(array $record): array
    {
        $reasons = [];

        $schemaVersion = is_string($record['schema_version'] ?? null) ? trim($record['schema_version']) : '';
        $status = is_string($record['status'] ?? null) ? trim($record['status']) : '';
        $storedHash = is_string($record['content_hash'] ?? null) ? trim($record['content_hash']) : '';
        $payload = is_array($record['payload'] ?? null) ? $record['payload'] : [];

        // (a) schema_version explicit + known family.
        if ($schemaVersion === '') {
            $reasons[] = 'missing_schema_version';
        } elseif (! $this->isKnownFamily($schemaVersion)) {
            $reasons[] = 'unknown_schema_version';
        }

        // status must be one of the documented lifecycle states.
        if (! in_array($status, [self::STATUS_DRAFT, self::STATUS_FROZEN, self::STATUS_SUPERSEDED], true)) {
            $reasons[] = 'invalid_status';
        }

        $recomputed = $this->contentHash($payload);

        // (b) content_hash present, lowercase hex, and matches its own content.
        if ($storedHash === '') {
            $reasons[] = 'missing_content_hash';
        } elseif (preg_match('/^[0-9a-f]{64}$/', $storedHash) !== 1) {
            $reasons[] = 'content_hash_not_lowercase_hex';
        } elseif (! hash_equals($recomputed, $storedHash)) {
            $reasons[] = 'content_hash_mismatch';
        }

        // (c) a frozen record is immutable: its hash must still match its content.
        $frozenImmutable = true;
        if ($status === self::STATUS_FROZEN
            && $storedHash !== ''
            && ! hash_equals($recomputed, $storedHash)) {
            $frozenImmutable = false;
            $reasons[] = 'frozen_record_mutated';
        }

        // (d) a superseded record must point to a newer record.
        if ($status === self::STATUS_SUPERSEDED) {
            $supersededBy = is_string($record['superseded_by'] ?? null) ? trim($record['superseded_by']) : '';
            if ($supersededBy === '') {
                $reasons[] = 'superseded_without_successor';
            }
        }

        return [
            'schema' => self::SCHEMA,
            'valid' => $reasons === [],
            'status' => $status,
            'family' => $this->isKnownFamily($schemaVersion) ? $schemaVersion : null,
            'recomputed_hash' => $recomputed,
            'frozen_immutable' => $frozenImmutable,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * Supersede ordering: the successor MUST be a strictly newer version.
     *
     * Invariant "Supersede points to the newer version." A record may never be
     * superseded by an equal or older version of the same family name.
     */
    public function isValidSupersede(int $predecessorVersion, int $successorVersion): bool
    {
        return $successorVersion > $predecessorVersion;
    }

    /**
     * Staleness surfacing for a task blueprint.
     *
     * Invariant "Stale task blueprints are visible when upstream
     * contract/project blueprint changes." A task blueprint built against a
     * given upstream content_hash is stale the moment the live upstream hash
     * diverges from it.
     */
    public function isStale(string $builtAgainstUpstreamHash, string $currentUpstreamHash): bool
    {
        $built = trim($builtAgainstUpstreamHash);
        $current = trim($currentUpstreamHash);

        if ($built === '' || $current === '') {
            // No anchor to compare against -> treat as stale (fail-visible).
            return true;
        }

        return ! hash_equals($built, $current);
    }

    /**
     * Surface state for a record's gates + acceptance evidence.
     *
     * Invariant "Blocking gates and missing evidence are directly exposed to
     * surfaces." The verdict is `block` when ANY gate blocks OR ANY acceptance
     * criterion lacks an evidence ref. It is never silently `ready`.
     *
     * @param  list<array{id?:string,status?:string}>  $gates
     * @param  list<array{id?:string,evidence_ref?:mixed}>  $acceptance
     * @return array{
     *     schema:string, state:string, blocking_gates:list<string>,
     *     missing_evidence:list<string>, exposed:bool
     * }
     */
    public function surfaceState(array $gates, array $acceptance): array
    {
        $blockingGates = [];
        foreach ($gates as $gate) {
            $gateStatus = is_string($gate['status'] ?? null) ? trim($gate['status']) : '';
            if (in_array($gateStatus, ['block', 'blocking', 'fail', 'failed'], true)) {
                $blockingGates[] = is_string($gate['id'] ?? null) ? (string) $gate['id'] : 'unknown_gate';
            }
        }

        $missingEvidence = [];
        foreach ($acceptance as $criterion) {
            $ref = $criterion['evidence_ref'] ?? null;
            $hasEvidence = is_string($ref) ? trim($ref) !== '' : ! empty($ref);
            if (! $hasEvidence) {
                $missingEvidence[] = is_string($criterion['id'] ?? null) ? (string) $criterion['id'] : 'unnamed_acceptance';
            }
        }

        $state = ($blockingGates === [] && $missingEvidence === []) ? 'ready' : 'block';

        return [
            'schema' => self::SCHEMA,
            'state' => $state,
            'blocking_gates' => $blockingGates,
            'missing_evidence' => $missingEvidence,
            'exposed' => true,
        ];
    }

    /**
     * Evaluate a proposed schema evolution against the Compatibility policy.
     *
     * `Compatibility`: "Schema evolution must be additive unless a migration and
     * compatibility adapter are shipped with tests. App types, API resources and
     * CLI output must evolve in the same implementation wave."
     *
     * Inputs:
     *   from_version, to_version: int
     *   additive: bool  (true => only fields added)
     *   has_migration, has_compat_adapter, has_tests: bool
     *   wave: list<string>  (declared surfaces evolving together)
     *
     * @param  array{
     *     from_version?:int, to_version?:int, additive?:bool,
     *     has_migration?:bool, has_compat_adapter?:bool, has_tests?:bool,
     *     wave?:list<string>
     * }  $change
     * @return array{
     *     schema:string, allowed:bool, additive:bool, reasons:list<string>
     * }
     */
    public function evaluateEvolution(array $change): array
    {
        $reasons = [];

        $from = (int) ($change['from_version'] ?? 0);
        $to = (int) ($change['to_version'] ?? 0);
        $additive = (bool) ($change['additive'] ?? false);
        $hasMigration = (bool) ($change['has_migration'] ?? false);
        $hasAdapter = (bool) ($change['has_compat_adapter'] ?? false);
        $hasTests = (bool) ($change['has_tests'] ?? false);
        $wave = array_values(array_filter(
            (array) ($change['wave'] ?? []),
            static fn ($s): bool => is_string($s) && trim($s) !== ''
        ));

        // Version must advance by exactly one integer step.
        if ($to !== $from + 1) {
            $reasons[] = 'version_bump_must_be_single_step';
        }

        // A non-additive change requires migration + adapter + tests.
        if (! $additive) {
            if (! $hasMigration) {
                $reasons[] = 'non_additive_requires_migration';
            }
            if (! $hasAdapter) {
                $reasons[] = 'non_additive_requires_compat_adapter';
            }
            if (! $hasTests) {
                $reasons[] = 'non_additive_requires_tests';
            }
        }

        // Same-wave evolution of the downstream surfaces is mandatory.
        $required = ['app_types', 'api_resources', 'cli_output'];
        foreach ($required as $surface) {
            if (! in_array($surface, $wave, true)) {
                $reasons[] = 'missing_wave_surface_' . $surface;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'allowed' => $reasons === [],
            'additive' => $additive,
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * Recursively sort associative-array keys so the hash is order-independent.
     *
     * @param  mixed  $value
     * @return mixed
     */
    private function canonicalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $isList = array_is_list($value);

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->canonicalise($item);
        }

        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
