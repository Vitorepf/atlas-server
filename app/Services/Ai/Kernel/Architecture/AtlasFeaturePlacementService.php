<?php

namespace App\Services\Ai\Kernel\Architecture;

use App\Models\AtlasEngineeringKnowledgeItem;
use App\Services\Engineering\EngineeringKnowledgeBaseService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use SplFileInfo;

class AtlasFeaturePlacementService
{
    public function __construct(
        private readonly EngineeringKnowledgeBaseService $knowledge,
        private readonly AtlasArchitectureOperationsCatalog $operations,
        private readonly AtlasRuntimeLanguageBoundaryReportService $runtimeBoundary,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function place(string $feature, array $hints = []): array
    {
        $feature = trim($feature);
        $text = Str::lower($feature.' '.implode(' ', array_map('strval', $hints)));
        $placement = $this->placement($text);
        $owners = $this->ownerDocs($placement, $text);
        $duplicates = $this->duplicateCandidates($feature, $owners);
        $kb = $this->knowledge->summary();
        $risks = $this->risks($placement, $duplicates, $kb);
        $blockedWhen = $this->blockedWhen($placement, $owners, $duplicates, $kb);

        return [
            'schema_version' => 'atlas.feature_placement.v1',
            'status' => 'ok',
            'feature' => $feature,
            'placement' => $placement,
            'gate_status' => $this->gateStatus($blockedWhen, $duplicates, $risks),
            'owner_docs' => $owners,
            'duplicate_candidates' => $duplicates,
            'implementation_contract' => $this->implementationContract($placement),
            'pre_implementation_checklist' => $this->preImplementationChecklist($placement),
            'blocked_when' => $blockedWhen,
            'next_actions' => $this->nextActions($placement, $owners, $duplicates, $blockedWhen),
            'kb_status' => [
                'status' => $kb['status'] ?? 'unknown',
                'last_indexed_at' => $kb['last_indexed_at'] ?? null,
                'active' => $kb['active'] ?? 0,
                'canonical_doc_count' => $kb['canonical_doc_count'] ?? 0,
            ],
            'risks' => $risks,
            'architecture_operations' => $this->placementOperations(),
            'required_validation' => [
                'git diff --check',
                'atlas engineering knowledge docs-health',
                'php artisan atlas:ai:architecture-validate --json',
                'php artisan atlas:ai:runtime-boundary --json',
                'atlas engineering knowledge sync --prune',
                'atlas engineering knowledge index-code --prune',
            ],
            'anti_duplication_rules' => [
                'surface_nao_decide',
                'provider_nao_decide',
                'tool_nao_decide',
                'domain_nao_burla_policy',
                'runtime_nao_executa_sem_decision_receipt',
                'tudo_repetido_vira_core',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function placementOperations(): array
    {
        $summary = $this->operations->summary();
        $requiredIds = [
            'architecture_readiness',
            'feature_placement',
            'session_bootstrap',
            'documentation_split_plan',
            'architecture_validate',
            'runtime_language_boundary',
            'documentation_health',
            'knowledge_sync',
            'code_intelligence_index',
        ];
        $commands = collect((array) ($summary['commands'] ?? []))
            ->filter(fn (array $command): bool => in_array((string) ($command['id'] ?? ''), $requiredIds, true))
            ->values()
            ->all();

        return [
            'schema_version' => $summary['schema_version'] ?? 'atlas.architecture_operations.v1',
            'section' => $summary['section'] ?? 'arquitetura_mae',
            'operation_ids' => array_values(array_filter(array_map(
                fn (array $command): ?string => is_string($command['id'] ?? null) ? $command['id'] : null,
                $commands,
            ))),
            'command_count' => count($commands),
            'commands' => $commands,
            'owner_layer_operations' => [
                'runtime' => $this->operations->summary(['owner_layer' => 'runtime']),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function placement(string $text): array
    {
        $domain = $this->domain($text);
        $surface = $this->surface($text);
        $runtime = $this->runtime($text);
        $businessContext = $this->businessContext($text);
        $requiresAp = $this->requiresAp($text, $runtime);
        $layer = $surface !== null ? 'surface' : ($runtime !== null ? 'runtime' : ($domain !== 'general' ? 'domain' : 'core_or_general'));

        if (Str::contains($text, ['evidence', 'ledger', 'audit', 'telemetry', 'slo', 'metric'])) {
            $layer = 'evidence';
        }
        if (Str::contains($text, ['doc', 'documenta', 'knowledge', 'kb', 'obsidian', 'agents.md', 'claude.md'])) {
            $layer = 'documentation_governance';
        }
        if ($this->isProviderEvolution($text)) {
            $layer = 'provider_evolution';
        }

        return [
            'layer' => $layer,
            'domain' => $domain,
            'business_context' => $businessContext,
            'business_context_role' => $businessContext === null ? null : 'context_not_atlas_ai_domain',
            'surface' => $surface,
            'runtime' => $runtime,
            'flow' => $this->flow($domain, $text),
            'requires_ap' => $requiresAp,
            'status_hint' => Str::contains($text, ['scaffold', 'futuro', 'future']) ? 'scaffold_or_future' : 'implementation_candidate',
        ];
    }

    private function requiresAp(string $text, ?string $runtime): bool
    {
        if (Str::contains($text, ['novo', 'future', 'futuro', 'autonom', 'self improve', 'auto melhorar', 'experimental'])) {
            return true;
        }

        if ($runtime === 'python_ai_data' && Str::contains($text, [
            'graph rag',
            'local rag',
            'rag',
            'embedding',
            'embeddings',
            'rerank',
            'reranker',
            'faiss',
            'chroma',
            'vector',
            'graph',
            'ml',
            'machine learning',
        ])) {
            return true;
        }

        return false;
    }

    private function domain(string $text): string
    {
        return match (true) {
            Str::contains($text, ['cognitive', 'aprendiz', 'estudo', 'dreyfus', 'failure', 'worked example']) => 'learning',
            Str::contains($text, ['finance', 'mercado', 'risco', 'trading']) => 'finance',
            Str::contains($text, ['program', 'codigo', 'code', 'developer', 'atlas dev', 'dev ', ' dev', 'forge', 'frontend', 'backend', 'bug', 'refactor']) => 'programming',
            Str::contains($text, ['marketing', 'copy', 'criativo', 'campanha', 'landing']) => 'marketing',
            Str::contains($text, ['self-improvement', 'curator', 'curadoria', 'auto melhorar', 'gap', 'drift']) => 'self_improvement',
            Str::contains($text, ['personal', 'habito', 'rotina', 'performance cognitiva']) => 'personal_development',
            Str::contains($text, ['decisao', 'decisão', 'strategy', 'estrateg', 'roadmap', 'precificacao', 'pricing', 'empresa', 'business']) => 'strategic_decision',
            default => 'general',
        };
    }

    private function businessContext(string $text): ?string
    {
        return match (true) {
            Str::contains($text, ['blackink', 'black ink']) => 'blackink',
            Str::contains($text, ['atlas server', 'atlas-server', 'atlas ai', 'estrutura mae', 'estrutura mãe']) => 'atlas',
            default => null,
        };
    }

    private function surface(string $text): ?string
    {
        return match (true) {
            Str::contains($text, ['voice', 'voz', 'livekit']) => 'voice_realtime',
            Str::contains($text, ['mobile', 'app mobile', 'iphone']) => 'atlas_app_mobile',
            Str::contains($text, ['cli', 'terminal', 'atlas dev', 'atlas forge']) => 'atlas_cli',
            Str::contains($text, ['obsidian', 'vault']) => 'atlas_vault',
            $this->isAtlasApiSurface($text) => 'atlas_api',
            default => null,
        };
    }

    private function isAtlasApiSurface(string $text): bool
    {
        return Str::contains($text, ['endpoint', 'webhook endpoint', 'http endpoint', 'rest endpoint'])
            || preg_match('/\b(atlas\s+api|api\s+route|api\s+endpoint|rest\s+api|http\s+api)\b/i', $text) === 1;
    }

    private function runtime(string $text): ?string
    {
        return match (true) {
            Str::contains($text, ['livekit agents', 'livekit agent', 'stt', 'tts', 'turn detection']) => 'python_ai_data',
            Str::contains($text, ['livekit server', 'webrtc', 'sfu', 'go edge']) => 'go_edge_concurrency',
            Str::contains($text, ['livekit swift', 'mobile native edge']) => 'swift_native_mac',
            Str::contains($text, ['python', 'rag', 'embedding', 'embeddings', 'rerank', 'reranker', 'ml', 'machine learning', 'pandas', 'faiss', 'chroma', 'vector', 'graph rag', 'graph']) => 'python_ai_data',
            Str::contains($text, ['golang', ' go ', 'webhook', 'postback', 'streaming', 'concorr', 'concurrency', 'ingestion', 'ingestor', 'sse', 'event stream']) => 'go_edge_concurrency',
            Str::contains($text, ['swift', 'macos', 'keychain', 'touch id', 'screencapture', 'screen capture', 'fsevents', 'accessibility api', 'core ml', 'secure enclave']) => 'swift_native_mac',
            Str::contains($text, ['laravel', 'kernel', 'maestro']) => 'laravel_kernel',
            default => null,
        };
    }

    private function flow(string $domain, string $text): string
    {
        if ($this->isProviderEvolution($text)) {
            return 'provider_evolution.review';
        }
        if (Str::contains($text, ['voice', 'voz', 'livekit'])) {
            return 'voice_realtime.session';
        }

        return match ($domain) {
            'programming' => Str::contains($text, ['review']) ? 'programming.review' : (Str::contains($text, ['frontend']) ? 'programming.frontend' : 'programming.dev'),
            'learning' => Str::contains($text, ['failure']) ? 'learning.failure_review' : 'learning.practice',
            'self_improvement' => Str::contains($text, ['provider']) ? 'self_improvement.provider_performance_review' : 'self_improvement.docs_drift_review',
            'finance' => 'finance.review',
            'marketing' => 'marketing.forge',
            'personal_development' => 'personal_development.plan',
            'strategic_decision' => 'strategic_decision.review',
            default => 'general.answer',
        };
    }

    /**
     * @param  array<string,mixed>  $placement
     * @return array<int,array<string,string>>
     */
    private function ownerDocs(array $placement, string $text): array
    {
        $paths = [
            'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md',
            'docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md',
            'docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md',
        ];
        if (($placement['business_context'] ?? null) !== null || Str::contains($text, ['blackink', 'empresa', 'business', 'produto'])) {
            $paths[] = 'docs/engineering-knowledge-base/atlas-ai-business-contexts.md';
        }

        $map = [
            'documentation_governance' => 'docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md',
            'surface' => 'docs/engineering-knowledge-base/atlas-ai-pipeline.md',
            'runtime' => 'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md',
            'evidence' => 'docs/engineering-knowledge-base/atlas-ai-telemetry-evidence-performance.md',
            'provider_evolution' => 'docs/engineering-knowledge-base/atlas-ai-provider-evolution-intelligence.md',
        ];
        $paths[] = $map[(string) $placement['layer']] ?? 'docs/engineering-knowledge-base/atlas-ai-master-architecture.md';

        $domain = (string) $placement['domain'];
        if ($domain !== 'general') {
            $paths[] = 'docs/engineering-knowledge-base/domains/'.$domain.'.md';
        }
        if (($placement['surface'] ?? null) === 'voice_realtime') {
            $paths[] = 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md';
        }
        if (($placement['surface'] ?? null) === 'atlas_app_mobile' || Str::contains($text, ['mobile', 'app mobile', 'iphone'])) {
            $paths[] = 'docs/engineering-knowledge-base/atlas-ai-mobile-surface-gateway.md';
        }
        if (Str::contains($text, ['frontend', 'design'])) {
            $paths[] = 'docs/engineering-knowledge-base/domains/programming-frontend-superpower.md';
        }
        if ($this->isProviderEvolution($text)) {
            $paths[] = 'docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md';
            $paths[] = 'docs/engineering-knowledge-base/atlas-ai-governed-backlog.md';
        }
        foreach ($this->runtimeOwnerDocs($placement, $text) as $path) {
            $paths[] = $path;
        }

        return collect($paths)
            ->unique()
            ->map(fn (string $path): array => [
                'path' => $path,
                'exists' => File::exists(base_path($path)) ? 'yes' : 'no',
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $placement
     * @return array<int,string>
     */
    private function runtimeOwnerDocs(array $placement, string $text): array
    {
        $runtime = (string) ($placement['runtime'] ?? '');
        $paths = [];

        if ($runtime !== '') {
            $paths[] = 'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md';
        }
        if ($runtime === 'python_ai_data') {
            $paths[] = 'docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md';
        }
        if ($runtime === 'go_edge_concurrency') {
            $paths[] = 'docs/engineering-knowledge-base/atlas-ai-runtime-language-boundaries.md';
        }
        if ($runtime === 'swift_native_mac') {
            $paths[] = 'docs/engineering-knowledge-base/atlas-native-mac-agent.md';
        }
        if (Str::contains($text, ['voice', 'voz', 'livekit', 'stt', 'tts', 'webrtc', 'sfu', 'turn detection'])) {
            $paths[] = 'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md';
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<int,array<string,string>>  $owners
     * @return array<int,array<string,mixed>>
     */
    private function duplicateCandidates(string $feature, array $owners): array
    {
        $terms = collect(preg_split('/[^a-zA-Z0-9_\\-]+/', Str::lower($feature)) ?: [])
            ->filter(fn (string $term): bool => strlen($term) >= 4)
            ->take(8)
            ->values();

        $docs = $this->docCandidates($terms->all());
        $kb = $this->kbCandidates($terms->all());
        $code = $this->codeCandidates($terms->all());

        return collect([...$kb, ...$docs, ...$code])
            ->unique(fn (array $candidate): string => (string) ($candidate['path'] ?? '').':'.(string) ($candidate['source'] ?? ''))
            ->sortByDesc('score')
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>>
     */
    private function docCandidates(array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        return collect(File::allFiles(base_path('docs/engineering-knowledge-base')))
            ->filter(fn (SplFileInfo $file): bool => $file->getExtension() === 'md')
            ->map(function (SplFileInfo $file) use ($terms): array {
                $path = str_replace(base_path().'/', '', $file->getPathname());
                $body = Str::lower((string) File::get($file->getPathname()));
                $score = collect($terms)->sum(fn (string $term): int => substr_count($body, $term));

                return ['source' => 'repo_docs', 'path' => $path, 'score' => $score];
            })
            ->filter(fn (array $candidate): bool => (int) $candidate['score'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>>
     */
    private function kbCandidates(array $terms): array
    {
        if ($terms === [] || ! Schema::hasTable('atlas_engineering_knowledge_items')) {
            return [];
        }

        return AtlasEngineeringKnowledgeItem::query()
            ->active()
            ->get()
            ->map(function (AtlasEngineeringKnowledgeItem $item) use ($terms): array {
                $haystack = Str::lower(implode(' ', [
                    $item->title,
                    $item->summary,
                    $item->body_excerpt,
                    implode(' ', (array) $item->tags_json),
                    implode(' ', (array) $item->capabilities_json),
                ]));
                $score = collect($terms)->sum(fn (string $term): int => substr_count($haystack, $term));

                return [
                    'source' => 'postgres_kb',
                    'path' => $item->canonical_path,
                    'slug' => $item->slug,
                    'title' => $item->title,
                    'score' => $score,
                    'indexed_at' => $item->indexed_at?->toJSON(),
                ];
            })
            ->filter(fn (array $candidate): bool => (int) $candidate['score'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $terms
     * @return array<int,array<string,mixed>>
     */
    private function codeCandidates(array $terms): array
    {
        if ($terms === []) {
            return [];
        }

        $roots = ['app', 'routes', 'config', 'database/migrations', 'tests'];

        return collect($roots)
            ->flatMap(fn (string $root) => File::isDirectory(base_path($root)) ? File::allFiles(base_path($root)) : [])
            ->filter(fn (SplFileInfo $file): bool => in_array($file->getExtension(), ['php', 'json', 'yaml', 'yml'], true))
            ->map(function (SplFileInfo $file) use ($terms): array {
                $path = str_replace(base_path().'/', '', $file->getPathname());
                $body = Str::lower((string) File::get($file->getPathname()));
                $matchedTerms = collect($terms)
                    ->filter(fn (string $term): bool => str_contains($body, $term))
                    ->values()
                    ->all();
                $score = collect($terms)->sum(fn (string $term): int => substr_count($body, $term));

                return [
                    'source' => 'repo_code',
                    'path' => $path,
                    'score' => $score,
                    'matched_terms' => $matchedTerms,
                ];
            })
            ->filter(fn (array $candidate): bool => (int) $candidate['score'] > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $placement
     * @param  array<int,array<string,mixed>>  $duplicates
     * @param  array<string,mixed>  $kb
     * @return array<int,string>
     */
    private function risks(array $placement, array $duplicates, array $kb): array
    {
        $risks = [];
        if ($duplicates !== []) {
            $risks[] = 'possible_existing_capability_or_doc_overlap_review_duplicate_candidates_first';
        }
        if (($kb['status'] ?? null) !== 'ready') {
            $risks[] = 'postgres_kb_not_ready_run_sync_before_trusting_context_pack';
        }
        if (($placement['requires_ap'] ?? false) === true) {
            $risks[] = 'new_or_future_capability_requires_ap_before_runtime_code';
        }
        if (($placement['layer'] ?? null) === 'surface') {
            $risks[] = 'surface_adapter_must_not_own_decision_or_provider_routing';
        }
        if (($placement['layer'] ?? null) === 'provider_evolution') {
            $risks[] = 'provider_release_must_be_reviewed_before_routing_policy_or_domain_maturity_changes';
        }
        if (($placement['business_context'] ?? null) !== null) {
            $risks[] = 'business_context_must_not_be_promoted_to_atlas_ai_domain_without_dedicated_runtime_gates_memory_and_evidence';
        }
        $runtime = (string) ($placement['runtime'] ?? '');
        if ($runtime === 'python_ai_data') {
            $risks[] = 'python_ai_data_must_stay_behind_kernel_decision_receipt_and_not_inside_laravel_app';
            $risks[] = 'rag_ml_graph_or_embedding_work_must_not_create_parallel_memory_or_context_store';
        }
        if ($runtime === 'go_edge_concurrency') {
            $risks[] = 'go_edge_must_only_ingest_or_stream_events_and_must_not_decide_policy_provider_or_domain';
        }
        if ($runtime === 'swift_native_mac') {
            $risks[] = 'swift_native_mac_requires_explicit_privacy_consent_eclipse_rules_and_kernel_receipt';
        }

        return $risks;
    }

    /**
     * @param  array<string,mixed>  $placement
     * @return array<string,mixed>
     */
    private function implementationContract(array $placement): array
    {
        $layer = (string) ($placement['layer'] ?? 'core_or_general');
        $domain = (string) ($placement['domain'] ?? 'general');
        $surface = $placement['surface'] ?? null;
        $runtime = $placement['runtime'] ?? null;

        return [
            'schema_version' => 'atlas.feature_implementation_contract.v1',
            'owner_layer' => $layer,
            'owner_domain' => $domain,
            'business_context' => $placement['business_context'] ?? null,
            'runtime_family' => $runtime,
            'runtime_invocation_contract' => $this->runtimeInvocationContract($runtime),
            'runtime_boundary_rule' => $runtime === null
                ? 'no_specialized_runtime_detected'
                : 'specialized_runtime_must_be_invoked_by_kernel_decision_receipt_and_report_evidence',
            'allowed_write_scopes' => $this->allowedWriteScopes($layer, $domain, $surface, $runtime),
            'forbidden_write_scopes' => $this->forbiddenWriteScopes($layer, $surface, $runtime),
            'documentation_rule' => 'update_owner_doc_before_or_with_code_never_after',
            'test_rule' => 'add_or_update_focused_tests_for_the_owner_layer_and_run_architecture_validate',
            'evidence_rule' => 'important_runtime_or_policy_changes_must_emit_or_preserve_evidence_ledger_contracts',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function runtimeInvocationContract(mixed $runtime): ?array
    {
        if ($runtime === null) {
            return null;
        }

        $contract = $this->runtimeBoundary->invocationContract();

        return [
            ...$contract,
            'selected_runtime_family' => $this->canonicalRuntimeFamily($runtime),
            'placement_runtime_alias' => $runtime,
            'evidence_rule' => 'runtime_must_return_evidence_refs_or_output_artifacts_to_kernel',
        ];
    }

    private function canonicalRuntimeFamily(mixed $runtime): string
    {
        return match ($runtime) {
            'go_edge_concurrency' => 'go_edge',
            default => (string) $runtime,
        };
    }

    /**
     * @return array<int,string>
     */
    private function allowedWriteScopes(string $layer, string $domain, mixed $surface, mixed $runtime): array
    {
        $scopes = [
            'docs/engineering-knowledge-base/<owner-docs>',
            'tests/<focused-owner-tests>',
        ];

        if ($domain !== 'general') {
            $scopes[] = 'app/Services/Ai/Domain';
            $scopes[] = 'docs/engineering-knowledge-base/domains';
        }

        if ($layer === 'documentation_governance') {
            $scopes[] = 'app/Services/Engineering';
            $scopes[] = 'docs/engineering-knowledge-base';
        }
        if ($layer === 'provider_evolution') {
            $scopes[] = 'app/Services/Ai/Kernel/Architecture/AtlasProviderReleaseIntelligenceService.php';
            $scopes[] = 'app/Services/Ai/SelfImprovement';
            $scopes[] = 'app/Console/Commands/AtlasAiProviderReleaseReviewCommand.php';
            $scopes[] = 'app/Http/Controllers/AtlasAiProviderReleaseReviewController.php';
        }
        if ($surface !== null) {
            $scopes[] = 'app/Services/Ai/Surface';
            $scopes[] = 'app/Http/Controllers';
            $scopes[] = 'routes';
        }
        if ($runtime === 'python_ai_data') {
            $scopes[] = 'runtimes/python';
        }
        if ($runtime === 'go_edge_concurrency') {
            $scopes[] = 'runtimes/go';
        }
        if ($runtime === 'swift_native_mac') {
            $scopes[] = 'runtimes/swift';
        }

        return array_values(array_unique($scopes));
    }

    /**
     * @return array<int,string>
     */
    private function forbiddenWriteScopes(string $layer, mixed $surface, mixed $runtime): array
    {
        $forbidden = [
            'do_not_create_new_domain_for_business_context_without_formal_domain_onboarding',
            'do_not_put_provider_selection_inside_surface_runtime_or_tool',
            'do_not_duplicate_existing_core_capability_inside_domain_or_surface',
        ];

        if ($surface !== null) {
            $forbidden[] = 'surface_must_collect_input_and_render_output_only';
        }
        if ($runtime !== null) {
            $forbidden[] = 'runtime_must_not_execute_without_decision_receipt';
            $forbidden[] = 'do_not_create_parallel_brain_context_store_or_policy_engine_inside_runtime';
        }
        if ($runtime === 'python_ai_data') {
            $forbidden[] = 'do_not_implement_heavy_rag_embeddings_rerank_graph_or_ml_inside_laravel_app';
        }
        if ($runtime === 'go_edge_concurrency') {
            $forbidden[] = 'do_not_put_provider_policy_memory_or_domain_decision_inside_go_edge_runtime';
        }
        if ($runtime === 'swift_native_mac') {
            $forbidden[] = 'do_not_capture_mic_screen_keychain_touchid_or_accessibility_without_kernel_policy_and_user_consent';
        }
        if ($layer === 'provider_evolution') {
            $forbidden[] = 'provider_release_must_not_change_routing_defaults_without_review_signal_or_human_approval';
        }

        return $forbidden;
    }

    /**
     * @param  array<string,mixed>  $placement
     * @return array<int,string>
     */
    private function preImplementationChecklist(array $placement): array
    {
        $items = [
            'run_session_bootstrap_for_the_task',
            'read_all_owner_docs_before_editing',
            'review_duplicate_candidates_and_reuse_existing_capabilities_first',
            'update_or_create_tests_before_broad_refactors',
            'run_architecture_validate_before_final_response',
        ];

        if (($placement['business_context'] ?? null) !== null) {
            $items[] = 'keep_business_context_separate_from_atlas_ai_domain';
        }
        if (($placement['requires_ap'] ?? false) === true) {
            $items[] = 'create_or_update_ap_contract_before_runtime_code';
        }
        if (($placement['runtime'] ?? null) !== null) {
            $items[] = 'run_runtime_language_boundary_before_and_after_changes';
            $items[] = 'declare_kernel_decision_receipt_contract_for_runtime_invocation';
            $items[] = 'prove_runtime_outputs_return_to_evidence_ledger_or_output_renderer';
        }

        return $items;
    }

    /**
     * @param  array<string,mixed>  $placement
     * @param  array<int,array<string,string>>  $owners
     * @param  array<int,array<string,mixed>>  $duplicates
     * @param  array<string,mixed>  $kb
     * @return array<int,string>
     */
    private function blockedWhen(array $placement, array $owners, array $duplicates, array $kb): array
    {
        $blocked = [];
        if (collect($owners)->contains(fn (array $doc): bool => ($doc['exists'] ?? 'no') !== 'yes')) {
            $blocked[] = 'owner_doc_missing_create_or_fix_doc_before_code';
        }
        if (($placement['layer'] ?? null) === 'core_or_general') {
            $blocked[] = 'ambiguous_placement_requires_more_specific_feature_or_hint';
        }
        if (($placement['requires_ap'] ?? false) === true) {
            $blocked[] = 'new_or_future_capability_requires_ap_contract_first';
        }
        if (collect($duplicates)->contains(fn (array $candidate): bool => (int) ($candidate['score'] ?? 0) >= 25)) {
            $blocked[] = 'high_overlap_duplicate_candidate_requires_reuse_or_explicit_supersede_decision';
        }

        return array_values(array_unique($blocked));
    }

    /**
     * @param  array<int,string>  $blockedWhen
     * @param  array<int,array<string,mixed>>  $duplicates
     * @param  array<int,string>  $risks
     */
    private function gateStatus(array $blockedWhen, array $duplicates, array $risks): string
    {
        if ($blockedWhen !== []) {
            return 'blocked';
        }
        if ($duplicates !== [] || $risks !== []) {
            return 'attention_required';
        }

        return 'passed';
    }

    /**
     * @param  array<string,mixed>  $placement
     * @param  array<int,array<string,string>>  $owners
     * @param  array<int,array<string,mixed>>  $duplicates
     * @param  array<int,string>  $blockedWhen
     * @return array<int,string>
     */
    private function nextActions(array $placement, array $owners, array $duplicates, array $blockedWhen): array
    {
        if ($blockedWhen !== []) {
            $actions = [
                'resolve_blocked_when_items_first',
                'rerun_php_artisan_atlas_ai_place_feature',
                'only_then_edit_owner_scope',
            ];
            if (($placement['layer'] ?? null) === 'provider_evolution') {
                array_unshift($actions, 'run_provider_release_review_before_changing_routing');
            }

            return array_values(array_unique($actions));
        }

        $actions = [
            'read_owner_docs',
            'inspect_duplicate_candidates',
            'edit_only_allowed_write_scopes',
            'run_required_validation',
        ];
        if (($placement['layer'] ?? null) === 'provider_evolution') {
            array_unshift($actions, 'run_provider_release_review_before_changing_routing');
        }
        if ($duplicates !== []) {
            array_unshift($actions, 'decide_reuse_extend_or_supersede_existing_capability');
        }

        return array_values(array_unique($actions));
    }

    private function isProviderEvolution(string $text): bool
    {
        return Str::contains($text, [
            'provider release',
            'release de provider',
            'lancamento de provider',
            'lançamento de provider',
            'anthropic',
            'openai',
            'chatgpt',
            'gemini',
            'claude finance',
            'finance agents',
            'provider evolution',
            'novidade do claude',
            'novidade da openai',
            'novidade do gemini',
        ]);
    }
}
