<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSddTemplatesAndSchemasService;
use Illuminate\Console\Command;

/**
 * Runtime surface for the Atlas SDD Templates And Schemas doc.
 * Without args it prints the canonical snapshot (spec schema + SDD policy).
 * With --spec it validates an Operational Spec against the schema; with
 * --signals it applies the SDD policy (clarification / design-system / evidence)
 * to a request.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/templates-and-schemas.md
 */
final class AtlasSddTemplatesAndSchemasCommand extends Command
{
    protected $signature = 'atlas:aaeos:sdd-templates-and-schemas
        {--spec= : JSON Operational Spec to validate against the documented schema}
        {--signals= : JSON map of request signal flags for the SDD policy gate}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and enforce the Atlas SDD templates and schemas (operational spec schema + SDD policy) runtime.';

    public function handle(AtlasSddTemplatesAndSchemasService $service): int
    {
        $json = (bool) $this->option('json');

        try {
            $spec = $this->option('spec');
            if (is_string($spec) && trim($spec) !== '') {
                $decoded = json_decode($spec, true);
                $map = is_array($decoded) ? $decoded : [];

                return $this->emit($service->validateSpec($map), $json);
            }

            $signals = $this->option('signals');
            if (is_string($signals) && trim($signals) !== '') {
                $decoded = json_decode($signals, true);
                $map = is_array($decoded) ? $decoded : [];

                return $this->emit($service->evaluateSddPolicy($map), $json);
            }

            return $this->emit($service->policySnapshot(), $json);
        } catch (\Throwable $e) {
            $this->emit([
                'schema_version' => AtlasSddTemplatesAndSchemasService::SCHEMA_VERSION,
                'error' => true,
                'message' => $e->getMessage(),
            ], $json);

            return self::FAILURE;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
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
