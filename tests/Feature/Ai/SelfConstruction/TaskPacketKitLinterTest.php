<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Tests\TestCase;

/**
 * K3 (Obra #18) — the order linter rejects malformed kit orders AT THE SOURCE
 * (a real path exists on disk, a sigla resolves, a pre-written test rides),
 * while leaving legacy packets untouched. No gate-operador rule.
 */
class TaskPacketKitLinterTest extends TestCase
{
    private const REAL_FILE = 'app/Services/Ai/Memory/AtlasMemoryRegistryService.php';

    private const REAL_TEST = 'tests/Feature/Ai/Brain/EvolutionDiaryTest.php';

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function inspectKitOrder(array $extra): array
    {
        return (new AtlasTaskPacketQualityInspector)->inspect(array_merge([
            'objective' => 'refactor X into Y',
            'allowed_files' => ['app/Services/Ai/NewThing.php'],
            'forbidden_files' => [self::REAL_TEST],
            'acceptance_criteria' => ['php artisan test'],
            'required_evidence' => ['tests_or_gates_result'],
            'acceptance_test_ref' => ['path' => self::REAL_TEST, 'hash' => 'h'],
        ], $extra));
    }

    public function test_valid_kit_order_trips_no_kit_deficiency(): void
    {
        $result = $this->inspectKitOrder([
            'glossary' => ['REG' => self::REAL_FILE],
            'frozen_callers' => [['caller' => self::REAL_FILE.':49', 'destination' => 'stays additive']],
        ]);

        foreach (['glossary_sigla_without_path', 'cited_path_missing', 'kit_order_missing_pre_written_test', 'acceptance_cites_unlisted_file'] as $code) {
            $this->assertNotContains($code, $result['deficiencies'], "valid kit order must not trip {$code}");
        }
    }

    public function test_glossary_sigla_without_path_blocks(): void
    {
        $result = $this->inspectKitOrder(['glossary' => ['ORPHAN' => '']]);
        $this->assertContains('glossary_sigla_without_path', $result['blocking_deficiencies']);
        $this->assertFalse($result['self_sufficient']);
    }

    public function test_phantom_cited_path_blocks(): void
    {
        $result = $this->inspectKitOrder(['glossary' => ['GHOST' => 'app/Services/Ai/DoesNotExistPhantom.php']]);
        $this->assertContains('cited_path_missing', $result['blocking_deficiencies']);
    }

    public function test_frozen_caller_phantom_blocks(): void
    {
        $result = $this->inspectKitOrder(['frozen_callers' => [['caller' => 'app/Ghost.php:10', 'destination' => 'x']]]);
        $this->assertContains('cited_path_missing', $result['blocking_deficiencies']);
    }

    public function test_kit_order_without_pre_written_test_blocks(): void
    {
        // A kit order (declares stop_and_return) but no acceptance_test_ref.
        $result = (new AtlasTaskPacketQualityInspector)->inspect([
            'objective' => 'do X',
            'allowed_files' => ['app/Services/Ai/NewThing.php'],
            'acceptance_criteria' => ['php artisan test'],
            'required_evidence' => ['tests_or_gates_result'],
            'stop_and_return' => ['pare se sigla não resolve'],
        ]);
        $this->assertContains('kit_order_missing_pre_written_test', $result['blocking_deficiencies']);
    }

    public function test_acceptance_citing_file_outside_scope_blocks(): void
    {
        $result = $this->inspectKitOrder([
            'acceptance_criteria' => ['php artisan test app/Services/Ai/SomewhereElse.php'],
        ]);
        $this->assertContains('acceptance_cites_unlisted_file', $result['blocking_deficiencies']);
    }

    public function test_legacy_packet_without_kit_fields_is_untouched(): void
    {
        $result = (new AtlasTaskPacketQualityInspector)->inspect([
            'objective' => 'ordinary task',
            'allowed_files' => ['app/Services/Ai/NewThing.php'],
            'acceptance_criteria' => ['php artisan test'],
            'required_evidence' => ['tests_or_gates_result'],
        ]);

        foreach (['glossary_sigla_without_path', 'cited_path_missing', 'kit_order_missing_pre_written_test', 'acceptance_cites_unlisted_file'] as $code) {
            $this->assertNotContains($code, $result['deficiencies'], "legacy packet must not trip {$code}");
        }
    }
}
