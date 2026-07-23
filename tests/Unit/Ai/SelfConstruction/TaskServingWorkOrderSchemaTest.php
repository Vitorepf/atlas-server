<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * K1 (Obra #18) — the work-order schema is an ADDITIVE extension of the served
 * task-packet envelope. projectTask() is a pure projection (no instance deps),
 * so we exercise it directly.
 */
final class TaskServingWorkOrderSchemaTest extends TestCase
{
    /**
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function project(array $packet): array
    {
        $service = (new ReflectionClass(AtlasTaskServingService::class))->newInstanceWithoutConstructor();
        $method = (new ReflectionClass($service))->getMethod('projectTask');

        /** @var array<string,mixed> */
        return $method->invoke($service, ['queue_entry' => ['task_packet' => $packet]]);
    }

    public function test_new_kit_fields_project_from_the_packet(): void
    {
        $env = $this->project([
            'objective' => 'do X',
            'allowed_files' => ['app/A.php'],
            'forbidden_files' => ['app/B.php'],
            'frozen_callers' => [['caller' => 'app/C.php:10', 'destination' => 'stays additive']],
            'acceptance_test_ref' => ['path' => 'tests/Feature/XTest.php', 'hash' => 'abc123'],
            'stop_and_return' => ['scope creep', ' phantom symbol '],
            'glossary' => ['REG' => 'app/Services/Ai/Memory/AtlasMemoryRegistryService.php'],
            'baseline_artifact' => ['metric' => 'x', 'value' => 0],
        ]);

        $this->assertSame([['caller' => 'app/C.php:10', 'destination' => 'stays additive']], $env['frozen_callers']);
        $this->assertSame(['path' => 'tests/Feature/XTest.php', 'hash' => 'abc123'], $env['acceptance_test_ref']);
        $this->assertSame(['scope creep', 'phantom symbol'], $env['stop_and_return']);
        $this->assertSame(['REG' => 'app/Services/Ai/Memory/AtlasMemoryRegistryService.php'], $env['glossary']);
        $this->assertSame(['metric' => 'x', 'value' => 0], $env['baseline_artifact']);
    }

    public function test_acceptance_test_path_is_merged_into_forbidden_files(): void
    {
        $env = $this->project([
            'forbidden_files' => ['app/B.php'],
            'acceptance_test_ref' => ['path' => 'tests/Feature/XTest.php', 'hash' => 'h'],
        ]);

        $this->assertContains('tests/Feature/XTest.php', $env['forbidden_files'], 'the pre-written test must be forbidden to edit.');
        $this->assertContains('app/B.php', $env['forbidden_files']);
    }

    public function test_glossary_drops_unresolved_entries(): void
    {
        $env = $this->project([
            'glossary' => ['GOOD' => 'app/Real.php', 'BAD' => '', '' => 'app/Nameless.php'],
        ]);

        $this->assertSame(['GOOD' => 'app/Real.php'], $env['glossary']);
    }

    public function test_absent_kit_fields_are_empty_backward_compatible(): void
    {
        $env = $this->project(['objective' => 'y']);

        $this->assertSame([], $env['frozen_callers']);
        $this->assertSame(['path' => '', 'hash' => ''], $env['acceptance_test_ref']);
        $this->assertSame([], $env['stop_and_return']);
        $this->assertSame([], $env['glossary']);
        $this->assertNull($env['baseline_artifact']);
        // legacy fields untouched
        $this->assertSame('y', $env['objective']);
        $this->assertArrayHasKey('delivery_rules', $env);
    }
}
