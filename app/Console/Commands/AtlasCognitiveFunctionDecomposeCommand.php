<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AtlasCognitiveFunctionDecomposerService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasCognitiveFunctionDecomposeCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:cognitive-function:decompose
        {input : Natural language request to decompose}
        {--role= : Operator role hint (engineer|auditor|writer|researcher|...)}
        {--framework= : Framework hint (cartography|kernel_vault|programming|sdd|bdd|mission_mode|hyperflow|vision)}
        {--privacy=normal : Privacy class}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Decompose a natural-language pedido into the canonical 6-axis cognitive function tuple.';

    public function handle(AtlasCognitiveFunctionDecomposerService $svc): int
    {
        $input = (string) $this->argument('input');
        $env = $svc->decompose($input, [
            'role' => (string) $this->option('role') ?: null,
            'framework' => (string) $this->option('framework') ?: null,
            'privacy_class' => (string) $this->option('privacy'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($env));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Dominant</>', $env['dominant_function']);
        foreach ($env['weights'] as $axis => $w) {
            $bar = str_repeat('▓', (int) round($w * 30));
            $this->components->twoColumnDetail($axis, $bar.' '.number_format((float) $w, 3));
        }
        $this->components->twoColumnDetail('Hash', $env['decomposition_hash']);

        return self::SUCCESS;
    }
}
