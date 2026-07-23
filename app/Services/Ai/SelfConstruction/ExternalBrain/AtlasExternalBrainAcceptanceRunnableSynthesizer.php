<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Generates runnable acceptance criteria from allowed implementation
 * and test files, preventing non-runnable or target-unbound task specs
 * before enqueue.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasExternalBrainAcceptanceRunnableSynthesizer
{
    public const SCHEMA = 'atlas.self_construction.external_brain_acceptance_runnable_synthesizer.v1';

    public const DEFICIENCY_NO_TEST_FILE = 'no_test_file';
    public const DEFICIENCY_NO_IMPLEMENTATION_FILE = 'no_implementation_file';
    public const DEFICIENCY_TEST_NOT_IN_TESTS_DIR = 'test_not_in_tests_dir';

    /**
     * @param  array<string, mixed>  $input  {allowed_files: list<string>}
     * @return array<string, mixed>
     */
    public function synthesize(array $input): array
    {
        $allowedFiles = array_values(array_filter(array_map('strval', (array) ($input['allowed_files'] ?? []))));

        $testFiles = [];
        $implFiles = [];
        $deficiencies = [];

        foreach ($allowedFiles as $file) {
            if (str_starts_with($file, 'tests/')) {
                $testFiles[] = $file;
            } elseif (str_starts_with($file, 'app/')) {
                $implFiles[] = $file;
            }
        }

        if ($testFiles === []) {
            $deficiencies[] = self::DEFICIENCY_NO_TEST_FILE;
        }
        if ($implFiles === []) {
            $deficiencies[] = self::DEFICIENCY_NO_IMPLEMENTATION_FILE;
        }

        $runnableCriteria = [];
        foreach ($testFiles as $testFile) {
            $testClass = $this->extractTestClass($testFile);
            if ($testClass !== null) {
                $runnableCriteria[] = '/opt/homebrew/bin/php artisan test --filter='.$testClass;
            }
        }

        if ($runnableCriteria === [] && $testFiles !== []) {
            // Fallback: use the test file path directly
            foreach ($testFiles as $testFile) {
                $runnableCriteria[] = '/opt/homebrew/bin/php artisan test '.$testFile;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'runnable' => $deficiencies === [] && $runnableCriteria !== [],
            'runnable_acceptance_criteria' => $runnableCriteria,
            'test_files' => $testFiles,
            'implementation_files' => $implFiles,
            'deficiencies' => $deficiencies,
            'target_bound' => $implFiles !== [],
        ];
    }

    private function extractTestClass(string $testFile): ?string
    {
        // Extract class name from path: tests/Unit/Foo/BarTest.php -> BarTest
        $basename = basename($testFile, '.php');
        if (! str_ends_with($basename, 'Test')) {
            return null;
        }

        return $basename;
    }
}
