<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Vault Cartography Schema — pure, deterministic INDEX-level cartography
 * router / auditor.
 *
 * The parent index doc sits ABOVE its two child specs (contracts + runbook). Its
 * own contract is not the frontmatter schema (that is the contracts child,
 * implemented by {@see AtlasVaultCartographySchemaContractsService}); it is the
 * behaviour of the cartography AS A SOURCE-AWARE READER, NAVIGATOR AND AUDITOR
 * over two INDEPENDENT canonical roots. "The cartography is a reader, navigator
 * and auditor over two independent canonical sources." It is "never a source of
 * truth", it "renders real files, shows their origin and path, and fails closed
 * when a file is missing". This service turns those index-level rules into
 * runtime. It is read-only and deterministic: it never reads a file, walks the
 * vault, opens a source, writes a doc or emits evidence — it only decides routing,
 * resolution status and which non-negotiables a piece violates.
 *
 * Four documented decision surfaces are implemented:
 *
 *   1. Source Authority routing (Canon table). The index doc states which root
 *      OWNS which content class: repo docs are authoritative for "Architecture,
 *      contracts, operations, Kernel, Runtime, policies and auditable blockers";
 *      AtlasVault is authoritative for "Human memory, books, philosophy,
 *      marginalia, stories, hypotheses and reflective writing". {@see routeContentClass()}
 *      maps a content class to its owning root + canonical root path.
 *
 *   2. Resolution status / fail-closed (Canon + Non-Negotiables + runbook Missing
 *      Source). "It renders real files ... and fails closed when a file is
 *      missing." A piece is `resolved` only when its source file is present;
 *      otherwise it is `missing_source`, which is "a visible drift state, never an
 *      acceptable default" and must "never render a missing file as truth".
 *      {@see resolvePiece()} computes the status, the truth-renderability flag, the
 *      kept origin/source-relative path, and (for a miss) the documented drift
 *      affordances (show expected path, disable lying open actions, emit drift).
 *
 *   3. Open-action routing (runbook Reader Model: Open row). A repo-sourced piece
 *      opens via "code editor / OS handler"; a vault-sourced piece opens via
 *      "obsidian://open". A missing source disables "open source actions that would
 *      lie". {@see resolveOpenAction()} returns the source-aware handler + whether
 *      it is enabled.
 *
 *   4. Non-Negotiables gate (index "Non-Negotiables"). The five hard rules:
 *      (a) do not mirror repo docs into AtlasVault;
 *      (b) do not treat AI narration as source truth;
 *      (c) do not use `graph_source: vault` for canonical architectural pipelines,
 *          lanes, steps or components;
 *      (d) do not introduce fields outside the `graph_*` namespace for visual
 *          concerns;
 *      (e) do not render missing files as truth (use `missing_source`).
 *      {@see auditNonNegotiables()} checks a piece against all five and reports the
 *      breached rule ids with reasons.
 *
 * {@see assess()} composes routing, resolution and the non-negotiable audit into a
 * single verdict envelope. Callers enforce; this service only decides.
 *
 * @see docs/engineering-knowledge-base/vault/atlas-vault-cartography-schema.md
 */
final class AtlasVaultCartographySchemaService
{
    /** Stable schema id for the verdict envelopes this decider emits. */
    public const SCHEMA = 'atlas.vault.cartography.index.v1';

    /** The two INDEPENDENT canonical roots (Canon table). */
    public const ROOT_REPO = 'repo';
    public const ROOT_VAULT = 'vault';

    /** Canonical root paths (child contracts Source Authority). */
    public const REPO_ROOT_PATH = 'docs/engineering-knowledge-base/';
    public const VAULT_ROOT_MARKER = 'AtlasVault/';

    /** Resolution statuses (Canon "fails closed" + runbook Missing Source). */
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_MISSING = 'missing_source';

    /** Open-action handlers (runbook Reader Model → Open row). */
    public const OPEN_REPO = 'os-handler';
    public const OPEN_VAULT = 'obsidian://open';
    public const OPEN_DISABLED = 'disabled';

    public const VERDICT_OK = 'ok';
    public const VERDICT_BLOCKED = 'blocked';

    /**
     * Content classes the REPO root is authoritative for (Canon table, repo row).
     * Lowercased single tokens.
     */
    public const REPO_AUTHORITY = [
        'architecture',
        'contracts',
        'operations',
        'kernel',
        'runtime',
        'policies',
        'blockers',
        'evidence',
        'forge',
    ];

