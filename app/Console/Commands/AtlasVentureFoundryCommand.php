<?php

namespace App\Console\Commands;

use App\Models\AiVenture;
use App\Models\AiVentureIdea;
use App\Models\AiVentureStrategyReview;
use App\Services\Ai\VentureFoundry\VentureBusinessRuleService;
use App\Services\Ai\VentureFoundry\VentureExecutionBridgeService;
use App\Services\Ai\VentureFoundry\VentureFoundryException;
use App\Services\Ai\VentureFoundry\VentureGrowthLadderService;
use App\Services\Ai\VentureFoundry\VentureIdeaGenerationService;
use App\Services\Ai\VentureFoundry\VentureIdeationService;
use App\Services\Ai\VentureFoundry\VentureRegistryService;
use App\Services\Ai\VentureFoundry\VentureResearchHandoffService;
use App\Services\Ai\VentureFoundry\VentureStrategistService;
use Illuminate\Console\Command;

class AtlasVentureFoundryCommand extends Command
{
    protected $signature = 'atlas:venture
        {action : idea-register|idea-list|ideate-from-radar|ideate-generate|promote|venture-list|venture-show|link|rule-add|rule-list|metric-record|ladder|strategist-review|review-cycle|comprehend|comprehension-report|comprehension-findings|assess|assessment-report|questions|focus|data-readiness|question-catalog|research-handoff|bridge-execution|status}
        {--dimension-filter= : Filter answers by dimension (questions)}
        {--status-filter= : Filter answers by status (questions)}
        {--analyze : Include the provider-backed strategic opinion (explicit spend)}
        {--brief= : Operator brief for governed generative ideation (ideate-generate)}
        {--count= : Max ideas to generate (ideate-generate)}
        {--question=* : Extra research questions (research-handoff)}
        {--review= : Review uuid to bridge; default latest (bridge-execution)}
        {--title= : Idea title (idea-register)}
        {--problem= : Problem statement (idea-register)}
        {--icp= : Ideal customer profile (idea-register)}
        {--pain= : Pain description (idea-register)}
        {--urgency=medium : low|medium|high|critical (idea-register)}
        {--market-size-usd= : Estimated market size in USD (idea-register)}
        {--pain-severity=3 : 0-5 (idea-register)}
        {--founder-fit=3 : 0-5 (idea-register)}
        {--sovereignty-fit=3 : 0-5 (idea-register)}
        {--idea= : Idea uuid or idea_id (promote)}
        {--venture= : Venture uuid or venture_id}
        {--name= : Venture name (promote)}
        {--thesis= : Venture thesis (promote)}
        {--target-arr-usd= : Target ARR in USD, default 100M (promote)}
        {--opportunity-id= : Opportunity uuid to link (link)}
        {--blueprint-id= : Venture blueprint uuid to link (link)}
        {--north-star-metric= : North star metric key (link)}
        {--north-star-target= : North star target value (link)}
        {--rule-id= : Stable business rule id (rule-add)}
        {--category= : Business rule category (rule-add)}
        {--statement= : Business rule statement (rule-add)}
        {--rationale= : Business rule rationale (rule-add)}
        {--metric= : Metric key (metric-record)}
        {--value= : Metric value (metric-record)}
        {--unit= : Metric unit (metric-record)}
        {--currency= : Metric currency (metric-record)}
        {--apply : Apply recommended stage on strategist-review}
        {--only= : Comma-separated capability subset for comprehend (business_rule,problem,improvement,audience_usage,documentation)}
        {--promote-rules : Promote high-confidence mined rules into the venture rule canon (comprehend)}
        {--write-docs= : Absolute base dir to write generated canonical docs to disk (comprehend)}
        {--capability= : Filter findings by capability (comprehension-findings)}
        {--severity= : Filter findings by severity (comprehension-findings)}
        {--limit=30 : Max findings to list (comprehension-findings)}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Venture Foundry: criação e gestão de empresas — ideação, regras de negócio, métricas e estratégia até o alvo de 100M USD.';

