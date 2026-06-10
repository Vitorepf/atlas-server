<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScalarNormalizer;
use Tests\TestCase;

final class AreaFocusScalarNormalizerTest extends TestCase
{
    public function test_clamp_unit_preserves_existing_closed_interval_semantics(): void
    {
        $this->assertSame(0.0, AreaFocusScalarNormalizer::clampUnit(-0.2));
        $this->assertSame(0.0, AreaFocusScalarNormalizer::clampUnit(-INF));
        $this->assertSame(0.42, AreaFocusScalarNormalizer::clampUnit(0.42));
        $this->assertSame(1.0, AreaFocusScalarNormalizer::clampUnit(1.7));
        $this->assertSame(1.0, AreaFocusScalarNormalizer::clampUnit(INF));
        $this->assertTrue(is_nan(AreaFocusScalarNormalizer::clampUnit(NAN)));
    }

    public function test_finite_clamp_unit_fails_closed_for_non_finite_values(): void
    {
        $this->assertSame(0.0, AreaFocusScalarNormalizer::finiteClampUnit(NAN));
        $this->assertSame(0.0, AreaFocusScalarNormalizer::finiteClampUnit(INF));
        $this->assertSame(0.0, AreaFocusScalarNormalizer::finiteClampUnit(-0.1));
        $this->assertSame(0.5, AreaFocusScalarNormalizer::finiteClampUnit(0.5));
        $this->assertSame(1.0, AreaFocusScalarNormalizer::finiteClampUnit(1.2));
    }

    public function test_finite_number_helpers_preserve_numeric_type_contract(): void
    {
        $this->assertTrue(AreaFocusScalarNormalizer::finiteNumber(4));
        $this->assertTrue(AreaFocusScalarNormalizer::finiteNumber(4.5));
        $this->assertFalse(AreaFocusScalarNormalizer::finiteNumber('4.5'));
        $this->assertFalse(AreaFocusScalarNormalizer::finiteNumber(INF));

        $this->assertSame(4.5, AreaFocusScalarNormalizer::finiteNumberOrZero(4.5));
        $this->assertSame(0.0, AreaFocusScalarNormalizer::finiteNumberOrZero('4.5'));
        $this->assertSame(4.5, AreaFocusScalarNormalizer::numberOrDefault(4.5, 1.0));
        $this->assertTrue(is_nan(AreaFocusScalarNormalizer::numberOrDefault(NAN, 1.0)));
        $this->assertSame(1.0, AreaFocusScalarNormalizer::numberOrDefault('4.5', 1.0));
        $this->assertSame(4.5, AreaFocusScalarNormalizer::payloadNumberOrDefault(['value' => 4.5], 'value', 1.0));
        $this->assertSame(4.5, AreaFocusScalarNormalizer::finiteNumericOrZero('4.5'));
        $this->assertSame(0.0, AreaFocusScalarNormalizer::finiteNumericOrZero('1e400'));
    }

    public function test_payload_numeric_helpers_preserve_default_and_casting_contract(): void
    {
        $payload = [
            'int_value' => '12.8',
            'float_value' => '0.42',
            'nan_value' => NAN,
            'bad_value' => 'nope',
        ];

        $this->assertTrue(AreaFocusScalarNormalizer::numericValue(1));
        $this->assertTrue(AreaFocusScalarNormalizer::numericValue(NAN));
        $this->assertTrue(AreaFocusScalarNormalizer::numericValue('1.25'));
        $this->assertFalse(AreaFocusScalarNormalizer::numericValue('nope'));
        $this->assertSame(12, AreaFocusScalarNormalizer::numericIntOrZero('12.8'));
        $this->assertSame(0, AreaFocusScalarNormalizer::numericIntOrZero('nope'));
        $this->assertSame(0, AreaFocusScalarNormalizer::numericIntOrZero(true));
        $this->assertSame(12, AreaFocusScalarNormalizer::nonNegativeInt('12abc'));
        $this->assertSame(0, AreaFocusScalarNormalizer::nonNegativeInt(-3));
        $this->assertSame(7, AreaFocusScalarNormalizer::intWithDefaultAndMin(null, 7, 2));
        $this->assertSame(7, AreaFocusScalarNormalizer::intWithDefaultAndMin('', 7, 2));
        $this->assertSame(2, AreaFocusScalarNormalizer::intWithDefaultAndMin(1, 7, 2));
        $this->assertSame(12, AreaFocusScalarNormalizer::intWithDefaultAndMin('12abc', 7, 2));

        $this->assertSame(12, AreaFocusScalarNormalizer::payloadInt($payload, 'int_value', 3));
        $this->assertSame(3, AreaFocusScalarNormalizer::payloadInt($payload, 'bad_value', 3));
        $this->assertSame(12, AreaFocusScalarNormalizer::payloadFiniteInt($payload, 'int_value', 3));
        $this->assertSame(3, AreaFocusScalarNormalizer::payloadFiniteInt($payload, 'nan_value', 3));
        $this->assertSame(3, AreaFocusScalarNormalizer::payloadFiniteInt(['huge' => '1e400'], 'huge', 3));
        $this->assertSame(PHP_INT_MAX, AreaFocusScalarNormalizer::saturatingInt('9223372036854775808'));
        $this->assertSame(PHP_INT_MIN, AreaFocusScalarNormalizer::saturatingInt('-9223372036854775809'));
        $this->assertSame(3, AreaFocusScalarNormalizer::saturatingInt('1e400', 3));
        $this->assertSame(3, AreaFocusScalarNormalizer::saturatingInt(NAN, 3));
        $this->assertSame(PHP_INT_MAX, AreaFocusScalarNormalizer::payloadSaturatingInt(['huge' => '9223372036854775808'], 'huge', 0));
        $this->assertSame(0.42, AreaFocusScalarNormalizer::payloadFloat($payload, 'float_value', 0.0));
        $this->assertTrue(is_nan(AreaFocusScalarNormalizer::payloadFloat($payload, 'nan_value', 0.0)));
        $this->assertSame(-1.0, AreaFocusScalarNormalizer::payloadFiniteFloat($payload, 'nan_value', -1.0));
        $this->assertSame(-1.0, AreaFocusScalarNormalizer::payloadFiniteFloat(['inf' => INF], 'inf', -1.0));
    }

