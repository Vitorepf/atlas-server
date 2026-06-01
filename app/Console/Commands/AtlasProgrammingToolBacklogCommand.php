<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingToolBacklogService;
use Illuminate\Console\Command;

/**
 * Runtime surface for the Programming Tool Backlog doc. Without args it prints the
 * governance snapshot (the four priority sections P0-P3, the governed promotion
 * channels, the P1 normalizer required fields, and worked examples). With
 * --promote it classifies one promotion channel against the "never by ad hoc
 * command execution" rule; with --normalizer it evaluates a comma-separated set of
 * present normalizer fields against the P1 contract.
 *
 * @see docs/engineering-knowledge-base/tool-runtime/programming-tool-backlog.md
 */
final class AtlasProgrammingToolBacklogCommand extends Command
{
    protected $signature = 'atlas:aaeos:programming-tool-backlog
        {--promote= : Classify one promotion channel (recipe|normalizer|gate|ux|ad_hoc_command)}
        {--normalizer= : Comma-separated present normalizer fields to evaluate against the P1 contract}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and evaluate the Programming Tool Backlog (priorities P0-P3, governed promotion channels, P1 normalizer contract, P2 authority gate) runtime.';

    public function handle(AtlasProgrammingToolBacklogService $service): int
    {
        $json = (bool) $this->option('json');

        try {
            $promote = $this->option('promote');
            if (is_string($promote) && trim($promote) !== '') {
                return $this->emit($service->classifyPromotion($promote), $json);
            }

            $normalizer = $this->option('normalizer');
            if (is_string($normalizer) && trim($normalizer) !== '') {
                $fields = [];
                foreach (explode(',', $normalizer) as $field) {
                    $key = trim($field);
                    if ($key !== '') {
                        $fields[$key] = true;
                    }
                }

                return $this->emit($service->evaluateNormalizer($fields), $json);
            }

            return $this->emit($service->snapshot(), $json);
        } catch (\Throwable $e) {
            $this->emit([
                'schema_version' => AtlasProgrammingToolBacklogService::SCHEMA_VERSION,
                'error' => true,
                'message' => $e->getMessage(),
            ], $json);

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, bool $json): int
    {
        $this->line((string) json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ));

        return self::SUCCESS;
    }
}
