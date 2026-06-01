<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAiImplementationPacketContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Self-Construction · AI Implementation Packet admission CLI.
 *
 *   php artisan atlas:aaeos:ai-implementation-packet-contract [--json]
 *
 * Validates a packet artifact against the documented Packet Schema, Hard
 * Invariants and Rejected-Packet rules and emits admitted|rejected|blocked
 * evidence. Read-only and deterministic; it NEVER flips execution_allowed, signs
 * receipts or authorizes merge. Defaults below are a deliberately safe sample.
 *
 * @see docs/engineering-knowledge-base/self-construction/ai-implementation-packet-contract.md
 */
class AtlasAiImplementationPacketContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:ai-implementation-packet-contract {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas self-construction · validate an AI implementation packet for admission (admitted|rejected|blocked).';

    public function handle(AtlasAiImplementationPacketContractService $service): int
    {
        try {
            // Safe default: the doc's "Safe Packet Example" — a low-risk docs packet.
            $packet = [
                'packet_id' => 'AIP-20260601-0001',
                'status' => AtlasAiImplementationPacketContractService::STATUS_AVAILABLE,
                'lane' => 'docs',
                'risk_level' => 'low',
                'execution_allowed' => false,
                'allowed_files' => [
                    'docs/engineering-knowledge-base/self-construction/scope-validator-contract.md',
                    'docs/ap/AP-691-atlas-self-construction-os-contract.md',
                ],
                'forbidden_files' => [
                    'runtimes/python/voice_realtime/**',
                    'app/Services/Ai/Voice/**',
                ],
                'required_gates' => ['docs-health', 'architecture-validate', 'git diff --check'],
                'acceptance_criteria' => ['Scope Validator contract documented and linked from AP-691.'],
                'required_evidence' => ['docs-health status ok'],
                'provider_contract' => [
                    'provider_profile' => 'generic',
                    'normalized_final_response_required' => true,
                ],
                'structural_contract_exists' => true,
                'has_critical_ap' => false,
                'hot_files' => [],
            ];

            $result = $service->validateAdmission($packet);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['admissible'] === true ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'ai_implementation_packet_validation_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
