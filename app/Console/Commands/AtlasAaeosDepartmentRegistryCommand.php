<?php

namespace App\Console\Commands;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasDepartmentRegistryService;
use Illuminate\Console\Command;

/**
 * Runtime surface for the AAEOS Department Contract registry. Without args it
 * prints the canonical departments and the mandatory schema fields; with
 * --registry it validates a department registry JSON (required fields, unique
 * ids, escalation cycles).
 *
 * @see docs/engineering-knowledge-base/atlas-agentic-engineering-os-department-contract.md
 */
class AtlasAaeosDepartmentRegistryCommand extends Command
{
    protected $signature = 'atlas:aeos:department-registry
        {--registry= : JSON list of department contracts to validate}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and validate the AAEOS department registry (atlas.aaeos.department.v1). [was atlas:aaeos:*; TRI-HYGIENE rename]';

    public function handle(AtlasDepartmentRegistryService $registry): int
    {
        $raw = $this->option('registry');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            $departments = is_array($decoded) ? (array_is_list($decoded) ? $decoded : [$decoded]) : [];
            $payload = $registry->validateRegistry($departments);

            return $this->emit($payload, (bool) ($payload['valid'] ?? false));
        }

        $payload = [
            'schema_version' => AtlasDepartmentRegistryService::SCHEMA,
            'canonical_departments' => AtlasDepartmentRegistryService::CANONICAL_DEPARTMENTS,
        ];

        return $this->emit($payload, true);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, bool $ok): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        foreach ($payload as $key => $value) {
            $this->components->twoColumnDetail((string) $key, is_scalar($value) || $value === null ? (string) $value : json_encode($value));
        }
        if (($payload['valid'] ?? null) === false) {
            $this->warn('Registry invalid: '.json_encode([
                'duplicate_ids' => $payload['duplicate_ids'] ?? [],
                'escalation_cycles' => $payload['escalation_cycles'] ?? [],
            ]));
        }

        return self::SUCCESS;
    }
}
