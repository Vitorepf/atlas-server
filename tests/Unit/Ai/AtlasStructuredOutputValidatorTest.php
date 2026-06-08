<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasDecide\AtlasStructuredOutputValidator;
use PHPUnit\Framework\TestCase;

class AtlasStructuredOutputValidatorTest extends TestCase
{
    private AtlasStructuredOutputValidator $validator;

    /** @var array<string,mixed> */
    private array $schema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new AtlasStructuredOutputValidator();
        $this->schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['decision', 'confidence', 'findings'],
            'properties' => [
                'decision' => ['type' => 'string', 'enum' => ['ship', 'hold', 'reject']],
                'confidence' => ['type' => 'number'],
                'findings' => [
                    'type' => 'array',
                    'items' => ['type' => 'object', 'required' => ['title'], 'properties' => ['title' => ['type' => 'string']]],
                ],
            ],
        ];
    }

    public function test_valid_output_passes_and_returns_decoded_value(): void
    {
        $out = json_encode(['decision' => 'hold', 'confidence' => 0.8, 'findings' => [['title' => 'x']]]);
        $r = $this->validator->validate((string) $out, $this->schema);

        $this->assertTrue($r['valid'], implode(' | ', $r['errors']));
        $this->assertSame([], $r['errors']);
        $this->assertSame('hold', $r['value']['decision']);
    }

    public function test_missing_required_field_fails(): void
    {
        $out = json_encode(['decision' => 'hold', 'confidence' => 0.8]);
        $r = $this->validator->validate((string) $out, $this->schema);

        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('missing required "findings"', implode(' ', $r['errors']));
    }

    public function test_enum_violation_fails(): void
    {
        $out = json_encode(['decision' => 'maybe', 'confidence' => 0.8, 'findings' => []]);
        $r = $this->validator->validate((string) $out, $this->schema);

        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('enum', implode(' ', $r['errors']));
    }

    public function test_wrong_scalar_type_fails(): void
    {
        $out = json_encode(['decision' => 'hold', 'confidence' => 'high', 'findings' => []]);
        $r = $this->validator->validate((string) $out, $this->schema);

        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('confidence', implode(' ', $r['errors']));
    }

    public function test_nested_array_item_validated(): void
    {
        $out = json_encode(['decision' => 'ship', 'confidence' => 1, 'findings' => [['notitle' => 'x']]]);
        $r = $this->validator->validate((string) $out, $this->schema);

        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('missing required "title"', implode(' ', $r['errors']));
    }

    public function test_additional_property_rejected_when_closed(): void
    {
        $out = json_encode(['decision' => 'hold', 'confidence' => 0.5, 'findings' => [], 'sneaky' => true]);
        $r = $this->validator->validate((string) $out, $this->schema);

        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('unexpected property "sneaky"', implode(' ', $r['errors']));
    }

    public function test_non_json_output_is_unsatisfied(): void
    {
        $r = $this->validator->validate('Sure! Here is my answer in prose.', $this->schema);

        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('output_not_json', implode(' ', $r['errors']));
    }

    public function test_empty_output_is_unsatisfied(): void
    {
        $r = $this->validator->validate('   ', $this->schema);

        $this->assertFalse($r['valid']);
        $this->assertContains('output_empty', $r['errors']);
    }

    public function test_empty_object_rejected_for_array_type(): void
    {
        // Adversarial re-proof BREAK 1: an empty object must NOT satisfy type:array
        // (assoc decode used to collapse {} and [] to the same PHP []).
        $this->assertFalse($this->validator->validate('{}', ['type' => 'array'])['valid']);
    }

    public function test_empty_array_rejected_for_object_type(): void
    {
        // BREAK 2: an empty array must NOT satisfy type:object even with no `required`.
        $this->assertFalse($this->validator->validate('[]', ['type' => 'object'])['valid']);
    }

    public function test_type_union_accepts_a_member_and_rejects_non_members(): void
    {
        $schema = ['type' => ['string', 'null']];
        $this->assertTrue($this->validator->validate('"hi"', $schema)['valid']);
        $this->assertTrue($this->validator->validate('null', $schema)['valid']);
        $this->assertFalse($this->validator->validate('5', $schema)['valid']);
    }

    public function test_numeric_enum_matches_across_int_and_float(): void
    {
        // An int decode must satisfy a float-declared enum (JSON has one number type).
        $schema = ['type' => 'number', 'enum' => [1.0, 2.0, 3.0]];
        $this->assertTrue($this->validator->validate('1', $schema)['valid']);
        $this->assertFalse($this->validator->validate('4', $schema)['valid']);
    }
}
