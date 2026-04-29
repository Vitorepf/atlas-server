<?php

namespace App\Console\Commands;

use App\Services\Semantic\ActivationEngine;
use Illuminate\Console\Command;

class SemanticActivateCommand extends Command
{
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

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
