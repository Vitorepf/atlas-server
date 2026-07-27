<?php

namespace App\Services\Engineering\CodeGraph;

/**
 * Self-construction — the SAFE proposal step (AP-811 / AP-812 M-7).
 *
 * {@see \App\Services\Engineering\CodeGraph\CodeGraphMissingLanguageProposer} answers
 * "which uncovered language locks out the most files?" — it ranks the blind spots.
 * This generator is the strictly-downstream complement: given ONE chosen extension,
 * it DESCRIBES, in prose, how Atlas would build its own extractor for that language.
 *
 * It is the self-construction loop's first, deliberately-defanged move: Atlas
 * reasoning about extending Atlas. To keep that safe it obeys hard rails that are
 * encoded in the returned proposal itself, never inferred by a caller:
 *
 *   - promotion_allowed   = false  (a proposal is NEVER a green light to ship)
 *   - auto_execute        = false  (nothing here may be run automatically)
 *   - requires_human_review = true (a human gates the next step)
 *
 * Critically, the `scaffold_outline` is a HUMAN-READABLE TEXT DESCRIPTION of the
 * steps an engineer (or a separately-governed build step under human review) would
 * take. It is NOT, and must never become, runnable code: this class emits no PHP,
 * no Python, no shell — only a narrative outline. Nothing here is ever executed,
 * written to disk, registered, or routed. It feeds the decision; it is not a
 * parallel decision brain and it does not self-construct anything by itself.
 *
 * Pure transform: no DB, no IO, no provider, no Python runtime, no self-write,
 * no randomness — identical input yields identical output.
 *
 * @phpstan-type ExtractorScaffoldProposal array{
 *     schema_version:string,
 *     type:string,
 *     extension:string,
 *     file_count:int,
 *     suggested_node_types:array<int,string>,
 *     observed_keywords:array<int,string>,
 *     scaffold_outline:string,
 *     promotion_allowed:false,
 *     requires_human_review:true,
 *     auto_execute:false,
 *     notes:array<int,string>
 * }
 */
class CodeGraphExtractorScaffoldGenerator
{
    public const SCHEMA = 'atlas.code_graph.extractor_scaffold.v1';

    /** The only proposal kind this generator emits. */
    public const TYPE_EXTRACTOR_SCAFFOLD = 'extractor_scaffold';

    /**
     * Node types every code-graph extractor produces regardless of language, so a
     * scaffold proposal always names a concrete, non-empty target node set even
     * when no language-specific keywords were observed.
     *
     * @var array<int,string>
     */
    private const BASELINE_NODE_TYPES = ['module', 'symbol'];

    /**
     * Maps an observed source keyword to the code-graph node type it implies.
     * Keys are normalized (lowercased). This is a heuristic SUGGESTION surfaced for
     * human review — never an authoritative extractor spec.
     *
     * @var array<string,string>
     */
    private const KEYWORD_NODE_TYPES = [
        'class' => 'class',
        'interface' => 'interface',
        'trait' => 'trait',
        'enum' => 'enum',
        'struct' => 'struct',
        'impl' => 'struct',
        'fn' => 'function',
        'func' => 'function',
        'function' => 'function',
        'def' => 'function',
        'sub' => 'function',
        'method' => 'method',
        'module' => 'module',
        'mod' => 'module',
        'package' => 'module',
        'namespace' => 'module',
        'import' => 'import',
        'use' => 'import',
        'require' => 'import',
        'include' => 'import',
        'from' => 'import',
        'type' => 'type',
        'typedef' => 'type',
        'const' => 'constant',
        'var' => 'variable',
        'let' => 'variable',
        'test' => 'test',
        'describe' => 'test',
        'it' => 'test',
    ];

    /**
     * Describe how Atlas would build an extractor for an uncovered language.
     *
     * @param  string  $extension  the uncovered file extension, e.g. "rs" (normalized:
     *   lowercased, leading "*"/"." stripped). Falls back to "unknown" when blank.
     * @param  int  $fileCount  how many files of that extension exist in the workspace
     *   (the coverage gain). Clamped to >= 0; negatives become 0.
     * @param  array<int,mixed>  $observedKeywords  language tokens sampled from those
     *   files, e.g. ['fn', 'struct', 'impl', 'use']. Used only to SUGGEST node types.
     *   Order-independent and de-duplicated; non-strings are ignored.
     * @return ExtractorScaffoldProposal
     */
    public function propose(string $extension, int $fileCount, array $observedKeywords = []): array
    {
        $normalizedExtension = $this->normalizeExtension($extension) ?? 'unknown';
        $count = max(0, $fileCount);
        $keywords = $this->normalizeKeywords($observedKeywords);
        $suggestedNodeTypes = $this->suggestNodeTypes($keywords);

        return [
            'schema_version' => self::SCHEMA,
            'type' => self::TYPE_EXTRACTOR_SCAFFOLD,
            'extension' => $normalizedExtension,
            'file_count' => $count,
            'suggested_node_types' => $suggestedNodeTypes,
            'observed_keywords' => $keywords,
            'scaffold_outline' => $this->buildOutline($normalizedExtension, $count, $suggestedNodeTypes, $keywords),
            // Hard self-construction rails — encoded in the payload, not assumed by callers.
            'promotion_allowed' => false,
            'requires_human_review' => true,
            'auto_execute' => false,
            'notes' => [
                'This is a PROPOSAL describing how Atlas would build an extractor; it is not runnable code.',
                'Nothing here is executed, written to disk, registered, or routed by Atlas.',
                'Promotion to a real extractor is a separate, human-reviewed step.',
            ],
        ];
    }

