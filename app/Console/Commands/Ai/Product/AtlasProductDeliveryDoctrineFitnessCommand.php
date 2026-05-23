<?php

declare(strict_types=1);

namespace App\Console\Commands\Ai\Product;

use App\Services\Ai\Product\AtlasProductDeliveryDoctrineFitnessService;
use Illuminate\Console\Command;

class AtlasProductDeliveryDoctrineFitnessCommand extends Command
{
    protected $signature = 'atlas:product-delivery:doctrine-fitness
        {--limit=100 : Maximum outcome memories to inspect}
        {--json : Emit JSON}
        {--strict : Exit non-zero only when status is neither ready nor watch}';

    protected $description = 'Evaluates AEDPDS doctrine fitness from persisted outcome memory without providers or writes.';

    public function handle(AtlasProductDeliveryDoctrineFitnessService $service): int
    {
        $payload = $service->evaluate([
            'limit' => (int) $this->option('limit'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Doctrine Fitness', (string) $payload['schema_version']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('sample_size', (string) $payload['sample_size']);
            $this->components->twoColumnDetail('hash', (string) $payload['fitness_hash']);
        }

        return (bool) $this->option('strict')
            && ! in_array($payload['status'] ?? null, ['ready', 'watch'], true)
                ? self::FAILURE
                : self::SUCCESS;
    }
}
