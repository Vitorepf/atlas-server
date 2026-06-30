<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderPerformanceLedger;
use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderRecommendationEngine;
use App\Services\Ai\SelfConstruction\Maestro\ProviderLearning\AtlasMaestroProviderRecommendationReceiptLedger;
use Illuminate\Console\Command;

/**
 * Operator/loop surface for the Maestro provider-learning triad.
 *
 *   atlas:task:maestro:provider-perf inspect    — print facts (optionally filtered) as JSON or table.
 *   atlas:task:maestro:provider-perf recommend  — call the recommendation engine and write a receipt.
 *
 * STRICTLY ADVISORY. NEVER mutates routing. NEVER calls a provider. NEVER touches the provider
 * manager, the loop router, or any merge-application surface.
 */
final class AtlasTaskMaestroProviderPerfCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:task:maestro:provider-perf {action : inspect|recommend}
        {--class= : task_class filter (inspect/recommend)}
        {--provider= : provider filter (inspect)}
        {--actor=cli : actor string recorded as requested_by (recommend)}
        {--json : emit machine-readable JSON}';

    protected $description = 'Maestro provider-learning CLI: inspect facts | recommend (advisory only).';

    public function handle(
        AtlasMaestroProviderPerformanceLedger $ledger,
        AtlasMaestroProviderRecommendationEngine $engine,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'inspect'   => $this->inspect($ledger),
            // ponytail: lazy-resolve receipts only when recommend runs — inspect must stay receipt-free
            'recommend' => $this->recommend($engine, app(AtlasMaestroProviderRecommendationReceiptLedger::class)),
            default     => $this->failWith('unknown_action:'.$action.' (expected one of inspect|recommend)'),
        };
    }

    private function inspect(AtlasMaestroProviderPerformanceLedger $ledger): int
    {
        $class = (string) ($this->option('class') ?? '');
        $providerFilter = (string) ($this->option('provider') ?? '');

        if ($class !== '') {
            $facts = $ledger->factsForClass($class);
            $providers = [];
            foreach ($facts as $provider => $row) {
                if ($providerFilter !== '' && (string) $provider !== $providerFilter) {
                    continue;
                }
                $successCount = (int) ($row['success_count'] ?? 0);
                $giveBackCount = (int) ($row['give_back_count'] ?? 0);
                $total = $successCount + $giveBackCount;
                $providers[] = [
                    'provider' => (string) $provider,
                    'success_count' => $successCount,
                    'give_back_count' => $giveBackCount,
                    'success_rate' => $total > 0 ? $successCount / $total : 0.0,
                    'avg_duration_ms' => (int) ($row['avg_duration_ms'] ?? 0),
                ];
            }
            $this->emit([
                'task_class' => $class,
                'providers' => $providers,
            ]);

            return self::EXIT_OK;
        }

        $all = $ledger->allFacts();
        if ($providerFilter !== '') {
            $filtered = [];
            foreach ($all as $taskClass => $rows) {
                foreach ($rows as $provider => $row) {
                    if ((string) $provider === $providerFilter) {
                        $filtered[(string) $taskClass][(string) $provider] = $row;
                    }
                }
            }
            $all = $filtered;
        }
        $this->emit(['all_facts' => $all]);

        return self::EXIT_OK;
    }

    private function recommend(
        AtlasMaestroProviderRecommendationEngine $engine,
        AtlasMaestroProviderRecommendationReceiptLedger $receipts,
    ): int {
        $class = (string) ($this->option('class') ?? '');
        if ($class === '') {
            return $this->failWith('class_option_missing');
        }
        $actor = (string) ($this->option('actor') ?? 'cli');

        $verdict = $engine->bestProviderFor($class);

        if ((string) ($verdict['status'] ?? '') === 'insufficient_data') {
            $this->emit(['status' => 'insufficient_data', 'task_class' => $class, 'samples_seen' => $verdict['samples_seen'] ?? []]);

            return self::EXIT_OK;
        }

        $receipts->record([
            'task_class' => $class,
            'recommended_provider' => (string) $verdict['provider'],
            'sample_size' => (int) ($verdict['sample_size'] ?? 0),
            'success_rate' => (float) ($verdict['success_rate'] ?? 0),
            'give_back_rate' => (float) ($verdict['give_back_rate'] ?? 0),
            'tied_with' => array_values((array) ($verdict['tied_with'] ?? [])),
            'tie_reason' => (string) ($verdict['tie_reason'] ?? ''),
            'requested_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'requested_by' => $actor,
        ]);

        $this->emit([
            'status' => 'ok',
            'task_class' => $class,
            'recommended_provider' => $verdict['provider'],
            'tied_with' => $verdict['tied_with'] ?? [],
            'tie_reason' => $verdict['tie_reason'] ?? '',
        ]);

        return self::EXIT_OK;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            foreach ($payload as $k => $v) {
                $this->line($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
            }
        }
    }

    private function failWith(string $reason): int
    {
        $this->line($reason);

        return self::EXIT_USAGE;
    }
}
