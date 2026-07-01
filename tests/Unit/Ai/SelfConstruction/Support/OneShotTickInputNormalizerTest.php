<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\OneShotTickInputNormalizer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OneShotTickInputNormalizerTest extends TestCase
{
    private function validHash(): string
    {
        return str_repeat('a1', 32);
    }

    public function test_missing_required_field_throws_missing_field_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing_run_key');

        (new OneShotTickInputNormalizer)->normalize(
            ['other_field' => 'x'],
            ['run_key'],
            [],
        );
    }

    public function test_uppercase_and_trailing_space_hash_is_normalized_safely(): void
    {
        $result = (new OneShotTickInputNormalizer)->normalize(
            ['hash' => strtoupper($this->validHash()).'  '],
            [],
            ['hash'],
        );

        self::assertSame($this->validHash(), $result['hash']);
    }

    public function test_trailing_newline_hash_fails_instead_of_being_silently_trimmed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_hash');

        (new OneShotTickInputNormalizer)->normalize(
            ['hash' => $this->validHash()."\n"],
            [],
            ['hash'],
        );
    }

    public function test_malformed_hash_throws_invalid_field_message(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid_hash');

        (new OneShotTickInputNormalizer)->normalize(
            ['hash' => 'not-a-hash'],
            [],
            ['hash'],
        );
    }

    public function test_unrelated_fields_are_preserved_and_no_exception_on_valid_input(): void
    {
        $result = (new OneShotTickInputNormalizer)->normalize(
            ['run_key' => 'abc', 'hash' => $this->validHash(), 'unrelated' => 'kept'],
            ['run_key'],
            ['hash'],
        );

        self::assertSame('abc', $result['run_key']);
        self::assertSame('kept', $result['unrelated']);
        self::assertSame($this->validHash(), $result['hash']);
    }
}
