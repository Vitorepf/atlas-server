<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Golden freeze for the 23 *Certification() payloads of
 * ProgrammingProfessionalCompletionAuditService (Obra #8 / R-16).
 *
 * These payloads are consumed by AtlasProgrammingCompletionAuditCommand via
 * report() and were not frozen by any test before this one. The golden must
 * pass IDENTICAL (23/23) before and after any mechanical refactor of the
 * certification methods.
 *
 * How it works:
 *  - Each method is invoked twice in-process via reflection with fixed inputs
 *    (Carbon test-now pinned); the two sha256(json) hashes must match
 *    (determinism proof).
 *  - The per-method hashes are compared against a baseline fixture. The
 *    baseline is machine/working-tree specific (certs scan real repo +
 *    atlas-desktop sources), so it is auto-created on first run and NOT meant
 *    to survive commits. Regenerate with ATLAS_GOLDEN_WRITE=1 immediately
 *    before a refactor, then re-run after the refactor without the flag.
 *
 * Volatile-field normalization: exactly ONE field proved volatile across two
 * in-process calls on an unchanged working tree —
 * rivalsEvidencePackCertification().fixture_pack_summary.evidence_pack_id
 * (AtlasRivalsEvidencePackService::generate() mints a fresh ULID per call).
 * All other fields of all 23 payloads were byte-stable. See normalize().
 */
class ProgrammingCompletionAuditGoldenTest extends TestCase
{
    private const BASELINE = __DIR__.'/__fixtures__/programming_completion_audit_golden_baseline.json';

    /**
     * All 23 certification methods, in source order.
     *
     * @var array<int,string>
     */
    private const CERTIFICATION_METHODS = [
        'forgeRuntimeCertification',
        'forgeLiveExecutionCertification',
        'atlasCodeEnterpriseCertification',
        'forgeFastPathCertification',
        'forgeReviewCompletionCertification',
        'forgeNativeRivalsCertification',
        'forgeWorkIntakeCertification',
        'forgeOperatorCockpitCertification',
        'atlasForgeContinuumCertification',
        'atlasForgeProviderCapacityCertification',
        'atlasForgeProviderInvocationCertification',
        'atlasForgeRealProviderDriversCertification',
        'atlasCodeForgeHumanFirstUxCertification',
        'atlasCodeObraCommandCenterCertification',
        'atlasCodeVisualErgonomicsCertification',
        'atlasCodePremiumWorkbenchVisualComfortCertification',
        'atlasSelfImprovementGovernanceCertification',
        'atlasSelfImprovementForgeActivationCertification',
        'atlasSelfImprovementActivationCockpitCertification',
        'atlasSelfImprovementClosedLoopLevel7Certification',
        'rivalsOneShotEnterpriseEvaluationCertification',
        'rivalsEvidencePackCertification',
        'externalRivalsCertification',
    ];

    public function test_all_23_certification_payloads_are_deterministic_and_match_golden_baseline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06T00:00:00Z'));

        $service = app(ProgrammingProfessionalCompletionAuditService::class);
        $reflection = new \ReflectionClass($service);
        $workspace = base_path();

        $hashes = [];
        foreach (self::CERTIFICATION_METHODS as $methodName) {
            $method = $reflection->getMethod($methodName);
            $args = $this->argumentsFor($method, $workspace);

            $first = $this->hashPayload($method->invokeArgs($service, $args));
            $second = $this->hashPayload($method->invokeArgs($service, $args));

            $this->assertSame(
                $first,
                $second,
                "Certification payload of {$methodName}() is non-deterministic across two in-process calls.",
            );

            $hashes[$methodName] = $first;
        }

        $this->assertCount(23, $hashes);

        if (! is_file(self::BASELINE) || getenv('ATLAS_GOLDEN_WRITE') === '1') {
            @mkdir(dirname(self::BASELINE), 0775, true);
            file_put_contents(
                self::BASELINE,
                json_encode($hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            );
            $this->addToAssertionCount(1);

            return;
        }

        $baseline = json_decode((string) file_get_contents(self::BASELINE), true);
        $this->assertIsArray($baseline, 'Golden baseline fixture is unreadable.');

        $diverged = [];
        foreach ($hashes as $methodName => $hash) {
            if (($baseline[$methodName] ?? null) !== $hash) {
                $diverged[] = $methodName;
            }
        }

        $this->assertSame(
            [],
            $diverged,
            'Certification payloads diverged from golden baseline (regenerate deliberately with ATLAS_GOLDEN_WRITE=1 if the change is intended): '
            .implode(', ', $diverged),
        );
    }

    /**
     * Fixed, deterministic inputs per method signature. Three of the 23 do not
     * take (string $workspace): forgeRuntimeCertification(array $checklist),
     * externalRivalsCertification(array $verificationEvidence) and the
     * zero-argument ones.
     *
     * @return array<int,mixed>
     */
    private function argumentsFor(\ReflectionMethod $method, string $workspace): array
    {
        $parameters = $method->getParameters();
        if ($parameters === []) {
            return [];
        }

        $parameter = $parameters[0];
        $type = $parameter->getType();
        if ($type instanceof \ReflectionNamedType && $type->getName() === 'array') {
            return [$this->fixedArrayInputFor($method->getName())];
        }

        return [$workspace];
    }

    /**
     * @return array<string,mixed>
     */
    private function fixedArrayInputFor(string $methodName): array
    {
        return match ($methodName) {
            // One passed + one blocked forge-core item exercises both branches.
            'forgeRuntimeCertification' => [
                ['id' => 'agentic_rag_context_pack', 'status' => 'passed'],
                ['id' => 'stage_receipts_resume', 'status' => 'blocked', 'blocker' => 'golden_fixture_blocker'],
                ['id' => 'not_a_forge_core_item', 'status' => 'blocked'],
            ],
            'externalRivalsCertification' => [
                'rivals_external_claim' => [
                    'status' => 'external_battery_invalid',
                    'claim_ready' => false,
                    'comparable_case_count' => 0,
                    'blocking_reasons' => ['golden_fixture_reason'],
                ],
                'invalid_battery_triage_packet' => ['status' => 'triage_required_before_rerun'],
                'current_workspace_preflight' => ['status' => 'blocked'],
                'current_local_recheck_evidence' => ['status' => 'unknown'],
                'operator_safety' => ['rerun_provider_battery_allowed_now' => false],
            ],
            default => [],
        };
    }

    private function hashPayload(mixed $payload): string
    {
        return hash('sha256', json_encode(
            $this->normalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Normalize ONLY fields PROVEN volatile (probed with two in-process calls
     * on an unchanged working tree). Sole hit: fixture_pack_summary
     * .evidence_pack_id — a fresh ULID minted by
     * AtlasRivalsEvidencePackService::generate() on every call inside
     * rivalsEvidencePackCertification(). Everything else is byte-stable.
     */
    private function normalize(mixed $payload): mixed
    {
        if (is_array($payload) && isset($payload['fixture_pack_summary']['evidence_pack_id'])) {
            $payload['fixture_pack_summary']['evidence_pack_id'] = '<volatile:evidence_pack_id>';
        }

        return $payload;
    }
}
