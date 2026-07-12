<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Execution;

use InvalidArgumentException;

final readonly class ForgeControlCommand
{
    public const COMMANDS = ['pause', 'drain', 'resume', 'cancel'];
    private function __construct(public string $command) {}
    public static function fromString(string $command): self
    {
        $command = trim($command);
        if (! in_array($command, self::COMMANDS, true)) throw new InvalidArgumentException('forge_control_command_invalid');
        return new self($command);
    }
}
