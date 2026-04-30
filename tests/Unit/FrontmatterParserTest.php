<?php

namespace Tests\Unit;

use App\Services\Semantic\FrontmatterParser;
use Tests\TestCase;

class FrontmatterParserTest extends TestCase
{
    public function test_validate_accepts_skill_system_activation_contract(): void
    {
        $parser = new FrontmatterParser;

        $errors = $parser->validate([
            'id' => 'atlas-skill-example',
            'type' => 'atlas_ai_skill',
            'title' => 'Example',
            'status' => 'default',
            'summary' => 'Example skill.',
            'activation' => [
                'primary_triggers' => ['example'],
                'negative_triggers' => ['not example'],
            ],
        ]);

        $this->assertSame([], $errors);
    }
}
