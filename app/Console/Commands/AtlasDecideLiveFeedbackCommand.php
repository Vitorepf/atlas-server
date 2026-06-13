<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use Illuminate\Console\Command;

/**
 * Atlas Decide Live Feedback CLI.
 *
 * Doc canon: docs/engineering-knowledge-base/atlas-decide-live-outcome-feedback.md
 *
 * Actions:
 *   record       --task-category --role --provider [--framework] [--model] --result [--latency-ms] [--quality] [--cost-usd] [--tokens-used]
 *   stats        --task-category --role [--framework]
 *   signal       --task-category --role --provider [--framework] [--model]
 *   sweep        [--actor=autonomous_feedback_loop]  (calls ADML.autoDeactivateOnDegradation)
 *   list-outcomes
 */
class AtlasDecideLiveFeedbackCommand extends Command
{
    protected $signature = 'atlas:atlas-decide:live-feedback
        {--action=stats : record|stats|signal|sweep|list-outcomes}
        {--task-category= : task category (e.g., code_generation)}
        {--role= : role (e.g., primary)}
        {--framework= : framework (e.g., laravel)}
        {--provider= : provider key}
        {--model= : provider model}
        {--result= : success|failure|timeout}
        {--latency-ms= : optional integer}
        {--quality= : optional float 0..1}
        {--cost-usd= : optional measured or receipt-backed USD cost}
        {--tokens-used= : optional total token count}
        {--input-tokens= : optional input token count}
        {--output-tokens= : optional output token count}
        {--actor=autonomous_feedback_loop}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Decide Live Outcome Feedback — record provider call outcomes, query stats/signals, sweep degraded routes.';

    public function handle(AtlasDecideLiveOutcomeFeedbackService $svc, AtlasDecideMetaLearningService $adml): int
    {
        $action = (string) $this->option('action');
        $json = (bool) $this->option('json');

        switch ($action) {
            case 'record':
                try {
                    $entry = $svc->record([
                        'task_category' => (string) $this->option('task-category'),
                        'role' => (string) $this->option('role'),
                        'framework' => $this->option('framework') ?: null,
                        'provider' => (string) $this->option('provider'),
                        'model' => $this->option('model') ?: null,
                        'result' => (string) $this->option('result'),
                        'latency_ms' => $this->option('latency-ms') !== null ? (int) $this->option('latency-ms') : null,
                        'quality_score' => $this->option('quality') !== null ? (float) $this->option('quality') : null,
                        'cost_usd' => $this->option('cost-usd') !== null ? (float) $this->option('cost-usd') : null,
                        'tokens_used' => $this->option('tokens-used') !== null ? (int) $this->option('tokens-used') : null,
                        'input_tokens' => $this->option('input-tokens') !== null ? (int) $this->option('input-tokens') : null,
                        'output_tokens' => $this->option('output-tokens') !== null ? (int) $this->option('output-tokens') : null,
                        'actor' => (string) $this->option('actor'),
                    ]);

                    return $this->emit($entry, $json);
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

            case 'stats':
                $stats = $svc->routeStats(
                    (string) $this->option('task-category'),
                    (string) $this->option('role'),
                    $this->option('framework') ?: null,
                );

                return $this->emit($stats, $json);

            case 'signal':
                $sig = $svc->degradationSignal(
                    (string) $this->option('task-category'),
                    (string) $this->option('role'),
                    $this->option('framework') ?: null,
                    (string) $this->option('provider'),
                    $this->option('model') ?: null,
                );

                return $this->emit($sig, $json);

            case 'sweep':
                $env = $adml->autoDeactivateOnDegradation((string) $this->option('actor'));

                return $this->emit($env, $json);

            case 'list-outcomes':
                $list = $svc->listOutcomes();

                return $this->emit(['count' => count($list), 'outcomes' => $list], $json);

            default:
                $this->error("action '{$action}' desconhecida.");

                return self::FAILURE;
        }
    }

    private function emit(array $payload, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) || $v === null ? "{$k}: ".var_export($v, true) : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
