<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasCyberComplianceMappingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cyber Compliance Mapping CLI.
 *
 *   php artisan atlas:aaeos:cyber-compliance-mapping
 *     [--pci]            // PCI-DSS scope / payment processor / touches CHD
 *     [--phi]            // PHI / healthcare / business associate
 *     [--eu]             // operates in EU or collects EU-resident data
 *     [--br]             // operates in Brazil or collects BR-citizen data
 *     [--dod]            // DoD contractor / military base / CUI
 *     [--soc2]           // declares SOC 2 Type II in posture
 *     [--iso]            // declares ISO/IEC 27001 in posture
 *     [--json]
 *
 * Read-only, deterministic. The cyber-bb-runner skill consults this mapping in the
 * scoping phase to derive the engagement's compliance_profile and the cumulative
 * cross-jurisdiction constraints (data residency, shortest notification SLA, must_redact,
 * minimal-collection exfil cap, cumulative hard refusals) the kernel Policy Engine
 * should inject. It NEVER executes anything against a target.
 *
 * @see docs/engineering-knowledge-base/cyber-security/compliance-mapping.md
 */
class AtlasCyberComplianceMappingCommand extends Command
{
    protected $signature = 'atlas:aaeos:cyber-compliance-mapping
        {--pci : PCI-DSS scope / payment processor / touches CHD}
        {--phi : PHI / healthcare provider / business associate}
        {--eu : operates in EU or collects EU-resident data}
        {--br : operates in Brazil or collects BR-citizen data}
        {--dod : DoD contractor / military base / CUI}
        {--soc2 : declares SOC 2 Type II in posture}
        {--iso : declares ISO/IEC 27001 in posture}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cyber · maps BB/target jurisdiction signals to the cumulative compliance_profile envelope (residency, notification SLA, must_redact, exfil cap, hard refusals).';

    public function handle(AtlasCyberComplianceMappingService $service): int
    {
        try {
            $signals = [
                'pci_dss_scope' => (bool) $this->option('pci'),
                'phi' => (bool) $this->option('phi'),
                'operates_eu' => (bool) $this->option('eu'),
                'operates_br' => (bool) $this->option('br'),
                'dod_contractor' => (bool) $this->option('dod'),
                'soc2_type2' => (bool) $this->option('soc2'),
                'iso_27001' => (bool) $this->option('iso'),
            ];

            $result = $service->resolve($signals);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'cyber_compliance_mapping_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
