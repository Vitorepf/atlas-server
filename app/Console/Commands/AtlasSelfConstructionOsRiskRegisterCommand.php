<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionOsRiskRegisterService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only runtime surface for the Atlas Self-Construction OS Risk Register v1
 * doc. With no args it renders the register snapshot: all 15 risks with their
 * frozen severity / likelihood / status, the still-open risks the doc flags as
 * unmitigated runtime, and the rule that a risk may only become
 * mitigated_by_runtime with a green gate + replay diff + signed receipt.
 *
 * @see docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-risk-register-v1.md
 */
class AtlasSelfConstructionOsRiskRegisterCommand extends Command
{
    protected $signature = 'atlas:aaeos:self-construction-os-risk-register {--json : Print machine-readable JSON}';

    protected $description = 'Render the read-only Self-Construction OS risk register (15 risks, runtime-mitigation gate, canonical runtime_safety alarm).';

    public function handle(AtlasSelfConstructionOsRiskRegisterService $service): int
    {
        try {
            // Safe default: no runtime-mitigation evidence supplied, so every risk
            // holds its declared status exactly as the doc states.
            $payload = $service->snapshot();
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => $e->getMessage(),
                'schema_version' => AtlasSelfConstructionOsRiskRegisterService::SCHEMA_VERSION,
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

        $this->components->twoColumnDetail('schema_version', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('position_date', (string) $payload['position_date']);
        $this->components->twoColumnDetail('risk_count', (string) $payload['risk_count']);
        $this->components->twoColumnDetail('open_count', (string) $payload['open_count']);
        $this->components->twoColumnDetail('open_risks', implode(', ', $payload['open_risks']));
        $this->components->twoColumnDetail('all_risks_have_signal', $payload['all_risks_have_signal'] ? 'true' : 'false');
        $this->components->twoColumnDetail('critical', (string) $payload['severity_breakdown']['critical']);
        $this->components->twoColumnDetail('high', (string) $payload['severity_breakdown']['high']);

        return self::SUCCESS;
    }
}
