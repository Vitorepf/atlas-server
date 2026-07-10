<?php

namespace App\Services\Ai\Rivals\Adapters\External;

/**
 * Legacy alias adapter. New plans must use Tau2BenchAdapter / BfclAdapter.
 * Kept only so old suite_id=tau2_bfcl artifacts can still be inspected.
 */
class Tau2BfclAdapter extends Tau2BenchAdapter
{
    public function suiteId(): string
    {
        return 'tau2_bench';
    }
}
