<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasLearningMutationRuntimeService;
use Illuminate\Console\Command;

class AtlasLearningMutationCommand extends Command
{
    protected $signature = 'atlas:learning:mutation
        {--action=list-evaluations : evaluate|apply|list-evaluations|list-applications}
        {--proposal-id= : proposal_id for evaluate/apply}
        {--proposal-hash= : proposal_hash for apply}
        {--context-json= : JSON context for evaluate}
        {--mutation-json= : JSON mutation details for apply}
        {--approver=operator : approver_actor for apply (must be "operator")}
        {--approval-receipt= : HMAC approval receipt for apply}
        {--limit=10 : tail size for list-*}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Learning Mutation Runtime — evaluate proposals + apply gated mutations (operator approval required).';

    public function handle(AtlasLearningMutationRuntimeService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');
        $limit = max(1, (int) $this->option('limit'));

        try {
            switch ($action) {
                case 'evaluate':
                    $pid = (string) ($this->option('proposal-id') ?? '');
                    if ($pid === '') {
                        $this->error('--proposal-id obrigatório.');

                        return self::FAILURE;
                    }
                    $raw = (string) ($this->option('context-json') ?? '');
                    $ctx = $raw !== '' ? json_decode($raw, true) : [];
                    if (! is_array($ctx)) {
                        $ctx = [];
                    }

                    return $this->emit($svc->evaluate($pid, $ctx), $json);

                case 'apply':
                    $pid = (string) ($this->option('proposal-id') ?? '');
                    $ph = (string) ($this->option('proposal-hash') ?? '');
                    $approver = (string) $this->option('approver');
                    $ar = (string) ($this->option('approval-receipt') ?? '');
                    if ($pid === '' || $ph === '' || $ar === '') {
                        $this->error('--proposal-id, --proposal-hash, --approval-receipt obrigatórios.');

                        return self::FAILURE;
                    }
                    $raw = (string) ($this->option('mutation-json') ?? '');
                    $mut = $raw !== '' ? json_decode($raw, true) : [];
                    if (! is_array($mut)) {
                        $mut = [];
                    }

                    return $this->emit($svc->apply($pid, $ph, $approver, $ar, $mut), $json);

                case 'list-evaluations':
                    $list = $svc->listEvaluations();

                    return $this->emit(['count' => count($list), 'tail' => array_slice($list, -$limit)], $json);

                case 'list-applications':
                    $list = $svc->listApplications();

                    return $this->emit(['count' => count($list), 'tail' => array_slice($list, -$limit)], $json);

                default:
                    $this->error("Unknown action '{$action}'.");

                    return self::FAILURE;
            }
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function emit(array $payload, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) ? "{$k}: {$v}" : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
