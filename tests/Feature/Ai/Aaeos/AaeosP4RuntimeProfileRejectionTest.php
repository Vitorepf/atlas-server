<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosP4RealOperationGauntlet;
use Tests\TestCase;

final class AaeosP4RuntimeProfileRejectionTest extends TestCase
{
    public function test_same_producer_verifier_url_is_rejected(): void
    {
        $pre = AaeosP4RealOperationGauntlet::preflight([
            'ATLAS_P4_PG_PRODUCER_URL' => 'pgsql://atlas_p4_x@localhost/db',
            'ATLAS_P4_PG_VERIFIER_URL' => 'pgsql://atlas_p4_x@localhost/db',
        ]);
        $this->assertFalse($pre['durable_pg_ready']);
        $this->assertContains('producer_verifier_must_be_distinct', $pre['blockers']);
    }
}
