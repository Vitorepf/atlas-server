<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskServingDuplicateIdAdmissionGuard;
use PHPUnit\Framework\TestCase;

final class AtlasTaskServingDuplicateIdAdmissionGuardTest extends TestCase
{
    private AtlasTaskServingDuplicateIdAdmissionGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new AtlasTaskServingDuplicateIdAdmissionGuard;
    }

    private function spec(string $id, string $objective): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => $objective,
            'allowed_files' => ["app/Services/{$id}.php"],
            'acceptance_criteria' => ["test {$id}"],
        ];
    }

    // ── AC: duplicate id with different hash rejects the batch ──

    public function test_duplicate_id_with_different_content_rejects_batch(): void
    {
        $result = $this->guard->admit([
            'specs' => [
                $this->spec('task-1', 'do thing A'),
                $this->spec('task-1', 'do thing B'),
            ],
        ]);

        $this->assertSame(AtlasTaskServingDuplicateIdAdmissionGuard::VERDICT_REJECT, $result['verdict']);
        $this->assertTrue($result['blocks_enqueue']);
        $this->assertSame(1, $result['admitted_count']);
        $this->assertSame(1, $result['duplicate_count']);
        $this->assertSame('duplicate_id_with_different_content', $result['duplicate_violations'][0]['reason']);
    }

    // ── AC: duplicate id with same content is idempotent ──

    public function test_duplicate_id_with_same_content_is_idempotent(): void
    {
        $spec = $this->spec('task-1', 'do thing A');

        $result = $this->guard->admit([
            'specs' => [$spec, $spec],
        ]);

        $this->assertSame(AtlasTaskServingDuplicateIdAdmissionGuard::VERDICT_ADMIT, $result['verdict']);
        $this->assertFalse($result['blocks_enqueue']);
        $this->assertSame(1, $result['admitted_count']);
        $this->assertSame(0, $result['duplicate_count']);
    }

    // ── AC: unique ids enqueue normally ──

    public function test_unique_ids_enqueue_normally(): void
    {
        $result = $this->guard->admit([
            'specs' => [
                $this->spec('task-1', 'do thing A'),
                $this->spec('task-2', 'do thing B'),
                $this->spec('task-3', 'do thing C'),
            ],
        ]);

        $this->assertSame(AtlasTaskServingDuplicateIdAdmissionGuard::VERDICT_ADMIT, $result['verdict']);
        $this->assertSame(3, $result['admitted_count']);
        $this->assertSame(0, $result['duplicate_count']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->guard->admit(['specs' => []]);

        $this->assertSame(AtlasTaskServingDuplicateIdAdmissionGuard::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('admitted_specs', $result);
        $this->assertArrayHasKey('duplicate_violations', $result);
        $this->assertArrayHasKey('blocks_enqueue', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'specs' => [
                $this->spec('task-1', 'do thing A'),
                $this->spec('task-2', 'do thing B'),
            ],
        ];

        $a = $this->guard->admit($input);
        $b = $this->guard->admit($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