    public function test_payload_bool_accepts_only_literal_true_with_explicit_default(): void
    {
        $this->assertTrue(AreaFocusScalarNormalizer::payloadBool(['flag' => true], 'flag'));
        $this->assertFalse(AreaFocusScalarNormalizer::payloadBool(['flag' => 'true'], 'flag'));
        $this->assertTrue(AreaFocusScalarNormalizer::payloadBool([], 'missing', true));
        $this->assertFalse(AreaFocusScalarNormalizer::payloadBool(['flag' => false], 'flag', true));
    }

    public function test_risk_level_or_medium_accepts_canonical_risk_levels_only(): void
    {
        $this->assertSame('critical', AreaFocusScalarNormalizer::riskLevelOrMedium(' Critical '));
        $this->assertSame('high', AreaFocusScalarNormalizer::riskLevelOrMedium('high'));
        $this->assertSame('medium', AreaFocusScalarNormalizer::riskLevelOrMedium('unknown'));
        $this->assertSame('medium', AreaFocusScalarNormalizer::riskLevelOrMedium(''));
        $this->assertSame('medium', AreaFocusScalarNormalizer::riskLevelOrMedium(42));
    }

    public function test_severity_or_medium_accepts_canonical_severity_levels_only(): void
    {
        $this->assertSame('critical', AreaFocusScalarNormalizer::severityOrMedium(' Critical '));
        $this->assertSame('low', AreaFocusScalarNormalizer::severityOrMedium('low'));
        $this->assertSame('medium', AreaFocusScalarNormalizer::severityOrMedium('unknown'));
        $this->assertSame('medium', AreaFocusScalarNormalizer::severityOrMedium(''));
        $this->assertSame('medium', AreaFocusScalarNormalizer::severityOrMedium(null));
    }

    public function test_choice_helpers_preserve_lowercase_nullable_and_exact_trim_contracts(): void
    {
        $this->assertSame('ready', AreaFocusScalarNormalizer::lowerChoice(' Ready ', ['ready', 'blocked'], 'unknown'));
        $this->assertSame('unknown', AreaFocusScalarNormalizer::lowerChoice('missing', ['ready', 'blocked'], 'unknown'));

        $this->assertSame('blocked', AreaFocusScalarNormalizer::nullableLowerChoice(' Blocked ', ['ready', 'blocked']));
        $this->assertNull(AreaFocusScalarNormalizer::nullableLowerChoice(null, ['ready', 'blocked']));
        $this->assertNull(AreaFocusScalarNormalizer::nullableLowerChoice('missing', ['ready', 'blocked']));

        $this->assertSame('aaeos_dev_lane', AreaFocusScalarNormalizer::trimmedChoice(' aaeos_dev_lane ', ['aaeos_dev_lane'], 'fallback'));
        $this->assertSame('fallback', AreaFocusScalarNormalizer::trimmedChoice('AAEOS_DEV_LANE', ['aaeos_dev_lane'], 'fallback'));
    }

