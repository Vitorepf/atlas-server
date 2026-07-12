<?php

declare(strict_types=1);

namespace App\Services\Ai\Product;

interface ProductIntentUncertaintyProbe
{
    public function probe(ProductIntentCase $case): ProductIntentProbeResult;
}
