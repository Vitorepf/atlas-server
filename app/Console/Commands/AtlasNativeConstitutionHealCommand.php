<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasNativeConstitutionHealer;
use App\Services\Ai\SelfConstruction\AtlasNativeConstitutionScanner;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasNativeConstitutionHealCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:native:constitution-heal
        {--repo= : Path to atlas-native repo}
        {--finding-hash= : Finding hash from constitution-scan (sha1:…)}
        {--execute : Apply the mechanical heal (default is dry_run)}
        {--file-findings : Rewrite Autônomos backlog cache after heal}
        {--json : Emit the versioned JSON receipt}';

    protected $description = 'Mechanically heal atlas-native constitution findings (R2 dead symbol v1)';

    public function handle(AtlasNativeConstitutionHealer $healer, AtlasNativeConstitutionScanner $scanner): int
    {
        $repo = trim((string) ($this->option('repo') ?: base_path('../atlas-native')));
        $hash = trim((string) $this->option('finding-hash'));
        $execute = (bool) $this->option('execute');

        try {
            $receipt = $healer->heal($repo, $hash, $execute);

            if ($this->option('file-findings') && ($receipt['status'] ?? '') === 'healed') {
                $report = $scanner->scan($repo);
                $path = $scanner->writeFindingCache($report);
                $receipt['finding_cache_path'] = basename($path);
            }

            if ($this->option('json')) {
                $this->line($this->encode($receipt));
            } else {
                $this->info(($receipt['status'] ?? '?').' · '.($receipt['finding_hash'] ?? $hash));
                if (($receipt['reason'] ?? null) !== null) {
                    $this->warn((string) $receipt['reason']);
                }
            }

            return ($receipt['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $exception) {
            if ($this->option('json')) {
                $this->line((string) json_encode([
                    'schema_version' => AtlasNativeConstitutionHealer::SCHEMA_VERSION,
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
