<?php

namespace App\Services\Engineering\CodeGraph;

use App\Services\Engineering\EngineeringStringListNormalizer;

/**
 * Builds the governed, provider-agnostic request that asks the Atlas brain to run
 * a *semantic* code-graph extraction pass (AP-812) over a set of files — the layer
 * the cheap structural resolver ({@see CodeGraphEdgeResolver}) cannot reach because
 * it needs a language model to read intent (call-by-string, dynamic dispatch,
 * implicit contracts) rather than just `use`/reference relations.
 *
 * Sovereignty contract (NON-NEGOTIABLE):
 *  - This is a PURE BUILDER. It does NOT call any provider, manager, model, runtime
 *    or IO. It only *shapes* an atlas.runtime.invoke-style request that the caller
 *    then routes through the existing provider manager. It adds no decision of its
 *    own: routing.strategy is 'atlas_decide' — the brain (Atlas Decide), not this
 *    builder, picks provider/model. This is a read-model/transform that FEEDS the
 *    decision path; it never decides provider/model/domain/policy.
 *  - Local-first is requested (routing.allow_local_first = true) so the operator's
 *    machine is preferred; the brain still owns the final routing call.
 *
 * Privacy contract (NON-NEGOTIABLE):
 *  - Sensitive files (.env / secret / key material) are EXCLUDED from the request's
 *    file list before it ever leaves PHP, and the request carries the explicit
 *    privacy.sensitive_excluded = true marker plus an audit list of what was
 *    dropped. Sovereignty classes never get shipped to an external provider.
 *
 * Build/test-only posture (anti-over-claim):
 *  - Nothing here is wired into Dev/Forge/production; no flag is flipped on. The
 *    builder simply returns a struct. Promotion (actually routing the request) is a
 *    separate, human-reviewed step.
 */
class SemanticExtractionRequestBuilder
{
    public const SCHEMA = 'atlas.code_graph.semantic_extraction_request.v1';

    public const OP = 'semantic_extract';

    public const DOMAIN_ID = 'programming';

    /**
     * Atlas Decide owns provider/model selection. The builder only *requests* this
     * strategy; it never resolves it to a concrete provider.
     */
    public const ROUTING_STRATEGY = 'atlas_decide';

    /**
     * Case-insensitive markers that classify a path as sovereignty-sensitive and
     * therefore non-shippable. Intentionally conservative (drop on any match) and
     * aligned with the existing diff-secret / do-not-touch conventions
     * ({@see \App\Services\Engineering\EngineeringPatchArtifactService},
     * {@see \App\Services\Engineering\AtlasVerifiedEvolutionRuntimeService}).
     *
     * @var array<int,string>
     */
    private const SENSITIVE_MARKERS = [
        '.env',
        'secret',
        'secrets',
        'credential',
        'private_key',
        'private-key',
        'id_rsa',
        '.pem',
        '.p12',
        '.pfx',
        '.key',
        '.keystore',
    ];

    /**
     * Build a semantic-extraction request from a list of file paths.
     *
     * @param  array<int,mixed>  $files  candidate file paths (strings). Non-strings,
     *   blanks and duplicates are dropped; sensitive paths are excluded.
     * @param  array<string,mixed>  $opts  optional shaping:
     *   - 'allow_local_first' (bool, default true): request local-first routing.
     *   - 'reason' (string): free-form audit note carried in metadata.
     *   - 'extra_sensitive_markers' (array<int,string>): caller-supplied additional
     *     sensitive substrings (case-insensitive) to also exclude.
     * @return array{
     *   schema_version:string,
     *   op:string,
     *   domain_id:string,
     *   files:array<int,string>,
     *   routing:array{strategy:string, allow_local_first:bool},
     *   privacy:array{sensitive_excluded:bool, excluded_paths:array<int,string>, excluded_count:int},
     *   metadata:array<string,mixed>
     * }
     *   an atlas.runtime.invoke-shaped request a caller routes via the provider manager.
     */
    public function build(array $files, array $opts = []): array
    {
        $markers = $this->markers($opts['extra_sensitive_markers'] ?? null);

        $kept = [];
        $excluded = [];
        $seen = [];

        foreach ($files as $file) {
            $path = $this->normalizePath($file);
            if ($path === null) {
                continue;
            }

            if ($this->isSensitive($path, $markers)) {
                if (! isset($seen['x|'.$path])) {
                    $seen['x|'.$path] = true;
                    $excluded[] = $path;
                }

                continue;
            }

            if (isset($seen['k|'.$path])) {
                continue;
            }
            $seen['k|'.$path] = true;
            $kept[] = $path;
        }

        $allowLocalFirst = array_key_exists('allow_local_first', $opts)
            ? (bool) $opts['allow_local_first']
            : true;

        $metadata = [
            'builder' => self::SCHEMA,
            'candidate_count' => count($kept) + count($excluded),
            'requested_count' => count($kept),
        ];
        $reason = $this->stringOrNull($opts['reason'] ?? null);
        if ($reason !== null) {
            $metadata['reason'] = $reason;
        }

        return [
            'schema_version' => self::SCHEMA,
            'op' => self::OP,
            'domain_id' => self::DOMAIN_ID,
            'files' => $kept,
            'routing' => [
                'strategy' => self::ROUTING_STRATEGY,
                'allow_local_first' => $allowLocalFirst,
            ],
            'privacy' => [
                // Always true: this builder always applies the exclusion pass. The
                // flag asserts the *policy was enforced*, not that something matched.
                'sensitive_excluded' => true,
                'excluded_paths' => $excluded,
                'excluded_count' => count($excluded),
            ],
            'metadata' => $metadata,
        ];
    }

    /**
     * Merge the default sensitive markers with any caller-supplied extras
     * (normalized to lowercase, blanks dropped).
     *
     * @param  mixed  $extra
     * @return array<int,string>
     */
    private function markers(mixed $extra): array
    {
        $markers = self::SENSITIVE_MARKERS;
        if (is_array($extra)) {
            foreach ($extra as $marker) {
                $value = $this->stringOrNull($marker);
                if ($value !== null) {
                    $markers[] = strtolower($value);
                }
            }
        }

        return EngineeringStringListNormalizer::uniqueNonEmptyStrings($markers);
    }

    /**
     * @param  array<int,string>  $markers
     */
    private function isSensitive(string $path, array $markers): bool
    {
        $haystack = strtolower($path);
        foreach ($markers as $marker) {
            if ($marker !== '' && str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function normalizePath(mixed $file): ?string
    {
        if (! is_string($file)) {
            return null;
        }
        $trimmed = trim($file);

        return $trimmed === '' ? null : $trimmed;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
