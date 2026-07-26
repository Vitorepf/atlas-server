<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\LongHorizon\AtlasTeosFinalCertificationService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\CertificationScaffoldHelpers;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Read-only certification that Atlas Dev and Atlas Forge are wired to the
 * strongest local programming flow currently available.
 *
 * This gate does not execute providers, rivals or benchmarks. It checks the
 * production wiring surface:
 *  - Dev uses the Fast Path + Mandatory RAG gate.
 *  - Dev emits/consumes long-horizon continuation packs.
 *  - Forge emits continuation packs from long-horizon state.
 *  - Dev->Forge handoff uses escalation_packet.v1 and Forge intake consumes it.
 *  - Programming Console long-horizon actions use real TEOS services, not
 *    placeholders.
 *  - TEOS local final certification has no blockers.
 */
final class DevForgeRobustFlowCertificationService
{
    use CertificationScaffoldHelpers;

    public const SCHEMA_VERSION = 'atlas.programming.dev_forge_robust_flow_certification.v1';

    public const STATUS_PASSED = 'passed';

    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        private readonly AtlasTeosFinalCertificationService $teosFinalCertification,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $now = CarbonImmutable::now();
        $checks = [
            $this->devFastPathUsesMandatoryRag(),
            $this->devContinuationPackSurface(),
            $this->forgeContinuationPackSurface(),
            $this->devToForgeEscalationContract(),
            $this->programmingConsoleUsesRealTeos(),
            $this->teosFinalReady(),
            $this->noExternalExecutionPolicy(),
        ];

