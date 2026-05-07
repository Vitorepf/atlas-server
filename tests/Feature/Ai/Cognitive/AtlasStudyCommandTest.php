<?php

namespace Tests\Feature\Ai\Cognitive;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasStudyCommandTest extends TestCase
{
    public function test_study_command_modulates_output_for_novato_and_expert(): void
    {
        Artisan::call('atlas:study', [
            'topic' => 'agricultura-soja',
            '--dreyfus-stage' => 1,
            '--json' => true,
        ]);
        $novato = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        Artisan::call('atlas:study', [
            'topic' => 'laravel-queues',
            '--dreyfus-stage' => 4,
            '--json' => true,
        ]);
        $expert = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('novato', data_get($novato, 'dreyfus.resolution.pedagogy_mode_resolved'));
        $this->assertContains('glossary_first', data_get($novato, 'dreyfus.prompt_policy.rules'));
        $this->assertSame('expert', data_get($expert, 'dreyfus.resolution.pedagogy_mode_resolved'));
        $this->assertContains('ill_structured_case', data_get($expert, 'dreyfus.prompt_policy.rules'));
        $this->assertContains('pedagogy_matches_stage', collect(data_get($expert, 'packet.gates'))->pluck('id')->all());
    }
}
