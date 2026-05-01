<?php

namespace App\Services\Ai\Runtime;

class AtlasTestCommandResolver
{
    /**
     * @param  array<int,string>  $commands
     */
    public function preferred(array $commands): string
    {
        return in_array('php artisan test', $commands, true)
            ? $this->artisanTestCommand()
            : ($commands[0] ?? '');
    }

    public function artisanTestCommand(): string
    {
        $binary = PHP_BINARY !== '' ? PHP_BINARY : 'php';

        return escapeshellarg($binary).' artisan test';
    }
}