        $status = $this->status($checks);
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => $now->toJSON(),
            'summary' => $this->summary($checks),
            'checks' => $checks,
            'blockers' => array_values(array_filter($checks, static fn (array $check): bool => $check['status'] === 'fail')),
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
                'allows_external_superiority_claim' => false,
                'certifies_local_wiring_only' => true,
            ],
            'scope' => [
                'dev' => 'AtlasDev fast path, mandatory RAG, continuation/resume surface, long-horizon console certification',
                'forge' => 'Forge intake, escalation packet, long-horizon state, continuation pack, Obra-scoped certification surface',
                'excluded' => 'real external benchmark/rivals/provider execution and live Forge certification without a concrete Obra',
            ],
            'writes' => false,
        ];
        $payload['certification_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function devFastPathUsesMandatoryRag(): array
    {
        $path = base_path('app/Services/Ai/Programming/AtlasDev/Pipeline/AtlasDevFastPathOrchestrator.php');
        $source = $this->read($path);
        $ok = $source !== null
            && str_contains($source, 'MandatoryRagGate')
            && str_contains($source, '->evaluate(')
            && str_contains($source, 'isBlocked');

        return $this->check(
            id: 'dev_fast_path_mandatory_rag',
            ok: $ok,
            summary: $ok
                ? 'Atlas Dev Fast Path evaluates MandatoryRagGate and blocks when the gate blocks.'
                : 'Atlas Dev Fast Path is not provably gated by MandatoryRagGate.',
            evidence: ['path' => $this->relative($path)],
            remediation: 'Wire AtlasDevFastPathOrchestrator through MandatoryRagGate before provider execution.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function devContinuationPackSurface(): array
    {
        $builderPath = base_path('app/Services/Ai/Programming/AtlasDev/ContinuationPack/DevContinuationPackBuilder.php');
        $resumePath = base_path('app/Services/Ai/Programming/ProgrammingResumeService.php');
        $builder = $this->read($builderPath);
        $resume = $this->read($resumePath);
        $ok = $builder !== null
            && $resume !== null
            && str_contains($builder, 'atlas.long_horizon.continuation_pack.v2')
            && str_contains($builder, 'AtlasLongHorizonContinuationPack::query()->create')
            && str_contains($resume, 'continuation_pack')
            && str_contains($resume, 'AtlasLongHorizonCanon::CONTINUATION_PACK_SCHEMA_VERSION');

        return $this->check(
            id: 'dev_continuation_pack_surface',
            ok: $ok,
            summary: $ok
                ? 'Atlas Dev has continuation pack builder plus resume adapter for continuation_pack.v2.'
                : 'Atlas Dev continuation/resume surface is incomplete.',
            evidence: ['paths' => [$this->relative($builderPath), $this->relative($resumePath)]],
            remediation: 'Restore DevContinuationPackBuilder and ProgrammingResumeService continuation_pack.v2 adapter.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeContinuationPackSurface(): array
    {
        $builderPath = base_path('app/Services/Ai/Programming/Forge/ForgeContinuationPackBuilder.php');
        $statePath = base_path('app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php');
        $builder = $this->read($builderPath);
        $state = $this->read($statePath);
        $ok = $builder !== null
            && $state !== null
            && str_contains($builder, 'AtlasLongHorizonContinuationPack::query()->create')
            && str_contains($builder, 'SCOPE_TYPE_OBRA')
            && str_contains($state, 'emitContinuationPack')
            && str_contains($state, 'ForgeContinuationPackBuilder');

        return $this->check(
            id: 'forge_continuation_pack_surface',
            ok: $ok,
            summary: $ok
                ? 'Atlas Forge emits Obra-scoped continuation packs from long-horizon state.'
                : 'Atlas Forge continuation pack surface is incomplete.',
            evidence: ['paths' => [$this->relative($builderPath), $this->relative($statePath)]],
            remediation: 'Restore ForgeLongHorizonStateService::emitContinuationPack and ForgeContinuationPackBuilder.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function devToForgeEscalationContract(): array
    {
        $schemaPath = base_path('app/Services/Ai/Programming/AtlasDev/Schemas/EscalationPacket.php');
        $intakePath = base_path('app/Services/Ai/Programming/Forge/ForgeIntakeService.php');
        $schema = $this->read($schemaPath);
        $intake = $this->read($intakePath);
        $ok = $schema !== null
            && $intake !== null
            && str_contains($schema, 'atlas.dev_to_forge.escalation_packet.v1')
            && str_contains($schema, "SOURCE_CORE = 'atlas_dev'")
            && str_contains($schema, "TARGET_CORE = 'atlas_forge'")
            && str_contains($intake, 'intakeFromEscalationPacket')
            && str_contains($intake, 'escalation_packet_hash');

        return $this->check(
            id: 'dev_to_forge_escalation_contract',
            ok: $ok,
            summary: $ok
                ? 'Dev->Forge handoff uses escalation_packet.v1 and Forge intake consumes packet hash/id.'
                : 'Dev->Forge escalation contract is incomplete.',
            evidence: ['paths' => [$this->relative($schemaPath), $this->relative($intakePath)]],
            remediation: 'Restore EscalationPacket v1 and ForgeIntakeService::intakeFromEscalationPacket.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function programmingConsoleUsesRealTeos(): array
    {
        $path = base_path('app/Services/Ai/Programming/Console/ProgrammingConsoleService.php');
        $testPath = base_path('tests/Feature/Ai/Programming/Console/ProgrammingConsoleLongHorizonActionsTest.php');
        $source = $this->read($path);
        $test = $this->read($testPath);
        $ok = $source !== null
            && $test !== null
            && str_contains($source, 'LongHorizonContextFreshnessGate')
            && str_contains($source, 'LongHorizonRecoveryPlannerService')
            && str_contains($source, 'LongHorizonContinuityCertificationService')
            && str_contains($source, "'continuity_certification_service' => 'shipped'")
            && ! str_contains($source, 'service_not_shipped')
            && ! str_contains($source, 'not shipped yet')
            && str_contains($test, 'test_long_horizon_certify_uses_shipped_continuity_certification_service');

        return $this->check(
            id: 'programming_console_real_teos',
            ok: $ok,
            summary: $ok
                ? 'Programming Console long-horizon status/certify uses real TEOS services, not placeholders.'
                : 'Programming Console still appears to expose placeholder long-horizon behavior.',
            evidence: ['paths' => [$this->relative($path), $this->relative($testPath)]],
            remediation: 'Wire ProgrammingConsoleService to freshness, recovery and continuity certification services.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function teosFinalReady(): array
    {
        try {
            $payload = $this->teosFinalCertification->certify();
        } catch (Throwable $exception) {
            return $this->check(
                id: 'teos_final_certification',
                ok: false,
                summary: 'TEOS final certification threw: '.$exception->getMessage(),
                evidence: [],
                remediation: 'Fix TEOS final certification before declaring Dev/Forge robust flow ready.',
            );
        }

        $status = (string) ($payload['status'] ?? 'unknown');
        $blockers = array_values((array) ($payload['blockers'] ?? []));
        $ok = $status === AtlasTeosFinalCertificationService::STATUS_READY
            || ($status === AtlasTeosFinalCertificationService::STATUS_PARTIAL && $blockers === []);

        return $this->check(
            id: 'teos_final_certification',
            ok: $ok,
            summary: 'TEOS final certification status: '.$status,
            evidence: [
                'certification_hash' => $payload['certification_hash'] ?? null,
                'summary' => $payload['summary'] ?? null,
                'blockers' => $blockers,
                'warnings' => $payload['warnings'] ?? [],
                'acceptance_policy' => 'ready_or_partial_without_blockers',
            ],
            remediation: 'Run php artisan atlas:teos:final-certify --json and fix blockers; warnings stay audit-visible.',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function noExternalExecutionPolicy(): array
    {
        return $this->check(
            id: 'no_external_execution_policy',
            ok: true,
            summary: 'This certification is read-only and does not run providers, rivals or benchmarks.',
            evidence: [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'provider_calls_made' => false,
            ],
            remediation: 'N/A',
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        unset($payload['generated_at'], $payload['certification_hash']);

        return MissionCanonicalHash::sha256($payload);
    }
}
