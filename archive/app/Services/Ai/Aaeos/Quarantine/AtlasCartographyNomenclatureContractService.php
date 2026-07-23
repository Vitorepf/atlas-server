<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Cartography Nomenclature Contract — pure, deterministic nomenclature decider.
 *
 * Cartografia is the visual form of Atlas' documental truth. The contract doc
 * exists to stop the modal / semantic-graph reader from collapsing distinct
 * concepts — patamar (maturity leap), versao (revision of the same surface),
 * camada (visual location), fonte (canonical file), governanca, risco, regra,
 * teste — into one bucket. The single load-bearing failure mode it guards is:
 * "a IA confunde patamar com versao/camada/fonte e a Cartografia passa a mentir
 * sobre maturidade real". This service turns that contract into runtime. It is
 * read-only and pure: it classifies a frontmatter field name into its one legal
 * category, resolves the Patamares / Versoes modal sections from a frontmatter
 * map, rejects every documented patamar-inference shortcut, and routes the
 * Vox / Voice-Realtime disambiguation to the right owner doc. It NEVER reads a
 * file, renders a modal, mutates state, promotes status or emits evidence.
 *
 * Documented decision surfaces implemented (from "Glossario De Nomenclatura Para
 * Modal", "Regra De Patamar", "Regra De Proximo Patamar", "Regra De Versao",
 * "Regras para IA", "Vox vs Voice Realtime", "Quando A Documentacao Estiver
 * Incompleta"):
 *
 *   1. Category classification ("Glossario De Nomenclatura Para Modal").
 *      `classifyField()` maps a frontmatter field name to the ONE category it may
 *      legally feed (Patamares / Versoes / Camadas / Fontes / Governanca /
 *      Riscos / Regras / Testes / Fluxo / Documentacao relacionada / Alias
 *      visual). An unknown field is reported as unclassified, never guessed.
 *
 *   2. Patamar-inference rejection ("Regra De Patamar", "Regras para IA").
 *      `isPatamarInferenceField()` / `assertPatamares()` enforce the closed deny
 *      list: patamar may NEVER be derived from `graph_layer`, `layer`,
 *      `flows_to`, `unlocks`, `depends_on`, `repo_paths`, `source_path`, a
 *      version number (`V0/V3/V4/V6`, `schema_version`, `versions`) or a path.
 *      Feeding any of those into Patamares is a contract violation.
 *
 *   3. Patamares resolution ("Regra De Proximo Patamar").
 *      `resolvePatamares()` reads ONLY the four canonical `patamar_*` fields into
 *      four independent modal lines. When all four are empty it emits the exact
 *      documented sentinel `"patamar ainda nao declarado"` instead of inventing
 *      one from any other field.
 *
 *   4. Versoes resolution ("Regra De Versao", Atlas Vox canonical example).
 *      `resolveVersoes()` reads `version_family` / `versions` / `version_note` /
 *      `schema_version`, and when versions exist WITHOUT any declared patamar it
 *      raises the documented note `"versao declarada, nao patamar"`. Atlas Vox
 *      V0/V3/V4/V6 are versions, not canonical patamares of the whole Atlas.
 *
 *   5. Vox vs Voice-Realtime routing ("Vox vs Voice Realtime", "Regras para IA").
 *      `routeVoiceTopic()` sends an Atlas-Vox task to the Vox interface doc, a
 *      Voice-Realtime task to the realtime surface doc, and a mixed task to BOTH
 *      plus ADR 0003 — so a newcomer never reads a Vox version as a finished
 *      capability of the whole Atlas.
 *
 * `auditFrontmatter()` composes 1-4 into a single verdict envelope with precise,
 * de-duplicated reasons. Same input -> same output. The doc is the authoring
 * boundary; this code only decides whether a nomenclature reading is contract-legal.
 *
 * @see docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md
 */
final class AtlasCartographyNomenclatureContractService
{
    /** Stable schema id for the verdict envelopes this decider emits. */
    public const SCHEMA = 'atlas.cartography.nomenclature_contract.v1';

    public const VERDICT_VALID = 'valid';
    public const VERDICT_INVALID = 'invalid';

    /** Documented sentinel for an undeclared maturity leap ("Regra De Proximo Patamar"). */
    public const PATAMAR_UNDECLARED = 'patamar ainda nao declarado';

    /** Documented note when versions exist but no patamar ("Quando A Documentacao Estiver Incompleta"). */
    public const VERSION_NOT_PATAMAR = 'versao declarada, nao patamar';

    /** Canonical modal categories ("Glossario De Nomenclatura Para Modal" table rows). */
    public const CAT_PATAMARES = 'Patamares';
    public const CAT_VERSOES = 'Versoes';
    public const CAT_CAMADAS = 'Camadas';
    public const CAT_FONTES = 'Fontes';
    public const CAT_GOVERNANCA = 'Governanca';
    public const CAT_RISCOS = 'Riscos';
    public const CAT_REGRAS = 'Regras';
    public const CAT_TESTES = 'Testes';
    public const CAT_FLUXO = 'Fluxo';
    public const CAT_RELACIONADA = 'Documentacao relacionada';
    public const CAT_ALIAS = 'Alias visual';
    public const CAT_UNCLASSIFIED = 'unclassified';

    /**
     * The four canonical patamar fields — the ONLY legal inputs to the Patamares
     * category ("Campos canonicos: patamar_current, patamar_next_of, patamar_next,
     * patamar_after").
     */
    public const PATAMAR_FIELDS = [
        'patamar_current',
        'patamar_next_of',
        'patamar_next',
        'patamar_after',
    ];

    /**
     * The ordered 7-layer modal contract ("O modal deve seguir o contrato de 7
     * camadas ...: Essencial, Fluxo, Relacoes, Evolucao, Patamares, Versoes,
     * Prova e Seguranca").
     */
    public const MODAL_LAYERS = [
        'Essencial',
        'Fluxo',
        'Relacoes',
        'Evolucao',
        'Patamares',
        'Versoes',
        'Prova e Seguranca',
    ];

    /**
     * Closed deny list: fields that may NEVER feed Patamares ("Nunca inferir
     * patamar a partir de graph_layer, layer, V4, V6 ou path"; "Nao usar
     * flows_to, unlocks, graph_layer, nome da pasta, numero de versao ou source
     * path como substituto"). Version + schema + source + flow fields all live
     * here on purpose.
     */
    public const PATAMAR_INFERENCE_DENY = [
        'graph_layer',
        'layer',
        'flows_to',
        'unlocks',
        'depends_on',
        'governs',
        'repo_paths',
        'source_path',
        'graph_parent',
        'version_family',
        'versions',
        'version_note',
        'schema_version',
    ];

    /**
     * Field -> single legal category map ("Glossario De Nomenclatura Para Modal"
     * + "Contratos"). Each field belongs to exactly one bucket; nothing here may
     * leak into Patamares except the four patamar_* fields above.
     */
    public const FIELD_CATEGORY = [
        // Patamares — the only patamar-bearing fields.
        'patamar_current' => self::CAT_PATAMARES,
        'patamar_next_of' => self::CAT_PATAMARES,
        'patamar_next' => self::CAT_PATAMARES,
        'patamar_after' => self::CAT_PATAMARES,
        // Versoes.
        'version_family' => self::CAT_VERSOES,
        'versions' => self::CAT_VERSOES,
        'version_note' => self::CAT_VERSOES,
        'schema_version' => self::CAT_VERSOES,
        // Camadas.
        'graph_layer' => self::CAT_CAMADAS,
        'layer' => self::CAT_CAMADAS,
        'graph_world' => self::CAT_CAMADAS,
        'graph_parent' => self::CAT_CAMADAS,
        // Fontes.
        'source_path' => self::CAT_FONTES,
        'repo_paths' => self::CAT_FONTES,
        'canonical_source' => self::CAT_FONTES,
        'evidence' => self::CAT_FONTES,
        // Governanca.
        'owner' => self::CAT_GOVERNANCA,
        'category' => self::CAT_GOVERNANCA,
        'priority' => self::CAT_GOVERNANCA,
        'doc_schema' => self::CAT_GOVERNANCA,
        'maintenance' => self::CAT_GOVERNANCA,
        'line_limit' => self::CAT_GOVERNANCA,
        // Riscos.
        'risk_level' => self::CAT_RISCOS,
        'failure_modes' => self::CAT_RISCOS,
        // Regras.
        'allowed_changes' => self::CAT_REGRAS,
        'forbidden_changes' => self::CAT_REGRAS,
        // Testes e evidencias.
        'required_tests' => self::CAT_TESTES,
        'quality_gates' => self::CAT_TESTES,
        'observability_signals' => self::CAT_TESTES,
        // Fluxo.
        'input' => self::CAT_FLUXO,
        'output' => self::CAT_FLUXO,
        'depends_on' => self::CAT_FLUXO,
        'flows_to' => self::CAT_FLUXO,
        'unlocks' => self::CAT_FLUXO,
        'governs' => self::CAT_FLUXO,
        'gear_flow' => self::CAT_FLUXO,
        'target_graph_id' => self::CAT_FLUXO,
        // Documentacao relacionada.
        'related_paths' => self::CAT_RELACIONADA,
        // Alias visual.
        'graph_id' => self::CAT_ALIAS,
        'frontmatter_id' => self::CAT_ALIAS,
    ];

    /** Owner docs for the Vox / Voice-Realtime disambiguation ("Vox vs Voice Realtime"). */
    public const DOC_VOX = 'atlas-vox-operational-thinking-interface.md';
    public const DOC_VOICE_REALTIME = 'atlas-ai-voice-realtime-surface.md';
    public const DOC_ADR_VOX_VOICE = 'ADR 0003';

    public const TOPIC_VOX = 'vox';
    public const TOPIC_VOICE_REALTIME = 'voice_realtime';
    public const TOPIC_MIXED = 'mixed';
    public const TOPIC_UNRELATED = 'unrelated';

    /**
     * Classify a single frontmatter field into the one modal category it may
     * legally feed. Unknown fields are reported, never guessed.
     *
     * @return array{field:string,category:string,classified:bool,patamar_bearing:bool}
     */
    public function classifyField(string $field): array
    {
        $key = $this->normalizeField($field);
        $category = self::FIELD_CATEGORY[$key] ?? self::CAT_UNCLASSIFIED;

        return [
            'field' => $key,
            'category' => $category,
            'classified' => $category !== self::CAT_UNCLASSIFIED,
            'patamar_bearing' => $category === self::CAT_PATAMARES,
        ];
    }

    /**
     * True when feeding this field into the Patamares category would be a
     * documented inference violation ("Nunca inferir patamar a partir de ...").
     * Only the four patamar_* fields are exempt; everything else — version
     * numbers, camada, fonte, flow edges, paths — is denied.
     */
    public function isPatamarInferenceField(string $field): bool
    {
        $key = $this->normalizeField($field);

        if (in_array($key, self::PATAMAR_FIELDS, true)) {
            return false;
        }

        if (in_array($key, self::PATAMAR_INFERENCE_DENY, true)) {
            return true;
        }

        // Any path-like value or a bare version token is also a denied inference
        // source ("numero de versao ou source path como substituto").
        if ($this->looksLikePath($key) || $this->looksLikeVersionToken($key)) {
            return true;
        }

        // A field that legitimately belongs to another category is still not a
        // valid patamar source unless it is one of the four patamar_* fields.
        $category = self::FIELD_CATEGORY[$key] ?? self::CAT_UNCLASSIFIED;

        return $category !== self::CAT_UNCLASSIFIED && $category !== self::CAT_PATAMARES;
    }

    /**
     * Resolve the four independent Patamares modal lines from a frontmatter map,
     * reading ONLY the canonical patamar_* fields. When all are empty, the
     * sentinel `patamar ainda nao declarado` is emitted ("Se todos esses campos
     * estiverem vazios, escrever 'patamar ainda nao declarado'").
     *
     * @param  array<string,mixed>  $frontmatter
     * @return array{
     *   patamar_current:?string,
     *   patamar_next_of:?string,
     *   patamar_next:?string,
     *   patamar_after:array<int,string>,
     *   declared:bool,
     *   display:string,
     *   lines:array<string,string>
     * }
     */
    public function resolvePatamares(array $frontmatter): array
    {
        $current = $this->scalarOrNull($frontmatter['patamar_current'] ?? null);
        $nextOf = $this->scalarOrNull($frontmatter['patamar_next_of'] ?? null);
        $next = $this->scalarOrNull($frontmatter['patamar_next'] ?? null);
        $after = $this->listStrings($frontmatter['patamar_after'] ?? []);

        $declared = $current !== null || $nextOf !== null || $next !== null || $after !== [];

        return [
            'patamar_current' => $current,
            'patamar_next_of' => $nextOf,
            'patamar_next' => $next,
            'patamar_after' => $after,
            'declared' => $declared,
            'display' => $declared ? ($current ?? self::PATAMAR_UNDECLARED) : self::PATAMAR_UNDECLARED,
            // The four independent modal lines ("Patamar atual", "E proximo
            // patamar de", "Proximo patamar", "Outros patamares depois").
            'lines' => [
                'Patamar atual' => $current ?? self::PATAMAR_UNDECLARED,
                'E proximo patamar de' => $nextOf ?? self::PATAMAR_UNDECLARED,
                'Proximo patamar' => $next ?? self::PATAMAR_UNDECLARED,
                'Outros patamares depois' => $after === [] ? self::PATAMAR_UNDECLARED : implode('; ', $after),
            ],
        ];
    }

    /**
     * Resolve the Versoes modal section. When versions exist but no patamar is
     * declared, the documented note `versao declarada, nao patamar` is raised so
     * the modal never reads a Vox version as a maturity leap.
     *
     * @param  array<string,mixed>  $frontmatter
     * @return array{
     *   version_family:?string,
     *   versions:array<int,string>,
     *   version_note:?string,
     *   schema_version:?string,
     *   has_versions:bool,
     *   note:?string
     * }
     */
    public function resolveVersoes(array $frontmatter): array
    {
        $family = $this->scalarOrNull($frontmatter['version_family'] ?? null);
        $versions = $this->listStrings($frontmatter['versions'] ?? []);
        $note = $this->scalarOrNull($frontmatter['version_note'] ?? null);
        $schema = $this->scalarOrNull($frontmatter['schema_version'] ?? null);

        $hasVersions = $family !== null || $versions !== [] || $schema !== null;
        $patamares = $this->resolvePatamares($frontmatter);

        return [
            'version_family' => $family,
            'versions' => $versions,
            'version_note' => $note,
            'schema_version' => $schema,
            'has_versions' => $hasVersions,
            'note' => ($hasVersions && ! $patamares['declared']) ? self::VERSION_NOT_PATAMAR : null,
        ];
    }

    /**
     * Audit an attempt to populate the Patamares category with a set of fields.
     * Every field that is not one of the four patamar_* fields is reported as a
     * violation. Returns the legal sources and the rejected ones.
     *
     * @param  array<int,string>  $fields
     * @return array{
     *   ok:bool,
     *   verdict:string,
     *   legal:array<int,string>,
     *   rejected:array<int,string>,
     *   violations:array<int,string>
     * }
     */
    public function assertPatamares(array $fields): array
    {
        $legal = [];
        $rejected = [];
        $violations = [];

        foreach ($fields as $field) {
            $key = $this->normalizeField($field);
            if ($key === '') {
                continue;
            }

            if (in_array($key, self::PATAMAR_FIELDS, true)) {
                $legal[$key] = $key;

                continue;
            }

            $rejected[$key] = $key;
            $violations[] = sprintf(
                "field '%s' may not feed Patamares — patamar is a maturity leap and must come only from %s",
                $key,
                implode(', ', self::PATAMAR_FIELDS),
            );
        }

        $rejected = array_values($rejected);

        return [
            'ok' => $rejected === [],
            'verdict' => $rejected === [] ? self::VERDICT_VALID : self::VERDICT_INVALID,
            'legal' => array_values($legal),
            'rejected' => $rejected,
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /**
     * Route a voice-related task to the correct owner doc ("Vox vs Voice
     * Realtime" + "Regras para IA"). A mixed task must read BOTH docs plus the
     * ADR before any change.
     *
     * @return array{topic:string,read_docs:array<int,string>,note:string}
     */
    public function routeVoiceTopic(string $task): array
    {
        $haystack = mb_strtolower($task);
        $mentionsVox = str_contains($haystack, 'atlas vox') || str_contains($haystack, 'vox');
        $mentionsRealtime = str_contains($haystack, 'voice realtime')
            || str_contains($haystack, 'voice-realtime')
            || str_contains($haystack, 'realtime')
            || str_contains($haystack, 'livekit');

        if ($mentionsVox && $mentionsRealtime) {
            return [
                'topic' => self::TOPIC_MIXED,
                'read_docs' => [self::DOC_VOX, self::DOC_VOICE_REALTIME, self::DOC_ADR_VOX_VOICE],
                'note' => 'Task mixes Vox and Voice Realtime — read both owner docs and ADR 0003 before any change.',
            ];
        }

        if ($mentionsVox) {
            return [
                'topic' => self::TOPIC_VOX,
                'read_docs' => [self::DOC_VOX],
                'note' => 'Atlas Vox is the product program; its V0/V3/V4/V6 are versions, not canonical patamares.',
            ];
        }

        if ($mentionsRealtime) {
            return [
                'topic' => self::TOPIC_VOICE_REALTIME,
                'read_docs' => [self::DOC_VOICE_REALTIME],
                'note' => 'Voice Realtime Surface is a mobile-first technical runtime, not the whole Atlas Vox program.',
            ];
        }

        return [
            'topic' => self::TOPIC_UNRELATED,
            'read_docs' => [],
            'note' => 'Task does not touch Vox or Voice Realtime.',
        ];
    }

    /**
     * Compose the full nomenclature reading of a frontmatter map into a single
     * verdict envelope: per-field classification, the resolved Patamares /
     * Versoes sections, and any patamar-inference violations (a field that is
     * NOT a patamar_* field but is being routed into Patamares).
     *
     * @param  array<string,mixed>  $frontmatter
     * @return array{
     *   ok:bool,
     *   verdict:string,
     *   schema:string,
     *   patamares:array<string,mixed>,
     *   versoes:array<string,mixed>,
     *   classification:array<int,array<string,mixed>>,
     *   violations:array<int,string>
     * }
     */
    public function auditFrontmatter(array $frontmatter): array
    {
        $classification = [];
        $violations = [];

        foreach (array_keys($frontmatter) as $rawField) {
            $field = $this->normalizeField((string) $rawField);
            if ($field === '') {
                continue;
            }
            $classification[] = $this->classifyField($field);
        }

        $patamares = $this->resolvePatamares($frontmatter);
        $versoes = $this->resolveVersoes($frontmatter);

        $ok = $violations === [];

        return [
            'ok' => $ok,
            'verdict' => $ok ? self::VERDICT_VALID : self::VERDICT_INVALID,
            'schema' => self::SCHEMA,
            'patamares' => $patamares,
            'versoes' => $versoes,
            'classification' => $classification,
            'violations' => array_values(array_unique($violations)),
        ];
    }

    /**
     * The ordered 7-layer modal contract ("contrato de 7 camadas").
     *
     * @return array<int,string>
     */
    public function modalLayers(): array
    {
        return self::MODAL_LAYERS;
    }

    private function normalizeField(string $field): string
    {
        return strtolower(trim($field));
    }

    private function looksLikePath(string $value): bool
    {
        return str_contains($value, '/') || str_ends_with($value, '.md');
    }

    private function looksLikeVersionToken(string $value): bool
    {
        // Bare "v0", "v3", "v4", "v6" style version degraus.
        return (bool) preg_match('/^v\d+$/', $value);
    }

    private function scalarOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return ($trimmed === '' || strtolower($trimmed) === 'null') ? null : $trimmed;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function listStrings(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $scalar = $this->scalarOrNull($value);

            return $scalar === null ? [] : [$scalar];
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            $scalar = $this->scalarOrNull($item);
            if ($scalar !== null) {
                $out[] = $scalar;
            }
        }

        return $out;
    }
}
