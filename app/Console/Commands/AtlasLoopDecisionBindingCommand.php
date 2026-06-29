<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Receipts\AtlasSelfConstructionDecisionBinding;
use Illuminate\Console\Command;

/**
 * Arms the dormant orphan {@see AtlasSelfConstructionDecisionBinding::verify()} at the operator surface: proves
 * that downstream fact classes (task/verification/merge/learning receipts) bind back to a known decision
 * receipt via decision_ref + matching decision_hash, emitting a per-row binding status (bound | missing_binding
 * | invalid_hash | stale_binding).
 *
 * Pure + read-only: worker self-report without an authoritative decision binding is never final evidence.
 */
final class AtlasLoopDecisionBindingCommand extends Command
{
    protected $signature = 'atlas:loop:decision-binding {--facts=} {--json}';

    protected $description = 'Read-only decision-binding verification over a receipt fact index.';

    public function handle(): int
    {
        $raw = trim((string) $this->option('facts'));
        if ($raw === '') {
            return $this->refuse('decision-binding requires --facts=<json object or path>');
        }
        if (is_file($raw) && is_readable($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $factIndex = json_decode($raw, true);
        if (! is_array($factIndex)) {
            return $this->refuse('--facts must be a JSON object');
        }

        $result = app(AtlasSelfConstructionDecisionBinding::class)->verify($factIndex);
        $bindings = $result['bindings'];
        $allBound = $bindings !== [] && array_reduce(
            $bindings,
            static fn (bool $carry, array $b): bool => $carry && ($b['status'] ?? '') === AtlasSelfConstructionDecisionBinding::STATUS_BOUND,
            true,
        );

        $facts = [
            'schema' => $result['schema'],
            'all_bound' => $allBound,
            'binding_count' => count($bindings),
            'bindings' => $bindings,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line('all_bound: '.($allBound ? 'yes' : 'no').'  bindings: '.$facts['binding_count']);
            foreach ($bindings as $b) {
                $this->line('  '.$b['kind'].'/'.$b['id'].': '.$b['status']);
            }
        }

        return self::SUCCESS;
    }

    private function refuse(string $message): int
    {
        $this->line((string) json_encode([
            'outcome' => 'refused',
            'reason' => 'usage_error',
            'message' => $message,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
