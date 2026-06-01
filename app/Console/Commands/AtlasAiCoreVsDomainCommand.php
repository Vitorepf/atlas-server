<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiCoreVsDomainService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Core Vs Domain founding placement decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-ai-core-vs-domain [--json]
 *
 * Read-only and deterministic. Emits worked applications of the doc's Regra
 * Rapida (a horizontal capability serving many surfaces -> Core; a single-
 * vertical criterion -> Domain), the Profile/model inversion guard (a model
 * profile must never define a flow), the `atlas fix` high-risk -> forge mapping,
 * the domain status list (marketing is scaffold, not ready) and the Surface
 * prohibition audit.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-core-vs-domain.md
 */
class AtlasAiCoreVsDomainCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-ai-core-vs-domain {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI Core-vs-Domain founding placement decider (Regra Rapida, Profile, domain status, Surface prohibitions).';

    public function handle(AtlasAiCoreVsDomainService $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'schema_version' => AtlasAiCoreVsDomainService::SCHEMA_VERSION,
                // Regra Rapida: paste-of-image style capability that serves many
                // surfaces resolves to the Core.
                'placement_core_example' => $service->placeCapability([
                    'serves_multiple_surfaces' => true,
                    'domain_count' => 1,
                ]),
                // Regra Rapida: a single-vertical specialized criterion -> Domain.
                'placement_domain_example' => $service->placeCapability([
                    'depends_on_vertical_criteria' => true,
                    'vertical' => 'programming',
                ]),
                // Teste De Decisao Q6: would be copied by another command -> Core.
                'placement_q6_escalation_example' => $service->placeCapability([
                    'only_collects_input_or_renders_output' => true,
                    'another_command_would_copy' => true,
                ]),
                'flow_atlas_dev' => $service->resolveFlowForCommand('atlas dev'),
                'flow_atlas_fix_high_risk' => $service->resolveFlowForCommand('atlas fix', 'high'),
                'model_profile_cannot_be_flow' => $service->assertModelDoesNotDefineFlow('opus'),
                'domain_status_marketing' => $service->domainStatus('marketing'),
                'domain_status_programming' => $service->domainStatus('programming'),
                'blackink_is_business_context' => $service->classifyContextOrDomain('blackink'),
                'surface_choose_provider_blocked' => $service->auditSurfaceAction('choose_provider'),
                'domain_provider_routing_blocked' => $service->auditDomainOwnership('provider_routing'),
                'duplication_signals' => $service->duplicationSignals(),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
