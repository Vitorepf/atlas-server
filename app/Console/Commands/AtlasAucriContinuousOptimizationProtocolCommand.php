<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAucriContinuousOptimizationProtocolService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Runtime surface for the AUCRI Continuous Optimization Protocol (ACOPRO).
 *
 * With no args it prints the contract snapshot (required experiment fields,
 * must-keep kinds, the ten techniques, and a worked clean-promote example).
 * With --experiment it evaluates one baseline-vs-variant optimization experiment
 * (JSON object) and emits the promote / revert / reject decision with receipt.
 *
 * @see docs/engineering-knowledge-base/atlas-aucri-continuous-optimization-protocol.md
 */
final class AtlasAucriContinuousOptimizationProtocolCommand extends Command
{
    protected $signature = 'atlas:aaeos:aucri-continuous-optimization-protocol
        {--experiment= : JSON object of one optimization experiment to evaluate (promote/revert/reject)}
        {--json : Emit canonical JSON}';

    protected $description = 'Evaluate AUCRI continuous-optimization experiments: promote only when tokens drop without quality, evidence or must-keep loss.';

    public function handle(AtlasAucriContinuousOptimizationProtocolService $service): int
    {
        try {
            $experimentOption = $this->option('experiment');

            if (is_string($experimentOption) && trim($experimentOption) !== '') {
                $decoded = json_decode($experimentOption, true);
                if (! is_array($decoded)) {
                    throw new \InvalidArgumentException('--experiment must be a JSON object.');
                }
                $payload = $service->evaluateExperiment($decoded);
            } else {
                $payload = $service->snapshot();
            }
        } catch (Throwable $e) {
            $error = [
                'schema_version' => AtlasAucriContinuousOptimizationProtocolService::SCHEMA_VERSION,
                'ok' => false,
                'error' => $e->getMessage(),
            ];

            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($error, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->components->error($e->getMessage());
            }

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas AUCRI Continuous Optimization Protocol', (string) $payload['schema_version']);

        if (array_key_exists('decision', $payload)) {
            $this->components->twoColumnDetail('Decision', (string) $payload['decision']);
            $this->components->twoColumnDetail('Reason', (string) $payload['reason']);
            $this->components->twoColumnDetail('Tokens saved', (string) data_get($payload, 'token_saving.tokens_saved', 0));
            $this->components->twoColumnDetail('Gates failed', (string) count((array) data_get($payload, 'gates_failed', [])));
        } else {
            $this->components->twoColumnDetail('Required fields', (string) count((array) data_get($payload, 'required_fields', [])));
            $this->components->twoColumnDetail('Techniques', (string) count((array) data_get($payload, 'techniques', [])));
            $this->components->twoColumnDetail('Example decision', (string) data_get($payload, 'example_clean_promote.decision', 'n/a'));
        }

        return self::SUCCESS;
    }
}
