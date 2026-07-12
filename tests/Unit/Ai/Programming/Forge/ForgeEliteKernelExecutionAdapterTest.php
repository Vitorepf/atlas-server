<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Forge;

use App\Services\Ai\Programming\Forge\ForgeEliteKernelExecutionAdapter;
use PHPUnit\Framework\TestCase;

final class ForgeEliteKernelExecutionAdapterTest extends TestCase
{
    public function test_workspace_serial_lock_key_is_stable_and_workspace_scoped(): void
    {
        $first = ForgeEliteKernelExecutionAdapter::workspaceLockKey('/tmp/forge-workspace');
        $same = ForgeEliteKernelExecutionAdapter::workspaceLockKey('/tmp/forge-workspace');
        $other = ForgeEliteKernelExecutionAdapter::workspaceLockKey('/tmp/other-workspace');

        self::assertSame($first, $same);
        self::assertNotSame($first, $other);
        self::assertStringStartsWith('atlas:forge:integration:workspace:', $first);
    }
}
