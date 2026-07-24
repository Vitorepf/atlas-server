<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Loop24hCertificationHarnessService;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * AP-792 · read-only 24h loop certification harness CLI.
 *
 * Read-only by default; uses temp dirs / sandbox fixtures only. `--use-real-services`
 * adds live read-only probes + inspects real recorded evidence. It never runs the
 * 24h loop and never merges. test_mode never certifies production.
 */
final class AtlasCertify24hLoopCommand extends Command
{
    protected $signature = 'atlas:software-company-stewardship:certify-24h-loop
        {--area=agentic_engineering_os : Canonical area_id}
        {--scenario= : Certify a single scenario by key}
        {--use-real-services : Inspect real recorded evidence + live read-only probes (runtime_real mode)}
        {--strict : Exit non-zero on partial or blocked}
        {--json : Emit JSON}';

    protected $description = 'AP-792 · read-only certification harness for the 24h autonomous loop. test_mode (fixtures) never certifies production; runtime_real certifies only with real Obra/Decision Receipt/topology/AWIS/Evidence.';

    public function handle(Loop24hCertificationHarnessService $harness): int
    {
        $payload = $harness->certify([
            'area_id' => (string) $this->option('area'),
            'scenario' => (string) ($this->option('scenario') ?: ''),
            'use_real_services' => (bool) $this->option('use-real-services'),
        ]);

        $status = (string) ($payload['status'] ?? '');
        $strictFail = (bool) $this->option('strict')
            && in_array($status, [Loop24hCertificationHarnessService::STATUS_PARTIAL, Loop24hCertificationHarnessService::STATUS_BLOCKED], true);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($status, $strictFail);
        }

        $this->components->twoColumnDetail('AP-792 24h loop cert', $status);
        $this->components->twoColumnDetail('Certification mode', (string) ($payload['certification_mode'] ?? ''));
        $this->components->twoColumnDetail('Production certified', YesNo::format($payload['production_certified'] ?? false));
        $this->components->twoColumnDetail('Missing capabilities', implode(', ', (array) ($payload['missing_capabilities'] ?? [])) ?: '—');
        $this->components->twoColumnDetail('Missing real authority', implode(', ', (array) ($payload['missing_real_authority'] ?? [])) ?: '—');

        foreach ((array) ($payload['scenarios'] ?? []) as $scenario) {
            $this->line(sprintf(
                '  [%s] %s · against=%s · self_test=%s%s',
                (string) ($scenario['status'] ?? '?'),
                (string) ($scenario['scenario'] ?? ''),
                (string) ($scenario['evaluated_against'] ?? '?'),
                YesNo::format((bool) ($scenario['contract_self_test'] ?? false)),
                ($scenario['missing_capabilities'] ?? []) !== [] ? ' · missing='.implode(',', (array) $scenario['missing_capabilities']) : '',
            ));
        }
        foreach ((array) ($payload['next_actions'] ?? []) as $action) {
            $this->line('  → '.(string) $action);
        }

        return $this->exitCode($status, $strictFail);
    }

    private function exitCode(string $status, bool $strictFail): int
    {
        if ($status === Loop24hCertificationHarnessService::STATUS_BLOCKED) {
            return self::FAILURE;
        }

        return $strictFail ? self::FAILURE : self::SUCCESS;
    }
}
