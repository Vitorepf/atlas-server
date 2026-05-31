<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\EvidenceLedgerHashChainIntegrityVerifier;
use PHPUnit\Framework\TestCase;

final class EvidenceLedgerHashChainIntegrityVerifierTest extends TestCase
{
    private EvidenceLedgerHashChainIntegrityVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new EvidenceLedgerHashChainIntegrityVerifier();
    }

    public function testValidChainReturnsOk(): void
    {
        $events = [
            [
                'event_id' => 'evt_001',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_a',
                'prev_event_hash' => null,
                'canonical_payload_hash' => 'hash_a',
            ],
            [
                'event_id' => 'evt_002',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_b',
                'prev_event_hash' => 'hash_a',
                'canonical_payload_hash' => 'hash_b',
            ],
            [
                'event_id' => 'evt_003',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_c',
                'prev_event_hash' => 'hash_b',
                'canonical_payload_hash' => 'hash_c',
            ],
        ];

        $results = $this->verifier->verify($events);
        $this->assertCount(1, $results);

        $result = $results[0];
        $this->assertSame('atlas.evidence.ledger_hash_chain_integrity.v1', $result['schema_version']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(3, $result['chain_length']);
        $this->assertSame(0, $result['gap_count']);
        $this->assertSame([], $result['tampered_event_ids']);
        $this->assertSame('evt_001', $result['first_event_id']);
        $this->assertSame('evt_003', $result['last_event_id']);
    }

    public function testBrokenPrevHashReturnsGap(): void
    {
        $events = [
            [
                'event_id' => 'evt_001',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_a',
                'prev_event_hash' => null,
                'canonical_payload_hash' => 'hash_a',
            ],
            [
                'event_id' => 'evt_002',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_b',
                'prev_event_hash' => 'wrong_hash',
                'canonical_payload_hash' => 'hash_b',
            ],
        ];

        $results = $this->verifier->verify($events);
        $this->assertCount(1, $results);

        $result = $results[0];
        $this->assertSame('gap', $result['status']);
        $this->assertSame(1, $result['gap_count']);
        $this->assertSame([], $result['tampered_event_ids']);
    }

    public function testTamperedEventHashReturnsTampered(): void
    {
        $events = [
            [
                'event_id' => 'evt_001',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_a',
                'prev_event_hash' => null,
                'canonical_payload_hash' => 'hash_a',
            ],
            [
                'event_id' => 'evt_002',
                'scope_key' => 'scope_a',
                'event_hash' => 'wrong_hash',
                'prev_event_hash' => 'hash_a',
                'canonical_payload_hash' => 'hash_b',
            ],
        ];

        $results = $this->verifier->verify($events);
        $this->assertCount(1, $results);

        $result = $results[0];
        $this->assertSame('tampered', $result['status']);
        $this->assertSame(0, $result['gap_count']);
        $this->assertSame(['evt_002'], $result['tampered_event_ids']);
    }

    public function testTwoIndependentScopesBothStartWithNullPrevHash(): void
    {
        $events = [
            [
                'event_id' => 'evt_001',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_a',
                'prev_event_hash' => null,
                'canonical_payload_hash' => 'hash_a',
            ],
            [
                'event_id' => 'evt_002',
                'scope_key' => 'scope_b',
                'event_hash' => 'hash_x',
                'prev_event_hash' => null,
                'canonical_payload_hash' => 'hash_x',
            ],
        ];

        $results = $this->verifier->verify($events);
        $this->assertCount(2, $results);

        $scopeA = collect($results)->firstWhere('scope_key', 'scope_a');
        $scopeB = collect($results)->firstWhere('scope_key', 'scope_b');

        $this->assertSame('ok', $scopeA['status']);
        $this->assertSame('ok', $scopeB['status']);
        $this->assertSame(1, $scopeA['chain_length']);
        $this->assertSame(1, $scopeB['chain_length']);
    }

    public function testEmptyEventsReturnsOkWithLengthZero(): void
    {
        $results = $this->verifier->verify([]);
        $this->assertCount(1, $results);

        $result = $results[0];
        $this->assertSame('atlas.evidence.ledger_hash_chain_integrity.v1', $result['schema_version']);
        $this->assertSame('', $result['scope_key']);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(0, $result['chain_length']);
        $this->assertSame(0, $result['gap_count']);
        $this->assertSame([], $result['tampered_event_ids']);
        $this->assertNull($result['first_event_id']);
        $this->assertNull($result['last_event_id']);
    }

    public function testScopeWithNoEventsNotIncluded(): void
    {
        $events = [
            [
                'event_id' => 'evt_001',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_a',
                'prev_event_hash' => null,
                'canonical_payload_hash' => 'hash_a',
            ],
            [
                'event_id' => 'evt_002',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_b',
                'prev_event_hash' => 'hash_a',
                'canonical_payload_hash' => 'hash_b',
            ],
            [
                'event_id' => 'evt_003',
                'scope_key' => 'scope_b',
                'event_hash' => 'hash_c',
                'prev_event_hash' => null,
                'canonical_payload_hash' => 'hash_c',
            ],
        ];

        $results = $this->verifier->verify($events);
        $this->assertCount(2, $results);

        $scopeA = collect($results)->firstWhere('scope_key', 'scope_a');
        $scopeB = collect($results)->firstWhere('scope_key', 'scope_b');

        $this->assertSame(2, $scopeA['chain_length']);
        $this->assertSame(1, $scopeB['chain_length']);
    }

    public function testMultipleGapsInChain(): void
    {
        $events = [
            [
                'event_id' => 'evt_001',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_a',
                'prev_event_hash' => null,
                'canonical_payload_hash' => 'hash_a',
            ],
            [
                'event_id' => 'evt_002',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_b',
                'prev_event_hash' => 'wrong_hash_1',
                'canonical_payload_hash' => 'hash_b',
            ],
            [
                'event_id' => 'evt_003',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_c',
                'prev_event_hash' => 'hash_b',
                'canonical_payload_hash' => 'hash_c',
            ],
            [
                'event_id' => 'evt_004',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_d',
                'prev_event_hash' => 'wrong_hash_2',
                'canonical_payload_hash' => 'hash_d',
            ],
        ];

        $results = $this->verifier->verify($events);
        $result = $results[0];

        $this->assertSame('gap', $result['status']);
        $this->assertSame(2, $result['gap_count']);
        $this->assertSame([], $result['tampered_event_ids']);
    }

    public function testTamperedEventTakesPrecedenceOverGap(): void
    {
        $events = [
            [
                'event_id' => 'evt_001',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_a',
                'prev_event_hash' => null,
                'canonical_payload_hash' => 'hash_a',
            ],
            [
                'event_id' => 'evt_002',
                'scope_key' => 'scope_a',
                'event_hash' => 'wrong_hash',
                'prev_event_hash' => 'wrong_prev_hash',
                'canonical_payload_hash' => 'hash_b',
            ],
        ];

        $results = $this->verifier->verify($events);
        $result = $results[0];

        $this->assertSame('tampered', $result['status']);
        $this->assertSame(1, $result['gap_count']);
        $this->assertSame(['evt_002'], $result['tampered_event_ids']);
    }

    public function testEventsAreSortedByEventId(): void
    {
        $events = [
            [
                'event_id' => 'evt_003',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_c',
                'prev_event_hash' => 'hash_b',
                'canonical_payload_hash' => 'hash_c',
            ],
            [
                'event_id' => 'evt_001',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_a',
                'prev_event_hash' => null,
                'canonical_payload_hash' => 'hash_a',
            ],
            [
                'event_id' => 'evt_002',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_b',
                'prev_event_hash' => 'hash_a',
                'canonical_payload_hash' => 'hash_b',
            ],
        ];

        $results = $this->verifier->verify($events);
        $result = $results[0];

        $this->assertSame('ok', $result['status']);
        $this->assertSame(3, $result['chain_length']);
        $this->assertSame('evt_001', $result['first_event_id']);
        $this->assertSame('evt_003', $result['last_event_id']);
    }

    public function testSchemaVersionIsCorrect(): void
    {
        $events = [
            [
                'event_id' => 'evt_001',
                'scope_key' => 'scope_a',
                'event_hash' => 'hash_a',
                'prev_event_hash' => null,
                'canonical_payload_hash' => 'hash_a',
            ],
        ];

        $results = $this->verifier->verify($events);
        $result = $results[0];

        $this->assertSame('atlas.evidence.ledger_hash_chain_integrity.v1', $result['schema_version']);
    }
}
