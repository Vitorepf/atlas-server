<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Maestro\ClosedLoop;

use App\Services\Ai\SelfConstruction\Maestro\ClosedLoop\AtlasMaestroBlockedBacklogLearningRouter;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroBlockedBacklogLearningRouterTest extends TestCase
{
    private AtlasMaestroBlockedBacklogLearningRouter $router;

    protected function setUp(): void
    {
        $this->router = new AtlasMaestroBlockedBacklogLearningRouter;
    }

    public function test_repeated_forbidden_targets_retire(): void
    {
        $result = $this->router->route([
            ['task_id' => 't1', 'block_reason' => 'forbidden_target', 'occurrence_count' => 3],
        ]);

        $this->assertSame('retire', $result['routes'][0]['route']);
    }

    public function test_missing_scope_repairs(): void
    {
        $result = $this->router->route([
            ['task_id' => 't1', 'block_reason' => 'missing_scope_files', 'occurrence_count' => 1],
        ]);

        $this->assertSame('repair', $result['routes'][0]['route']);
    }

    public function test_duplicate_satisfied_packets_give_back(): void
    {
        $result = $this->router->route([
            ['task_id' => 't1', 'block_reason' => 'duplicate_already_satisfied', 'occurrence_count' => 1],
        ]);

        $this->assertSame('give_back', $result['routes'][0]['route']);
    }

    public function test_unknown_blockers_request_learning(): void
    {
        $result = $this->router->route([
            ['task_id' => 't1', 'block_reason' => 'unknown_error', 'occurrence_count' => 1],
        ]);

        $this->assertSame('learning', $result['routes'][0]['route']);
    }

    public function test_schema_present(): void
    {
        $result = $this->router->route([]);
        $this->assertSame(AtlasMaestroBlockedBacklogLearningRouter::SCHEMA, $result['schema']);
    }
}
