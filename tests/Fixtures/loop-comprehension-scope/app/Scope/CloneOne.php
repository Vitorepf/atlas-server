<?php

namespace App\Scope;

final class CloneOne
{
    public function transform(array $items): array
    {
        $out = [];
        foreach ($items as $key => $item) {
            if ($item > 0) {
                $out[$key] = $item * 2;
            }
        }

        return $out;
    }
}
