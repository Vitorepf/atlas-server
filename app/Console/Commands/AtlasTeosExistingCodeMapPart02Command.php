<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasTeosExistingCodeMapPart02Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the "Atlas TEOS Existing Code Map · Parte 2"
 * doc. With no args it self-describes the 10 anti-duplication rules, the fixed
 * 14-slice inventory, a worked blocked decision and an all-green quality-gate
 * pass — proving the anti-duplication contract is live. It never authorizes
 * runtime.
 *
 * @see docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-02.md
 */
class AtlasTeosExistingCodeMapPart02Command extends Command
{
    protected $signature = 'atlas:aaeos:teos-existing-code-map-part-02 {--json : Print machine-readable JSON}';

    protected $description = 'Describe the TEOS anti-duplication rules + slice inventory and run a worked blocked decision and quality-gate pass (read-only, never authorizes runtime).';

    public function handle(AtlasTeosExistingCodeMapPart02Service $service): int
    {
        try {
            $payload = $service->describe();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasTeosExistingCodeMapPart02Service::SCHEMA_VERSION,
            ];

            if ((bool) $this->option('json')) {
                $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        $inv = $payload['slice_inventory'];
        $blocked = $payload['sample_blocked'];
        $gates = $payload['sample_quality_gates_green'];

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('mode', (string) $payload['mode']);
        $this->components->twoColumnDetail('rule_count', (string) $payload['rule_count']);
        $this->components->twoColumnDetail('max_recovery_attempts', (string) $payload['max_recovery_attempts']);
        $this->components->twoColumnDetail('slices total/greenfield/extend/doc', sprintf('%d / %d / %d / %d', $inv['total'], $inv['greenfield'], $inv['extend'], $inv['doc_only']));
        $this->components->twoColumnDetail('sample_blocked.verdict', (string) $blocked['verdict']);
        $this->components->twoColumnDetail('sample_quality_gates.passed', $gates['passed'] ? 'true' : 'false');
        $this->components->twoColumnDetail('runtime_authorized', $payload['runtime_authorized'] ? 'true' : 'false');

        return self::SUCCESS;
    }
}
