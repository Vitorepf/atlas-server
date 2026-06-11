<?php

namespace App\Services\Engineering;

use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\File;
use SplFileInfo;

class EngineeringDocumentationAuthorityAuditService
{
    public const SCHEMA_VERSION = 'atlas.documentation.authority_audit.v1';

    /**
     * @var array<int,string>
     */
    private const CANONICAL_STATUSES = [
        'active',
        'building',
        'planned',
        'future',
        'implemented',
        'implemented_ready',
        'scaffold',
    ];

    public function __construct(private readonly CanonicalDocsFrontmatterParser $frontmatter) {}

    /**
     * @return array<string,mixed>
     */
    public function report(?string $root = null): array
    {
        $docs = $this->scanDocs($root ?? base_path('docs/engineering-knowledge-base'));
        $identityDuplicates = $this->identityDuplicates($docs);
        $runtimeDuplicates = $this->runtimeDuplicates($docs);
        $capabilityClusters = $this->capabilityClusters($docs);
        $ownerGaps = $this->ownerGaps($docs);
        $blockers = array_values(array_merge(
            $this->blockersFromDuplicateGroups('duplicate_doc_id', $identityDuplicates['id']),
            $this->blockersFromDuplicateGroups('duplicate_graph_id', $identityDuplicates['graph_id']),
            $this->blockersFromDuplicateGroups('duplicate_technical_runtime', $runtimeDuplicates['technical_runtime']),
        ));
        $reviewItems = array_values(array_merge(
            $this->reviewItemsFromDuplicateGroups('duplicate_product_acronym', $runtimeDuplicates['product_acronym']),
            $this->reviewItemsFromCapabilityClusters($capabilityClusters),
            $this->reviewItemsFromOwnerGaps($ownerGaps),
        ));

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers !== [] ? 'blocked' : ($reviewItems !== [] ? 'review' : 'ready'),
            'summary' => [
                'doc_count' => count($docs),
                'canonical_doc_count' => count(array_filter($docs, static fn (array $doc): bool => $doc['canonical'] === true)),
                'blocker_count' => count($blockers),
                'review_item_count' => count($reviewItems),
                'identity_duplicate_group_count' => count($identityDuplicates['id']) + count($identityDuplicates['graph_id']),
                'runtime_duplicate_group_count' => count($runtimeDuplicates['technical_runtime']) + count($runtimeDuplicates['product_acronym']),
                'capability_overlap_group_count' => count($capabilityClusters),
                'owner_gap_count' => count($ownerGaps),
            ],
            'blockers' => $blockers,
            'review_items' => $reviewItems,
            'identity_duplicates' => $identityDuplicates,
            'runtime_duplicates' => $runtimeDuplicates,
            'capability_overlap_clusters' => $capabilityClusters,
            'owner_gaps' => $ownerGaps,
            'ai_enforcement_contract' => [
                'must_run_before_new_runtime_or_macro_doc' => true,
                'must_read_owner_doc_before_implementation' => true,
                'blocked_means_no_new_doc_or_runtime_until_resolved' => true,
                'review_means_reuse_or_supersede_decision_required' => true,
                'chat_memory_is_not_authority' => true,
            ],
            'claim_policy' => [
                'writes' => false,
                'providers_invoked' => false,
                'rivals_run' => false,
                'external_benchmark_run' => false,
            ],
            'writes' => false,
            'generated_at' => now()->toJSON(),
        ];

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function scanDocs(string $root): array
    {
        if (! File::isDirectory($root)) {
            return [];
        }

        return collect(File::allFiles($root))
            ->filter(fn (SplFileInfo $file): bool => strtolower($file->getExtension()) === 'md')
            ->map(function (SplFileInfo $file): array {
                $path = $file->getPathname();
                $markdown = File::get($path);
                $parsed = $this->frontmatter->parse($markdown);
                $frontmatter = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
                $status = (string) ($frontmatter['status'] ?? 'missing');

                return [
                    'path' => $this->relativePath($path),
                    'canonical' => (($frontmatter['doc_schema'] ?? null) === 'atlas_canonical_module_doc.v1')
                        && in_array($status, self::CANONICAL_STATUSES, true),
                    'id' => $this->scalar($frontmatter['id'] ?? null),
                    'graph_id' => $this->scalar($frontmatter['graph_id'] ?? null),
                    'graph_kind' => $this->scalar($frontmatter['graph_kind'] ?? null),
                    'graph_parent' => $this->scalar($frontmatter['graph_parent'] ?? null),
                    'status' => $status,
                    'title' => $this->scalar($frontmatter['title'] ?? null),
                    'owner' => $this->scalar($frontmatter['owner'] ?? null),
                    'category' => $this->scalar($frontmatter['category'] ?? null),
                    'product_name' => $this->scalar($frontmatter['product_name'] ?? null),
                    'runtime_acronym' => $this->scalar($frontmatter['runtime_acronym'] ?? null),
                    'technical_runtime' => $this->scalar($frontmatter['technical_runtime'] ?? null),
                    'capabilities' => $this->stringList($frontmatter['capabilities'] ?? []),
                    'repo_paths' => $this->stringList($frontmatter['repo_paths'] ?? []),
                    'related_paths' => $this->stringList($frontmatter['related_paths'] ?? []),
                    'evidence' => $this->stringList($frontmatter['evidence'] ?? []),
                    'summary' => $this->scalar($frontmatter['summary'] ?? null),
                ];
            })
            ->filter(static fn (array $doc): bool => $doc['canonical'] === true)
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function identityDuplicates(array $docs): array
    {
        return [
            'id' => $this->duplicateGroups($docs, 'id'),
            'graph_id' => $this->duplicateGroups($docs, 'graph_id'),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function runtimeDuplicates(array $docs): array
    {
        $productAcronymGroups = [];
        foreach ($docs as $doc) {
            $product = (string) $doc['product_name'];
            $acronym = (string) $doc['runtime_acronym'];
            if ($product === '' || $acronym === '') {
                continue;
            }

            $productAcronymGroups[$this->normalize($product).'::'.$this->normalize($acronym)][] = $doc;
        }

        return [
            'technical_runtime' => $this->duplicateGroups($docs, 'technical_runtime'),
            'product_acronym' => $this->formatGroups($productAcronymGroups),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,mixed>>
     */
    private function capabilityClusters(array $docs): array
    {
        $groups = [];
        foreach ($docs as $doc) {
            if ($this->isIndexDoc($doc)) {
                continue;
            }

            foreach ((array) $doc['capabilities'] as $capability) {
                $key = $this->normalize($capability);
                if ($key === '') {
                    continue;
                }

                $groups[$key][] = $doc;
            }
        }

        return array_values(array_filter(array_map(function (array $items, string $key): ?array {
            $paths = $this->uniqueColumnStrings($items, 'path', filterEmpty: false);
            $owners = $this->uniqueColumnStrings($items, 'owner');
            if (count($paths) < 2) {
                return null;
            }
            if ($this->isDeclaredGraphFamily($items)) {
                return null;
            }

            return [
                'capability' => $key,
                'doc_count' => count($paths),
                'owner_count' => count($owners),
                'risk' => count($owners) > 1 ? 'cross_owner_overlap' : 'same_owner_overlap',
                'requires_decision' => count($owners) > 1,
                'paths' => $paths,
                'owners' => $owners,
            ];
        }, $groups, array_keys($groups))));
    }

    /**
     * Capability overlap inside a declared graph family is intentional:
     * parent docs, contracts and runbooks repeat the same capability so humans
     * and agents can navigate the family without inventing a second owner.
     *
     * @param  array<int,array<string,mixed>>  $items
     */
    private function isDeclaredGraphFamily(array $items): bool
    {
        $graphIds = $this->uniqueColumnStrings($items, 'graph_id');
        $graphParents = $this->uniqueColumnStrings($items, 'graph_parent');

        if ($graphIds === [] || $graphParents === []) {
            return false;
        }

        foreach ($graphParents as $parent) {
            if (in_array($parent, $graphIds, true)) {
                return true;
            }
        }

        return count($graphParents) === 1;
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,mixed>>
     */
    private function ownerGaps(array $docs): array
    {
        return array_values(array_filter(array_map(static function (array $doc): ?array {
            $missing = [];
            foreach (['owner', 'repo_paths', 'evidence', 'summary'] as $field) {
                $value = $doc[$field] ?? null;
                if ($value === '' || $value === [] || $value === null) {
                    $missing[] = $field;
                }
            }

            return $missing === [] ? null : [
                'path' => $doc['path'],
                'id' => $doc['id'],
                'missing' => $missing,
            ];
        }, $docs)));
    }

    /**
     * @param  array<int,array<string,mixed>>  $docs
     * @return array<int,array<string,mixed>>
     */
    private function duplicateGroups(array $docs, string $field): array
    {
        $groups = [];
        foreach ($docs as $doc) {
            $value = (string) ($doc[$field] ?? '');
            if ($value === '') {
                continue;
            }

            $groups[$this->normalize($value)][] = $doc;
        }

        return $this->formatGroups($groups);
    }

    /**
     * @param  array<string,array<int,array<string,mixed>>>  $groups
     * @return array<int,array<string,mixed>>
     */
    private function formatGroups(array $groups): array
    {
        $duplicates = [];
        foreach ($groups as $key => $items) {
            $paths = $this->uniqueColumnStrings($items, 'path', filterEmpty: false);
            if (count($paths) < 2) {
                continue;
            }

            $duplicates[] = [
                'key' => $key,
                'doc_count' => count($paths),
                'paths' => $paths,
                'owners' => $this->uniqueColumnStrings($items, 'owner'),
                'titles' => $this->uniqueColumnStrings($items, 'title'),
            ];
        }

        usort($duplicates, static fn (array $a, array $b): int => $b['doc_count'] <=> $a['doc_count']);

        return $duplicates;
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,string>
     */
    private function uniqueColumnStrings(array $items, string $field, bool $filterEmpty = true): array
    {
        return EngineeringStringListNormalizer::uniqueStringCasts(
            array_map(
                static fn (array $item): mixed => $item[$field] ?? '',
                $items,
            ),
            filterEmpty: $filterEmpty,
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $groups
     * @return array<int,array<string,mixed>>
     */
    private function blockersFromDuplicateGroups(string $reason, array $groups): array
    {
        return array_map(static fn (array $group): array => [
            'reason' => $reason,
            'key' => $group['key'],
            'paths' => $group['paths'],
        ], $groups);
    }

    /**
     * @param  array<int,array<string,mixed>>  $groups
     * @return array<int,array<string,mixed>>
     */
    private function reviewItemsFromDuplicateGroups(string $reason, array $groups): array
    {
        return array_map(static fn (array $group): array => [
            'reason' => $reason,
            'key' => $group['key'],
            'paths' => $group['paths'],
            'required_action' => 'declare_primary_owner_or_supersede_duplicate',
        ], $groups);
    }

    /**
     * @param  array<int,array<string,mixed>>  $clusters
     * @return array<int,array<string,mixed>>
     */
    private function reviewItemsFromCapabilityClusters(array $clusters): array
    {
        return array_values(array_map(static fn (array $cluster): array => [
            'reason' => 'capability_overlap_cluster',
            'capability' => $cluster['capability'],
            'risk' => $cluster['risk'],
            'paths' => $cluster['paths'],
            'required_action' => $cluster['requires_decision']
                ? 'cross_owner_overlap_requires_owner_decision'
                : 'same_owner_overlap_should_link_primary_doc',
        ], array_slice($clusters, 0, 25)));
    }

    /**
     * @param  array<int,array<string,mixed>>  $ownerGaps
     * @return array<int,array<string,mixed>>
     */
    private function reviewItemsFromOwnerGaps(array $ownerGaps): array
    {
        return array_values(array_map(static fn (array $gap): array => [
            'reason' => 'canonical_doc_owner_or_evidence_gap',
            'path' => $gap['path'],
            'missing' => $gap['missing'],
            'required_action' => 'fill_owner_repo_paths_evidence_summary_or_demote_doc',
        ], array_slice($ownerGaps, 0, 25)));
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $item): string => $this->scalar($item),
            is_array($value) ? $value : [$value],
        )));
    }

    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function normalize(string $value): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', trim($value)) ?? '');
    }

    /**
     * @param  array<string,mixed>  $doc
     */
    private function isIndexDoc(array $doc): bool
    {
        $path = (string) ($doc['path'] ?? '');

        return ($doc['graph_kind'] ?? null) === 'index'
            || preg_match('/-part-\d+\.md$/', $path) === 1
            || str_ends_with($path, '/README.md')
            || str_ends_with($path, '/START_HERE.md')
            || $path === 'docs/engineering-knowledge-base/README.md'
            || $path === 'docs/engineering-knowledge-base/START_HERE.md';
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
