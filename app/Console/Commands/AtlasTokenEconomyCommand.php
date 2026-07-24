<?php

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasTokenEconomyRuntimeService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Compatibility input alias for the Atlas Token Economy Runtime.
 *
 * @see docs/engineering-knowledge-base/atlas-token-economy-runtime.md
 */
class AtlasTokenEconomyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:context:token-economy:input
        {--input= : JSON input for the optimization pass}
        {--json : Print machine-readable JSON}';

    protected $description = 'Compatibility alias for Atlas Token Economy JSON-input optimization.';

    public function handle(AtlasTokenEconomyRuntimeService $tokenEconomy): int
    {
        $raw = $this->option('input');
        $input = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : [];
        if (! is_array($input)) {
            $input = [];
        }

        try {
            $result = $tokenEconomy->optimize($input);
        } catch (Throwable $e) {
            $result = ['error' => $e::class, 'message' => $e->getMessage()];
        }

        if ((bool) $this->option('json')) {
            $this->jsonLine($result);

            return self::SUCCESS;
        }

        foreach ((array) $result as $key => $value) {
            $this->components->twoColumnDetail((string) $key, is_scalar($value) || $value === null ? (string) $value : json_encode($value));
        }

        return self::SUCCESS;
    }
}
