<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Support\BaselineSignature;

use App\Services\Ai\Programming\AtlasDev\Support\BaselineSignature\BaselineSignature;
use App\Services\Ai\Programming\AtlasDev\Support\BaselineSignature\BaselineSignatureLoader;
use App\Services\Ai\Programming\AtlasDev\Support\BaselineSignature\DeltaGate;
use App\Services\Ai\Programming\AtlasDev\Support\BaselineSignature\DeltaGateResult;
use PHPUnit\Framework\TestCase;

/**
 * Covers the M0 baseline-signature mechanism and SCOPED delta-gating
 * (VAL-M0-001..006, VAL-M0-010). All assertions are model-irrelevant and
 * structural: they exercise the pinned artifact and the delta-gate helper
 * directly with synthetic fresh-run payloads.
 */
final class BaselineSignatureDeltaGateTest extends TestCase
{
    private const PINT_BASELINE_FILES = [
        'app/Services/Ai/Programming/AtlasDev/Schemas/Components/CompletionSummary.php',
        'app/Services/Ai/Programming/AtlasDev/Repair/FailureSignatureHasher.php',
        'app/Services/Ai/Programming/AtlasDev/Pipeline/SpecComposer.php',
    ];

    private const PHPSTAN_BASELINE_ENTRIES = [
        [
            'file' => 'app/Services/Ai/Programming/AtlasDev/ContinuationPack/DevContinuationPackBuilder.php',
            'line' => 368,
            'message' => 'Instanceof between App\\Models\\AtlasProgrammingStageReceipt and App\\Models\\AtlasProgrammingStageReceipt will always evaluate to true.',
            'identifier' => 'instanceof.alwaysTrue',
        ],
        [
            'file' => 'app/Services/Ai/Programming/AtlasDev/Gate/PatchApplier.php',
            'line' => 118,
            'message' => 'Variable $normalisedFallback might not be defined.',
            'identifier' => 'possibly_undefined',
        ],
    ];

    private const SMOKE_TEST_IDS = [
        'tests/Feature/Ai/Programming/AtlasDev/Http/PipelineRunExecutorHttpSmokeTest.php::test_real_executor_composes_receipt_with_task_kind_and_risk_level_from_compact_sdd',
        'tests/Feature/Ai/Programming/AtlasDev/Http/RunCompactSddMissingTest.php::test_executor_compact_sdd_missing_maps_to_422_with_typed_code',
        'tests/Feature/Ai/Programming/AtlasDev/Http/RunCompactSddMissingTest.php::test_executor_compact_sdd_invalid_maps_to_422_with_invalid_code',
        'tests/Feature/Ai/Programming/AtlasDev/AtlasDevDesktopRealSmokeCommandTest.php::test_real_smoke_passes_through_confirmation_token_compact_hash_and_receipt',
    ];

    // -- VAL-M0-001: artifact captures exactly the 4 smoke-test identifiers --

    public function test_pinned_artifact_lists_exactly_the_4_smoke_test_identifiers_each_resolvable(): void
    {
        $baseline = $this->loadPinnedBaseline();

        $this->assertSame(4, $baseline->testCount());
        $this->assertCount(4, $baseline->excludedTestIds());

        foreach ($baseline->excludedTestIds() as $id) {
            $this->assertNotEmpty($id, 'baseline test id must be non-empty');
            $this->assertStringContainsString('::', $id, 'baseline test id must be resolvable as <file>::<method>');
            [$file, $method] = explode('::', $id, 2);
            $this->assertNotEmpty($file);
            $this->assertNotEmpty($method);
        }

        // Each documented identifier is in the pinned signature.
        foreach (self::SMOKE_TEST_IDS as $id) {
            $this->assertContains($id, $baseline->excludedTestIds(), "missing documented smoke id: $id");
        }
    }

    // -- VAL-M0-002: artifact captures the pint-violation file set --

