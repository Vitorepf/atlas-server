<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\WorkerSwarm;

use App\Services\Ai\SelfConstruction\WorkerSwarm\AtlasSelfConstructionWorkerScopedExecutionEnvelope;
use Tests\TestCase;

final class AtlasSelfConstructionWorkerScopedExecutionEnvelopeTest extends TestCase
{
    private function input(array $overrides = []): array
    {
        return $overrides + [
            'task_id' => 'pkt-1',
            'lease_id' => 'lease-xyz',
            'allowed_files' => ['app/Foo.php', 'tests/FooTest.php'],
            'forbidden_files' => ['config/atlas.php'],
            'gates' => ['phpunit', 'php-lint'],
            'evidence_requirements' => ['phpunit_green'],
            'rollback_plan' => ['mode' => 'revert_commit'],
            'worker_capability' => ['worker_id' => 'w-alpha', 'capabilities' => ['php']],
        ];
    }

    public function test_valid_envelope_emits_canonical_keys_and_a_hash(): void
    {
        $env = (new AtlasSelfConstructionWorkerScopedExecutionEnvelope)->compose($this->input());

        $this->assertTrue($env['valid']);
        $this->assertSame([], $env['blockers']);
        foreach (['task_id', 'lease_id', 'allowed_files', 'forbidden_files', 'gates', 'evidence_requirements', 'rollback_plan', 'worker_capability', 'envelope_hash'] as $key) {
            $this->assertArrayHasKey($key, $env);
        }
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $env['envelope_hash']);
    }

    public function test_missing_lease_id_blocks(): void
    {
        $env = (new AtlasSelfConstructionWorkerScopedExecutionEnvelope)->compose($this->input(['lease_id' => '']));
        $this->assertFalse($env['valid']);
        $this->assertContains('lease_id_missing', $env['blockers']);
    }

    public function test_empty_allowed_files_blocks(): void
    {
        $env = (new AtlasSelfConstructionWorkerScopedExecutionEnvelope)->compose($this->input(['allowed_files' => []]));
        $this->assertFalse($env['valid']);
        $this->assertContains('allowed_files_empty', $env['blockers']);
    }

    public function test_forbidden_overlap_blocks(): void
    {
        $env = (new AtlasSelfConstructionWorkerScopedExecutionEnvelope)->compose($this->input([
            'allowed_files' => ['app/Foo.php', 'config/atlas.php'],
            'forbidden_files' => ['config/atlas.php'],
        ]));
        $this->assertFalse($env['valid']);
        $blockerStr = implode('|', $env['blockers']);
        $this->assertStringContainsString('allowed_forbidden_overlap:config/atlas.php', $blockerStr);
    }

    public function test_missing_gates_blocks(): void
    {
        $env = (new AtlasSelfConstructionWorkerScopedExecutionEnvelope)->compose($this->input(['gates' => []]));
        $this->assertFalse($env['valid']);
        $this->assertContains('gates_missing', $env['blockers']);
    }

    public function test_envelope_hash_is_stable_across_two_calls_with_identical_input(): void
    {
        $svc = new AtlasSelfConstructionWorkerScopedExecutionEnvelope;
        $a = $svc->compose($this->input());
        $b = $svc->compose($this->input());

        $this->assertSame($a['envelope_hash'], $b['envelope_hash']);
    }

    public function test_envelope_hash_changes_when_lease_changes(): void
    {
        $svc = new AtlasSelfConstructionWorkerScopedExecutionEnvelope;
        $a = $svc->compose($this->input(['lease_id' => 'lease-1']));
        $b = $svc->compose($this->input(['lease_id' => 'lease-2']));

        $this->assertNotSame($a['envelope_hash'], $b['envelope_hash']);
    }

    public function test_envelope_hash_is_independent_of_associative_key_order(): void
    {
        $svc = new AtlasSelfConstructionWorkerScopedExecutionEnvelope;
        $a = $svc->compose($this->input([
            'rollback_plan' => ['mode' => 'revert_commit', 'timeout' => 60],
            'worker_capability' => ['capabilities' => ['php'], 'worker_id' => 'w-alpha'],
        ]));
        $b = $svc->compose($this->input([
            'rollback_plan' => ['timeout' => 60, 'mode' => 'revert_commit'],
            'worker_capability' => ['worker_id' => 'w-alpha', 'capabilities' => ['php']],
        ]));

        $this->assertSame($a['envelope_hash'], $b['envelope_hash'], 'hash must be independent of associative key order');
    }
}
