<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\ContextIntelligence;

use App\Services\Ai\ContextIntelligence\AtlasContextIntelligenceCertificationService;
use App\Services\Ai\ContextIntelligence\AtlasContextIntelligenceService;
use App\Services\Ai\ContextIntelligence\ContextIntelligencePayloadHash;
use App\Services\Ai\LongHorizon\AtlasTeosFinalCertificationService;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class AtlasContextIntelligenceCertificationServiceTest extends TestCase
{
    public function test_certification_passes_with_teos_and_dev_forge_ready(): void
    {
        $this->bindTeosFinal(AtlasTeosFinalCertificationService::STATUS_READY);

        $payload = app(AtlasContextIntelligenceCertificationService::class)->certify();

        $this->assertSame('atlas.context_intelligence.certification.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame(13, $payload['summary']['total']);
        $this->assertSame(13, $payload['summary']['pass']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['provider_calls_made']);
        $this->assertFalse($payload['claim_policy']['rivals_compared']);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
    }

    public function test_certification_blocks_when_teos_is_not_ready(): void
    {
        $this->bindTeosFinal(AtlasTeosFinalCertificationService::STATUS_BLOCKED, blockers: [['id' => 'fake']]);

        $payload = app(AtlasContextIntelligenceCertificationService::class)->certify();

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('fail', collect($payload['checks'])->firstWhere('id', 'teos_final_certification')['status'] ?? null);
    }

    public function test_certification_accepts_teos_partial_without_blockers(): void
    {
        $this->bindTeosFinal(
            AtlasTeosFinalCertificationService::STATUS_PARTIAL,
            blockers: [],
            warnings: [['id' => 'obra_review']],
        );

        $payload = app(AtlasContextIntelligenceCertificationService::class)->certify();
        $check = collect($payload['checks'])->firstWhere('id', 'teos_final_certification');

        $this->assertSame('passed', $payload['status']);
        $this->assertSame('pass', $check['status'] ?? null);
        $this->assertSame('ready_or_partial_without_blockers', $check['evidence']['acceptance_policy'] ?? null);
        $this->assertSame([['id' => 'obra_review']], $check['evidence']['warnings'] ?? null);
    }

    public function test_command_emits_json_and_strict_passes(): void
    {
        $this->bindTeosFinal(AtlasTeosFinalCertificationService::STATUS_READY);

        $exit = Artisan::call('atlas:context-intelligence:certify', [
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('atlas.context_intelligence.certification.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
    }

    /**
     * @param  list<array<string,mixed>>|null  $blockers
     * @param  list<array<string,mixed>>  $warnings
     */
    private function bindTeosFinal(string $status, ?array $blockers = null, array $warnings = []): void
    {
        /** @var AtlasTeosFinalCertificationService&MockInterface $mock */
        $mock = Mockery::mock(AtlasTeosFinalCertificationService::class);
        $blockers ??= $status === AtlasTeosFinalCertificationService::STATUS_READY ? [] : [['id' => 'fake']];
        $mock->shouldReceive('certify')->andReturn([
            'status' => $status,
            'certification_hash' => 'sha256:teos',
            'summary' => ['total' => 6, 'pass' => $status === AtlasTeosFinalCertificationService::STATUS_READY ? 6 : 5, 'warn' => count($warnings), 'fail' => count($blockers)],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ]);
        $this->instance(AtlasTeosFinalCertificationService::class, $mock);
    }

    /**
     * Build a basic runtime payload with a correctly computed context_certification_hash.
     *
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function buildValidRuntimePayload(array $overrides = []): array
    {
        $body = array_merge([
            'status' => AtlasContextIntelligenceService::STATUS_READY,
            'schema_version' => 'atlas.context_intelligence.runtime.v1',
            'claim_policy' => ['provider_calls_made' => false],
        ], $overrides);

        // If no hash override, compute the real hash.
        if (! array_key_exists('context_certification_hash', $overrides)) {
            $body['context_certification_hash'] = ContextIntelligencePayloadHash::forPayload($body, 'context_certification_hash');
        }

        return $body;
    }

    // ── AC: hash integrity — PASS (untampered payload) ────────────────────────

    public function test_hash_integrity_pass_untampered_payload(): void
    {
        $this->bindTeosFinal(AtlasTeosFinalCertificationService::STATUS_READY);
        $service = app(AtlasContextIntelligenceCertificationService::class);
        $service->injectTestRuntimePayload($this->buildValidRuntimePayload());

        $payload = $service->certify();

        $this->assertSame('passed', $payload['status']);
        $check = collect($payload['checks'])->firstWhere('id', 'runtime_smoke');
        $this->assertNotNull($check);
        $this->assertSame('pass', $check['status']);
        $this->assertArrayHasKey('hash_integrity', $check['evidence']);
        $this->assertSame('pass', $check['evidence']['hash_integrity']['status']);
        $this->assertArrayHasKey('stored_hash', $check['evidence']['hash_integrity']);
        $this->assertArrayHasKey('recomputed_hash', $check['evidence']['hash_integrity']);
        $this->assertSame(
            $check['evidence']['hash_integrity']['stored_hash'],
            $check['evidence']['hash_integrity']['recomputed_hash'],
            'stored and recomputed hash must match for an untampered payload',
        );
    }

    // ── AC: hash integrity — FAIL (fake/stale hash) ───────────────────────────

    public function test_hash_integrity_fail_fake_hash(): void
    {
        $this->bindTeosFinal(AtlasTeosFinalCertificationService::STATUS_READY);
        $service = app(AtlasContextIntelligenceCertificationService::class);
        $service->injectTestRuntimePayload(
            $this->buildValidRuntimePayload(['context_certification_hash' => 'sha256:fake']),
        );

        $payload = $service->certify();

        $this->assertSame('blocked', $payload['status']);
        $check = collect($payload['checks'])->firstWhere('id', 'runtime_smoke');
        $this->assertNotNull($check);
        $this->assertSame('fail', $check['status']);
        $this->assertSame('fail', $check['evidence']['hash_integrity']['status']);
        $this->assertSame('sha256:fake', $check['evidence']['hash_integrity']['stored_hash']);
        $this->assertNotSame(
            $check['evidence']['hash_integrity']['stored_hash'],
            $check['evidence']['hash_integrity']['recomputed_hash'],
            'fake hash must not match the recomputed hash',
        );
    }

    // ── AC: hash integrity — FAIL (body mutated after hashing) ────────────────

    public function test_hash_integrity_fail_body_mutated_after_hash(): void
    {
        // Build the body, hash it, then flip a field to simulate tampering.
        $body = $this->buildValidRuntimePayload();
        $body['flag'] = 'tampered_after_hash';

        $this->bindTeosFinal(AtlasTeosFinalCertificationService::STATUS_READY);
        $service = app(AtlasContextIntelligenceCertificationService::class);
        $service->injectTestRuntimePayload($body);

        $payload = $service->certify();

        $this->assertSame('blocked', $payload['status']);
        $check = collect($payload['checks'])->firstWhere('id', 'runtime_smoke');
        $this->assertNotNull($check);
        $this->assertSame('fail', $check['status']);
        $this->assertSame('fail', $check['evidence']['hash_integrity']['status']);
        $this->assertNotSame(
            $check['evidence']['hash_integrity']['stored_hash'],
            $check['evidence']['hash_integrity']['recomputed_hash'],
            'mutated body must cause hash mismatch',
        );
    }
}
