<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainTaskSpecTranslator;
use Tests\TestCase;

final class AtlasBrainTaskSpecTranslatorTest extends TestCase
{
    private AtlasBrainTaskSpecTranslator $translator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->translator = new AtlasBrainTaskSpecTranslator;
    }

    private function translate(array $overrides = []): array
    {
        return $this->translator->translate(array_merge([
            'objective'   => 'Add missing guard to Foo::bar',
            'target_path' => 'app/Services/Foo.php',
            'obligations' => [],
            'snapshot_id' => 'snap-1',
        ], $overrides));
    }

    // ── AC2: allowed_files from target_path + obligations only ───────────────

    public function test_target_path_appears_in_allowed_files(): void
    {
        $r = $this->translate(['target_path' => 'app/Services/Foo.php']);

        $this->assertContains('app/Services/Foo.php', $r['allowed_files']);
    }

    public function test_obligation_file_path_appears_in_allowed_files(): void
    {
        $r = $this->translate([
            'obligations' => [['file' => 'app/Services/Bar.php']],
        ]);

        $this->assertContains('app/Services/Bar.php', $r['allowed_files']);
    }

    public function test_allowed_files_are_sorted_deterministically(): void
    {
        $r = $this->translate([
            'obligations' => [
                ['file' => 'app/Services/Zzz.php'],
                ['file' => 'app/Services/Aaa.php'],
            ],
        ]);

        $sorted = $r['allowed_files'];
        $copy = $sorted;
        sort($copy, SORT_STRING);
        $this->assertSame($copy, $sorted);
    }

    public function test_path_traversal_is_refused(): void
    {
        $r = $this->translate(['target_path' => 'app/../etc/passwd']);

        $this->assertNotContains('app/../etc/passwd', $r['allowed_files']);
        $this->assertNotContains('../etc/passwd', $r['allowed_files']);
    }

    public function test_ellipsis_path_is_refused(): void
    {
        $r = $this->translate(['target_path' => 'app/.../Foo.php']);

        $this->assertEmpty(array_filter($r['allowed_files'], fn($p) => str_contains($p, '...')));
    }

    public function test_non_php_obligation_value_not_added(): void
    {
        $r = $this->translate([
            'obligations' => [['file' => 'some-doc.txt']],
        ]);

        $this->assertNotContains('some-doc.txt', $r['allowed_files']);
    }

    // ── AC3: test path granted when required but absent ───────────────────────

    public function test_convention_test_path_granted_for_app_target(): void
    {
        $r = $this->translate(['target_path' => 'app/Services/Foo.php']);

        // evidence should include tests_or_gates_result (added for app/ targets)
        $this->assertContains('tests_or_gates_result', $r['evidence_requirements']);
        // mirror path should appear
        $this->assertContains('tests/Unit/Services/FooTest.php', $r['allowed_files']);
    }

    public function test_test_path_not_duplicated_when_obligation_already_supplies_one(): void
    {
        $r = $this->translate([
            'target_path' => 'app/Services/Foo.php',
            'obligations' => [['file' => 'tests/Unit/Services/FooTest.php']],
        ]);

        $testPaths = array_filter($r['allowed_files'], fn($p) => str_starts_with($p, 'tests/'));
        $this->assertCount(1, $testPaths);
    }

    public function test_no_test_path_granted_for_non_app_target(): void
    {
        $r = $this->translate(['target_path' => 'config/app.php']);

        $testPaths = array_filter($r['allowed_files'], fn($p) => str_starts_with($p, 'tests/'));
        $this->assertEmpty($testPaths);
    }

    // ── AC4: required fields in translated packet ─────────────────────────────

    public function test_packet_includes_required_seed_gate_fields(): void
    {
        $r = $this->translate();

        foreach (['problem', 'expected_delta', 'value', 'duplicate_key', 'freshness_check', 'anti_proxy'] as $field) {
            $this->assertArrayHasKey($field, $r, "missing field: $field");
            $this->assertNotEmpty($r[$field], "field $field should not be empty");
        }
    }

    public function test_packet_includes_modifies_existing_files_bool(): void
    {
        $r = $this->translate();

        $this->assertArrayHasKey('modifies_existing_files', $r);
        $this->assertIsBool($r['modifies_existing_files']);
    }

    public function test_packet_includes_existing_file_delta(): void
    {
        $r = $this->translate();

        $this->assertArrayHasKey('existing_file_delta', $r);
    }

    // ── deterministic ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = [
            'objective'   => 'Fix guard',
            'target_path' => 'app/Services/Baz.php',
            'obligations' => [],
            'snapshot_id' => 'snap-x',
        ];

        $a = $this->translator->translate($input);
        $b = $this->translator->translate($input);

        $this->assertSame($a['task_packet_id'], $b['task_packet_id']);
        $this->assertSame($a['duplicate_key'],  $b['duplicate_key']);
    }
}