    public function test_payload_string_reads_first_non_blank_string_only(): void
    {
        $payload = [
            'first' => '   ',
            'second' => 42,
            'third' => ' value ',
        ];

        $this->assertSame('value', AreaFocusScalarNormalizer::payloadString($payload, ['first', 'second', 'third'], 'fallback'));
        $this->assertSame('fallback', AreaFocusScalarNormalizer::payloadString($payload, ['missing', 'second'], 'fallback'));
        $this->assertSame('value', AreaFocusScalarNormalizer::payloadTrimmedString($payload, 'third'));
        $this->assertSame('fallback', AreaFocusScalarNormalizer::payloadTrimmedString($payload, 'first', 'fallback'));
        $this->assertSame(' value ', AreaFocusScalarNormalizer::payloadRawString($payload, 'third'));
        $this->assertSame('fallback', AreaFocusScalarNormalizer::payloadRawString($payload, 'second', 'fallback'));
    }

    public function test_trimmed_string_only_rejects_non_strings_without_scalar_casting(): void
    {
        $this->assertSame('value', AreaFocusScalarNormalizer::trimmedStringOnly(' value '));
        $this->assertSame('', AreaFocusScalarNormalizer::trimmedStringOnly(42));
        $this->assertSame('', AreaFocusScalarNormalizer::trimmedStringOnly(null));
    }

    public function test_collapsed_lower_whitespace_matches_title_key_contract(): void
    {
        $this->assertSame(
            'fix provider retry path',
            AreaFocusScalarNormalizer::collapsedLowerWhitespace("  Fix\tProvider\nRetry   Path  "),
        );
        $this->assertSame('', AreaFocusScalarNormalizer::collapsedLowerWhitespace(" \n\t "));
    }

    public function test_canonical_doc_id_matches_frame_registry_contract(): void
    {
        $this->assertSame('atlas-axis-n', AreaFocusScalarNormalizer::canonicalDocId(' Atlas-Axis-N.md '));
        $this->assertSame('atlas-axis-n.mdx', AreaFocusScalarNormalizer::canonicalDocId('Atlas-Axis-N.mdx'));
        $this->assertSame('', AreaFocusScalarNormalizer::canonicalDocId('   '));
    }

    public function test_string_or_number_trims_strings_and_casts_numeric_scalars(): void
    {
        $this->assertSame('value', AreaFocusScalarNormalizer::stringOrNumber(' value '));
        $this->assertSame('42', AreaFocusScalarNormalizer::stringOrNumber(42));
        $this->assertSame('4.5', AreaFocusScalarNormalizer::stringOrNumber(4.5));
        $this->assertSame('', AreaFocusScalarNormalizer::stringOrNumber(true));
        $this->assertSame('42', AreaFocusScalarNormalizer::payloadStringOrNumber(['id' => 42], 'id'));
    }

    public function test_int_representable_float_guards_warning_edges(): void
    {
        $this->assertTrue(AreaFocusScalarNormalizer::intRepresentableFloat(12.9));
        $this->assertFalse(AreaFocusScalarNormalizer::intRepresentableFloat(NAN));
        $this->assertFalse(AreaFocusScalarNormalizer::intRepresentableFloat(INF));
        $this->assertFalse(AreaFocusScalarNormalizer::intRepresentableFloat((float) PHP_INT_MAX));
        $this->assertTrue(AreaFocusScalarNormalizer::intRepresentableFloat((float) PHP_INT_MIN));
    }

    public function test_nullable_string_trims_scalar_values_and_converts_empty_to_null(): void
    {
        $this->assertNull(AreaFocusScalarNormalizer::nullableString(null));
        $this->assertNull(AreaFocusScalarNormalizer::nullableString(''));
        $this->assertNull(AreaFocusScalarNormalizer::nullableString('   '));
        $this->assertSame('alpha', AreaFocusScalarNormalizer::nullableString(' alpha '));
        $this->assertSame('42', AreaFocusScalarNormalizer::nullableString(42));
        $this->assertSame('4.2', AreaFocusScalarNormalizer::nullableString(4.2));
        $this->assertSame('1', AreaFocusScalarNormalizer::nullableString(true));
    }

    public function test_nullable_string_only_accepts_strings_without_scalar_casting(): void
    {
        $this->assertNull(AreaFocusScalarNormalizer::nullableStringOnly(null));
        $this->assertNull(AreaFocusScalarNormalizer::nullableStringOnly(42));
        $this->assertNull(AreaFocusScalarNormalizer::nullableStringOnly(true));
        $this->assertNull(AreaFocusScalarNormalizer::nullableStringOnly('   '));
        $this->assertSame('alpha', AreaFocusScalarNormalizer::nullableStringOnly(' alpha '));
    }
}
