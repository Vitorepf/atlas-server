<?php

namespace App\Other;

// A same-basename decoy. It mentions the short word Widget in prose, but NEVER the scoped
// fully-qualified name, so a basename grep would false-match it while the FQCN oracle must not.
final class Widget
{
    public function noop(): int
    {
        return 0;
    }
}
