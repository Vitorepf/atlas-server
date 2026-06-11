<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Publishing\BlogEditorialPlannerService;
use Illuminate\Console\Command;

final class AtlasBlogEditorialPlanCommand extends Command
{
    protected $signature = 'atlas:blog:editorial-plan
        {--site= : Public site repository path}
        {--backlog=content/backlog/blog-first-month.yaml : Backlog path relative to the site root}
        {--with-context : Attach governed Atlas KB/code-intelligence refs to posts}
        {--source-map : Attach read-only Atlas editorial source readiness map}
        {--review-queue : Attach accepted candidate review queue state}
        {--coverage-map : Attach read-only sequence coverage and gap map}
        {--operations : Attach read-only daily editorial operations packet}
        {--operating-state : Attach compact read-only state for the Atlas blog planning area}
        {--editorial-radar : Attach read-only week/sequence/candidate radar}
        {--editorial-golden-set : Attach read-only sequence golden-set evaluation}
        {--editorial-graph-context : Attach bounded Codebase World Model editorial graph context}
        {--editorial-graph-candidates : Suggest review-only backlog candidates from bounded editorial graph context}
        {--graph-world-model-id= : Optional Codebase World Model id for editorial graph context}
        {--graph-context-limit=8 : Maximum bounded graph evidence nodes}
        {--graph-rag-readiness : Attach read-only P2 graph/RAG readiness preflight}
        {--writing-packet : Attach read-only writing packet for the next ready post}
        {--writing-slug= : Specific post slug to prepare instead of the next ready post}
        {--execute-open-brain : Execute the audited Open Brain context export for the operations/writing target}
        {--context-limit=5 : Maximum KB/module/symbol refs per context group}
        {--suggest-candidates : Suggest reviewable new backlog candidates from Atlas read-models}
        {--candidate-limit=10 : Maximum backlog candidates to suggest}
        {--accept-candidate= : Candidate slug to accept into the review queue}
        {--promote-candidate= : Accepted review-queue candidate slug to promote into the main backlog}
        {--promotion-after= : Existing post slug to use as the promoted candidate prerequisite}
        {--write : Write the requested acceptance/promotion; without this, mutating actions are dry-run}
        {--json : Emit canonical JSON}';

    protected $description = 'Read the public blog backlog and report the next governed editorial step.';

