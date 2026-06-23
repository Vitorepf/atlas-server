<?php

namespace App\Console\Commands;

use App\Services\Ai\MarketingDomain\CampaignPlanService;
use App\Services\Ai\MarketingDomain\CopyBriefService;
use App\Services\Ai\MarketingDomain\CreativeBriefService;
use App\Services\Ai\MarketingDomain\FunnelPlanService;
use App\Services\Ai\MarketingDomain\GrowthExperimentPlanService;
use App\Services\Ai\MarketingDomain\ICPPositioningService;
use App\Services\Ai\MarketingDomain\MarketingAnalyticsPlanService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Emit any skill blueprint — the 28-skill depth made queryable. Each skill returns the deterministic
 * professional skeleton (the playbook knowledge) the agent/operator fills in. No LLM, no side effects.
 */
class AtlasAiMarketingBriefCommand extends Command
{
    protected $signature = 'atlas:ai:marketing:brief
        {skill : copy|email|creative|funnel|icp|growth|analytics|campaign}
        {--awareness= : unaware|problem_aware|solution_aware|product_aware|most_aware (copy/icp)}
        {--channel=google_search : google_search|youtube (campaign) ; cold|warm (funnel)}
        {--json : saída JSON}';

    protected $description = 'Atlas Marketing: emite o blueprint determinístico de uma skill (copy/funnel/icp/campaign/…).';

    public function handle(
        CopyBriefService $copy,
        CreativeBriefService $creative,
        FunnelPlanService $funnel,
        ICPPositioningService $icp,
        GrowthExperimentPlanService $growth,
        MarketingAnalyticsPlanService $analytics,
        CampaignPlanService $campaign,
    ): int {
        try {
            $skill = strtolower(trim((string) $this->argument('skill')));
            $awareness = $this->strOpt('awareness');
            $channel = (string) ($this->option('channel') ?: 'google_search');

            $blueprint = match ($skill) {
                'copy' => $copy->blueprint($awareness),
                'email' => $copy->emailArcBlueprint(),
                'creative' => $creative->blueprint(),
                'funnel' => $funnel->blueprint($channel === 'warm' ? 'warm' : 'cold'),
                'icp' => $icp->blueprint($awareness),
                'growth' => $growth->blueprint(),
                'analytics' => $analytics->blueprint(),
                'campaign' => $campaign->blueprint($channel),
                default => null,
            };

            if ($blueprint === null) {
                return $this->respondError("skill desconhecida [{$skill}] — use copy|email|creative|funnel|icp|growth|analytics|campaign");
            }

            if ((bool) $this->option('json')) {
                $this->line(json_encode(['ok' => true, 'skill' => $skill, 'blueprint' => $blueprint], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('skill', (string) ($blueprint['skill'] ?? $skill));
            foreach ($blueprint as $k => $v) {
                if ($k === 'skill') {
                    continue;
                }
                $this->components->twoColumnDetail($k, is_scalar($v) ? (string) $v : '['.count((array) $v).' itens] — --json p/ detalhe');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            return $this->respondError($e->getMessage(), $e::class);
        }
    }

    private function strOpt(string $name): ?string
    {
        $v = trim((string) $this->option($name));

        return $v === '' ? null : $v;
    }

    private function respondError(string $message, ?string $type = null): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode(array_filter(['ok' => false, 'error' => $message, 'type' => $type])) ?: '{}');
        } else {
            $this->components->error($message);
        }

        return self::FAILURE;
    }
}
