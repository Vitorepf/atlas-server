<?php

declare(strict_types=1);

namespace App\Services\Ai\LongHorizon;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\ProgrammingRuntime\FilesystemRepoProbe;
use App\Services\Ai\ProgrammingRuntime\RepoProbe;
use Carbon\CarbonImmutable;

/**
 * TEOS-I2 release certification.
 *
 * This is intentionally a read-only repo audit. TEOS-I2 is the increment that
 * promotes the I1 primitives into three operational surfaces:
 *
 * - provider-independent replay manifest;
 * - causal decision graph lite;
 * - long-horizon continuity certification.
 *
 * It never runs providers, never runs rivals, and never claims benchmark
 * superiority. It exists to avoid treating separate green slices as an
 * integrated increment without evidence.
 */
class AtlasTeosIncrement2CertificationService
{
    public const SCHEMA_VERSION = 'atlas.teos.increment_2_certification.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_BLOCKED = 'blocked';

    public const CHECK_STATUS_PASS = 'pass';

    public const CHECK_STATUS_WARN = 'warn';

    public const CHECK_STATUS_FAIL = 'fail';

    public const SEVERITY_P0 = 'P0';

    public const SEVERITY_P1 = 'P1';

    public const SEVERITY_P2 = 'P2';

    public const CHECK_REPLAY_SURFACE = 'replay_manifest_surface';

    public const CHECK_CAUSAL_GRAPH_SURFACE = 'causal_graph_lite_surface';

    public const CHECK_CONTINUITY_CERTIFICATION_SURFACE = 'continuity_certification_surface';

    public const CHECK_I2_TESTS_PRESENT = 'increment_2_tests_present';

    public const CHECK_CANON_CONSTANTS = 'increment_2_canon_constants';

    public const CHECK_DOCS_PRESENT = 'increment_2_docs_present';

    public const CHECK_NO_PROVIDER_OR_RIVALS = 'no_provider_or_rivals_invocation';

    public const ALL_CHECK_IDS = [
        self::CHECK_REPLAY_SURFACE,
        self::CHECK_CAUSAL_GRAPH_SURFACE,
        self::CHECK_CONTINUITY_CERTIFICATION_SURFACE,
        self::CHECK_I2_TESTS_PRESENT,
        self::CHECK_CANON_CONSTANTS,
        self::CHECK_DOCS_PRESENT,
        self::CHECK_NO_PROVIDER_OR_RIVALS,
    ];

