<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchRefNormalizer;
use Tests\TestCase;

final class AreaFocusBranchRefNormalizerTest extends TestCase
{
    public function test_normalizes_branch_refs_from_strings_arrays_and_row_maps(): void
    {
        $this->assertSame(
            ['atlas/area-focus/a', 'atlas/area-focus/b', 'atlas/area-focus/c'],
            AreaFocusBranchRefNormalizer::fromValue([
                ' atlas/area-focus/a ',
                ['branch_ref' => 'atlas/area-focus/b'],
                ['branch' => ' atlas/area-focus/c '],
                ['branch_ref' => 'atlas/area-focus/a'],
                '',
                ['other' => 'ignored'],
            ]),
        );

        $this->assertSame(
            ['atlas/area-focus/a', 'atlas/area-focus/b'],
            AreaFocusBranchRefNormalizer::fromInput([
                'branch_refs' => "atlas/area-focus/a, atlas/area-focus/b\natlas/area-focus/a",
            ]),
        );
    }

    public function test_returns_empty_list_for_missing_or_non_array_values(): void
    {
        $this->assertSame([], AreaFocusBranchRefNormalizer::fromValue(null));
        $this->assertSame([], AreaFocusBranchRefNormalizer::fromInput([]));
    }
}