    public function test_pinned_artifact_captures_the_pint_violation_file_set_as_real_paths(): void
    {
        $baseline = $this->loadPinnedBaseline();

        $this->assertGreaterThan(0, $baseline->pintCount());
        $this->assertCount($baseline->pintCount(), $baseline->excludedPintFiles());

        foreach ($baseline->excludedPintFiles() as $file) {
            $this->assertStringStartsWith('app/Services/Ai/Programming/AtlasDev/', $file);
            $this->assertStringEndsWith('.php', $file);
        }
    }

    // -- VAL-M0-003: artifact captures the phpstan error set --

    public function test_pinned_artifact_captures_the_phpstan_error_set_with_delta_match_identity(): void
    {
        $baseline = $this->loadPinnedBaseline();

        $this->assertSame(35, $baseline->phpstanCount());
        $this->assertCount(35, $baseline->excludedPhpstanEntries());

        foreach ($baseline->excludedPhpstanEntries() as $entry) {
            $this->assertArrayHasKey('file', $entry);
            $this->assertArrayHasKey('line', $entry);
            $this->assertArrayHasKey('message', $entry);
            $this->assertNotEmpty($entry['file']);
            $this->assertNotEmpty($entry['message']);
            $this->assertIsInt($entry['line']);
        }

        foreach (self::PHPSTAN_BASELINE_ENTRIES as $expected) {
            $found = false;
            foreach ($baseline->excludedPhpstanEntries() as $actual) {
                if ($actual['file'] === $expected['file']
                    && $actual['line'] === $expected['line']
                    && $actual['message'] === $expected['message']) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'missing documented phpstan entry: '.$expected['file'].':'.$expected['line']);
        }
    }

    // -- VAL-M0-004: delta-gate IGNORES pre-existing reds (clean state passes) --

    public function test_delta_gate_over_an_unchanged_baseline_reports_empty_delta_and_passes_with_pre_existing_reds_excluded(): void
    {
        $gate = new DeltaGate($this->loadPinnedBaseline());

        $result = $gate->compare(
            freshTestIds: self::SMOKE_TEST_IDS,
            freshPhpstan: self::PHPSTAN_BASELINE_ENTRIES,
            freshPintFiles: self::PINT_BASELINE_FILES,
        );

        $this->assertTrue($result->passes);
        $this->assertFalse($result->hasNewFailures());
        $this->assertSame([], $result->newFailures['tests']);
        $this->assertSame([], $result->newFailures['phpstan']);
        $this->assertSame([], $result->newFailures['pint']);

        $this->assertNotEmpty($result->excludedPreExisting['tests'], 'pre-existing smoke reds must be listed as excluded');
        $this->assertNotEmpty($result->excludedPreExisting['phpstan'], 'pre-existing phpstan reds must be listed as excluded');
        $this->assertNotEmpty($result->excludedPreExisting['pint'], 'pre-existing pint reds must be listed as excluded');
    }

    // -- VAL-M0-005: delta-gate CATCHES a newly-introduced failing test as the sole delta --

    public function test_delta_gate_catches_a_newly_introduced_failing_test_as_the_sole_delta(): void
    {
        $gate = new DeltaGate($this->loadPinnedBaseline());

        $newTestId = 'tests/Unit/Ai/Programming/AtlasDev/SomeBrandNewRegressionTest.php::test_brand_new_regression';

        $result = $gate->compare(
            freshTestIds: array_merge(self::SMOKE_TEST_IDS, [$newTestId]),
            freshPhpstan: self::PHPSTAN_BASELINE_ENTRIES,
            freshPintFiles: self::PINT_BASELINE_FILES,
        );

        $this->assertFalse($result->passes);
        $this->assertSame([$newTestId], $result->newFailures['tests']);
        $this->assertSame([], $result->newFailures['phpstan'], 'pre-existing phpstan reds must NOT leak into the delta');
        $this->assertSame([], $result->newFailures['pint'], 'pre-existing pint reds must NOT leak into the delta');
        $this->assertSame(1, $result->newFailureCount());

        // Pre-existing reds are still listed as excluded, not absorbed into the delta.
        $this->assertSame(self::SMOKE_TEST_IDS, $result->excludedPreExisting['tests']);
    }

