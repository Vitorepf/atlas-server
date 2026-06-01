<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasP0PromotionsService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Resolver Corpus P0 Promotions command — runs the documented source-theme ->
 * owner-doc routing and the Promotion Rule over a representative candidate batch,
 * emitting the promotion receipt (patch / skip / reject per candidate).
 *
 * @see docs/engineering-knowledge-base/resolver-corpus/p0-promotions.md
 */
class AtlasP0PromotionsCommand extends Command
{
    protected $signature = 'atlas:aaeos:p0-promotions {--json : Print machine-readable JSON}';

    protected $description = 'Route P0 resolver themes to their owner docs and apply the Promotion Rule (patch only missing stable decisions).';

    public function handle(AtlasP0PromotionsService $service): int
    {
        try {
            // Safe defaults: one candidate per documented theme plus the two
            // Promotion-Rule violations (already-present -> skip, wholesale -> reject).
            $report = $service->promoteBatch([
                ['source_theme' => 'Atlas Decide Final Architecture', 'decision' => 'Decide compiles into a receipt.', 'stable_decision' => true],
                ['source_theme' => 'Super Tool Runtime Core', 'decision' => 'Tool Runtime is shared Core.', 'stable_decision' => true],
                ['source_theme' => 'Atlas Programming Product Architecture', 'decision' => 'Programming is a domain with flows.', 'stable_decision' => true],
                ['source_theme' => 'Domain Profile Orchestration', 'decision' => 'Domain != flow.', 'stable_decision' => true, 'already_in_destination' => true],
                ['source_theme' => 'Gaps/ideas source', 'decision' => 'Future backlog.', 'stable_decision' => true],
                ['source_theme' => 'Atlas Decide Final Architecture', 'decision' => 'Reopen resolver to add a new path.', 'stable_decision' => true, 'copied_wholesale' => true],
            ]);

            $payload = $report + ['generated_at' => now()->toJSON()];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $summary = (array) ($report['summary'] ?? []);
            $byAction = (array) ($summary['by_action'] ?? []);
            $this->components->twoColumnDetail('evaluated', (string) ($summary['evaluated'] ?? 0));
            $this->components->twoColumnDetail('patch', (string) ($byAction['patch'] ?? 0));
            $this->components->twoColumnDetail('skip', (string) ($byAction['skip'] ?? 0));
            $this->components->twoColumnDetail('reject', (string) ($byAction['reject'] ?? 0));

            $this->table(
                ['source theme', 'destination', 'action', 'reasons'],
                collect((array) ($report['items'] ?? []))->map(fn (array $row): array => [
                    (string) ($row['source_theme'] ?? ''),
                    (string) ($row['destination'] ?? '-'),
                    (string) ($row['action'] ?? ''),
                    count((array) ($row['reasons'] ?? [])) > 0 ? implode('; ', (array) $row['reasons']) : '-',
                ])->all(),
            );

            if (($summary['clean'] ?? false) === true) {
                $this->info('All candidates rule-compliant: patched missing stable decisions, skipped present ones.');
            } else {
                $this->warn('Some candidates were rejected by the Promotion Rule — see rows above.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema_version' => AtlasP0PromotionsService::SCHEMA,
                'ok' => false,
                'error' => $e->getMessage(),
            ];
            $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}');

            return self::FAILURE;
        }
    }
}
