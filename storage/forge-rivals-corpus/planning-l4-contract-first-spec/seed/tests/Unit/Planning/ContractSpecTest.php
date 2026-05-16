<?php

declare(strict_types=1);

namespace Tests\Unit\Planning;

use PHPUnit\Framework\TestCase;

final class ContractSpecTest extends TestCase
{
    private const CONTRACT_MD = __DIR__.'/../../../docs/planning/inbox/feature.contract.md';

    private const EXAMPLES_JSON = __DIR__.'/../../../docs/planning/inbox/feature.contract.examples.json';

    public function test_artefacts_exist(): void
    {
        $this->assertFileExists(self::CONTRACT_MD);
        $this->assertFileExists(self::EXAMPLES_JSON);
    }

    public function test_contract_declares_dto_invariants_errors_and_compat(): void
    {
        $md = (string) file_get_contents(self::CONTRACT_MD);
        $this->assertMatchesRegularExpression('/^##\s+DTO/mi', $md, 'missing ## DTO section');
        $this->assertMatchesRegularExpression('/^##\s+Invariants/mi', $md, 'missing ## Invariants section');
        $this->assertMatchesRegularExpression('/^##\s+Error modes/mi', $md, 'missing ## Error modes section');
        $this->assertMatchesRegularExpression('/^##\s+Backward[- ]compatibility/mi', $md, 'missing backward-compat section');

        $errorCodes = $this->extractErrorCodes($md);
        $this->assertGreaterThanOrEqual(2, count($errorCodes), 'must declare at least 2 error codes');

        $invariants = $this->extractInvariants($md);
        $this->assertGreaterThanOrEqual(2, count($invariants), 'must declare at least 2 invariants');
    }

    public function test_examples_cover_valid_and_invalid_paths(): void
    {
        $examples = json_decode((string) file_get_contents(self::EXAMPLES_JSON), true);
        $this->assertIsArray($examples);

        $valid = array_filter($examples, static fn (array $e): bool => ($e['expect']['kind'] ?? '') === 'ok');
        $invalid = array_filter($examples, static fn (array $e): bool => ($e['expect']['kind'] ?? '') === 'error');

        $this->assertGreaterThanOrEqual(3, count($valid), 'need ≥3 valid examples');
        $this->assertGreaterThanOrEqual(2, count($invalid), 'need ≥2 invalid examples');

        $errorCodes = $this->extractErrorCodes((string) file_get_contents(self::CONTRACT_MD));
        foreach ($invalid as $example) {
            $code = (string) ($example['expect']['error_code'] ?? '');
            $this->assertContains($code, $errorCodes, "invalid example references unknown error_code {$code}");
        }
    }

    public function test_examples_have_named_payloads(): void
    {
        $examples = json_decode((string) file_get_contents(self::EXAMPLES_JSON), true);
        foreach ($examples as $idx => $example) {
            $this->assertArrayHasKey('name', $example, "example #{$idx} missing name");
            $this->assertArrayHasKey('payload', $example, "example #{$idx} missing payload");
        }
    }

    /** @return list<string> */
    private function extractErrorCodes(string $md): array
    {
        $codes = [];
        foreach (preg_split('/\R/', $md) ?: [] as $line) {
            if (preg_match('/^- error_code:\s*([A-Z][A-Z0-9_]+)/', $line, $m)) {
                $codes[] = $m[1];
            }
        }

        return array_values(array_unique($codes));
    }

    /** @return list<string> */
    private function extractInvariants(string $md): array
    {
        $out = [];
        foreach (preg_split('/\R/', $md) ?: [] as $line) {
            if (preg_match('/^- invariant:\s*(.+)$/', $line, $m)) {
                $out[] = trim($m[1]);
            }
        }

        return $out;
    }
}
