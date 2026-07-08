<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Documentation Operating System (system-graph node `doc-os`) — pure,
 * deterministic decider that governs how a single canonical doc is allowed to
 * enter the Cartography as a real repo source.
 *
 * The doc states the contract plainly: input is "docs canonicas, schemas,
 * indices e KB"; output is "contexto verificavel e mapa navegavel"; the
 * invariant is "doc oficial e repo-first para tecnica". The flow is "Docs
 * oficiais sao validadas por schema. Cartografia le a fonte real." And the
 * load-bearing rule lives in Exemplos: "Uma etapa do Kernel so entra na
 * Cartografia como repo source quando tem frontmatter canonico validado."
 *
 * This service turns that one decision into runtime. It is read-only and
 * deterministic: it never reads a file, walks the repo, opens a source, writes
 * a doc or emits evidence — it only inspects a frontmatter array and decides
 * whether the piece may be admitted to the Cartography as `graph_source: repo`,
 * and if not, exactly which documented rule it breaks.
 *
 * The required v1 frontmatter contract it enforces (from the sibling spec
 * `atlas-canonical-module-doc-v1.md`, the doc's declared dependency) is:
 *   - `doc_schema` MUST equal `atlas_canonical_module_doc.v1`;
 *   - `graph_source` MUST equal `repo` (Exemplos: only a repo source enters);
 *   - the v1 mandatory fields MUST all be present and non-empty;
 *   - `graph_status` MUST be one of the allowed lifecycle values;
 *   - when `requires_evidence` is true, `evidence` MUST be present and non-empty
 *     (forbidden_changes: "Remover evidencia obrigatoria de modulos ativos");
 *   - when `macro_layer` is true, the four macro naming fields MUST be present
 *     (the v1 macro gate);
 *   - `graph_source: vault` or any non-repo source is REJECTED for technical
 *     canon (invariant: repo-first; forbidden: parallel cartography that does
 *     not read the official doc; "depender de resumo de chat como canon").
 *
 * {@see admit()} composes the whole verdict envelope. {@see validateSchema()},
 * {@see evidenceGate()} and {@see macroNamingGate()} are the individual gates.
 * Callers enforce; this service only decides.
 *
 * @see docs/engineering-knowledge-base/system-graph/doc-os.md
 */
final class AtlasSystemGraphDocOsService
{
    /** Stable schema id for the verdict envelopes this decider emits. */
    public const SCHEMA = 'atlas.system_graph.doc_os.v1';

    /** The doc_schema value a piece must carry to be canonical v1 (Contratos). */
    public const CANONICAL_DOC_SCHEMA = 'atlas_canonical_module_doc.v1';

    /** Cartography only admits a repo-first technical source (Exemplos + invariant). */
    public const SOURCE_REPO = 'repo';

    /**
     * v1 mandatory frontmatter fields (Contratos of atlas-canonical-module-doc-v1).
     * Every one must be present and non-empty before a piece is repo-source ready.
     *
     * @var list<string>
     */
    private const REQUIRED_FRONTMATTER = [
        'doc_schema',
        'graph_id',
        'graph_title',
        'graph_world',
        'graph_layer',
        'graph_kind',
        'graph_parent',
        'graph_status',
        'graph_source',
        'owner',
        'repo_paths',
        'allowed_changes',
        'forbidden_changes',
        'evidence',
        'required_tests',
        'risk_level',
        'next_actions',
    ];

    /** Allowed lifecycle values for `graph_status`. */
    private const ALLOWED_STATUS = ['planned', 'future', 'building', 'active', 'deprecated'];

    /** Allowed values for `graph_layer`. */
    private const ALLOWED_LAYERS = ['world', 'system', 'flow', 'module', 'gear', 'subcomponent'];

    /** Allowed values for `risk_level`. */
    private const ALLOWED_RISK = ['low', 'medium', 'high', 'critical'];

    /**
     * The four macro naming fields required when `macro_layer: true`
     * (v1 macro gate: docs-health blocks a macro layer missing these).
     *
     * @var list<string>
     */
    private const MACRO_NAMING_FIELDS = [
        'product_name',
        'runtime_acronym',
        'internal_product_name',
        'technical_runtime',
    ];

    /**
     * Decide whether a single doc's frontmatter may be admitted to the Cartography
     * as a real repo source, composing every documented gate.
     *
     * `verdict` is one of:
     *   - `repo_source_ready`  : passes every gate; Cartography may render it as a
     *                            `graph_source: repo` node and trust it as canon.
     *   - `rejected`           : breaks at least one rule; it must NOT enter as a
     *                            repo source. `breaches` lists the rule ids.
     *
     * @param array<string,mixed> $frontmatter raw doc frontmatter
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   admitted:bool,
     *   repo_first:bool,
     *   schema_check:array{ok:bool,breaches:list<string>},
     *   evidence_check:array{ok:bool,required:bool,present:bool,breach:?string},
     *   macro_check:array{ok:bool,is_macro:bool,missing:list<string>},
     *   breaches:list<string>,
     *   reasons:list<string>
     * }
     */
    public function admit(array $frontmatter): array
    {
        $schemaCheck = $this->validateSchema($frontmatter);
        $evidenceCheck = $this->evidenceGate($frontmatter);
        $macroCheck = $this->macroNamingGate($frontmatter);

        $breaches = $schemaCheck['breaches'];
        $reasons = [];

        foreach ($schemaCheck['breaches'] as $b) {
            $reasons[] = $this->reasonFor($b);
        }
        if (! $evidenceCheck['ok']) {
            $breaches[] = 'evidence_required_but_missing';
            $reasons[] = 'requires_evidence is true but evidence is empty; an active/building module may not drop mandatory evidence.';
        }
        if (! $macroCheck['ok']) {
            $breaches[] = 'macro_layer_naming_incomplete';
            $reasons[] = 'macro_layer is true but the four macro naming fields are not all present: '.implode(', ', $macroCheck['missing']).'.';
        }

        $breaches = array_values(array_unique($breaches));
        $admitted = $breaches === [];

        // Repo-first invariant: a technical canon source is admissible only when it
        // is repo-sourced. Anything else (e.g. graph_source: vault, chat summary)
        // can never be repo-first canon.
        $repoFirst = ($frontmatter['graph_source'] ?? null) === self::SOURCE_REPO;

        return [
            'schema' => self::SCHEMA,
            'verdict' => $admitted ? 'repo_source_ready' : 'rejected',
            'admitted' => $admitted,
            'repo_first' => $repoFirst,
            'schema_check' => $schemaCheck,
            'evidence_check' => $evidenceCheck,
            'macro_check' => $macroCheck,
            'breaches' => $breaches,
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * Schema gate: presence of every required field, correct doc_schema, repo
     * source, and valid enum values for status/layer/kind-bearing fields.
     *
     * @param array<string,mixed> $frontmatter
     * @return array{ok:bool,breaches:list<string>}
     */
    public function validateSchema(array $frontmatter): array
    {
        $breaches = [];

        foreach (self::REQUIRED_FRONTMATTER as $field) {
            if ($this->isEmpty($frontmatter[$field] ?? null)) {
                $breaches[] = 'missing_field:'.$field;
            }
        }

        // doc_schema must be exactly the canonical v1 value.
        if (($frontmatter['doc_schema'] ?? null) !== self::CANONICAL_DOC_SCHEMA) {
            $breaches[] = 'wrong_doc_schema';
        }

        // Cartography only admits repo-first technical sources.
        if (! $this->isEmpty($frontmatter['graph_source'] ?? null)
            && ($frontmatter['graph_source'] ?? null) !== self::SOURCE_REPO) {
            $breaches[] = 'non_repo_source';
        }

        // Enum validations (only when present; absence already flagged above).
        if (! $this->isEmpty($frontmatter['graph_status'] ?? null)
            && ! in_array($frontmatter['graph_status'], self::ALLOWED_STATUS, true)) {
            $breaches[] = 'invalid_graph_status';
        }
        if (! $this->isEmpty($frontmatter['graph_layer'] ?? null)
            && ! in_array($frontmatter['graph_layer'], self::ALLOWED_LAYERS, true)) {
            $breaches[] = 'invalid_graph_layer';
        }
        if (! $this->isEmpty($frontmatter['risk_level'] ?? null)
            && ! in_array($frontmatter['risk_level'], self::ALLOWED_RISK, true)) {
            $breaches[] = 'invalid_risk_level';
        }

        $breaches = array_values(array_unique($breaches));

        return [
            'ok' => $breaches === [],
            'breaches' => $breaches,
        ];
    }

    /**
     * Evidence gate: when `requires_evidence` is truthy, the `evidence` list must
     * be present and non-empty. Modules that require evidence may never enter the
     * Cartography as truth without it.
     *
     * @param array<string,mixed> $frontmatter
     * @return array{ok:bool,required:bool,present:bool,breach:?string}
     */
    public function evidenceGate(array $frontmatter): array
    {
        $required = (bool) ($frontmatter['requires_evidence'] ?? false);
        $present = ! $this->isEmpty($frontmatter['evidence'] ?? null);
        $ok = ! $required || $present;

        return [
            'ok' => $ok,
            'required' => $required,
            'present' => $present,
            'breach' => $ok ? null : 'evidence_required_but_missing',
        ];
    }

    /**
     * Macro naming gate: when `macro_layer: true`, all four macro naming fields
     * must be present. A macro layer with a missing name is blocked.
     *
     * @param array<string,mixed> $frontmatter
     * @return array{ok:bool,is_macro:bool,missing:list<string>}
     */
    public function macroNamingGate(array $frontmatter): array
    {
        $isMacro = (bool) ($frontmatter['macro_layer'] ?? false);
        $missing = [];

        if ($isMacro) {
            foreach (self::MACRO_NAMING_FIELDS as $field) {
                if ($this->isEmpty($frontmatter[$field] ?? null)) {
                    $missing[] = $field;
                }
            }
        }

        return [
            'ok' => $missing === [],
            'is_macro' => $isMacro,
            'missing' => array_values($missing),
        ];
    }

    /**
     * Stable, machine-readable contract surface for callers (Cartography, KB,
     * docs-health) that want the gate definitions without reflecting on the class.
     *
     * @return array{
     *   schema:string,
     *   canonical_doc_schema:string,
     *   source_repo:string,
     *   required_frontmatter:list<string>,
     *   allowed_status:list<string>,
     *   allowed_layers:list<string>,
     *   allowed_risk:list<string>,
     *   macro_naming_fields:list<string>
     * }
     */
    public function contract(): array
    {
        return [
            'schema' => self::SCHEMA,
            'canonical_doc_schema' => self::CANONICAL_DOC_SCHEMA,
            'source_repo' => self::SOURCE_REPO,
            'required_frontmatter' => self::REQUIRED_FRONTMATTER,
            'allowed_status' => self::ALLOWED_STATUS,
            'allowed_layers' => self::ALLOWED_LAYERS,
            'allowed_risk' => self::ALLOWED_RISK,
            'macro_naming_fields' => self::MACRO_NAMING_FIELDS,
        ];
    }

    /** Human-readable reason for a schema-gate breach id. */
    private function reasonFor(string $breach): string
    {
        if (str_starts_with($breach, 'missing_field:')) {
            return 'required v1 frontmatter field is missing or empty: ['.substr($breach, strlen('missing_field:')).'].';
        }

        return match ($breach) {
            'wrong_doc_schema' => 'doc_schema must equal ['.self::CANONICAL_DOC_SCHEMA.'] to be a validated canonical v1 doc.',
            'non_repo_source' => 'graph_source must be [repo]; technical canon is repo-first and the Cartography only admits a repo source.',
            'invalid_graph_status' => 'graph_status is not one of the allowed lifecycle values.',
            'invalid_graph_layer' => 'graph_layer is not one of the allowed layer values.',
            'invalid_risk_level' => 'risk_level is not one of the allowed values.',
            default => 'frontmatter breach: '.$breach.'.',
        };
    }

    /** A value is "empty" when null, an empty string, or an empty array. */
    private function isEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
