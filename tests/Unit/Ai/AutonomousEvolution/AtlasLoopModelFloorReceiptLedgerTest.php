<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopModelFloorReceiptLedger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Proves the model-floor receipt ledger: full receipt schema with null encoded (never omitted), an append-only
 * surface that refuses a tampered/truncated ledger, and a sha256 chain that pinpoints the broken line.
 */
final class AtlasLoopModelFloorReceiptLedgerTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-floor-'.bin2hex(random_bytes(6)).'/floor-receipts.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path.'.head');
        @rmdir(dirname($this->path));
        parent::tearDown();
    }

    private function ledger(): AtlasLoopModelFloorReceiptLedger
    {
        return new AtlasLoopModelFloorReceiptLedger($this->path);
    }

    /** @return array<string,mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'master_enabled' => true,
            'frozen_bundle_hash' => 'bundle-abc',
            'anti_farm_verdict' => 'eligible',
            'triangulator_verdict' => 'agree',
            'rollback_decision' => 'keep',
            'hard_case_trigger' => null, // intentionally null
            'timestamp_utc' => '2026-06-24T00:00:00+00:00',
        ], $overrides);
    }

    public function test_receipt_carries_full_schema_with_null_encoded_not_omitted(): void
    {
        $line = $this->ledger()->append($this->payload());

        foreach (['master_enabled', 'frozen_bundle_hash', 'anti_farm_verdict', 'triangulator_verdict', 'rollback_decision', 'hard_case_trigger', 'timestamp_utc'] as $field) {
            $this->assertArrayHasKey($field, $line, "field {$field} present");
        }
        $this->assertNull($line['hard_case_trigger']);

        // null fields are encoded as JSON null on disk, never dropped.
        $raw = trim((string) file_get_contents($this->path));
        $this->assertStringContainsString('"hard_case_trigger":null', $raw);
        $this->assertArrayHasKey('prev_sha256', $line);
        $this->assertArrayHasKey('sha256', $line);
    }

    public function test_chain_links_and_verifies_ok(): void
    {
        $ledger = $this->ledger();
        $first = $ledger->append($this->payload(['frozen_bundle_hash' => 'b1']));
        $second = $ledger->append($this->payload(['frozen_bundle_hash' => 'b2']));

        $this->assertSame('', $first['prev_sha256'], 'genesis has empty prev');
        $this->assertSame($first['sha256'], $second['prev_sha256'], 'second chains to first');
        $this->assertSame('ok', $ledger->verifyChain());
    }

    public function test_mutated_byte_breaks_chain_at_exact_line(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->payload(['frozen_bundle_hash' => 'b1']));
        $ledger->append($this->payload(['frozen_bundle_hash' => 'MUTATE_ME']));
        $ledger->append($this->payload(['frozen_bundle_hash' => 'b3']));

        // Flip a byte inside line 2's payload on disk.
        $raw = (string) file_get_contents($this->path);
        $tampered = str_replace('MUTATE_ME', 'MUTATE_MX', $raw);
        $this->assertNotSame($raw, $tampered);
        file_put_contents($this->path, $tampered);

        $this->assertSame('broken_at_line_2', $ledger->verifyChain());
    }

    public function test_append_only_refuses_truncated_ledger(): void
    {
        $ledger = $this->ledger();
        $ledger->append($this->payload(['frozen_bundle_hash' => 'b1']));
        $ledger->append($this->payload(['frozen_bundle_hash' => 'b2']));

        // External truncation: drop the last receipt line.
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->path)), static fn ($l): bool => trim($l) !== ''));
        file_put_contents($this->path, $lines[0]."\n");

        $this->expectException(RuntimeException::class);
        $ledger->append($this->payload(['frozen_bundle_hash' => 'b3']));
    }

    public function test_no_mutation_api_is_exposed(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(AtlasLoopModelFloorReceiptLedger::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );

        foreach ($methods as $name) {
            $this->assertDoesNotMatchRegularExpression('/^(update|delete|truncate|mutate|overwrite|edit|remove|rewrite)/i', $name, "append-only: no mutation method '{$name}'");
        }
        $this->assertContains('append', $methods);
        $this->assertContains('verifyChain', $methods);
    }
}
