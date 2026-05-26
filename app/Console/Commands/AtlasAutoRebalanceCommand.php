<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Patamar4\AtlasSubsystemAutoRebalanceService;
use Illuminate\Console\Command;

/**
 * Atlas Subsystem Auto-Rebalance CLI.
 *
 * Doc canon: docs/engineering-knowledge-base/atlas-subsystem-auto-rebalance.md
 *
 *   plan --kind=<canon>   READ-ONLY rebalance plan + diagnostics
 *   apply --kind --actor --reason [--operator-class=operator]   consume Trust Budget + emit advice
 *   list                  all receipts
 *   latest                last receipt
 */
class AtlasAutoRebalanceCommand extends Command
{
    protected $signature = 'atlas:rebalance
        {--action=plan : plan|apply|list|latest}
        {--kind=}
        {--actor=}
        {--reason=}
        {--operator-class=operator}
        {--json}';

    protected $description = 'Atlas Subsystem Auto-Rebalance — cache/AGRN/AEMOR/MCP pool rebalance plans + Trust Budget gated apply.';

    public function handle(AtlasSubsystemAutoRebalanceService $svc): int
    {
        $action = (string) $this->option('action');
        $json = (bool) $this->option('json');

        switch ($action) {
            case 'plan':
                $kind = (string) ($this->option('kind') ?? '');
                if ($kind === '') {
                    $this->error('plan requires --kind.');

                    return self::FAILURE;
                }
                try {
                    $env = $svc->plan($kind, (string) ($this->option('actor') ?? 'auto_rebalance'));
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit($env, $json);

            case 'apply':
                $kind = (string) ($this->option('kind') ?? '');
                $actor = (string) ($this->option('actor') ?? '');
                $reason = (string) ($this->option('reason') ?? '');
                if ($kind === '' || $actor === '' || $reason === '') {
                    $this->error('apply requires --kind --actor --reason.');

                    return self::FAILURE;
                }
                try {
                    $env = $svc->apply($kind, $actor, $reason, (string) $this->option('operator-class'));
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit($env, $json);

            case 'latest':
                return $this->emit($svc->lastReceipt() ?? ['note' => 'no receipt yet'], $json);

            case 'list':
                $list = $svc->listReceipts();

                return $this->emit(['count' => count($list), 'receipts' => $list], $json);

            default:
                $this->error("action '{$action}' desconhecida.");

                return self::FAILURE;
        }
    }

    private function emit(array $payload, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) || $v === null ? "{$k}: ".var_export($v, true) : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