    /**
     * Build the prose scaffold outline. This is intentionally a TEXT DESCRIPTION of
     * the steps a human (or a separately-governed, human-gated build step) would
     * follow — never emitted runnable code. The numbered steps mirror how the
     * existing PHP/Python extractors are structured so the description is concrete
     * without being executable.
     *
     * @param  array<int,string>  $suggestedNodeTypes
     * @param  array<int,string>  $keywords
     */
    private function buildOutline(string $extension, int $fileCount, array $suggestedNodeTypes, array $keywords): string
    {
        $nodeList = implode(', ', $suggestedNodeTypes);
        $keywordList = $keywords === [] ? 'none observed' : implode(', ', $keywords);

        $lines = [
            sprintf(
                'Proposed extractor for ".%s" (%d uncovered file(s) in the workspace).',
                $extension,
                $fileCount,
            ),
            'This is a human-readable plan, NOT executable code. Atlas does not build, run, or register anything from this proposal.',
            '',
            'Observed language keywords: '.$keywordList.'.',
            'Suggested code-graph node types to emit: '.$nodeList.'.',
            '',
            'Outline a human reviewer (or a separately-governed build step) would follow:',
            sprintf('  1. Add ".%s" to the extractor registry only AFTER human approval; default it OFF behind the code-graph feature flag.', $extension),
            sprintf('  2. Parse each ".%s" file into the node types above, mirroring the existing PHP/Python extractor shape (one node per declaration).', $extension),
            '  3. Capture relations per file (imports/dependencies, symbol references, test targets) using the same record shape CodeGraphEdgeResolver already consumes.',
            '  4. Hand those records to the existing CodeGraphEdgeResolver to emit confidence-graded edges; do NOT invent a new edge pipeline.',
            '  5. Add fixture-based unit tests for the new extractor (pure parse -> nodes/relations) before any promotion.',
            '  6. Submit the result for human review; promotion stays a separate, gated decision (promotion_allowed=false here).',
            '',
            'Safety: this proposal carries promotion_allowed=false, auto_execute=false, requires_human_review=true. It feeds the decision; it decides nothing.',
        ];

        return implode("\n", $lines);
    }

    /**
     * Derive suggested node types from observed keywords, always including the
     * language-agnostic baseline. Deterministic: baseline first (in declared order),
     * then keyword-derived types sorted ascending, de-duplicated.
     *
     * @param  array<int,string>  $keywords  already normalized
     * @return array<int,string>
     */
    private function suggestNodeTypes(array $keywords): array
    {
        $derived = [];
        foreach ($keywords as $keyword) {
            if (isset(self::KEYWORD_NODE_TYPES[$keyword])) {
                $derived[self::KEYWORD_NODE_TYPES[$keyword]] = true;
            }
        }

        // Baseline always present; keyword-derived extras appended in stable order.
        $extras = array_keys(array_diff_key($derived, array_flip(self::BASELINE_NODE_TYPES)));
        sort($extras, SORT_STRING);

        return array_values(array_merge(self::BASELINE_NODE_TYPES, $extras));
    }

    /**
     * Normalize, de-duplicate and sort observed keywords for determinism.
     *
     * @param  array<int,mixed>  $observedKeywords
     * @return array<int,string>
     */
    private function normalizeKeywords(array $observedKeywords): array
    {
        $set = [];
        foreach ($observedKeywords as $raw) {
            if (! is_string($raw)) {
                continue;
            }
            $normalized = strtolower(trim($raw));
            if ($normalized !== '') {
                $set[$normalized] = true;
            }
        }
        $keywords = array_keys($set);
        sort($keywords, SORT_STRING);

        return $keywords;
    }

    /**
     * Normalize an extension token: lowercased, with surrounding whitespace, a
     * leading "*" glob and a leading "." stripped to a bare token ("*.rs" -> "rs").
     * Returns null when empty. Matches CodeGraphMissingLanguageProposer's rule so
     * the two self-construction stages speak the same extension vocabulary.
     */
    private function normalizeExtension(string $value): ?string
    {
        $trimmed = strtolower(trim($value));
        $trimmed = ltrim($trimmed, '*');
        $trimmed = ltrim($trimmed, '.');
        $trimmed = trim($trimmed);

        return $trimmed === '' ? null : $trimmed;
    }
}
