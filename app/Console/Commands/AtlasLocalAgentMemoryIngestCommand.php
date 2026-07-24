<?php

namespace App\Console\Commands;

use App\Services\Ai\Memory\LocalAgentIngestion\LocalAgentMemoryIngestionService;
use Illuminate\Console\Command;
use App\Support\YesNo;

class AtlasLocalAgentMemoryIngestCommand extends Command
{
    protected $signature = 'atlas:local-agent:ingest
        {--root=* : Limit to these alias(es). Defaults to all configured enabled roots.}
        {--dry-run : Force dry-run (no DB writes). Default comes from config.}
        {--no-dry-run : Force a real run (overrides config default).}
        {--actor= : Optional actor alias recorded in the run for audit.}
        {--json : Emit the receipt as JSON only.}';

    protected $description = 'Run the Atlas local agent memory ingestion pipeline against configured roots.';

    public function handle(LocalAgentMemoryIngestionService $service): int
    {
        if ($this->option('dry-run') && $this->option('no-dry-run')) {
            $this->error('Refusing to run: --dry-run and --no-dry-run are mutually exclusive.');

            return self::FAILURE;
        }

        $configDefault = (bool) config('atlas_local_agent_ingestion.dry_run_default', true);
        $dryRun = $configDefault;
        if ($this->option('dry-run')) {
            $dryRun = true;
        } elseif ($this->option('no-dry-run')) {
            $dryRun = false;
        }

        $rootAliases = array_values(array_filter(
            (array) $this->option('root'),
            static fn ($r): bool => is_string($r) && trim($r) !== '',
        ));
        $actor = trim((string) ($this->option('actor') ?? ''));
        if ($actor === '') {
            $actor = 'cli:atlas:local-agent:ingest';
        }

        $result = $service->run([
            'dry_run' => $dryRun,
            'roots' => $rootAliases === [] ? null : $rootAliases,
            'actor_alias' => $actor,
        ]);

        if ($this->option('json')) {
            $this->line(json_encode($result['receipt'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}');

            return self::SUCCESS;
        }

        $r = $result['receipt'];
        $this->info(sprintf('Run %s — status=%s dry_run=%s', $r['run_uuid'], $r['status'], YesNo::trueFalse($r['dry_run'])));
        if ($r['no_roots_configured'] ?? false) {
            $this->warn('No roots configured. Set config/atlas_local_agent_ingestion.php#roots before running.');

            return self::SUCCESS;
        }
        $this->line(sprintf(
            'Discovered=%d ingested=%d skipped=%d duplicate=%d quarantined=%d secret_findings=%d candidates=%d',
            $r['discovered_count'], $r['ingested_count'], $r['skipped_count'], $r['duplicate_count'],
            $r['quarantined_count'], $r['secret_finding_total'], $r['candidate_count'],
        ));
        $this->line('receipt_hash='.$r['receipt_hash']);

        return self::SUCCESS;
    }
}
