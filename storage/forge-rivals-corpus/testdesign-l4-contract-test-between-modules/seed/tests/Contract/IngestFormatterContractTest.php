<?php

declare(strict_types=1);

/*
 * Contract version: v1
 * Approval channel: #inbox-contract-board
 *
 * This test pins the wire shape produced by InboxIngest::produce() and
 * consumed by CaptureFormatter::format(). Any change to either side
 * must be approved on the channel above and the fixture must be bumped
 * to a new version file (ingest_formatter_v2.json).
 */

namespace Tests\Contract;

use App\Domain\Captures\Format\CaptureFormatter;
use App\Domain\Inbox\InboxIngest;
use PHPUnit\Framework\TestCase;

final class IngestFormatterContractTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../fixtures/contracts/ingest_formatter_v1.json';

    public function test_golden_fixture_present(): void
    {
        $this->assertFileExists(self::FIXTURE);
    }

    public function test_produce_matches_golden_input_for_formatter(): void
    {
        $rows = json_decode((string) file_get_contents(self::FIXTURE), true);
        $this->assertIsArray($rows);
        foreach ($rows as $idx => $row) {
            $produced = InboxIngest::produce($row['ingest_input']);
            $this->assertSame(
                $row['expected_formatter_input'],
                $produced,
                "row #{$idx}: producer drifted from contract v1",
            );

            // Roundtrip: formatter must accept whatever the producer emits.
            $formatted = CaptureFormatter::format($produced);
            $this->assertNotSame('', $formatted);
        }
    }
}