    /**
     * Content classes the VAULT root is authoritative for (Canon table, vault row).
     */
    public const VAULT_AUTHORITY = [
        'human-memory',
        'memory',
        'books',
        'philosophy',
        'marginalia',
        'stories',
        'hypotheses',
        'reflective',
    ];

    /**
     * Architectural visual kinds that may NEVER carry `graph_source: vault`
     * (Non-Negotiables: "Do not use `graph_source: vault` for canonical
     * architectural pipelines, lanes, steps or components").
     */
    public const ARCHITECTURAL_KINDS = [
        'pipeline',
        'lane',
        'step',
        'component',
        'continent',
        'system',
    ];

    // ---------------------------------------------------------------------
    // 1. Source Authority routing
    // ---------------------------------------------------------------------

    /**
     * Route a content class to its owning canonical root per the Canon table.
     *
     * @return array{class:string, root:string|null, root_path:string|null, known:bool}
     */
    public function routeContentClass(string $contentClass): array
    {
        $class = strtolower(trim($contentClass));

        if (in_array($class, self::REPO_AUTHORITY, true)) {
            return [
                'class' => $class,
                'root' => self::ROOT_REPO,
                'root_path' => self::REPO_ROOT_PATH,
                'known' => true,
            ];
        }

        if (in_array($class, self::VAULT_AUTHORITY, true)) {
            return [
                'class' => $class,
                'root' => self::ROOT_VAULT,
                'root_path' => self::VAULT_ROOT_MARKER,
                'known' => true,
            ];
        }

        return [
            'class' => $class,
            'root' => null,
            'root_path' => null,
            'known' => false,
        ];
    }

    // ---------------------------------------------------------------------
    // 2. Resolution status / fail-closed
    // ---------------------------------------------------------------------

    /**
     * Resolve a single cartography piece against the documented fail-closed rule.
     * The cartography "fails closed when a file is missing" and must "never render
     * a missing file as truth"; a missing source is "a visible drift state, never
     * an acceptable default".
     *
     * The caller supplies whether the real source file is present (`source_present`).
     * This service NEVER touches the filesystem; it only decides the consequences.
     *
     * @param array{graph_source?:string, source_present?:bool, expected_path?:string, cached_content?:string} $piece
     * @return array{
     *     status:string,
     *     source:string,
     *     renderable_as_truth:bool,
     *     expected_path:string,
     *     show_expected_path:bool,
     *     show_cached_content:bool,
     *     open_disabled:bool,
     *     emit_drift_evidence:bool,
     *     reasons:list<string>
     * }
     */
    public function resolvePiece(array $piece): array
    {
        $source = strtolower(trim((string) ($piece['graph_source'] ?? '')));
        $present = (bool) ($piece['source_present'] ?? false);
        $expectedPath = (string) ($piece['expected_path'] ?? '');
        $hasCached = trim((string) ($piece['cached_content'] ?? '')) !== '';

        if ($present) {
            return [
                'status' => self::STATUS_RESOLVED,
                'source' => $source,
                'renderable_as_truth' => true,
                'expected_path' => $expectedPath,
                'show_expected_path' => true,
                'show_cached_content' => false,
                'open_disabled' => false,
                'emit_drift_evidence' => false,
                'reasons' => [],
            ];
        }

        // Missing source: fail closed. Visible drift, never truth, never the
        // default. Show expected path + last known cached content if available,
        // disable lying "open source" actions, emit documentation drift evidence.
        return [
            'status' => self::STATUS_MISSING,
            'source' => $source,
            'renderable_as_truth' => false,
            'expected_path' => $expectedPath,
            'show_expected_path' => true,
            'show_cached_content' => $hasCached,
            'open_disabled' => true,
            'emit_drift_evidence' => true,
            'reasons' => ['missing source — fail closed; render as missing_source, never as truth'],
        ];
    }

    // ---------------------------------------------------------------------
    // 3. Open-action routing
    // ---------------------------------------------------------------------

    /**
     * Decide the source-aware "open source" action for a piece (runbook Reader
     * Model → Open). Repo → code editor / OS handler; vault → obsidian://open. A
     * missing source disables any open action "that would lie".
     *
     * @param array{graph_source?:string, source_present?:bool} $piece
     * @return array{handler:string, enabled:bool, reason:string}
     */
    public function resolveOpenAction(array $piece): array
    {
        $present = (bool) ($piece['source_present'] ?? false);
        if (! $present) {
            return [
                'handler' => self::OPEN_DISABLED,
                'enabled' => false,
                'reason' => 'missing source — open action disabled (would lie)',
            ];
        }

        $source = strtolower(trim((string) ($piece['graph_source'] ?? '')));

        return match ($source) {
            self::ROOT_REPO => [
                'handler' => self::OPEN_REPO,
                'enabled' => true,
                'reason' => 'repo source opens via code editor / OS handler',
            ],
            self::ROOT_VAULT => [
                'handler' => self::OPEN_VAULT,
                'enabled' => true,
                'reason' => 'vault source opens via obsidian://open',
            ],
            default => [
                'handler' => self::OPEN_DISABLED,
                'enabled' => false,
                'reason' => "source '{$source}' has no source-aware open handler",
            ],
        };
    }

