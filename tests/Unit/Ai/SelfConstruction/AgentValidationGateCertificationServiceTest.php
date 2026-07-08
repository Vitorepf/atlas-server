<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Support\AgentValidationGateCertificationService;
use PHPUnit\Framework\TestCase;

final class AgentValidationGateCertificationServiceTest extends TestCase
{
    private AgentValidationGateCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new AgentValidationGateCertificationService();
    }

    public function test_schema_only_output_is_proxy_evidence(): void
    {
        $result = $this->service->certifyEvidence([
            'evidence_type' => 'schema',
        ]);

        self::assertFalse($result['certified']);
        self::assertTrue($result['is_proxy']);
        self::assertEquals('proxy_evidence', $result['evidence_class']);
        self::assertContains('schema_only_output', $result['reasons']);
        self::assertContains('proxy_evidence_type', $result['reasons']);
    }

    public function test_vague_success_text_is_proxy_evidence(): void
    {
        $result = $this->service->certifyEvidence([
            'command' => 'php artisan test',
            'target_path' => 'tests/ExampleTest.php',
            'result' => 'it works',
        ]);

        self::assertFalse($result['certified']);
        self::assertTrue($result['is_proxy']);
        self::assertContains('vague_success_text', $result['reasons']);
    }

    public function test_concrete_command_target_and_fresh_result_pass(): void
    {
        $result = $this->service->certifyEvidence([
            'command' => '/opt/homebrew/bin/php artisan test --filter=ExampleTest',
            'target_path' => 'app/Services/ExampleService.php',
            'result' => 'PHPUnit 11.0: 5 tests passed, 0 failures, 0 warnings in 0.12s',
            'timestamp' => now()->toIso8601String(),
        ]);

        self::assertTrue($result['certified']);
        self::assertFalse($result['is_proxy']);
        self::assertEquals('executable_proof', $result['evidence_class']);
        self::assertEquals([], $result['reasons']);
    }

    public function test_stale_receipt_fails_freshness(): void
    {
        $result = $this->service->certifyEvidence([
            'command' => 'php artisan test',
            'target_path' => 'tests/ExampleTest.php',
            'result' => 'PHPUnit 11.0: 5 tests passed, 0 failures in 0.12s',
            'timestamp' => now()->subHours(30)->toIso8601String(),
        ]);

        self::assertFalse($result['certified']);
        self::assertTrue($result['is_proxy']);
        self::assertContains('stale_receipt', $result['reasons']);
    }

    public function test_proxy_evidence_type_is_detected(): void
    {
        $result = $this->service->certifyEvidence([
            'evidence_type' => 'notes',
            'result' => 'some notes about the implementation',
        ]);

        self::assertFalse($result['certified']);
        self::assertTrue($result['is_proxy']);
        self::assertContains('proxy_evidence_type', $result['reasons']);
    }
}