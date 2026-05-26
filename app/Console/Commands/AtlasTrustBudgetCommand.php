<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Governance\AtlasTrustBudgetService;
use Illuminate\Console\Command;

/**
 * Atlas Trust Budget CLI.
 *
 * Doc canon: docs/engineering-knowledge-base/atlas-trust-budget.md
 *
 *   canon                                  show canonical (tier × class) budget table
 *   state --tier --operator-class          show daily state
 *   check --tier --operator-class          verdict only (no record)
 *   consume --tier --operator-class --action-kind --actor --reason
 *   rollback --action-id --actor --reason
 *   list                                   all receipts
 */
class AtlasTrustBudgetCommand extends Command
{
    protected $signature = 'atlas:trust-budget
        {--action=state : canon|state|check|consume|rollback|list}
        {--tier=}
        {--operator-class=}
        {--action-kind=}
        {--actor=}
        {--reason=}
        {--action-id=}
        {--json}';

    protected $description = 'Atlas Trust Budget — tiered daily budget for mutative actions with rollback + receipt.';

    public function handle(AtlasTrustBudgetService $svc): int
    {
        $action = (string) $this->option('action');
        $json = (bool) $this->option('json');

        switch ($action) {
            case 'canon':
                return $this->emit($svc->canonicalBudget(), $json);

            case 'state':
                $tier = (string) ($this->option('tier') ?? '');
                $class = (string) ($this->option('operator-class') ?? '');
                if ($tier === '' || $class === '') {
                    $this->error('state requires --tier and --operator-class.');

                    return self::FAILURE;
                }

                return $this->emit($svc->state($tier, $class), $json);

            case 'check':
                $tier = (string) ($this->option('tier') ?? '');
                $class = (string) ($this->option('operator-class') ?? '');
                if ($tier === '' || $class === '') {
                    $this->error('check requires --tier and --operator-class.');

                    return self::FAILURE;
                }

                return $this->emit($svc->check($tier, $class), $json);

            case 'consume':
                try {
                    $env = $svc->consume([
                        'tier' => (string) $this->option('tier'),
                        'operator_class' => (string) $this->option('operator-class'),
                        'action_kind' => (string) $this->option('action-kind'),
                        'actor' => (string) $this->option('actor'),
                        'reason' => (string) $this->option('reason'),
                    ]);
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit($env, $json);

            case 'rollback':
                $aid = (string) ($this->option('action-id') ?? '');
                $actor = (string) ($this->option('actor') ?? '');
                $reason = (string) ($this->option('reason') ?? '');
                if ($aid === '' || $actor === '' || $reason === '') {
                    $this->error('rollback requires --action-id --actor --reason.');

                    return self::FAILURE;
                }
                try {
                    $env = $svc->rollback($aid, $actor, $reason);
                } catch (\Throwable $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit($env, $json);

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
