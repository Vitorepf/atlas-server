<?php

declare(strict_types=1);

namespace Tests\Mutation;

use PHPUnit\Framework\TestCase;

final class MutationBaselineTest extends TestCase
{
    private const BASELINE = __DIR__.'/baseline.json';

    private const DECISION_DOC = __DIR__.'/../../docs/quality/mutation-survivors-decision.md';

    public function test_baseline_exists_and_has_required_keys(): void
    {
        $this->assertFileExists(self::BASELINE);
        $data = json_decode((string) file_get_contents(self::BASELINE), true);
        $this->assertIsArray($data);
        foreach (['tool', 'module_under_test', 'msi', 'killed', 'escaped', 'sample_size', 'generated_at'] as $key) {
            $this->assertArrayHasKey($key, $data, "baseline.json missing {$key}");
        }
        $this->assertIsFloat($data['msi'] + 0.0);
    }

    public function test_decision_doc_exists_and_lists_one_section_per_escaped_mutant(): void
    {
        $this->assertFileExists(self::DECISION_DOC);
        $data = json_decode((string) file_get_contents(self::BASELINE), true);
        $survivors = (array) ($data['survivors'] ?? []);
        $doc = (string) file_get_contents(self::DECISION_DOC);

        foreach ($survivors as $survivor) {
            $id = (string) ($survivor['id'] ?? '');
            $this->assertStringContainsString(
                $id,
                $doc,
                "survivor {$id} not documented in mutation-survivors-decision.md",
            );
        }
    }
}