    // -- VAL-M0-006: a newly-introduced phpstan error / pint-violating file is caught as the sole delta --

    public function test_delta_gate_catches_a_new_phpstan_error_and_a_new_pint_file_as_sole_delta(): void
    {
        $gate = new DeltaGate($this->loadPinnedBaseline());

        $newPhpstan = [
            'file' => 'app/Services/Ai/Programming/AtlasDev/Pipeline/BrandNewService.php',
            'line' => 42,
            'message' => 'Cannot call method doStuff() on null.',
            'identifier' => 'nullsafe.method',
        ];
        $newPintFile = 'app/Services/Ai/Programming/AtlasDev/Pipeline/BrandNewService.php';

        $result = $gate->compare(
            freshTestIds: self::SMOKE_TEST_IDS,
            freshPhpstan: array_merge(self::PHPSTAN_BASELINE_ENTRIES, [$newPhpstan]),
            freshPintFiles: array_merge(self::PINT_BASELINE_FILES, [$newPintFile]),
        );

        $this->assertFalse($result->passes);

        $this->assertCount(1, $result->newFailures['phpstan']);
        $this->assertSame($newPhpstan['file'], $result->newFailures['phpstan'][0]['file']);
        $this->assertSame($newPhpstan['line'], $result->newFailures['phpstan'][0]['line']);
        $this->assertSame($newPhpstan['message'], $result->newFailures['phpstan'][0]['message']);

        $this->assertSame([$newPintFile], $result->newFailures['pint']);

        // Pre-existing set is excluded, not counted as delta.
        $this->assertSame([], $result->newFailures['tests']);
        $this->assertSame(self::PINT_BASELINE_FILES, $result->excludedPreExisting['pint']);
    }

    // -- VAL-M0-010: tampering the signature mid-run does not suppress the delta --

    public function test_tampering_the_signature_to_include_a_fresh_failing_identifier_does_not_suppress_the_delta(): void
    {
        $baseline = $this->loadPinnedBaseline();
        $gate = new DeltaGate($baseline);

        $freshTestId = 'tests/Unit/Ai/Programming/AtlasDev/TamperedRegressionTest.php::test_tampered_regression';

        // Tampered (attacker) signature that tries to add the fresh identifier
        // so the delta-gate would falsely absorb it.
        $tamperedPayload = $baseline->payload;
        $tamperedPayload['tests']['entries'][] = [
            'id' => $freshTestId,
            'file' => 'tests/Unit/Ai/Programming/AtlasDev/TamperedRegressionTest.php',
            'test' => 'test_tampered_regression',
            'reason' => 'attacker-injected mask',
        ];
        $tamperedPayload['tests']['count'] = count($tamperedPayload['tests']['entries']);
        $tamperedBaseline = BaselineSignature::fromPayload($tamperedPayload);

        $this->assertNotSame(
            $baseline->payloadHash,
            $tamperedBaseline->payloadHash,
            'tampered signature must have a different payload hash than the pinned copy',
        );

        // The gate was bound to the PINNED copy, not the tampered one, so the
        // fresh failure still surfaces as a delta.
        $result = $gate->compare(
            freshTestIds: [$freshTestId],
            freshPhpstan: [],
            freshPintFiles: [],
        );

        $this->assertFalse($result->passes, 'gate bound to the pinned copy must NOT be masked by tampering');
        $this->assertSame([$freshTestId], $result->newFailures['tests']);
        $this->assertSame($baseline->payloadHash, $result->baselinePayloadHash, 'gate must report the pinned copy hash, not the tampered one');
    }

