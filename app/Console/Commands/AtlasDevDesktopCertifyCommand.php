<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevDesktopCertificationService;
use Illuminate\Console\Command;
use App\Support\YesNo;

final class AtlasDevDesktopCertifyCommand extends Command
{
    protected $signature = 'atlas:dev:desktop:certify
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when certification is not passed}';

    protected $description = 'Certify Atlas Dev Desktop operational readiness without model/provider calls.';

    public function handle(AtlasDevDesktopCertificationService $certification): int
    {
        $report = $certification->certify();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Atlas Dev Desktop', (string) $report['schema_version']);
            $this->components->twoColumnDetail('Status', (string) $report['status']);
            $this->components->twoColumnDetail('External provider call', YesNo::format($report['external_provider_call']));

            foreach ((array) ($report['stages'] ?? []) as $stage) {
                if (! is_array($stage)) {
                    continue;
                }
                $this->components->twoColumnDetail(
                    '· '.(string) ($stage['name'] ?? 'stage'),
                    (string) ($stage['status'] ?? 'unknown'),
                );
            }
        }

        if ((bool) $this->option('strict') && ($report['status'] ?? null) !== 'passed') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
