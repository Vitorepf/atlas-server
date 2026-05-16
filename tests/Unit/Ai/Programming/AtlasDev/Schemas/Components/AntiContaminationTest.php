<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use PHPUnit\Framework\TestCase;

final class AntiContaminationTest extends TestCase
{
    /**
     * Verifies Schemas (Support + Components + Contracts) stay clean of competitive
     * evaluation tokens. The single allow-listed exception is the canonical safety
     * field `no_hidden_benchmark_instruction` defined in the QualityChecks contract;
     * that field exists precisely to enforce the absence of benchmark behaviour.
     */
    public function test_no_competitive_evaluation_tokens_in_schemas(): void
    {
        $base = realpath(__DIR__.'/../../../../../../../app/Services/Ai/Programming/AtlasDev/Schemas');
        $this->assertNotFalse($base, 'Schemas directory must exist for anti-contamination scan');

        $files = $this->phpFilesIn($base);
        $this->assertNotEmpty($files);

        $forbiddenTokens = [
            'Rivals', 'rivals',
            'OpusChallenge', 'opus_challenge', 'opus challenge',
            'arm_a', 'arm_b',
            'cost_normalized', 'cost-normalized',
            'PrivateOracle', 'private_oracle',
        ];

        $allowedExceptions = [
            'no_hidden_benchmark_instruction',
            'noHiddenBenchmarkInstruction',
        ];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            foreach ($allowedExceptions as $exception) {
                $contents = str_replace($exception, '__safe_token__', $contents);
            }

            foreach ($forbiddenTokens as $token) {
                $this->assertStringNotContainsString(
                    $token,
                    $contents,
                    "Forbidden token '{$token}' found in {$file}",
                );
            }
        }
    }

    /**
     * @return list<string>
     */
    private function phpFilesIn(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        $files = [];
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