    public function __construct(
        private readonly RepoProbe $probe = new FilesystemRepoProbe,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function certify(): array
    {
        $checks = [
            $this->checkReplaySurface(),
            $this->checkCausalGraphSurface(),
            $this->checkContinuityCertificationSurface(),
            $this->checkIncrement2TestsPresent(),
            $this->checkCanonConstants(),
            $this->checkDocsPresent(),
            $this->checkNoProviderOrRivalsInvocation(),
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->aggregateStatus($checks),
            'summary' => $this->summarize($checks),
            'checks' => $checks,
            'blockers' => $this->collectFindings($checks, self::CHECK_STATUS_FAIL),
            'warnings' => $this->collectFindings($checks, self::CHECK_STATUS_WARN),
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'allows_external_superiority_claim' => false,
                'declares_teos_complete' => false,
                'scope' => 'teos_i2_release_gate_only',
            ],
            'provider_calls_made' => false,
            'writes' => false,
            'evidence_refs' => $this->topLevelEvidenceRefs($checks),
        ];

        $hashPayload = $payload;
        $payload['generated_at'] = CarbonImmutable::now()->toISOString();
        $payload['certification_hash'] = MissionCanonicalHash::sha256($hashPayload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkReplaySurface(): array
    {
        return $this->requiredFilesCheck(
            self::CHECK_REPLAY_SURFACE,
            self::SEVERITY_P0,
            [
                'app/Models/AtlasLongHorizonReplayManifest.php',
                'database/migrations/2026_05_19_150000_create_atlas_long_horizon_replay_manifests_table.php',
                'app/Services/Ai/LongHorizon/Replay/LongHorizonReplayManifestBuilder.php',
                'app/Services/Ai/LongHorizon/Replay/ReplayManifestReader.php',
                'app/Console/Commands/AtlasLongHorizonReplayManifestCommand.php',
            ],
            'Replay Manifest surface is present',
            'restore replay manifest model, migration, builder, reader and CLI command',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCausalGraphSurface(): array
    {
        return $this->requiredFilesCheck(
            self::CHECK_CAUSAL_GRAPH_SURFACE,
            self::SEVERITY_P0,
            [
                'app/Services/Ai/LongHorizon/Causal/CausalGraphBuilder.php',
                'app/Services/Ai/LongHorizon/Causal/LongHorizonCausalDecisionGraphService.php',
                'app/Console/Commands/AtlasLongHorizonCausalGraphCommand.php',
            ],
            'Causal Decision Graph Lite surface is present',
            'restore causal graph builder, read model service and CLI command',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkContinuityCertificationSurface(): array
    {
        return $this->requiredFilesCheck(
            self::CHECK_CONTINUITY_CERTIFICATION_SURFACE,
            self::SEVERITY_P0,
            [
                'app/Services/Ai/LongHorizon/LongHorizonContinuityCertificationService.php',
                'app/Console/Commands/AtlasLongHorizonContinuityCertifyCommand.php',
            ],
            'Continuity Certification full surface is present',
            'restore continuity certification service and CLI command',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkIncrement2TestsPresent(): array
    {
        $files = [
            'tests/Feature/Ai/LongHorizon/Replay/LongHorizonReplayManifestBuilderTest.php',
            'tests/Feature/Ai/LongHorizon/Replay/ReplayManifestReaderTest.php',
            'tests/Feature/Ai/LongHorizon/Replay/AtlasLongHorizonReplayManifestCommandTest.php',
            'tests/Feature/Ai/LongHorizon/Causal/LongHorizonCausalDecisionGraphServiceTest.php',
            'tests/Feature/Ai/LongHorizon/Causal/AtlasLongHorizonCausalGraphCommandTest.php',
            'tests/Feature/Ai/LongHorizon/LongHorizonContinuityCertificationServiceTest.php',
        ];

        $missing = $this->missingFiles($files);
        if ($missing === []) {
            return $this->pass(
                self::CHECK_I2_TESTS_PRESENT,
                self::SEVERITY_P1,
                'Focused TEOS-I2 replay, causal graph and continuity tests are present',
                $files,
            );
        }

        return $this->warn(
            self::CHECK_I2_TESTS_PRESENT,
            self::SEVERITY_P1,
            'Missing focused TEOS-I2 tests: '.implode(', ', $missing),
            $files,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCanonConstants(): array
    {
        $path = 'app/Services/Ai/LongHorizon/AtlasLongHorizonCanon.php';
        if (! $this->probe->fileExists($path)) {
            return $this->fail(
                self::CHECK_CANON_CONSTANTS,
                self::SEVERITY_P0,
                'AtlasLongHorizonCanon is missing',
                'restore AtlasLongHorizonCanon before certifying I2',
                [$path],
            );
        }

        $source = (string) $this->probe->readFile($path);
        $missing = [];
        foreach ([
            'REPLAY_MANIFEST_SCHEMA_VERSION',
            'REPLAY_READER_SCHEMA_VERSION',
            'CAUSAL_GRAPH_LITE_SCHEMA_VERSION',
            'CAUSAL_GRAPH_LITE_ALLOWED_SCOPES',
        ] as $token) {
            if (! str_contains($source, $token)) {
                $missing[] = $token;
            }
        }

        if ($missing === []) {
            return $this->pass(
                self::CHECK_CANON_CONSTANTS,
                self::SEVERITY_P0,
                'TEOS-I2 canon constants are present',
                [$path],
            );
        }

        return $this->fail(
            self::CHECK_CANON_CONSTANTS,
            self::SEVERITY_P0,
            'AtlasLongHorizonCanon is missing I2 tokens: '.implode(', ', $missing),
            'restore replay and causal graph constants in AtlasLongHorizonCanon',
            [$path],
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDocsPresent(): array
    {
        $files = [
            'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system.md',
            'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-primitives.md',
            'docs/engineering-knowledge-base/atlas-temporal-engineering-operating-system-operations.md',
            'docs/engineering-knowledge-base/atlas-teos-increment-1-plan.md',
            'docs/engineering-knowledge-base/atlas-long-horizon-replay-manifest.md',
        ];

        $missing = $this->missingFiles($files);
        if ($missing === []) {
            return $this->pass(
                self::CHECK_DOCS_PRESENT,
                self::SEVERITY_P2,
                'TEOS docs and replay manifest doc are present',
                $files,
            );
        }

        return $this->warn(
            self::CHECK_DOCS_PRESENT,
            self::SEVERITY_P2,
            'Missing non-blocking TEOS-I2 docs: '.implode(', ', $missing),
            $files,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkNoProviderOrRivalsInvocation(): array
    {
        $files = [
            'app/Services/Ai/LongHorizon/Replay/LongHorizonReplayManifestBuilder.php',
            'app/Services/Ai/LongHorizon/Replay/ReplayManifestReader.php',
            'app/Services/Ai/LongHorizon/Causal/LongHorizonCausalDecisionGraphService.php',
            'app/Services/Ai/LongHorizon/LongHorizonContinuityCertificationService.php',
        ];
        $tokens = [
            'AtlasForgeClaudeCliInvocationDriver',
            'AtlasForgeCodexCliInvocationDriver',
            'AtlasForgeGeminiCliInvocationDriver',
            'RivalsHarness',
            'RivalsBattery',
            'runs_rivals = true',
            "'runs_rivals' => true",
            '"rivals_compared" => true',
            "'rivals_compared' => true",
        ];

        $violations = [];
        foreach ($files as $file) {
            $source = (string) $this->probe->readFile($file);
            foreach ($tokens as $token) {
                if (str_contains($source, $token)) {
                    $violations[] = "{$file}:{$token}";
                }
            }
        }

        if ($violations === []) {
            return $this->pass(
                self::CHECK_NO_PROVIDER_OR_RIVALS,
                self::SEVERITY_P0,
                'TEOS-I2 surfaces do not invoke providers or rivals',
                $files,
            );
        }

        return $this->fail(
            self::CHECK_NO_PROVIDER_OR_RIVALS,
            self::SEVERITY_P0,
            'Provider/rivals invocation tokens found: '.implode(', ', $violations),
            'remove provider/rivals calls from TEOS-I2 certification surfaces; benchmark is a later authorized gate',
            $files,
        );
    }

    /**
     * @param  array<int,string>  $files
     * @return array<string,mixed>
     */
    private function requiredFilesCheck(
        string $checkId,
        string $severity,
        array $files,
        string $passReason,
        string $remediation,
    ): array {
        $missing = $this->missingFiles($files);
        if ($missing === []) {
            return $this->pass($checkId, $severity, $passReason, $files);
        }

        return $this->fail(
            $checkId,
            $severity,
            'Missing required files: '.implode(', ', $missing),
            $remediation,
            $files,
        );
    }

    /**
     * @param  array<int,string>  $files
     * @return array<int,string>
     */
    private function missingFiles(array $files): array
    {
        $missing = [];
        foreach ($files as $file) {
            if (! $this->probe->fileExists($file)) {
                $missing[] = $file;
            }
        }

        return $missing;
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     */
    private function aggregateStatus(array $checks): string
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? null) === self::CHECK_STATUS_FAIL
                && in_array($check['severity'] ?? null, [self::SEVERITY_P0, self::SEVERITY_P1], true)) {
                return self::STATUS_BLOCKED;
            }
        }
        foreach ($checks as $check) {
            if (($check['status'] ?? null) === self::CHECK_STATUS_WARN
                && in_array($check['severity'] ?? null, [self::SEVERITY_P0, self::SEVERITY_P1], true)) {
                return self::STATUS_PARTIAL;
            }
        }

        return self::STATUS_READY;
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<string,int>
     */
    private function summarize(array $checks): array
    {
        $summary = [
            'total' => count($checks),
            'pass' => 0,
            'warn' => 0,
            'fail' => 0,
            'p0_blockers' => 0,
            'p1_blockers' => 0,
        ];
        foreach ($checks as $check) {
            $status = (string) ($check['status'] ?? '');
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
            if ($status === self::CHECK_STATUS_FAIL && ($check['severity'] ?? null) === self::SEVERITY_P0) {
                $summary['p0_blockers']++;
            }
            if ($status === self::CHECK_STATUS_FAIL && ($check['severity'] ?? null) === self::SEVERITY_P1) {
                $summary['p1_blockers']++;
            }
        }

        return $summary;
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<int,array<string,mixed>>
     */
    private function collectFindings(array $checks, string $status): array
    {
        return array_values(array_filter(
            $checks,
            fn (array $check): bool => ($check['status'] ?? null) === $status,
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $checks
     * @return array<int,string>
     */
    private function topLevelEvidenceRefs(array $checks): array
    {
        $refs = [];
        foreach ($checks as $check) {
            foreach ((array) ($check['evidence_refs'] ?? []) as $ref) {
                if (is_string($ref) && $ref !== '') {
                    $refs[$ref] = true;
                }
            }
        }
        ksort($refs);

        return array_keys($refs);
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function pass(string $checkId, string $severity, string $reason, array $evidenceRefs): array
    {
        return [
            'check_id' => $checkId,
            'status' => self::CHECK_STATUS_PASS,
            'severity' => $severity,
            'reason' => $reason,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function warn(string $checkId, string $severity, string $reason, array $evidenceRefs): array
    {
        return [
            'check_id' => $checkId,
            'status' => self::CHECK_STATUS_WARN,
            'severity' => $severity,
            'reason' => $reason,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function fail(
        string $checkId,
        string $severity,
        string $reason,
        string $remediation,
        array $evidenceRefs,
    ): array {
        return [
            'check_id' => $checkId,
            'status' => self::CHECK_STATUS_FAIL,
            'severity' => $severity,
            'reason' => $reason,
            'remediation' => $remediation,
            'evidence_refs' => $evidenceRefs,
        ];
    }
}
