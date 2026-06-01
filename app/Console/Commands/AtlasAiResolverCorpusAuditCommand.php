<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiResolverCorpusAuditService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Resolver Corpus Audit command — runs the documented classification/disposition
 * contract over a sample corpus batch and emits the audit receipt. Exercises the
 * three invariants (not-lost, classified-before-canonical, no-KB-competition) and
 * the promote-only-missing anti-pattern.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-resolver-corpus-audit.md
 */
class AtlasAiResolverCorpusAuditCommand extends Command
{
    protected $signature = 'atlas:aaeos:resolver-corpus-audit {--json : Print machine-readable JSON}';

    protected $description = 'Audit resolver corpus items against the documented classification + invariants and emit dispositions.';

    public function handle(AtlasAiResolverCorpusAuditService $service): int
    {
        try {
            // Safe defaults: a representative corpus batch covering each documented
            // level and the two anti-pattern violations.
            $report = $service->auditCorpus([
                ['id' => 'p0-mother-arch', 'level' => 'P0', 'source_link_preserved' => true],
                ['id' => 'p0-already-owned', 'level' => 'P0', 'source_link_preserved' => true, 'duplicates_owner_decision' => true],
                ['id' => 'p1-roadmap', 'level' => 'P1', 'source_link_preserved' => true],
                ['id' => 'p2-future-idea', 'level' => 'P2', 'source_link_preserved' => true],
                ['id' => 'history-only', 'level' => 'Archive', 'source_link_preserved' => true],
                ['id' => 'unclassified-blob', 'level' => ''],
            ]);

            $payload = $report + ['generated_at' => now()->toJSON()];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

                return self::SUCCESS;
            }

            $summary = (array) ($report['summary'] ?? []);
            $byDisposition = (array) ($summary['by_disposition'] ?? []);
            $this->components->twoColumnDetail('evaluated', (string) ($summary['evaluated'] ?? 0));
            $this->components->twoColumnDetail('promote', (string) ($byDisposition['promote'] ?? 0));
            $this->components->twoColumnDetail('archive', (string) ($byDisposition['archive'] ?? 0));
            $this->components->twoColumnDetail('reject', (string) ($byDisposition['reject'] ?? 0));
            $this->components->twoColumnDetail('lost-value warnings', (string) ($summary['lost_value_warnings'] ?? 0));

            $this->table(
                ['item', 'level', 'disposition', 'violations'],
                collect((array) ($report['items'] ?? []))->map(fn (array $row): array => [
                    (string) ($row['id'] ?? ''),
                    (string) ($row['level'] ?? '-'),
                    (string) ($row['disposition'] ?? ''),
                    count((array) ($row['violations'] ?? [])) > 0 ? implode('; ', (array) $row['violations']) : '-',
                ])->all(),
            );

            if (($summary['clean'] ?? false) === true) {
                $this->info('Corpus clean: nothing important lost and no invariant broken.');
            } else {
                $this->warn('Corpus has lost-value warnings or invariant violations — see rows above.');
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema_version' => AtlasAiResolverCorpusAuditService::SCHEMA,
                'ok' => false,
                'error' => $e->getMessage(),
            ];
            $this->line(json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}');

            return self::FAILURE;
        }
    }
}
