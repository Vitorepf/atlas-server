<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Gateway\AtlasGatewayPreflightService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Atlas Gateway Preflight CLI.
 *
 * Doc canon: docs/engineering-knowledge-base/atlas-gateway-preflight.md
 *
 *   preflight --input=... --provider=... [--privacy=normal] [--autonomy=execute_with_approval] [--force]
 *   latest
 *   list
 */
class AtlasGatewayPreflightCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:gateway:preflight
        {--action=preflight : preflight|latest|list}
        {--input= : operator input text (for preflight)}
        {--provider= : provider key (for preflight)}
        {--privacy=normal}
        {--autonomy=execute_with_approval}
        {--force : force preflight even if not classified major}
        {--json}';

    protected $description = 'Atlas Gateway Preflight — counterfactual tree before token spend on major decisions.';

    public function handle(AtlasGatewayPreflightService $svc): int
    {
        $action = (string) $this->option('action');
        $json = (bool) $this->option('json');

        switch ($action) {
            case 'preflight':
                $input = (string) ($this->option('input') ?? '');
                $provider = (string) ($this->option('provider') ?? '');
                if ($input === '' || $provider === '') {
                    $this->error('preflight requires --input and --provider.');

                    return self::FAILURE;
                }
                $env = $svc->preflight($input, $provider, [
                    'privacy_class' => (string) $this->option('privacy'),
                    'requested_autonomy' => (string) $this->option('autonomy'),
                    'force_preflight' => (bool) $this->option('force'),
                ]);

                return $this->emit($env, $json);

            case 'latest':
                return $this->emit($svc->lastEnvelope() ?? ['note' => 'no preflight yet'], $json);

            case 'list':
                $list = $svc->listEnvelopes();

                return $this->emit(['count' => count($list), 'envelopes' => $list], $json);

            default:
                $this->error("action '{$action}' desconhecida.");

                return self::FAILURE;
        }
    }

    private function emit(array $payload, bool $json): int
    {
        if ($json) {
            $this->line($this->encode($payload));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) || $v === null ? "{$k}: ".var_export($v, true) : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