    public function handle(BlogEditorialPlannerService $planner): int
    {
        $siteRoot = (string) ($this->option('site') ?: dirname(base_path(), 2).'/vitorepf-site');
        $payload = $planner->plan($siteRoot, (string) $this->option('backlog'), [
            'with_context' => (bool) $this->option('with-context'),
            'source_map' => (bool) $this->option('source-map'),
            'review_queue' => (bool) $this->option('review-queue'),
            'coverage_map' => (bool) $this->option('coverage-map'),
            'operations' => (bool) $this->option('operations'),
            'operating_state' => (bool) $this->option('operating-state'),
            'editorial_radar' => (bool) $this->option('editorial-radar'),
            'editorial_golden_set' => (bool) $this->option('editorial-golden-set'),
            'editorial_graph_context' => (bool) $this->option('editorial-graph-context'),
            'editorial_graph_candidates' => (bool) $this->option('editorial-graph-candidates'),
            'graph_world_model_id' => $this->option('graph-world-model-id'),
            'graph_context_limit' => (int) $this->option('graph-context-limit'),
            'graph_rag_readiness' => (bool) $this->option('graph-rag-readiness'),
            'writing_packet' => (bool) $this->option('writing-packet'),
            'writing_slug' => $this->option('writing-slug'),
            'execute_open_brain' => (bool) $this->option('execute-open-brain'),
            'context_limit' => (int) $this->option('context-limit'),
            'suggest_candidates' => (bool) $this->option('suggest-candidates'),
            'candidate_limit' => (int) $this->option('candidate-limit'),
            'accept_candidate' => $this->option('accept-candidate'),
            'promote_candidate' => $this->option('promote-candidate'),
            'promotion_after' => $this->option('promotion-after'),
            'write' => (bool) $this->option('write'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Blog Editorial Planner', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Cadence', (string) data_get($payload, 'backlog.cadence', 'unknown'));
        $this->components->twoColumnDetail('Planned', (string) data_get($payload, 'summary.planned_posts', 0));
        $this->components->twoColumnDetail('Published', (string) data_get($payload, 'summary.published_posts', 0));
        $this->components->twoColumnDetail('Ready', (string) data_get($payload, 'summary.ready_posts', 0));
        $this->components->twoColumnDetail('Context', (bool) data_get($payload, 'summary.with_context', false) ? 'attached' : 'off');
        $this->components->twoColumnDetail('Source map', (bool) data_get($payload, 'summary.with_source_map', false) ? 'attached' : 'off');
        $this->components->twoColumnDetail('Review queue', (bool) data_get($payload, 'summary.with_review_queue', false) ? 'attached' : 'off');
        $this->components->twoColumnDetail('Coverage map', (bool) data_get($payload, 'summary.with_coverage_map', false) ? 'attached' : 'off');
        $this->components->twoColumnDetail('Operations', (bool) data_get($payload, 'summary.with_operations_packet', false) ? 'attached' : 'off');
        $this->components->twoColumnDetail('Operating state', (bool) data_get($payload, 'summary.with_operating_state', false) ? 'attached' : 'off');
        $this->components->twoColumnDetail('Editorial radar', (bool) data_get($payload, 'summary.with_editorial_radar', false) ? 'attached' : 'off');
        $this->components->twoColumnDetail('Editorial golden set', (bool) data_get($payload, 'summary.with_editorial_golden_set', false) ? 'attached' : 'off');
        $this->components->twoColumnDetail('Editorial graph context', (bool) data_get($payload, 'summary.with_editorial_graph_context', false) ? 'attached' : 'off');
        $this->components->twoColumnDetail('Editorial graph candidates', (bool) data_get($payload, 'summary.with_editorial_graph_candidates', false) ? 'attached' : 'off');
        $this->components->twoColumnDetail('Graph/RAG readiness', (bool) data_get($payload, 'summary.with_graph_rag_readiness', false) ? 'attached' : 'off');
        $this->components->twoColumnDetail('Writing packet', (bool) data_get($payload, 'summary.with_writing_packet', false) ? 'attached' : 'off');
        $this->components->twoColumnDetail('Open Brain', (bool) data_get($payload, 'summary.with_open_brain_execution', false) ? 'executed' : 'handoff only');
        $this->components->twoColumnDetail('Candidates', (string) data_get($payload, 'backlog_candidates.candidate_count', 0));
        $this->components->twoColumnDetail('Acceptance', (string) data_get($payload, 'candidate_acceptance.status', 'off'));
        $this->components->twoColumnDetail('Promotion', (string) data_get($payload, 'candidate_promotion.status', 'off'));

        $next = data_get($payload, 'next_ready_post');
        if (is_array($next)) {
            $this->newLine();
            $this->line('Next ready post:');
            $this->line(((string) $next['order']).'. '.$next['title'].' ('.$next['slug'].')');
            $this->line((string) $next['main_question']);

            $knowledgeRefs = data_get($next, 'editorial_context.knowledge_refs', []);
            if (is_array($knowledgeRefs) && $knowledgeRefs !== []) {
                $this->newLine();
                $this->line('Context refs:');
                foreach (array_slice($knowledgeRefs, 0, 3) as $ref) {
                    if (is_array($ref)) {
                        $this->line('- '.((string) ($ref['canonical_path'] ?? $ref['title'] ?? 'unknown')));
                    }
                }
            }
        }

        $candidates = data_get($payload, 'backlog_candidates.candidates', []);
        if (is_array($candidates) && $candidates !== []) {
            $this->newLine();
            $this->line('Backlog candidates:');
            foreach (array_slice($candidates, 0, 5) as $candidate) {
                if (is_array($candidate)) {
                    $this->line('- '.((string) ($candidate['title'] ?? 'unknown')).' after '.((string) ($candidate['suggested_after_slug'] ?? 'review')));
                }
            }
        }

        $sourceMap = data_get($payload, 'source_map');
        if (is_array($sourceMap)) {
            $this->newLine();
            $this->line('Source map:');
            $this->line('- KB: '.((string) data_get($sourceMap, 'sources.engineering_knowledge.status', 'unknown')));
            $this->line('- Code: '.((string) data_get($sourceMap, 'sources.code_intelligence.status', 'unknown')));
            $this->line('- Graph/RAG: '.((string) data_get($sourceMap, 'sources.graph_retrieval.status', 'unknown')));
        }

        $reviewQueue = data_get($payload, 'review_queue');
        if (is_array($reviewQueue)) {
            $this->newLine();
            $this->line('Review queue:');
            $this->line('- status: '.((string) data_get($reviewQueue, 'status', 'unknown')));
            $this->line('- candidates: '.((string) data_get($reviewQueue, 'candidate_count', 0)));
            $this->line('- duplicates: '.((string) data_get($reviewQueue, 'duplicate_count', 0)));
        }

        $coverageMap = data_get($payload, 'coverage_map');
        if (is_array($coverageMap)) {
            $this->newLine();
            $this->line('Coverage map:');
            $this->line('- foundation planned: '.((string) data_get($coverageMap, 'summary.foundation_planned', 0)).'/'.((string) data_get($coverageMap, 'summary.foundation_items', 0)));
            $this->line('- sequence warnings: '.((string) data_get($coverageMap, 'summary.deep_sequence_warning_count', 0)));
        }

        $writingPacket = data_get($payload, 'writing_packet');
        if (is_array($writingPacket)) {
            $this->newLine();
            $this->line('Writing packet:');
            $this->line('- '.((string) data_get($writingPacket, 'post.slug', data_get($writingPacket, 'error', 'unknown'))));
            $this->line('- '.((string) data_get($writingPacket, 'writing_brief.reader_promise', '')));
        }

        $operations = data_get($payload, 'operations_packet');
        if (is_array($operations)) {
            $this->newLine();
            $this->line('Operations packet:');
            $this->line('- next action: '.((string) data_get($operations, 'next_action.action', 'unknown')));
            $this->line('- next slug: '.((string) data_get($operations, 'next_action.slug', 'unknown')));
            $this->line('- duplicate risks: '.((string) data_get($operations, 'public_archive_risks.duplicate_risk_count', 0)));
        }

        $operatingState = data_get($payload, 'operating_state');
        if (is_array($operatingState)) {
            $this->newLine();
            $this->line('Operating state:');
            $this->line('- stage: '.((string) data_get($operatingState, 'stage.current', 'unknown')));
            $this->line('- next post: '.((string) data_get($operatingState, 'next_post.slug', 'none')));
            $this->line('- queue: '.((string) data_get($operatingState, 'review_queue.candidate_count', 0)));
            $this->line('- candidate feed: '.((string) data_get($operatingState, 'candidate_pipeline.feed_count', 0)));
        }

        $radar = data_get($payload, 'editorial_radar');
        if (is_array($radar)) {
            $this->newLine();
            $this->line('Editorial radar:');
            $this->line('- next sequence order: '.((string) data_get($radar, 'current_state.next_sequence_order', 1)));
            $this->line('- next ready: '.((string) data_get($radar, 'current_state.next_ready_slug', 'none')));
            $this->line('- graph/RAG: '.((string) data_get($radar, 'source_readiness.graph_retrieval', 'unknown')));
            $this->line('- candidate feed: '.((string) data_get($radar, 'candidate_feed.candidate_count', 0)));
        }

        $goldenSet = data_get($payload, 'editorial_golden_set');
        if (is_array($goldenSet)) {
            $this->newLine();
            $this->line('Editorial golden set:');
            $this->line('- status: '.((string) data_get($goldenSet, 'status', 'unknown')));
            $this->line('- cases: '.((string) data_get($goldenSet, 'summary.passed_count', 0)).'/'.((string) data_get($goldenSet, 'summary.case_count', 0)));
        }

        $graphContext = data_get($payload, 'editorial_graph_context');
        if (is_array($graphContext)) {
            $this->newLine();
            $this->line('Editorial graph context:');
            $this->line('- status: '.((string) data_get($graphContext, 'status', 'unknown')));
            $this->line('- retrieval: '.((string) data_get($graphContext, 'graph_retrieval.status', 'unknown')));
            $this->line('- evidence: '.((string) data_get($graphContext, 'graph_retrieval.evidence_set.evidence_count', 0)));
        }

        $graphCandidates = data_get($payload, 'editorial_graph_candidates');
        if (is_array($graphCandidates)) {
            $this->newLine();
            $this->line('Editorial graph candidates:');
            $this->line('- status: '.((string) data_get($graphCandidates, 'status', 'unknown')));
            $this->line('- candidates: '.((string) data_get($graphCandidates, 'candidate_count', 0)));
            $this->line('- after: '.((string) data_get($graphCandidates, 'sequence_policy.default_suggested_after_slug', 'review')));
        }

        $graphRagReadiness = data_get($payload, 'graph_rag_readiness');
        if (is_array($graphRagReadiness)) {
            $this->newLine();
            $this->line('Graph/RAG readiness:');
            $this->line('- status: '.((string) data_get($graphRagReadiness, 'status', 'unknown')));
            $this->line('- current phase: '.((string) data_get($graphRagReadiness, 'current_phase', 'unknown')));
            $this->line('- target phase: '.((string) data_get($graphRagReadiness, 'target_phase', 'unknown')));
            $this->line('- blocking items: '.((string) data_get($graphRagReadiness, 'summary.blocking_item_count', 0)));
        }

        $acceptance = data_get($payload, 'candidate_acceptance');
        if (is_array($acceptance)) {
            $this->newLine();
            $this->line('Candidate acceptance:');
            $this->line('- '.((string) ($acceptance['candidate_slug'] ?? 'unknown')).' -> '.((string) ($acceptance['target_path'] ?? 'unknown')));
            $this->line('- mode: '.((string) ($acceptance['mode'] ?? 'unknown')));
        }

        $promotion = data_get($payload, 'candidate_promotion');
        if (is_array($promotion)) {
            $this->newLine();
            $this->line('Candidate promotion:');
            $this->line('- '.((string) ($promotion['candidate_slug'] ?? 'unknown')).' -> '.((string) ($promotion['backlog_path'] ?? 'unknown')));
            $this->line('- mode: '.((string) ($promotion['mode'] ?? 'unknown')));
        }

        return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
