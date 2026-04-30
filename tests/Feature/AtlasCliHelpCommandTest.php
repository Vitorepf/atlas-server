<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasCliHelpCommandTest extends TestCase
{
    public function test_cli_help_outputs_command_map_as_json(): void
    {
        $exitCode = Artisan::call('atlas:cli:help', [
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"Atlas CLI"', $output);
        $this->assertStringContainsString('atlas bootstrap', $output);
        $this->assertStringContainsString('atlas dev', $output);
        $this->assertStringContainsString('atlas doctor', $output);
        $this->assertStringContainsString('atlas install', $output);
        $this->assertStringContainsString('atlas quality', $output);
        $this->assertStringContainsString('atlas dogfood', $output);
        $this->assertStringContainsString('atlas release', $output);
    }
}
