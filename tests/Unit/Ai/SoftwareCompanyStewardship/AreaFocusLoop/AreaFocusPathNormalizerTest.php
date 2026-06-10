<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusPathNormalizer;
use Tests\TestCase;

final class AreaFocusPathNormalizerTest extends TestCase
{
    public function test_repo_relative_no_whitespace_normalizes_slice_paths(): void
    {
        $this->assertSame(
            'app/Services/Foo.php',
            AreaFocusPathNormalizer::repoRelativeNoWhitespace(' /app\\Services / Foo.php '),
        );
    }

    public function test_strip_leading_dot_slash_preserves_contract_specific_trimming(): void
    {
        $this->assertSame(
            'tests/Feature/FooTest.php',
            AreaFocusPathNormalizer::stripLeadingDotSlash(" './tests/Feature/FooTest.php' ", " \t'\""),
        );

        $this->assertSame(
            'docs/foo.md',
            AreaFocusPathNormalizer::stripLeadingDotSlash(" \n./docs/foo.md\n "),
        );
    }

    public function test_existing_or_raw_path_without_deleted_suffix_resolves_existing_paths(): void
    {
        $this->assertSame(getcwd(), AreaFocusPathNormalizer::existingOrRawPathWithoutDeletedSuffix(' . (deleted)'));
        $this->assertSame('', AreaFocusPathNormalizer::existingOrRawPathWithoutDeletedSuffix('   '));

        $missing = getcwd().'/missing-path-normalizer-test';
        $this->assertSame($missing, AreaFocusPathNormalizer::existingOrRawPathWithoutDeletedSuffix($missing.' (deleted)'));
    }

    public function test_trimmed_repo_relative_unique_strings_normalizes_path_lists(): void
    {
        $this->assertSame(
            ['app/Foo.php', 'tests/FooTest.php'],
            AreaFocusPathNormalizer::trimmedRepoRelativeUniqueStrings([
                ' /app\\Foo.php ',
                'app/Foo.php',
                '',
                42,
                '/tests/FooTest.php',
            ]),
        );

        $this->assertSame([], AreaFocusPathNormalizer::trimmedRepoRelativeUniqueStrings('app/Foo.php'));
    }

    public function test_base_path_helpers_convert_between_repo_relative_and_absolute_paths(): void
    {
        $relative = 'app/Services/Foo.php';
        $absolute = rtrim(base_path(), '/').'/'.$relative;

        $this->assertSame($relative, AreaFocusPathNormalizer::relativeToBasePath($absolute));
        $this->assertSame('/tmp/outside.php', AreaFocusPathNormalizer::relativeToBasePath('/tmp/outside.php'));
        $this->assertSame($absolute, AreaFocusPathNormalizer::absoluteFromBasePath($relative));
    }

    public function test_repo_relative_from_base_path_preserves_scanner_contract(): void
    {
        $relative = 'app/Services/Foo.php';
        $absolute = rtrim(base_path(), '/').'/'.$relative;

        $this->assertSame($relative, AreaFocusPathNormalizer::repoRelativeFromBasePath($absolute));
        $this->assertSame('tmp/outside.php', AreaFocusPathNormalizer::repoRelativeFromBasePath('/tmp/outside.php'));
        $this->assertSame('C:/repo/app/Foo.php', AreaFocusPathNormalizer::repoRelativeFromBasePath('C:\\repo\\app\\Foo.php'));
    }
}
