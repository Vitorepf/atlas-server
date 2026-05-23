<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasProductDeliveryCertificationService;
use Illuminate\Console\Command;

class AtlasProductDeliveryCertifyCommand extends Command
{
    protected $signature = 'atlas:product-delivery:certify
        {--json : Print JSON}
        {--strict : Exit non-zero unless status === ready}';

    protected $description = 'Certifies AEDPDS/APTC/APDR/APFPR product delivery runtime without invoking providers.';

    public function handle(AtlasProductDeliveryCertificationService $service): int
    {
        $report = $service->certify();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('AEDPDS Product Delivery Certification', (string) $report['schema_version']);
            $this->components->twoColumnDetail('status', (string) $report['status']);
            $this->components->twoColumnDetail('checks', sprintf(
                'passed=%d · failed=%d',
                (int) data_get($report, 'summary.passed', 0),
                (int) data_get($report, 'summary.failed', 0),
            ));
            $this->components->twoColumnDetail('certification_hash', (string) $report['certification_hash']);
        }

        return (bool) $this->option('strict') && ($report['status'] ?? null) !== 'ready'
            ? self::FAILURE
            : self::SUCCESS;
    }
}