    // ---------------------------------------------------------------------
    // 4. Non-Negotiables gate
    // ---------------------------------------------------------------------

    /**
     * Audit a piece against the five index-level Non-Negotiables.
     *
     * @param array{
     *     graph_source?:string,
     *     graph_kind?:string,
     *     source_present?:bool,
     *     renders_as_truth?:bool,
     *     mirrors_repo_into_vault?:bool,
     *     treated_as_truth?:string,
     *     extra_visual_fields?:list<string>
     * } $piece
     * @return array{ok:bool, breaches:list<array{rule:string, reason:string}>}
     */
    public function auditNonNegotiables(array $piece): array
    {
        $breaches = [];

        $source = strtolower(trim((string) ($piece['graph_source'] ?? '')));
        $kind = strtolower(trim((string) ($piece['graph_kind'] ?? '')));
        $present = (bool) ($piece['source_present'] ?? false);

        // (a) Do not mirror repo docs into AtlasVault.
        if (($piece['mirrors_repo_into_vault'] ?? false) === true) {
            $breaches[] = [
                'rule' => 'no-repo-to-vault-mirror',
                'reason' => 'piece mirrors repo docs into AtlasVault — repo and vault are independent canonical sources',
            ];
        }

        // (b) Do not treat AI narration as source truth.
        if (strtolower(trim((string) ($piece['treated_as_truth'] ?? ''))) === 'ai-narration') {
            $breaches[] = [
                'rule' => 'no-narration-as-truth',
                'reason' => 'AI narration treated as source truth — the human audits the real file',
            ];
        }

        // (c) Do not use graph_source: vault for canonical architectural kinds.
        if ($source === self::ROOT_VAULT && in_array($kind, self::ARCHITECTURAL_KINDS, true)) {
            $breaches[] = [
                'rule' => 'no-vault-source-for-architecture',
                'reason' => "graph_source: vault on architectural kind '{$kind}' — move the canon to repo docs",
            ];
        }

        // (d) Do not introduce visual fields outside the graph_* namespace.
        foreach ($piece['extra_visual_fields'] ?? [] as $field) {
            $name = (string) $field;
            if (! str_starts_with($name, 'graph_')) {
                $breaches[] = [
                    'rule' => 'graph-namespace-only',
                    'reason' => "visual field '{$name}' is outside the graph_* namespace",
                ];
            }
        }

        // (e) Do not render missing files as truth.
        if (! $present && ($piece['renders_as_truth'] ?? false) === true) {
            $breaches[] = [
                'rule' => 'no-missing-as-truth',
                'reason' => 'missing source rendered as truth — must use missing_source',
            ];
        }

        return ['ok' => $breaches === [], 'breaches' => $breaches];
    }

    // ---------------------------------------------------------------------
    // Composition
    // ---------------------------------------------------------------------

    /**
     * Compose routing, resolution and the non-negotiable audit into one verdict.
     *
     * @param array<string,mixed> $piece
     * @return array{
     *     schema:string,
     *     verdict:string,
     *     ok:bool,
     *     resolution:array<string,mixed>,
     *     open_action:array<string,mixed>,
     *     non_negotiables:array<string,mixed>,
     *     blocking_reasons:list<string>
     * }
     */
    public function assess(array $piece): array
    {
        $resolution = $this->resolvePiece($piece);
        $open = $this->resolveOpenAction($piece);
        $audit = $this->auditNonNegotiables($piece);

        $blocking = [];
        // A piece is blocked when it breaches a non-negotiable OR when it is
        // missing source yet claims truth-renderability via the audit (e).
        foreach ($audit['breaches'] as $breach) {
            $blocking[] = $breach['rule'] . ': ' . $breach['reason'];
        }

        return [
            'schema' => self::SCHEMA,
            'verdict' => $blocking === [] ? self::VERDICT_OK : self::VERDICT_BLOCKED,
            'ok' => $blocking === [],
            'resolution' => $resolution,
            'open_action' => $open,
            'non_negotiables' => $audit,
            'blocking_reasons' => $blocking,
        ];
    }
}
