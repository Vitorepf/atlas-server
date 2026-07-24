<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasNativeConstitutionScanner;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasNativeConstitutionScanCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:native:constitution-scan
        {--repo= : Path to atlas-native repo}
        {--json : Emit the versioned JSON report}
        {--file-findings : Write findings into the Autônomos backlog cache seam}
        {--findings-path= : Override finding cache output path for tests/probes}';

    protected $description = 'Scan atlas-native constitution rules R1-R5';

    public function handle(AtlasNativeConstitutionScanner $scanner): int
    {
        $repo = trim((string) ($this->option('repo') ?: base_path('../atlas-native')));

        try {
            $report = $scanner->scan($repo);
            if ($this->option('file-findings')) {
                $path = $scanner->writeFindingCache(
                    $report,
                    $this->option('findings-path') ? (string) $this->option('findings-path') : null,
                );
                $report['finding_cache_path'] = basename($path);
            }

            if ($this->option('json')) {
                $this->line($this->encode($report));
            } else {
                $this->info(sprintf('%d native constitution finding(s)', (int) $report['finding_count']));
                foreach ((array) $report['findings'] as $finding) {
                    if (is_array($finding)) {
                        $this->line(($finding['rule_id'] ?? '?').' · '.($finding['target'] ?? ''));
                    }
                }
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line((string) json_encode([
                    'schema_version' => AtlasNativeConstitutionScanner::SCHEMA_VERSION,
                    'status' => 'failed',
                    'reason' => $exception->getMessage(),
                    'provider_safe' => true,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }
}
