<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPublicContractExtractor;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainPublicContractExtractorTest extends TestCase
{
    private function extractor(): AtlasExternalBrainPublicContractExtractor
    {
        return new AtlasExternalBrainPublicContractExtractor;
    }

    public function test_public_contract_case_covers_all_four_categories(): void
    {
        $r = $this->extractor()->extract([
            'cli_signatures' => [
                ['signature' => 'atlas:task next --client='],
            ],
            'methods' => [
                ['name' => 'evaluate', 'class' => 'SomeService', 'visibility' => 'public'],
            ],
            'output_keys' => [
                ['key' => 'readiness'],
            ],
            'evidence_schema_names' => [
                ['name' => 'atlas.external_brain.compression_control_plane.v1'],
            ],
        ]);

        $types = array_column($r['preserved_contracts'], 'type');
        $this->assertContains('cli_signature', $types);
        $this->assertContains('public_method', $types);
        $this->assertContains('output_key', $types);
        $this->assertContains('evidence_schema_name', $types);

        $identifiers = array_column($r['preserved_contracts'], 'identifier');
        $this->assertContains('atlas:task next --client=', $identifiers);
        $this->assertContains('SomeService::evaluate', $identifiers);
        $this->assertContains('readiness', $identifiers);
        $this->assertContains('atlas.external_brain.compression_control_plane.v1', $identifiers);
    }

    public function test_private_helper_exclusion_case(): void
    {
        $r = $this->extractor()->extract([
            'methods' => [
                ['name' => 'publicApi', 'visibility' => 'public'],
                ['name' => 'privateHelper', 'visibility' => 'private'],
                ['name' => 'protectedHelper', 'visibility' => 'protected'],
                ['name' => 'internalScratch', 'visibility' => 'helper'],
            ],
        ]);

        $identifiers = array_column($r['preserved_contracts'], 'identifier');
        $this->assertContains('publicApi', $identifiers);
        $this->assertStringNotContainsString('privateHelper', implode(',', $identifiers));
        $this->assertStringNotContainsString('protectedHelper', implode(',', $identifiers));
        $this->assertStringNotContainsString('internalScratch', implode(',', $identifiers));
        $this->assertSame(3, $r['excluded_private_count']);
    }

    public function test_visibility_defaults_to_public_when_omitted(): void
    {
        $r = $this->extractor()->extract([
            'output_keys' => [['key' => 'implicit_public']],
        ]);

        $this->assertSame(['output_key' => 'implicit_public'], [
            'output_key' => $r['preserved_contracts'][0]['identifier'],
        ]);
        $this->assertSame(0, $r['excluded_private_count']);
    }

    public function test_empty_input_produces_empty_preserved_contracts(): void
    {
        $r = $this->extractor()->extract([]);

        $this->assertSame([], $r['preserved_contracts']);
        $this->assertSame(0, $r['excluded_private_count']);
    }

    public function test_blank_identifier_is_skipped(): void
    {
        $r = $this->extractor()->extract([
            'output_keys' => [['key' => '   '], ['key' => 'valid_key']],
        ]);

        $identifiers = array_column($r['preserved_contracts'], 'identifier');
        $this->assertSame(['valid_key'], $identifiers);
    }

    public function test_schema_present(): void
    {
        $r = $this->extractor()->extract([]);

        $this->assertSame(AtlasExternalBrainPublicContractExtractor::SCHEMA, $r['schema']);
    }
}