    public function handle(
        VentureIdeationService $ideation,
        VentureRegistryService $registry,
        VentureBusinessRuleService $rules,
        VentureGrowthLadderService $ladder,
        VentureStrategistService $strategist,
        VentureIdeaGenerationService $generation,
        VentureResearchHandoffService $research,
        VentureExecutionBridgeService $bridge,
        \App\Services\Ai\VentureFoundry\Comprehension\VentureComprehensionService $comprehension,
        \App\Services\Ai\VentureFoundry\Assessment\VentureAssessmentService $assessment,
    ): int {
        $action = (string) $this->argument('action');

        try {
            return match ($action) {
                'idea-register' => $this->ideaRegister($ideation),
                'idea-list' => $this->ideaList(),
                'ideate-from-radar' => $this->ideateFromRadar($ideation),
                'ideate-generate' => $this->ideateGenerate($generation),
                'promote' => $this->promote($registry),
                'venture-list' => $this->ventureList(),
                'venture-show' => $this->ventureShow($registry, $rules, $ladder),
                'link' => $this->link($registry),
                'rule-add' => $this->ruleAdd($registry, $rules),
                'rule-list' => $this->ruleList($registry, $rules),
                'metric-record' => $this->metricRecord($registry, $ladder),
                'ladder' => $this->renderLadder($ladder),
                'strategist-review' => $this->strategistReview($registry, $strategist),
                'review-cycle' => $this->output_($strategist->reviewCycle($this->option('analyze') ? true : null)),
                'comprehend' => $this->comprehend($registry, $comprehension),
                'comprehension-report' => $this->comprehensionReport($registry),
                'comprehension-findings' => $this->comprehensionFindings($registry),
                'assess' => $this->assess($registry, $assessment),
                'assessment-report' => $this->assessmentReport($registry),
                'questions' => $this->questions($registry),
                'focus' => $this->focus($registry),
                'data-readiness' => $this->dataReadiness($registry),
                'question-catalog' => $this->questionCatalog(),
                'research-handoff' => $this->researchHandoff($registry, $ladder, $research),
                'bridge-execution' => $this->bridgeExecution($registry, $bridge),
                'status' => $this->sectorStatus(),
                default => $this->failReport("Unknown action [{$action}]."),
            };
        } catch (VentureFoundryException $e) {
            return $this->failReport($e->getMessage());
        }
    }

    private function ideaRegister(VentureIdeationService $ideation): int
    {
        $idea = $ideation->register([
            'title' => $this->option('title'),
            'problem' => $this->option('problem'),
            'icp' => $this->option('icp'),
            'pain' => $this->option('pain'),
            'urgency' => $this->option('urgency'),
            'market_size_usd' => $this->option('market-size-usd'),
            'pain_severity' => $this->option('pain-severity'),
            'founder_fit' => $this->option('founder-fit'),
            'sovereignty_fit' => $this->option('sovereignty-fit'),
        ]);

        return $this->output_(['idea' => $idea->toArray()]);
    }

    private function ideaList(): int
    {
        $ideas = AiVentureIdea::query()
            ->orderByDesc('score')
            ->get(['idea_id', 'title', 'urgency', 'score', 'status', 'source', 'uuid'])
            ->toArray();

        return $this->output_(['count' => count($ideas), 'ideas' => $ideas]);
    }

    private function ideateFromRadar(VentureIdeationService $ideation): int
    {
        $created = $ideation->ideateFromRadar();

        return $this->output_([
            'created' => count($created),
            'ideas' => array_map(fn ($idea) => $idea->only(['idea_id', 'title', 'score', 'status']), $created),
        ]);
    }

