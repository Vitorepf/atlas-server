<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AtlasCode\AtlasCodeViolationService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasCodeScanCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:code:scan {repo} {--json : Emit the versioned JSON projection}';

    protected $description = 'Scan Atlas Código rules from a registered repository';

    public function handle(AtlasCodeViolationService $violations): int
    {
        try {
            $result = $violations->capture((string) $this->argument('repo'));
            if ($this->option('json')) {
                $this->line($this->encode($result));
            } else {
                $this->info(sprintf('%d violation(s)', count((array) $result['violations'])));
                foreach ((array) $result['violations'] as $violation) {
                    $this->line($violation['rule_id'].' · '.$violation['target']);
                }
            }
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