    public function test_loader_caches_the_pinned_snapshot_and_ignores_a_mid_run_rewrite(): void
    {
        // A custom loader on a temp artifact proves the cache is sticky: after
        // load() the gate's verdict depends solely on the originally-loaded
        // snapshot, not on a rewritten file.
        $tmp = sys_get_temp_dir().'/atlas-baseline-tamper-'.bin2hex(random_bytes(4)).'.json';
        try {
            $pinnedPayload = $this->pinnedPayloadForLoaderTest();
            file_put_contents($tmp, json_encode($pinnedPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            $loader = new BaselineSignatureLoader($tmp);
            $firstLoad = $loader->load();
            $firstHash = $firstLoad->payloadHash;

            // Attacker rewrites the artifact to include the fresh failing id.
            $tampered = $pinnedPayload;
            $tampered['tests']['entries'][] = [
                'id' => 'tests/Some/FreshTest.php::test_fresh',
                'file' => 'tests/Some/FreshTest.php',
                'test' => 'test_fresh',
            ];
            $tampered['tests']['count'] = count($tampered['tests']['entries']);
            file_put_contents($tmp, json_encode($tampered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            // Cached load returns the originally-loaded pinned snapshot.
            $secondLoad = $loader->load();
            $this->assertSame($firstHash, $secondLoad->payloadHash, 'loader must return the cached pinned copy');

            $gate = DeltaGate::fromLoader($loader);
            $result = $gate->compare(['tests/Some/FreshTest.php::test_fresh'], [], []);

            $this->assertFalse($result->passes, 'fresh failure must surface even though the file was rewritten mid-run');
            $this->assertSame(['tests/Some/FreshTest.php::test_fresh'], $result->newFailures['tests']);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_baseline_signature_rejects_a_payload_with_a_wrong_schema_version(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported schema_version/i');

        $payload = $this->pinnedPayloadForLoaderTest();
        $payload['schema_version'] = 'not-the-real-schema.v0';

        BaselineSignature::fromPayload($payload);
    }

    public function test_baseline_signature_rejects_a_payload_whose_declared_count_does_not_match_entries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/declared count .* does not match entries count/i');

        $payload = $this->pinnedPayloadForLoaderTest();
        $payload['tests']['count'] = 999;

        BaselineSignature::fromPayload($payload);
    }

    public function test_delta_gate_is_anchored_to_the_pinned_copy_payload_hash(): void
    {
        $baseline = $this->loadPinnedBaseline();
        $gate = new DeltaGate($baseline);

        $this->assertSame($baseline->payloadHash, $gate->baselinePayloadHash);
    }

    private function loadPinnedBaseline(): BaselineSignature
    {
        return BaselineSignatureLoader::default()->load();
    }

    /**
     * @return array<string, mixed>
     */
    private function pinnedPayloadForLoaderTest(): array
    {
        $tests = array_map(
            static fn (string $id): array => [
                'id' => $id,
                'file' => explode('::', $id, 2)[0],
                'test' => explode('::', $id, 2)[1],
            ],
            self::SMOKE_TEST_IDS,
        );

        return [
            'schema_version' => BaselineSignature::SCHEMA_VERSION,
            'captured_at' => '2026-06-17T19:50:00Z',
            'scope' => [
                'tests' => 'tests/Feature/Ai/Programming/AtlasDev, tests/Unit/Ai/Programming/AtlasDev',
                'phpstan' => 'app/Services/Ai/Programming/AtlasDev',
                'pint' => 'app/Services/Ai/Programming/AtlasDev',
            ],
            'tests' => [
                'count' => count($tests),
                'entries' => $tests,
            ],
            'phpstan' => [
                'count' => count(self::PHPSTAN_BASELINE_ENTRIES),
                'entries' => self::PHPSTAN_BASELINE_ENTRIES,
            ],
            'pint' => [
                'count' => count(self::PINT_BASELINE_FILES),
                'entries' => array_map(static fn (string $f): array => ['file' => $f], self::PINT_BASELINE_FILES),
            ],
        ];
    }
}
