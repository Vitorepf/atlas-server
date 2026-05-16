<?php

declare(strict_types=1);

namespace Tests\Unit\Captures\Events;

use App\Domain\Captures\Events\CaptureEventGateway;
use PHPUnit\Framework\TestCase;

final class CaptureEventGatewayTest extends TestCase
{
    public function test_accepts_v1_with_deprecation_notice(): void
    {
        $notice = null;
        set_error_handler(function (int $errno, string $msg) use (&$notice): bool {
            $notice = $msg;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $gateway = new CaptureEventGateway;
            $event = $gateway->accept([
                'schema_version' => 'capture_event.v1',
                'capture_id' => 'c1',
                'tenant_id' => 't1',
                'body' => 'hello',
                'tags' => [],
            ]);
            $this->assertSame('capture_event.v1', $event['schema_version']);
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($notice, 'v1 must emit deprecation notice');
    }

    public function test_accepts_v2_without_notice(): void
    {
        $notice = null;
        set_error_handler(function (int $errno, string $msg) use (&$notice): bool {
            $notice = $msg;

            return true;
        }, E_USER_DEPRECATED);

        try {
            $gateway = new CaptureEventGateway;
            $event = $gateway->accept([
                'schema_version' => 'capture_event.v2',
                'capture_id' => 'c1',
                'tenant_id' => 't1',
                'body' => 'hello',
                'tags' => [],
                'source_channel' => 'desktop',
            ]);
            $this->assertSame('capture_event.v2', $event['schema_version']);
        } finally {
            restore_error_handler();
        }

        $this->assertNull($notice, 'v2 must not emit deprecation notice');
    }

    public function test_rejects_unknown_future_version(): void
    {
        $this->expectException(\Throwable::class);
        $gateway = new CaptureEventGateway;
        $gateway->accept(['schema_version' => 'capture_event.v3']);
    }
}
