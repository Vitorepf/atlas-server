<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\AgenticEngineeringOs\Support\AtlasStringListNormalizer;
use Tests\TestCase;

final class AtlasAaeosStringListNormalizerTest extends TestCase
{
    public function test_trimmed_strings_preserves_duplicates_for_gate_reasons(): void
    {
        $this->assertSame(
            ['alpha', 'alpha', 'beta'],
            AtlasStringListNormalizer::trimmedStrings([' alpha ', 'alpha', '', null, ' beta ']),
        );
    }

    public function test_trimmed_scalar_values_preserves_numbers_for_traceability_refs(): void
    {
        $this->assertSame(
            ['alpha', '42', '4.2', 'alpha'],
            AtlasStringListNormalizer::trimmedScalarValues([' alpha ', 42, false, ' ', ['nested'], 4.2, 'alpha']),
        );
    }

    public function test_trimmed_string_or_int_values_preserves_data_model_contract(): void
    {
        $this->assertSame(
            ['alpha', '42', 'alpha'],
            AtlasStringListNormalizer::trimmedStringOrIntValues([' alpha ', 42, true, false, ' ', ['nested'], 4.2, 'alpha']),
        );
    }

    public function test_non_blank_string_or_int_values_preserves_raw_source_ids(): void
    {
        $this->assertSame(
            [' alpha ', '42', '0'],
            AtlasStringListNormalizer::nonBlankStringOrIntValues([' alpha ', 42, true, false, ' ', ['nested'], 4.2, '0']),
        );
    }

    public function test_strings_preserves_raw_string_only_contract(): void
    {
        $this->assertSame(
            [' app/Foo.php ', '', '0'],
            AtlasStringListNormalizer::strings([' app/Foo.php ', '', null, 42, '0']),
        );
    }

    public function test_non_empty_array_strings_rejects_scalar_input(): void
    {
        $this->assertSame([], AtlasStringListNormalizer::nonEmptyArrayStrings('app/Foo.php'));
        $this->assertSame(
            [' app/Foo.php ', '0'],
            AtlasStringListNormalizer::nonEmptyArrayStrings([' app/Foo.php ', '', null, 42, '0']),
        );
    }

    public function test_non_empty_strings_preserves_raw_reservation_tokens(): void
    {
        $this->assertSame(
            [' app/Foo.php ', '0', ' app/Foo.php '],
            AtlasStringListNormalizer::nonEmptyStrings([' app/Foo.php ', '', null, 42, '0', ' app/Foo.php ']),
        );
    }

    public function test_unique_non_empty_strings_preserves_raw_frontend_stage_contract(): void
    {
        $this->assertSame(
            ['source ', ' ', 'bundle'],
            AtlasStringListNormalizer::uniqueNonEmptyStrings(['source ', '', ' ', 'bundle', 'source ']),
        );
    }

    public function test_non_blank_strings_preserves_raw_kernel_tokens(): void
    {
        $this->assertSame(
            [' app/Foo.php ', '0'],
            AtlasStringListNormalizer::nonBlankStrings([' app/Foo.php ', '', '   ', null, 42, '0']),
        );
    }

    public function test_unique_trimmed_strings_deduplicates_packet_scope_lists(): void
    {
        $this->assertSame(
            ['alpha', 'beta'],
            AtlasStringListNormalizer::uniqueTrimmedStrings([' alpha ', 'alpha', '', null, ' beta ']),
        );
    }

    public function test_lower_trimmed_strings_preserves_duplicates_for_policy_tokens(): void
    {
        $this->assertSame(
            ['--sandbox', '--sandbox', 'plugin'],
            AtlasStringListNormalizer::lowerTrimmedStrings([' --Sandbox ', '--sandbox', '', null, ' Plugin ']),
        );
    }

    public function test_unique_lower_trimmed_strings_deduplicates_frontend_product_proof_tokens(): void
    {
        $this->assertSame(
            ['desktop', 'mobile'],
            AtlasStringListNormalizer::uniqueLowerTrimmedStrings([' Desktop ', 'desktop', '', null, ' MOBILE ']),
        );
    }

    public function test_strings_from_artifact_refs_preserves_research_role_contract(): void
    {
        $this->assertSame(
            ['source_candidates', 'claim', 'artifact-id', 'named-artifact', 'raw'],
            AtlasStringListNormalizer::stringsFromArtifactRefs([
                ['kind' => ' source_candidates '],
                ['type' => 'claim'],
                ['id' => 'artifact-id'],
                ['name' => 'named-artifact'],
                ' raw ',
                '',
                ['kind' => ' '],
                42,
            ]),
        );
    }

    public function test_unique_sorted_strings_preserves_empty_string_contract(): void
    {
        $this->assertSame(
            ['', 'alpha', 'beta'],
            AtlasStringListNormalizer::uniqueSortedStrings(['beta', '', 'alpha', 'beta', '']),
        );
    }
}
