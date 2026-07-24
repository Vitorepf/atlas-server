<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosP4RealOperationGauntlet;
use Tests\TestCase;

final class AaeosP4CertifierReadOnlyBoundaryTest extends TestCase
{
    public function test_p4_certify_command_exists_and_is_helpable(): void
    {
        $exit = \Illuminate\Support\Facades\Artisan::call('atlas:aaeos:certify', ['--help' => true]);
        $this->assertSame(0, $exit);
        $out = \Illuminate\Support\Facades\Artisan::output();
        $this->assertStringContainsString('certify', strtolower($out));
    }

    public function test_gauntlet_forbids_certifier_ledger_append_and_provider_spawn(): void
    {
        $boundary = AaeosP4RealOperationGauntlet::certifyReadOnlyBoundary([
            'profile' => 'p4',
            'append_ledger' => false,
            'spawn_provider' => false,
        ]);
        $this->assertTrue($boundary['ok']);
    }
}
