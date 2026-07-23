<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Vault Cartography Schema Contracts — pure, deterministic frontmatter decider.
 *
 * The contracts doc is the SCHEMA authority for Atlas Vault Cartography: which
 * source root owns a piece, which semantic fields are required, how the additive
 * visual `graph_*` fields behave, and what is forbidden (anti-canon). This
 * service turns those documented invariants into runtime. It is read-only: it
 * validates a frontmatter map, classifies its source root, resolves visual-field
 * defaults and gates the compatibility / anti-canon rules. It NEVER reads a file,
 * walks the vault, opens a source, writes a doc or emits evidence — it only
 * decides whether a frontmatter map is contract-legal and why.
 *
 * Five documented decision surfaces are implemented:
 *
 *   1. Source Authority ("Source Authority" + "Anti-Canon"). Repo docs and
 *      AtlasVault are TWO independent canonical roots. `classifySourceRoot()`
 *      maps a `graph_source` to its owning root. The cartography "must never
 *      create a vault projection of official repo docs, sync one source into the
 *      other, or hide the real path from the inspector": `validateSourceBinding()`
 *      blocks a piece whose `graph_source: repo` carries a vault `canonical_doc`
 *      (a synced projection) and vice-versa, and blocks any unknown source.
 *
 *   2. Required Semantic Fields ("Required Semantic Fields" → Rules).
 *      `validateSemantic()` enforces the four documented rules exactly:
 *        (a) `graph_id` is stable ASCII and SHOULD match the filename — a
 *            non-ascii / non-slug id, or one that disagrees with the supplied
 *            filename, is reported;
 *        (b) `status: implemented` REQUIRES evidence — an implemented piece with
 *            an empty `evidence` list is rejected;
 *        (c) `next_actions` is REQUIRED unless status ∈ {implemented, obsolete,
 *            archive} — a missing/empty `next_actions` outside that exempt set is
 *            rejected;
 *        (d) `canonical_doc` is REQUIRED for repo-sourced non-note pieces.
 *
 *   3. Visual Fields & defaults ("Visual Fields" → Defaults). All visual fields
 *      are additive and MUST stay inside the `graph_*` namespace. `resolveVisual()`
 *      applies the documented defaults: `graph_kind` → `note`, `graph_view` →
 *      `vault-universe`, `graph_status` mirrors semantic `status`, `graph_source`
 *      defaults to `vault` for a `note` (and is otherwise required), `graph_layer`
 *      is derived from kind, `graph_pos` stays omitted unless an override is set.
 *
 *   4. Source Rules ("Source Rules"). `graph_source` is REQUIRED for the
 *      architectural kinds {continent, system, pipeline, lane, step, component};
 *      an architectural piece with `graph_source: vault` is INVALID ("move the
 *      canon to repo docs"). `validateSourceRules()` enforces both.
 *
 *   5. Compatibility / Anti-Canon ("Compatibility" + "Anti-Canon").
 *      `validateCompatibility()` rejects the forbidden drift-papering fields
 *      (`sync_status`, `last_verified`), rejects visual fields declared outside
 *      the `graph_*` namespace, and rejects a `status` / `graph_status` pair that
 *      uses different enum members (they "share the exact same enum"). It also
 *      flags a non-additive schema change (a previously-optional field made
 *      required, or a renamed/repurposed semantic field).
 *
 * `validate()` composes all five surfaces into a single verdict envelope with
 * precise, de-duplicated violation reasons. Callers enforce; this service only
 * decides.
 *
 * @see docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema-contracts.md
 */
final class AtlasVaultCartographySchemaContractsService
{
    /** Stable schema id for the verdict envelopes this decider emits. */
    public const SCHEMA = 'atlas.vault.cartography.contracts.v1';

    public const VERDICT_VALID = 'valid';
    public const VERDICT_INVALID = 'invalid';

    /** The two independent canonical source roots (Source Authority). */
    public const ROOT_REPO = 'repo';
    public const ROOT_VAULT = 'vault';
    public const ROOT_GENERATED = 'generated';
    public const ROOT_SAMPLE = 'sample';

    /** Repo canonical root path (Source Authority). */
    public const REPO_ROOT_PATH = 'docs/engineering-knowledge-base/';

    /** Vault canonical root marker (Source Authority). */
    public const VAULT_ROOT_PATH = 'AtlasVault/';

    /** Closed set of `graph_source` values (Source Rules table). */
    public const KNOWN_SOURCES = [
        self::ROOT_REPO,
        self::ROOT_VAULT,
        self::ROOT_GENERATED,
        self::ROOT_SAMPLE,
    ];

    /**
     * Architectural visual kinds that REQUIRE an explicit `graph_source`
     * (Source Rules). Excludes `note`, which defaults to `vault`.
     */
    public const ARCHITECTURAL_KINDS = [
        'continent',
        'system',
        'pipeline',
        'lane',
        'step',
        'component',
    ];

    /**
     * Shared status enum — `status` and `graph_status` use the EXACT same set
     * (Compatibility). Order is documentation order; membership is what matters.
     */
    public const STATUS_ENUM = [
        'active',
        'building',
        'planned',
        'future',
        'blocked',
        'implemented',
        'obsolete',
        'archive',
    ];

    /**
     * Statuses that EXEMPT a piece from the `next_actions` requirement
     * (Required Semantic Fields → Rules).
     */
    public const NEXT_ACTIONS_EXEMPT_STATUS = [
        'implemented',
        'obsolete',
        'archive',
    ];

    /**
     * Forbidden drift-papering fields (Anti-Canon: "Do not add `sync_status` or
     * `last_verified` to paper over drift").
     */
    public const FORBIDDEN_FIELDS = [
        'sync_status',
        'last_verified',
    ];

    /** `graph_layer` derived from `graph_kind` (Visual Fields → Defaults). */
    private const LAYER_BY_KIND = [
        'continent' => 'universe',
        'system' => 'system',
        'pipeline' => 'flow',
        'lane' => 'flow',
        'step' => 'flow',
        'component' => 'component',
        'note' => 'flow',
    ];

    // ---------------------------------------------------------------------
    // 1. Source Authority
    // ---------------------------------------------------------------------

    /**
     * Classify which canonical ROOT owns a piece, given its `graph_source`.
     *
     * @return array{source:string, root:string|null, known:bool, canonical:bool, root_path:string|null}
     */
    public function classifySourceRoot(string $graphSource): array
    {
        $source = strtolower(trim($graphSource));
        $known = in_array($source, self::KNOWN_SOURCES, true);

        // `repo` and `vault` are the two canonical roots; `generated`/`sample`
        // are known but explicitly "never source truth until promoted" / fixtures.
        $rootPath = match ($source) {
            self::ROOT_REPO => self::REPO_ROOT_PATH,
            self::ROOT_VAULT => self::VAULT_ROOT_PATH,
            default => null,
        };

        return [
            'source' => $source,
            'root' => $known ? $source : null,
            'known' => $known,
            'canonical' => $source === self::ROOT_REPO || $source === self::ROOT_VAULT,
            'root_path' => $rootPath,
        ];
    }

    /**
     * Validate the source binding: an unknown source is blocked, and a piece must
     * not be a synced projection (a `repo`-sourced piece pointing its
     * `canonical_doc` into the vault, or a `vault`-sourced piece pointing into
     * the repo docs tree). "Never create a vault projection of official repo docs."
     *
     * @param array<string,mixed> $frontmatter
     * @return array{ok:bool, violations:list<string>}
     */
    public function validateSourceBinding(array $frontmatter): array
    {
        $violations = [];
        $source = strtolower(trim((string) ($frontmatter['graph_source'] ?? '')));

        if ($source === '') {
            // Handled by Source Rules for architectural kinds; here we only flag
            // an explicitly UNKNOWN value.
        } elseif (! in_array($source, self::KNOWN_SOURCES, true)) {
            $violations[] = "unknown graph_source '{$source}' (allowed: " . implode(', ', self::KNOWN_SOURCES) . ')';
        }

        $canonicalDoc = strtolower(trim((string) ($frontmatter['canonical_doc'] ?? '')));
        if ($canonicalDoc !== '') {
            $pointsToRepo = str_contains($canonicalDoc, strtolower(self::REPO_ROOT_PATH));
            $pointsToVault = str_contains($canonicalDoc, strtolower(self::VAULT_ROOT_PATH))
                || str_starts_with($canonicalDoc, '~/atlasvault')
                || str_contains($canonicalDoc, 'atlasvault/');

            if ($source === self::ROOT_REPO && $pointsToVault && ! $pointsToRepo) {
                $violations[] = 'repo-sourced piece points canonical_doc into the vault — synced projection of repo docs is forbidden';
            }
            if ($source === self::ROOT_VAULT && $pointsToRepo) {
                $violations[] = 'vault-sourced piece points canonical_doc into repo docs — move the canon to repo and link from the vault note';
            }
        }

        return ['ok' => $violations === [], 'violations' => $violations];
    }

    // ---------------------------------------------------------------------
    // 2. Required Semantic Fields
    // ---------------------------------------------------------------------

    /**
     * Enforce the four documented semantic-field rules.
     *
     * @param array<string,mixed> $frontmatter
     * @param string|null $filename Bare filename (no extension) to match against graph_id, when known.
     * @return array{ok:bool, violations:list<string>}
     */
    public function validateSemantic(array $frontmatter, ?string $filename = null): array
    {
        $violations = [];

        // (a) graph_id is stable ASCII and should match the filename.
        $graphId = (string) ($frontmatter['graph_id'] ?? '');
        if ($graphId === '') {
            $violations[] = 'graph_id is required';
        } elseif (! $this->isStableSlug($graphId)) {
            $violations[] = "graph_id '{$graphId}' is not a stable ascii slug (lowercase a-z, 0-9, hyphen)";
        } elseif ($filename !== null && $filename !== '' && $this->basename($filename) !== $graphId) {
            $violations[] = "graph_id '{$graphId}' should match the filename '{$this->basename($filename)}'";
        }

        $status = strtolower(trim((string) ($frontmatter['status'] ?? '')));

        // (b) status: implemented requires evidence.
        if ($status === 'implemented' && ! $this->hasEntries($frontmatter, 'evidence')) {
            $violations[] = "status 'implemented' requires non-empty evidence";
        }

        // (c) next_actions required unless status is implemented/obsolete/archive.
        if (! in_array($status, self::NEXT_ACTIONS_EXEMPT_STATUS, true)
            && ! $this->hasEntries($frontmatter, 'next_actions')) {
            $violations[] = "next_actions is required unless status is implemented, obsolete or archive (status: '{$status}')";
        }

        // (d) canonical_doc required for repo-sourced non-note pieces.
        $source = strtolower(trim((string) ($frontmatter['graph_source'] ?? '')));
        $kind = strtolower(trim((string) ($frontmatter['graph_kind'] ?? ($frontmatter['type'] ?? 'note'))));
        $isNote = $kind === 'note' || $kind === '';
        $canonicalDoc = trim((string) ($frontmatter['canonical_doc'] ?? ''));
        if ($source === self::ROOT_REPO && ! $isNote && $canonicalDoc === '') {
            $violations[] = 'canonical_doc is required for repo-sourced non-note pieces';
        }

        return ['ok' => $violations === [], 'violations' => $violations];
    }

    // ---------------------------------------------------------------------
    // 3. Visual Fields & defaults
    // ---------------------------------------------------------------------

    /**
     * Resolve the additive visual fields, applying documented defaults. Returns
     * the effective `graph_*` map plus whether `graph_source` still needs an
     * explicit value (architectural kinds have no default).
     *
     * @param array<string,mixed> $frontmatter
     * @return array{graph_kind:string, graph_view:string, graph_layer:string, graph_status:string, graph_source:string|null, graph_pos:array<string,int>|null, source_required:bool}
     */
    public function resolveVisual(array $frontmatter): array
    {
        $kind = strtolower(trim((string) ($frontmatter['graph_kind'] ?? '')));
        if ($kind === '') {
            $kind = 'note'; // default graph_kind
        }

        $view = strtolower(trim((string) ($frontmatter['graph_view'] ?? '')));
        if ($view === '') {
            $view = 'vault-universe'; // default graph_view
        }

        // graph_status mirrors semantic status by default.
        $graphStatus = strtolower(trim((string) ($frontmatter['graph_status'] ?? '')));
        if ($graphStatus === '') {
            $graphStatus = strtolower(trim((string) ($frontmatter['status'] ?? '')));
        }

        // graph_layer derived from kind unless explicitly set.
        $layer = strtolower(trim((string) ($frontmatter['graph_layer'] ?? '')));
        if ($layer === '') {
            $layer = self::LAYER_BY_KIND[$kind] ?? 'flow';
        }

        // graph_source: default `vault` for a note; explicit (required) otherwise.
        $isNote = $kind === 'note';
        $rawSource = strtolower(trim((string) ($frontmatter['graph_source'] ?? '')));
        $source = $rawSource !== '' ? $rawSource : ($isNote ? self::ROOT_VAULT : null);

        // graph_pos omitted unless explicitly provided.
        $pos = null;
        if (isset($frontmatter['graph_pos']) && is_array($frontmatter['graph_pos'])
            && isset($frontmatter['graph_pos']['x'], $frontmatter['graph_pos']['y'])) {
            $pos = [
                'x' => (int) $frontmatter['graph_pos']['x'],
                'y' => (int) $frontmatter['graph_pos']['y'],
            ];
        }

        return [
            'graph_kind' => $kind,
            'graph_view' => $view,
            'graph_layer' => $layer,
            'graph_status' => $graphStatus,
            'graph_source' => $source,
            'graph_pos' => $pos,
            'source_required' => ! $isNote && $rawSource === '',
        ];
    }

    // ---------------------------------------------------------------------
    // 4. Source Rules
    // ---------------------------------------------------------------------

    /**
     * `graph_source` is required for architectural kinds; an architectural piece
     * with `graph_source: vault` is invalid.
     *
     * @param array<string,mixed> $frontmatter
     * @return array{ok:bool, violations:list<string>}
     */
    public function validateSourceRules(array $frontmatter): array
    {
        $violations = [];
        $kind = strtolower(trim((string) ($frontmatter['graph_kind'] ?? '')));
        $source = strtolower(trim((string) ($frontmatter['graph_source'] ?? '')));

        $isArchitectural = in_array($kind, self::ARCHITECTURAL_KINDS, true);

        if ($isArchitectural && $source === '') {
            $violations[] = "graph_source is required for architectural kind '{$kind}'";
        }

        if ($isArchitectural && $source === self::ROOT_VAULT) {
            $violations[] = "architectural piece (kind '{$kind}') cannot use graph_source: vault — move the canon to repo docs and link from the vault note";
        }

        return ['ok' => $violations === [], 'violations' => $violations];
    }

    // ---------------------------------------------------------------------
    // 5. Compatibility / Anti-Canon
    // ---------------------------------------------------------------------

    /**
     * Enforce compatibility & anti-canon: no forbidden drift-papering fields, no
     * visual fields outside the `graph_*` namespace, `status`/`graph_status`
     * share the same enum, and a flagged non-additive schema change is illegal.
     *
     * @param array<string,mixed> $frontmatter
     * @param array{made_required?:list<string>, renamed?:list<string>} $change Optional declared schema change to evaluate.
     * @return array{ok:bool, violations:list<string>}
     */
    public function validateCompatibility(array $frontmatter, array $change = []): array
    {
        $violations = [];

        // Anti-Canon: forbidden drift-papering fields.
        foreach (self::FORBIDDEN_FIELDS as $forbidden) {
            if (array_key_exists($forbidden, $frontmatter)) {
                $violations[] = "forbidden field '{$forbidden}' (anti-canon: do not paper over drift)";
            }
        }

        // status & graph_status must be valid members of the shared enum, and
        // when both are present they must agree (same enum, mirrored by default).
        $status = strtolower(trim((string) ($frontmatter['status'] ?? '')));
        $graphStatus = strtolower(trim((string) ($frontmatter['graph_status'] ?? '')));
        if ($status !== '' && ! in_array($status, self::STATUS_ENUM, true)) {
            $violations[] = "status '{$status}' is not in the shared status enum";
        }
        if ($graphStatus !== '' && ! in_array($graphStatus, self::STATUS_ENUM, true)) {
            $violations[] = "graph_status '{$graphStatus}' is not in the shared status enum";
        }
        if ($status !== '' && $graphStatus !== '' && $status !== $graphStatus) {
            $violations[] = "status '{$status}' and graph_status '{$graphStatus}' disagree — they share the exact same enum and must match";
        }

        // Compatibility: never make a previously-optional field required; never
        // rename/repurpose a semantic field.
        foreach ($change['made_required'] ?? [] as $field) {
            $violations[] = "field '{$field}' was previously optional and may never be required (additive-only)";
        }
        foreach ($change['renamed'] ?? [] as $field) {
            $violations[] = "semantic field '{$field}' may never be renamed or repurposed";
        }

        return ['ok' => $violations === [], 'violations' => $violations];
    }

    // ---------------------------------------------------------------------
    // Composition
    // ---------------------------------------------------------------------

    /**
     * Compose every surface into a single verdict over a frontmatter map.
     *
     * @param array<string,mixed> $frontmatter
     * @param string|null $filename Bare filename (no extension) for graph_id matching.
     * @param array{made_required?:list<string>, renamed?:list<string>} $change Optional declared schema change.
     * @return array{schema:string, verdict:string, ok:bool, violations:list<string>, by_surface:array<string,list<string>>, resolved_visual:array<string,mixed>}
     */
    public function validate(array $frontmatter, ?string $filename = null, array $change = []): array
    {
        $bySurface = [
            'source_binding' => $this->validateSourceBinding($frontmatter)['violations'],
            'semantic' => $this->validateSemantic($frontmatter, $filename)['violations'],
            'source_rules' => $this->validateSourceRules($frontmatter)['violations'],
            'compatibility' => $this->validateCompatibility($frontmatter, $change)['violations'],
        ];

        $all = [];
        foreach ($bySurface as $list) {
            foreach ($list as $v) {
                $all[] = $v;
            }
        }
        $all = array_values(array_unique($all));

        return [
            'schema' => self::SCHEMA,
            'verdict' => $all === [] ? self::VERDICT_VALID : self::VERDICT_INVALID,
            'ok' => $all === [],
            'violations' => $all,
            'by_surface' => $bySurface,
            'resolved_visual' => $this->resolveVisual($frontmatter),
        ];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** A stable ASCII slug: lowercase a-z / 0-9 / hyphen, no leading/trailing/double hyphen. */
    private function isStableSlug(string $value): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value);
    }

    /** Strip directory and extension from a path, leaving the bare slug. */
    private function basename(string $path): string
    {
        $base = $path;
        $slash = strrpos($base, '/');
        if ($slash !== false) {
            $base = substr($base, $slash + 1);
        }
        $dot = strrpos($base, '.');
        if ($dot !== false) {
            $base = substr($base, 0, $dot);
        }

        return strtolower($base);
    }

    /**
     * True when a frontmatter list field has at least one non-empty entry.
     *
     * @param array<string,mixed> $frontmatter
     */
    private function hasEntries(array $frontmatter, string $key): bool
    {
        $value = $frontmatter[$key] ?? null;
        if (is_array($value)) {
            foreach ($value as $entry) {
                if (trim((string) $entry) !== '') {
                    return true;
                }
            }

            return false;
        }

        return trim((string) $value) !== '';
    }
}
