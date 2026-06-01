<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDurableReservationApprovalDecisionTemplateService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction Durable Reservation Approval DECISION TEMPLATE CLI.
 *
 *   php artisan atlas:aaeos:durable-reservation-approval-decision-template [--json]
 *
 * Read-only, deterministic. Emits the unsigned decision-template slot a
 * human/operator later fills to approve or reject durable reservation work, and
 * records an (empty, unsigned) decision by safe default. Generating the template
 * approves nothing: status is `template_not_signed`, `approval_granted=false`,
 * and the non-execution guarantee (approval_granted / decision_signed /
 * migrations_allowed / storage_writes_allowed / dispatch_allowed) stays false.
 *
 * @see docs/engineering-knowledge-base/self-construction/durable-reservation-approval-decision-template.md
 */
class AtlasDurableReservationApprovalDecisionTemplateCommand extends Command
{
    protected $signature = 'atlas:aaeos:durable-reservation-approval-decision-template {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · durable reservation approval decision template — read-only unsigned decision slot stating allowed values, bindings and limits, approving nothing.';

    public function handle(AtlasDurableReservationApprovalDecisionTemplateService $service): int
    {
        try {
            // Safe defaults: no decision value, no signer, no bindings supplied
            // => the template stays unsigned and nothing is approved or
            // dispatched. Signer slots reflect the documented required roles.
            $result = $service->evaluate(
                bindings: [],
                signerRoles: [
                    'product_governor',
                    'architecture_governor',
                    'safety_governance_reviewer',
                    'implementation_operator',
                ],
                forbiddenScopes: [
                    'runtimes/python/voice_realtime/**',
                ],
            );

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // Success means the non-execution guarantee held, not that any
            // approval exists.
            return ($result['guarantee_held'] ?? false) === true
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'durable_reservation_approval_decision_template_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
