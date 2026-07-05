<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ReadinessCommandSurface;
use PHPUnit\Framework\TestCase;

/**
 * Proves ReadinessCommandSurface::packetCommand() sanitises the packet ID
 * through safeCommandValue() instead of concatenating raw — closing a
 * command-injection bug on the operator command surface.
 */
final class ReadinessCommandSurfaceTest extends TestCase
{
    public function test_safe_packet_id_is_unchanged(): void
    {
        $cmd = ReadinessCommandSurface::packetCommand('reserve-next', 'pkt-123');
        $this->assertStringContainsString('--packet=pkt-123', $cmd);
    }

    public function test_injectable_packet_id_is_sanitised(): void
    {
        $cmd = ReadinessCommandSurface::packetCommand('reserve-next', 'x; rm -rf /');
        $this->assertStringNotContainsString(';', $cmd);
        $this->assertStringNotContainsString('rm -rf', $cmd);
        $this->assertStringNotContainsString('--packet=x;', $cmd);
    }

    public function test_empty_packet_id_omits_flag(): void
    {
        $cmd = ReadinessCommandSurface::packetCommand('reserve-next', '');
        $this->assertStringNotContainsString('--packet=', $cmd);
    }

    public function test_non_string_packet_id_omits_flag(): void
    {
        $cmd = ReadinessCommandSurface::packetCommand('reserve-next', null);
        $this->assertStringNotContainsString('--packet=', $cmd);
    }

    public function test_packet_id_with_spaces_is_sanitised(): void
    {
        $cmd = ReadinessCommandSurface::packetCommand('reserve-next', 'pkt two words');
        $this->assertStringNotContainsString('pkt two words', $cmd);
        $this->assertStringContainsString('--packet=pkt-two-words', $cmd);
    }

    public function test_option_is_preserved(): void
    {
        $cmd = ReadinessCommandSurface::packetCommand('create-inbox', 'pkt-1');
        $this->assertStringStartsWith('php artisan atlas:ai:self-construction --create-inbox', $cmd);
        $this->assertStringEndsWith(' --json', $cmd);
    }
}
