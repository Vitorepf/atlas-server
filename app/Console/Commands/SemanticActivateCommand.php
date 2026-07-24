<?php

namespace App\Console\Commands;

use App\Services\Semantic\ActivationEngine;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class SemanticActivateCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:semantic:activate
        {--context=morning_briefing : Activation context type}
        {--signal=* : Extra trigger signal to include}';

    protected $description = 'Create contextual semantic memory activations.';

    public function handle(ActivationEngine $engine): int
    {
        $result = $engine->createForContext(
            contextType: (string) $this->option('context'),
            contextPayload: ['signals' => array_values((array) $this->option('signal'))],
        );

        $this->line($this->encode($result));

        return self::SUCCESS;
    }
}
