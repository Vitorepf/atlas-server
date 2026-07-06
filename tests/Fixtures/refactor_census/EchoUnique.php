<?php

declare(strict_types=1);

namespace Tests\Fixtures\RefactorCensus;

final class EchoUnique
{
    public function solo(string $word): string
    {
        return strrev($word).'-solo';
    }
}
