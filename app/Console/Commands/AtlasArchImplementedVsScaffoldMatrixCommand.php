<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasArchImplementedVsScaffoldMatrixService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Surfaces the Implemented vs Scaffold Matrix decider over one snapshot sample.
 *
 * @see docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md
 */
class AtlasArchImplementedVsScaffoldMatrixCommand extends Command
{
    protected $signature = 'atlas:aaeos:implemented-vs-scaffold-matrix {--json : Emit canonical JSON}';

    protected $description = 'Classify blocks against the Implemented vs Scaffold Matrix status vocabulary and report which are product-ready vs not sellable as final.';

    public function handle(AtlasArchImplementedVsScaffoldMatrixService $service): int
    {
        try {
            // Safe default models the doc's canonical trap: a scaffold block an
            // agent might mistake for a finished product.
            $payload = $service->snapshot();

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode(
                    $payload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                ));

                return self::SUCCESS;
            }

            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Implemented vs Scaffold</>', 'read-only snapshot');
            $this->components->twoColumnDetail('Total blocks', (string) $payload['total']);
            $this->components->twoColumnDetail('Product ready', (string) $payload['product_ready']);
            $this->components->twoColumnDetail('Not sellable as product', (string) $payload['not_sellable_as_product']);

            foreach ((array) $payload['blocks'] as $row) {
                $label = (string) $row['block'].' ['.(string) $row['status'].']';
                $this->components->twoColumnDetail($label, $row['product_ready'] ? 'product_ready' : implode(',', (array) $row['reasons']));
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $envelope = [
                'schema_version' => AtlasArchImplementedVsScaffoldMatrixService::SCHEMA_VERSION,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->error('[implemented-vs-scaffold-matrix] '.$e->getMessage());
            }

            return self::FAILURE;
        }
    }
}
