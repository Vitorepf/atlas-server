<?php

namespace App\Services\Ai\Runtime;

class AtlasTestCommandResolver
{
    private const DEFAULT_MEMORY_LIMIT = '1024M';

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

        return implode(' ', [
            escapeshellarg($binary),
            '-d',
            'memory_limit='.$this->memoryLimit(),
            'artisan',
            'test',
        ]);
    }

    private function memoryLimit(): string
    {
        $configured = (string) config('atlas.ai.test_memory_limit', self::DEFAULT_MEMORY_LIMIT);

        return preg_match('/^\d+[KMG]?$/i', $configured) === 1
            ? $configured
            : self::DEFAULT_MEMORY_LIMIT;
    }
}
