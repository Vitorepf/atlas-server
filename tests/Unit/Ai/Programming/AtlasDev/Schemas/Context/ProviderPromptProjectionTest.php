<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Context;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\PromptSections;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\QualityChecks;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\ProviderPromptProjection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProviderPromptProjectionTest extends TestCase
{
    private function loadFixture(): array
    {
        $raw = file_get_contents(__DIR__.'/../../../../../../Fixtures/AtlasDev/context/valid_provider_prompt_projection.json');
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function makeProjection(array $overrides = []): ProviderPromptProjection
    {
        return ProviderPromptProjection::fromArray(array_replace($this->loadFixture(), $overrides));
    }

    public function test_constructs_from_fixture_and_implements_contract(): void
    {
        $p = $this->makeProjection();

        $this->assertInstanceOf(AtlasDevSchemaContract::class, $p);
        $this->assertSame('atlas.dev.provider_prompt_projection.v1', $p->schemaVersion());
        $this->assertInstanceOf(PromptSections::class, $p->sections);
        $this->assertInstanceOf(QualityChecks::class, $p->qualityChecks);
        $this->assertNotSame('', $p->renderedPromptText);
        $this->assertNotSame('', $p->renderedPromptHash);
        $this->assertTrue($p->isSendable());
    }

    public function test_requires_rendered_prompt_text(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeProjection([
            'rendered_prompt_text' => '',
        ]);
    }

    public function test_requires_rendered_prompt_hash(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeProjection([
            'rendered_prompt_hash' => '',
        ]);
    }

    public function test_rendered_prompt_text_blocks_sendability_when_empty(): void
    {
        // Construction enforces non-empty; this test pins the isSendable() contract
        // so future relaxations (e.g. making text nullable again) cannot pass.
        $p = $this->makeProjection();
        $this->assertTrue($p->isSendable());
        $this->assertNotSame('', $p->renderedPromptText);
        $this->assertNotSame('', $p->renderedPromptHash);
    }

    public function test_canonical_array_is_sorted_and_deterministic(): void
    {
        $p = $this->makeProjection();

        $canonical = $p->toCanonicalArray();
        $keys = array_keys($canonical);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);

        $this->assertSame($canonical, $p->toCanonicalArray());
    }

    public function test_round_trip_through_canonical_array(): void
    {
        $p = $this->makeProjection();
        $rebuilt = ProviderPromptProjection::fromArray($p->toCanonicalArray());

        $this->assertSame($p->toCanonicalArray(), $rebuilt->toCanonicalArray());
        $this->assertSame($p->hash(), $rebuilt->hash());
    }

    public function test_hash_is_deterministic(): void
    {
        $p = $this->makeProjection();
        $this->assertSame($p->hash(), $p->hash());
    }

    public function test_hash_changes_when_quality_check_flips(): void
    {
        $base = $this->makeProjection();
        $degraded = $this->makeProjection([
            'quality_checks' => array_replace(
                $this->loadFixture()['quality_checks'],
                ['no_hidden_benchmark_instruction' => false],
            ),
        ]);

        $this->assertNotSame($base->hash(), $degraded->hash());
    }

    public function test_hash_ignores_prompt_projection_hash_field_itself(): void
    {
        $a = $this->makeProjection(['prompt_projection_hash' => 'first']);
        $b = $this->makeProjection(['prompt_projection_hash' => 'second']);

        $this->assertSame($a->hash(), $b->hash());
    }

    public function test_quality_checks_block_sendability_when_any_fails(): void
    {
        $base = $this->loadFixture()['quality_checks'];

        foreach (array_keys($base) as $flag) {
            $p = $this->makeProjection([
                'quality_checks' => array_replace($base, [$flag => false]),
            ]);

            $this->assertFalse($p->isSendable(), "flipping {$flag} must block sendability");
            $this->assertContains($flag, $p->qualityChecks->failedChecks());
        }
    }

    public function test_quality_checks_all_passed_helper(): void
    {
        $checks = QualityChecks::allPassing();

        $this->assertTrue($checks->allPassed());
        $this->assertSame([], $checks->failedChecks());
    }

    public function test_provider_safe_blocks_sendability_independently(): void
    {
        $p = $this->makeProjection(['provider_safe' => false]);

        $this->assertFalse($p->isProviderSafe());
        $this->assertFalse($p->isSendable());
    }

    public function test_prompt_sections_detect_file_rule_conflict(): void
    {
        $sections = $this->loadFixture()['sections'];
        $sections['forbidden_files'][] = $sections['allowed_files'][0];

        $sectionsObj = PromptSections::fromArray($sections);
        $this->assertTrue($sectionsObj->hasFileRuleConflict());
    }

    public function test_prompt_sections_detect_missing_required_sections(): void
    {
        $sections = $this->loadFixture()['sections'];
        $sections['objective'] = '';

        $sectionsObj = PromptSections::fromArray($sections);
        $this->assertFalse($sectionsObj->hasAllRequiredSections());
    }

    public function test_provider_safe_projection_matches_canonical_when_safe(): void
    {
        $p = $this->makeProjection();

        $this->assertTrue($p->isProviderSafe());
        $this->assertSame($p->toCanonicalArray(), $p->toProviderSafeArray());
    }

    public function test_provider_safe_projection_redacts_rendered_text_when_unsafe(): void
    {
        $p = $this->makeProjection(['provider_safe' => false]);

        $canonical = $p->toCanonicalArray();
        $safe = $p->toProviderSafeArray();

        $this->assertNotSame($canonical['rendered_prompt_text'], $safe['rendered_prompt_text']);
        $this->assertStringStartsWith('[redacted:', $safe['rendered_prompt_text']);
        $this->assertStringContainsString('provider_prompt_projection.rendered_prompt_text', $safe['rendered_prompt_text']);

        // Hash stays intact so auditors can confirm the original existed.
        $this->assertSame($canonical['rendered_prompt_hash'], $safe['rendered_prompt_hash']);

        $this->assertFalse($p->isSendable(), 'unsafe projection must not be sendable');
    }
}
