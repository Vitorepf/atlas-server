<?php

namespace App\Services\Ai\Skills;

class InvalidSkillManifestException extends \RuntimeException
{
    /**
     * @param  array<int,string>  $diagnostics
     */
    public function __construct(string $path, array $diagnostics)
    {
        parent::__construct('Invalid skill manifest ['.$path.']: '.implode('; ', $diagnostics));
    }
}
