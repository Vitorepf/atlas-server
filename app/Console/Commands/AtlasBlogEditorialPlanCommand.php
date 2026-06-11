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
        {--context-limit=5 : Maximum KB/module/symbol refs per context group}
        {--suggest-candidates : Suggest reviewable new backlog candidates from Atlas read-models}
        {--candidate-limit=10 : Maximum backlog candidates to suggest}
        {--accept-candidate= : Candidate slug to accept into the review queue}
        {--write : Write accepted candidate to the review queue; without this, acceptance is dry-run}
        {--json : Emit canonical JSON}';

    protected $description = 'Read the public blog backlog and report the next governed editorial step.';

    public function handle(BlogEditorialPlannerService $planner): int
    {
        $siteRoot = (string) ($this->option('site') ?: dirname(base_path(), 2).'/vitorepf-site');
        $payload = $planner->plan($siteRoot, (string) $this->option('backlog'), [
            'with_context' => (bool) $this->option('with-context'),
            'context_limit' => (int) $this->option('context-limit'),
            'suggest_candidates' => (bool) $this->option('suggest-candidates'),
            'candidate_limit' => (int) $this->option('candidate-limit'),
            'accept_candidate' => $this->option('accept-candidate'),
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
        $this->components->twoColumnDetail('Candidates', (string) data_get($payload, 'backlog_candidates.candidate_count', 0));
        $this->components->twoColumnDetail('Acceptance', (string) data_get($payload, 'candidate_acceptance.status', 'off'));

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

        $acceptance = data_get($payload, 'candidate_acceptance');
        if (is_array($acceptance)) {
            $this->newLine();
            $this->line('Candidate acceptance:');
            $this->line('- '.((string) ($acceptance['candidate_slug'] ?? 'unknown')).' -> '.((string) ($acceptance['target_path'] ?? 'unknown')));
            $this->line('- mode: '.((string) ($acceptance['mode'] ?? 'unknown')));
        }

        return ($payload['status'] ?? null) === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
