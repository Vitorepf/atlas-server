<?php

namespace Tests\Unit;

use App\Services\Semantic\AtlasVaultCommandInput;
use Tests\TestCase;

class AtlasVaultCommandInputTest extends TestCase
{
    public function test_normalizes_atlas_vault_command_limits_with_canonical_caps(): void
    {
        $input = new AtlasVaultCommandInput;

        $this->assertSame(AtlasVaultCommandInput::DEFAULT_SYNC_LIMIT, $input->syncLimit(null));
        $this->assertSame(AtlasVaultCommandInput::DEFAULT_CONFLICT_LIMIT, $input->conflictLimit('bad'));
        $this->assertSame(1, $input->syncLimit(-10));
        $this->assertSame(AtlasVaultCommandInput::MAX_COMMAND_LIMIT, $input->syncLimit(9999));
        $this->assertSame(AtlasVaultCommandInput::MAX_COMMAND_LIMIT, $input->conflictLimit(9999));
    }
}