    private function comprehend(VentureRegistryService $registry, \App\Services\Ai\VentureFoundry\Comprehension\VentureComprehensionService $comprehension): int
    {
        $venture = $registry->resolve($this->requireVentureOption());

        $only = [];
        if (is_string($this->option('only')) && $this->option('only') !== '') {
            $only = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('only')))));
        }

        $report = $comprehension->run($venture, array_filter([
            'only' => $only,
            'promote_rules' => (bool) $this->option('promote-rules'),
            'write_docs_to' => $this->option('write-docs') ?: null,
        ], fn ($v) => $v !== null && $v !== [] && $v !== false));

        return $this->output_($report);
    }

    private function comprehensionReport(VentureRegistryService $registry): int
    {
        $venture = $registry->resolve($this->requireVentureOption());
        $run = \App\Models\AiVentureComprehensionRun::query()
            ->where('venture_id', $venture->id)
            ->orderByDesc('created_at')
            ->first();

        if ($run === null) {
            return $this->failReport("Nenhuma comprehension run para a venture [{$venture->venture_id}]. Rode: atlas:venture comprehend --venture={$venture->venture_id}");
        }

        return $this->output_([
            'venture_id' => $venture->venture_id,
            'run_uuid' => $run->uuid,
            'status' => $run->status,
            'workspace_path' => $run->workspace_path,
            'repo_roots' => $run->repo_roots,
            'files_scanned' => $run->files_scanned,
            'findings_total' => $run->findings_total,
            'summary' => $run->summary,
            'completed_at' => $run->completed_at?->toIso8601String(),
        ]);
    }

    private function comprehensionFindings(VentureRegistryService $registry): int
    {
        $venture = $registry->resolve($this->requireVentureOption());
        $run = \App\Models\AiVentureComprehensionRun::query()
            ->where('venture_id', $venture->id)
            ->orderByDesc('created_at')
            ->first();

        if ($run === null) {
            return $this->failReport("Nenhuma comprehension run para a venture [{$venture->venture_id}].");
        }

        $query = \App\Models\AiVentureComprehensionFinding::query()->where('run_id', $run->id);
        if (is_string($this->option('capability')) && $this->option('capability') !== '') {
            $query->where('capability', $this->option('capability'));
        }
        if (is_string($this->option('severity')) && $this->option('severity') !== '') {
            $query->where('severity', $this->option('severity'));
        }

        $findings = $query
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
            ->orderByDesc('leverage_score')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['capability', 'kind', 'category', 'title', 'severity', 'leverage_score', 'confidence', 'evidence_path', 'evidence_line', 'recommendation']);

        return $this->output_([
            'venture_id' => $venture->venture_id,
            'run_uuid' => $run->uuid,
            'count' => $findings->count(),
            'findings' => $findings->toArray(),
        ]);
    }

    private function assess(VentureRegistryService $registry, \App\Services\Ai\VentureFoundry\Assessment\VentureAssessmentService $assessment): int
    {
        $venture = $registry->resolve($this->requireVentureOption());

        return $this->output_($assessment->run($venture));
    }

    private function assessmentReport(VentureRegistryService $registry): int
    {
        $venture = $registry->resolve($this->requireVentureOption());
        $run = \App\Models\AiVentureAssessmentRun::query()
            ->where('venture_id', $venture->id)
            ->orderByDesc('created_at')
            ->first();

        if ($run === null) {
            return $this->failReport("Nenhum assessment para a venture [{$venture->venture_id}]. Rode: atlas:venture assess --venture={$venture->venture_id}");
        }

        return $this->output_([
            'venture_id' => $venture->venture_id,
            'run_uuid' => $run->uuid,
            'stage' => $run->stage,
            'questions_total' => $run->questions_total,
            'data_readiness_pct' => $run->data_readiness_pct,
            'by_status' => $run->summary['by_status'] ?? [],
            'focus' => $run->focus,
            'data_gaps' => $run->data_gaps,
            'completed_at' => $run->completed_at?->toIso8601String(),
        ]);
    }

    private function questions(VentureRegistryService $registry): int
    {
        $venture = $registry->resolve($this->requireVentureOption());
        $run = \App\Models\AiVentureAssessmentRun::query()
            ->where('venture_id', $venture->id)
            ->orderByDesc('created_at')
            ->first();
        if ($run === null) {
            return $this->failReport("Nenhum assessment para [{$venture->venture_id}]. Rode: atlas:venture assess --venture={$venture->venture_id}");
        }

        $query = \App\Models\AiVentureQuestionAnswer::query()->where('run_id', $run->id);
        if (is_string($this->option('dimension-filter')) && $this->option('dimension-filter') !== '') {
            $query->where('dimension', $this->option('dimension-filter'));
        }
        if (is_string($this->option('status-filter')) && $this->option('status-filter') !== '') {
            $query->where('status', $this->option('status-filter'));
        }

        $answers = $query
            ->orderByDesc('priority_score')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['question_id', 'dimension', 'question_text', 'status', 'answer', 'confidence', 'data_kind', 'external_source', 'recommendation', 'priority_score']);

        return $this->output_([
            'venture_id' => $venture->venture_id,
            'run_uuid' => $run->uuid,
            'count' => $answers->count(),
            'answers' => $answers->toArray(),
        ]);
    }

    private function focus(VentureRegistryService $registry): int
    {
        $venture = $registry->resolve($this->requireVentureOption());
        $run = \App\Models\AiVentureAssessmentRun::query()
            ->where('venture_id', $venture->id)
            ->orderByDesc('created_at')
            ->first();
        if ($run === null) {
            return $this->failReport("Nenhum assessment para [{$venture->venture_id}]. Rode: atlas:venture assess --venture={$venture->venture_id}");
        }

        return $this->output_([
            'venture_id' => $venture->venture_id,
            'stage' => $run->stage,
            'focus' => $run->focus,
        ]);
    }

    private function dataReadiness(VentureRegistryService $registry): int
    {
        $venture = $registry->resolve($this->requireVentureOption());
        $run = \App\Models\AiVentureAssessmentRun::query()
            ->where('venture_id', $venture->id)
            ->orderByDesc('created_at')
            ->first();
        if ($run === null) {
            return $this->failReport("Nenhum assessment para [{$venture->venture_id}]. Rode: atlas:venture assess --venture={$venture->venture_id}");
        }

        return $this->output_([
            'venture_id' => $venture->venture_id,
            'data_readiness_pct' => $run->data_readiness_pct,
            'by_status' => $run->summary['by_status'] ?? [],
            'data_gaps' => $run->data_gaps,
            'note' => 'Cada fonte em data_gaps, ao ser conectada, torna N perguntas respondíveis — o caminho para autonomia.',
        ]);
    }

    private function questionCatalog(): int
    {
        $catalog = new \App\Services\Ai\VentureFoundry\Assessment\VentureQuestionCatalog;
        $all = $catalog->all();

        return $this->output_([
            'schema_version' => 'atlas.ai.venture.question_catalog.v1',
            'total_questions' => count($all),
            'dimensions' => \App\Services\Ai\VentureFoundry\Assessment\VentureQuestionCatalog::DIMENSION_LABELS,
            'external_sources' => \App\Services\Ai\VentureFoundry\Assessment\VentureQuestionCatalog::EXTERNAL_SOURCES,
            'questions' => array_map(fn ($q) => [
                'id' => $q['id'],
                'dimension' => $q['dimension'],
                'stages' => $q['stages'],
                'severity_if_blind' => $q['severity_if_blind'],
                'question' => $q['question'],
                'data_kind' => $q['data_kind'],
                'external_source' => $q['external_source'],
            ], $all),
        ]);
    }

    private function ideateGenerate(VentureIdeaGenerationService $generation): int
    {
        $brief = (string) $this->option('brief');
        $count = $this->option('count');

        $run = $generation->generate($brief, is_numeric($count) ? (int) $count : null);

        return $this->output_($run);
    }

    private function researchHandoff(VentureRegistryService $registry, VentureGrowthLadderService $ladder, VentureResearchHandoffService $research): int
    {
        $venture = $registry->resolve($this->requireVentureOption());
        $evaluation = $ladder->evaluate($venture);
        $questions = array_map('strval', (array) $this->option('question'));

        $handoff = $research->emitForVenture($venture, (array) $evaluation['gaps'], $questions);

        return $this->output_([
            'handoff_uuid' => $handoff->uuid,
            'source_domain' => $handoff->source_domain_id,
            'target_domain' => $handoff->target_domain_id,
            'handoff_status' => $handoff->status,
            'research_questions' => $handoff->context_pack['research_questions'] ?? [],
            'receipt_hash' => $handoff->receipt_hash,
        ]);
    }

    private function bridgeExecution(VentureRegistryService $registry, VentureExecutionBridgeService $bridge): int
    {
        $venture = $registry->resolve($this->requireVentureOption());

        $reviewReference = (string) $this->option('review');
        if ($reviewReference !== '') {
            $review = AiVentureStrategyReview::query()
                ->where('uuid', $reviewReference)
                ->where('venture_id', $venture->id)
                ->first();
            if ($review === null) {
                return $this->failReport("Review [{$reviewReference}] not found for venture [{$venture->venture_id}].");
            }

            return $this->output_($bridge->bridge($venture, $review));
        }

        return $this->output_($bridge->bridgeLatest($venture));
    }

    private function promote(VentureRegistryService $registry): int
    {
        $reference = (string) $this->option('idea');
        if ($reference === '') {
            return $this->failReport('Option --idea is required for promote.');
        }

        $idea = AiVentureIdea::query()
            ->where('uuid', $reference)
            ->orWhere('idea_id', $reference)
            ->first();
        if ($idea === null) {
            return $this->failReport("Idea [{$reference}] not found.");
        }

        $venture = $registry->promoteIdea($idea, array_filter([
            'name' => $this->option('name'),
            'thesis' => $this->option('thesis'),
            'target_arr_usd' => $this->option('target-arr-usd'),
        ], fn ($value) => $value !== null && $value !== ''));

        return $this->output_(['venture' => $venture->toArray()]);
    }

    private function ventureList(): int
    {
        $ventures = AiVenture::query()
            ->orderBy('created_at')
            ->get(['venture_id', 'name', 'status', 'stage', 'stage_key', 'target_arr_usd', 'uuid'])
            ->toArray();

        return $this->output_(['count' => count($ventures), 'ventures' => $ventures]);
    }

    private function ventureShow(VentureRegistryService $registry, VentureBusinessRuleService $rules, VentureGrowthLadderService $ladder): int
    {
        $venture = $registry->resolve($this->requireVentureOption());

        return $this->output_([
            'venture' => $venture->toArray(),
            'active_business_rules' => array_map(
                fn ($rule) => $rule->only(['rule_id', 'category', 'statement', 'version']),
                $rules->activeRules($venture),
            ),
            'latest_metrics' => $ladder->latestMetrics($venture),
        ]);
    }

    private function link(VentureRegistryService $registry): int
    {
        $venture = $registry->resolve($this->requireVentureOption());

        $northStar = null;
        if (is_string($this->option('north-star-metric')) && $this->option('north-star-metric') !== '') {
            $northStar = [
                'metric' => $this->option('north-star-metric'),
                'target' => $this->option('north-star-target'),
            ];
        }

        $venture = $registry->attachArtifacts($venture, [
            'opportunity_id' => $this->option('opportunity-id'),
            'venture_blueprint_id' => $this->option('blueprint-id'),
            'north_star' => $northStar,
        ]);

        return $this->output_(['venture' => $venture->toArray()]);
    }

    private function ruleAdd(VentureRegistryService $registry, VentureBusinessRuleService $rules): int
    {
        $venture = $registry->resolve($this->requireVentureOption());

        $rule = $rules->declare($venture, array_filter([
            'rule_id' => $this->option('rule-id'),
            'category' => $this->option('category'),
            'statement' => $this->option('statement'),
            'rationale' => $this->option('rationale'),
        ], fn ($value) => $value !== null && $value !== ''));

        return $this->output_(['rule' => $rule->toArray()]);
    }

    private function ruleList(VentureRegistryService $registry, VentureBusinessRuleService $rules): int
    {
        $venture = $registry->resolve($this->requireVentureOption());
        $active = $rules->activeRules($venture);

        return $this->output_([
            'venture_id' => $venture->venture_id,
            'count' => count($active),
            'rules' => array_map(fn ($rule) => $rule->only(['rule_id', 'category', 'statement', 'rationale', 'version', 'status']), $active),
        ]);
    }

    private function metricRecord(VentureRegistryService $registry, VentureGrowthLadderService $ladder): int
    {
        $venture = $registry->resolve($this->requireVentureOption());

        $observation = $ladder->recordMetric($venture, [
            'metric_key' => $this->option('metric'),
            'value' => $this->option('value'),
            'unit' => $this->option('unit'),
            'currency' => $this->option('currency'),
        ]);

        return $this->output_(['observation' => $observation->toArray()]);
    }

    private function renderLadder(VentureGrowthLadderService $ladder): int
    {
        return $this->output_(['ladder' => $ladder->ladder()]);
    }

    private function strategistReview(VentureRegistryService $registry, VentureStrategistService $strategist): int
    {
        $venture = $registry->resolve($this->requireVentureOption());
        $packet = $strategist->review($venture, (bool) $this->option('apply'), (bool) $this->option('analyze'));

        return $this->output_([
            'decision' => $packet['decision'],
            'current_stage' => $packet['venture']->stage,
            'recommended_stage' => $packet['evaluation']['recommended_stage'],
            'stage_applied' => $packet['stage_applied'],
            'observed_arr_usd' => $packet['evaluation']['observed_arr_usd'],
            'target_arr_usd' => $packet['evaluation']['target_arr_usd'],
            'gaps' => $packet['evaluation']['gaps'],
            'next_actions' => $packet['evaluation']['next_actions'],
            'playbook' => $packet['evaluation']['playbook'],
            'trajectory' => $packet['trajectory'],
            'analysis' => $packet['analysis'],
            'review_uuid' => $packet['review']->uuid,
            'strategy_memo_id' => $packet['strategy_memo_id'],
            'strategy_memo_error' => $packet['strategy_memo_error'],
        ]);
    }

    private function sectorStatus(): int
    {
        $ideasByStatus = AiVentureIdea::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->toArray();

        $venturesByStage = AiVenture::query()
            ->selectRaw('stage, count(*) as total')
            ->groupBy('stage')
            ->pluck('total', 'stage')
            ->toArray();

        return $this->output_([
            'sector' => 'venture_foundry',
            'ideas_by_status' => $ideasByStatus,
            'ventures_by_stage' => $venturesByStage,
            'reviews_recorded' => AiVentureStrategyReview::query()->count(),
        ]);
    }

    private function requireVentureOption(): string
    {
        $reference = (string) $this->option('venture');
        if ($reference === '') {
            throw VentureFoundryException::missingField('command', 'venture');
        }

        return $reference;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function output_(array $payload): int
    {
        $payload = array_merge(['schema_version' => 'atlas.ai.venture_foundry.report.v1', 'status' => 'ok'], $payload);
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function failReport(string $message): int
    {
        $this->line((string) json_encode([
            'schema_version' => 'atlas.ai.venture_foundry.report.v1',
            'status' => 'error',
            'message' => $message,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
