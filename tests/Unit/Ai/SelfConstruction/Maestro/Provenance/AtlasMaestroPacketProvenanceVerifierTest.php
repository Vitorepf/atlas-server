<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Provenance;

use App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceComposer;
use App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceVerifier;
use Tests\TestCase;

class AtlasMaestroPacketProvenanceVerifierTest extends TestCase
{
    private function composedRecord(): array
    {
        return (new AtlasMaestroPacketProvenanceComposer)->compose('pkt-1', [
            'origin_kind' => 'operator_intent',
            'origin_id' => 'op-1',
            'chain' => [
                ['parent_id' => null, 'source_kind' => 'operator_intent', 'source_id' => 'op-1', 'captured_at' => '2026-06-25T00:00:00Z'],
                ['parent_id' => '__will-be-replaced__', 'source_kind' => 'cortex_fact', 'source_id' => 'fact-1', 'captured_at' => '2026-06-25T00:00:01Z'],
                ['parent_id' => '__will-be-replaced__', 'source_kind' => 'loop_emergence', 'source_id' => 'em-1', 'captured_at' => '2026-06-25T00:00:02Z'],
            ],
        ]);
    }

    /**
     * Builds a record whose parent_id values point at the actual previous link_id. We do this by
     * composing iteratively (rebuild the chain once, peek at link_id 0, then re-compose with the
     * right parent_id for link 1, and again for link 2).
     */
    private function repairedChain(): array
    {
        $composer = new AtlasMaestroPacketProvenanceComposer();
        $links = [
            ['source_kind' => 'operator_intent', 'source_id' => 'op-1', 'captured_at' => '2026-06-25T00:00:00Z'],
            ['source_kind' => 'cortex_fact', 'source_id' => 'fact-1', 'captured_at' => '2026-06-25T00:00:01Z'],
            ['source_kind' => 'loop_emergence', 'source_id' => 'em-1', 'captured_at' => '2026-06-25T00:00:02Z'],
        ];

        $accumulated = [];
        for ($n = 1; $n <= count($links); $n++) {
            $chainInput = [];
            for ($i = 0; $i < $n; $i++) {
                $chainInput[] = [
                    'parent_id' => $i === 0 ? null : ($accumulated[$i - 1] ?? null),
                    'source_kind' => $links[$i]['source_kind'],
                    'source_id' => $links[$i]['source_id'],
                    'captured_at' => $links[$i]['captured_at'],
                ];
            }
            $record = $composer->compose('pkt-1', [
                'origin_kind' => 'operator_intent',
                'origin_id' => 'op-1',
                'chain' => $chainInput,
            ]);
            // Remember the newly resolved link_id at index n-1 for the next iteration.
            $accumulated[$n - 1] = $record['chain'][$n - 1]['link_id'];
        }

        return $record;
    }

    public function test_well_formed_record_passes_verification(): void
    {
        $record = $this->repairedChain();
        $verdict = (new AtlasMaestroPacketProvenanceVerifier)->verify($record);

        self::assertTrue($verdict['ok']);
        self::assertSame('OK', $verdict['reason_code']);
    }

    public function test_chain_empty_reason_code(): void
    {
        $record = $this->repairedChain();
        $record['chain'] = [];
        $verdict = (new AtlasMaestroPacketProvenanceVerifier)->verify($record);

        self::assertFalse($verdict['ok']);
        self::assertSame('CHAIN_EMPTY', $verdict['reason_code']);
    }

    public function test_origin_mismatch_reason_code(): void
    {
        $record = $this->repairedChain();
        $record['origin_id'] = 'different';
        $verdict = (new AtlasMaestroPacketProvenanceVerifier)->verify($record);

        self::assertFalse($verdict['ok']);
        self::assertSame('ORIGIN_MISMATCH', $verdict['reason_code']);
    }

    public function test_parent_missing_reason_code(): void
    {
        $record = $this->repairedChain();
        $record['chain'][1]['parent_id'] = 'never-existed';
        $verdict = (new AtlasMaestroPacketProvenanceVerifier)->verify($record);

        self::assertFalse($verdict['ok']);
        self::assertSame('PARENT_MISSING', $verdict['reason_code']);
        self::assertSame($record['chain'][1]['link_id'], $verdict['broken_link_id']);
    }

    public function test_cycle_detected_reason_code_when_parent_id_points_to_self(): void
    {
        $record = $this->repairedChain();
        $record['chain'][1]['parent_id'] = $record['chain'][1]['link_id']; // self-loop
        $verdict = (new AtlasMaestroPacketProvenanceVerifier)->verify($record);

        self::assertFalse($verdict['ok']);
        self::assertSame('CYCLE_DETECTED', $verdict['reason_code']);
    }

    public function test_hash_mismatch_reason_code(): void
    {
        $record = $this->repairedChain();
        $record['chain'][0]['content_hash'] = str_repeat('0', 64); // bogus
        $verdict = (new AtlasMaestroPacketProvenanceVerifier)->verify($record);

        self::assertFalse($verdict['ok']);
        self::assertSame('HASH_MISMATCH', $verdict['reason_code']);
        self::assertSame($record['chain'][0]['link_id'], $verdict['broken_link_id']);
    }

    public function test_timestamp_regression_reason_code(): void
    {
        $record = $this->repairedChain();
        // Force regression: rewrite last captured_at earlier than its predecessor, then RECOMPUTE
        // the hash so the test isolates the regression check (not the hash check).
        $record['chain'][2]['captured_at'] = '2026-06-25T00:00:00Z'; // earlier than chain[1]
        // Recompute content hash with the new captured_at so HASH_MISMATCH doesn't fire first.
        $newPayload = [
            'parent_id' => $record['chain'][2]['parent_id'],
            'source_kind' => $record['chain'][2]['source_kind'],
            'source_id' => $record['chain'][2]['source_id'],
            'captured_at' => $record['chain'][2]['captured_at'],
        ];
        ksort($newPayload);
        $record['chain'][2]['content_hash'] = hash('sha256', (string) json_encode($newPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $verdict = (new AtlasMaestroPacketProvenanceVerifier)->verify($record);

        self::assertFalse($verdict['ok']);
        self::assertSame('TIMESTAMP_REGRESSION', $verdict['reason_code']);
    }

    public function test_verifier_does_not_mutate_input(): void
    {
        $record = $this->repairedChain();
        $before = json_encode($record);
        (new AtlasMaestroPacketProvenanceVerifier)->verify($record);
        $after = json_encode($record);

        self::assertSame($before, $after);
    }

    public function test_calling_verify_twice_returns_equal_verdicts(): void
    {
        $record = $this->repairedChain();
        $verifier = new AtlasMaestroPacketProvenanceVerifier();

        self::assertSame($verifier->verify($record), $verifier->verify($record));
    }
}
