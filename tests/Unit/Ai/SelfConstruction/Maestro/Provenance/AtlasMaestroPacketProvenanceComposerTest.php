<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Provenance;

use App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceComposer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtlasMaestroPacketProvenanceComposerTest extends TestCase
{
    public function test_it_is_idempotent_for_identical_inputs(): void
    {
        $composer = new AtlasMaestroPacketProvenanceComposer;
        $inputs = [
            'origin_kind' => 'cortex_fact',
            'origin_id' => 'fact-123',
            'chain' => [
                [
                    'parent_id' => null,
                    'source_kind' => 'cortex_fact',
                    'source_id' => 'fact-123',
                    'captured_at' => '2026-06-24T07:00:00-03:00',
                    'content' => ['objective' => 'seed'],
                ],
                [
                    'parent_id' => 'root',
                    'source_kind' => 'operator_intent',
                    'source_id' => 'intent-456',
                    'captured_at' => '2026-06-24T10:15:00Z',
                    'content' => ['operator' => 'vitorepf'],
                ],
            ],
        ];

        $first = $composer->compose('packet-1', $inputs);
        $second = $composer->compose('packet-1', $inputs);

        $this->assertSame(
            hash('sha256', json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            hash('sha256', json_encode($second, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
        );
        $this->assertSame($first, $second);
    }

    public function test_it_rejects_unsupported_origin_kind_and_empty_chain(): void
    {
        $composer = new AtlasMaestroPacketProvenanceComposer;

        $this->expectException(InvalidArgumentException::class);
        $composer->compose('packet-1', [
            'origin_kind' => 'made_up_kind',
            'origin_id' => 'x',
            'chain' => [['source_kind' => 'cortex_fact', 'source_id' => 'x', 'captured_at' => '2026-06-24T10:00:00Z']],
        ]);
    }

    public function test_it_rejects_empty_chain(): void
    {
        $composer = new AtlasMaestroPacketProvenanceComposer;

        $this->expectException(InvalidArgumentException::class);
        $composer->compose('packet-1', [
            'origin_kind' => 'operator_intent',
            'origin_id' => 'intent-1',
            'chain' => [],
        ]);
    }

    public function test_each_chain_link_carries_required_structural_fields(): void
    {
        $record = (new AtlasMaestroPacketProvenanceComposer)->compose('packet-1', [
            'origin_kind' => 'loop_emergence',
            'origin_id' => 'emergence-1',
            'chain' => [
                [
                    'parent_id' => null,
                    'source_kind' => 'loop_emergence',
                    'source_id' => 'emergence-1',
                    'captured_at' => '2026-06-24T10:15:00+02:00',
                    'content' => ['slice' => 'M-PROV-1320'],
                ],
            ],
        ]);

        $this->assertCount(1, $record['chain']);
        $link = $record['chain'][0];

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $link['link_id']);
        $this->assertSame('loop_emergence', $link['source_kind']);
        $this->assertSame('emergence-1', $link['source_id']);
        $this->assertSame('2026-06-24T08:15:00Z', $link['captured_at']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $link['content_hash']);
        $this->assertArrayHasKey('parent_id', $link);
    }

    public function test_record_with_custom_content_key_verifies_ok_no_false_hash_mismatch(): void
    {
        $composer = new AtlasMaestroPacketProvenanceComposer;
        $record = $composer->compose('packet-prov', [
            'origin_kind' => 'cortex_fact',
            'origin_id' => 'fact-999',
            'chain' => [
                [
                    'parent_id' => null,
                    'source_kind' => 'cortex_fact',
                    'source_id' => 'fact-999',
                    'captured_at' => '2026-06-24T07:00:00-03:00',
                    'content' => ['objective' => 'seed'],
                ],
            ],
        ]);

        $verifier = new \App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceVerifier;
        $verdict = $verifier->verify($record);

        $this->assertTrue($verdict['ok']);
        $this->assertSame('OK', $verdict['reason_code']);
    }
}
