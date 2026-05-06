<?php

namespace App\Services\Ai\Runtime;

use App\Support\AtlasPhpBinary;

class AtlasTestCommandResolver
{
    public function __construct(private readonly TestCommandInput $input) {}

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
        return implode(' ', [
            escapeshellarg(AtlasPhpBinary::path()),
            '-d',
            'memory_limit='.$this->memoryLimit(),
            'artisan',
            'test',
        ]);
    }

    private function memoryLimit(): string
    {
        return $this->input->memoryLimit();
    }
}
