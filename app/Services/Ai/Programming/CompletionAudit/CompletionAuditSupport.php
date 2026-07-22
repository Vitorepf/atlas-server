<?php

namespace App\Services\Ai\Programming\CompletionAudit;

class CompletionAuditSupport
{
    /**
     * @param  array<int,string>  $expectedMethods
     * @return array{present_methods:array<int,string>,missing_methods:array<int,string>}
     */
    public function scanTestCoverage(string $testFile, array $expectedMethods): array
    {
        if (! is_file($testFile)) {
            return ['present_methods' => [], 'missing_methods' => $expectedMethods];
        }

        $source = (string) file_get_contents($testFile);
        $present = [];
        $missing = [];
        foreach ($expectedMethods as $method) {
            if (str_contains($source, 'function '.$method.'(')) {
                $present[] = $method;
            } else {
                $missing[] = $method;
            }
        }

        return ['present_methods' => $present, 'missing_methods' => $missing];
    }
}
