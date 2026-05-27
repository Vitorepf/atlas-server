<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals\Corpus;

/**
 * Atlas Forge Rivals · `cases` action handler.
 *
 * Lists the canonical Provider Arena corpus (or a filtered subset of it) in
 * the stable v2 envelope. The action never invokes a provider, never alters
 * any worktree, and never unlocks `external_rivals_certification`.
 *
 * Filters (mutually informative):
 *   - --case=<id>           ⇒ single case manifest
 *   - --case-set=<set>      ⇒ {quick,release,frontend,backend,bugfix,architecture}
 *   - --task-category=<cat> ⇒ filter by canonical category
 *
 * If multiple filters are passed, the planner consolidates them honestly:
 *   --case wins over --case-set wins over --task-category;
 *   --task-category may further narrow a --case-set.
 *
 * Schema: atlas.forge.rivals.provider_arena_corpus_cases.v1
 */
final class AtlasForgeRivalsCorpusCasesActionService
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.provider_arena_corpus_cases.v1';

    public function __construct(
        private readonly AtlasForgeRivalsProviderArenaCorpusService $corpus,
        private readonly AtlasForgeRivalsCorpusPlannerService $planner,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function handle(array $input): array
    {
        $plan = $this->planner->plan([
            'case' => (string) ($input['case'] ?? ''),
            'case_set' => (string) ($input['case_set'] ?? ''),
            'task_category' => (string) ($input['task_category'] ?? ''),
            'deepswe_path' => $input['deepswe_path'] ?? null,
            'input' => $input['input'] ?? null,
            'agent' => $input['agent'] ?? null,
            'model' => $input['model'] ?? null,
            'n_tasks' => $input['n_tasks'] ?? null,
            'sample_seed' => $input['sample_seed'] ?? null,
        ]);

        if (($plan['status'] ?? '') === 'blocked') {
            return array_replace($plan, [
                'cases_schema_version' => self::SCHEMA_VERSION,
                'corpus_schema_version' => AtlasForgeRivalsProviderArenaCorpusService::SCHEMA_VERSION,
                'snapshot' => $this->corpus->snapshot(),
                'next_command' => 'php artisan atlas:forge:rivals cases --json --strict',
                'note' => 'Filter resolveu para 0 casos — bloqueador honesto, nenhum provider chamado.',
            ]);
        }

        return [
            'status' => 'ok',
            'cases_schema_version' => self::SCHEMA_VERSION,
            'corpus_schema_version' => AtlasForgeRivalsProviderArenaCorpusService::SCHEMA_VERSION,
            'snapshot' => $this->corpus->snapshot(),
            'applied_filters' => $plan['applied_filters'] ?? [],
            'cases' => $plan['cases'] ?? [],
            'count' => $plan['count'] ?? 0,
            'replay_manifest' => $plan['replay_manifest'] ?? null,
            'case_sets' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SETS,
            'task_categories' => AtlasForgeRivalsProviderArenaCorpusService::TASK_CATEGORIES,
            'external_provider_call' => false,
            'separated_from_external_rivals_certification' => true,
            'next_command' => 'php artisan atlas:forge:rivals run-arena --arm-a=<id> --arm-b=<id> --case-set=quick --mode=local_fake --json',
            'note' => 'Listing declarativo do corpus; manifests completos disponíveis via --case=<id>.',
        ];
    }
}
