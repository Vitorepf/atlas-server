<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionContinuousRuntimeCycleRunner;
use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionContinuousRuntimeLearningIntegration;
use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionContinuousRuntimeReplenisherIntegration;
use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration;
use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionContinuousRuntimeWorkerIntegration;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator surface for the continuous Self-Construction runtime brain.
 *
 *   plan          summarise the bounded cycle plan (final_runtime_owner=atlas_native, no provider call)
 *   replenish     ContinuousRuntimeReplenisherIntegration::integrate(facts)
 *   worker        ContinuousRuntimeWorkerIntegration::integrate(packet)
 *   verify-merge  VerificationMergeIntegration::buildVerificationRequest + buildMergeDecision
 *   learn         ContinuousRuntimeLearningIntegration::integrate(outcomes)
 *   cycle         run one bounded cycle over facts-injected collaborators (safety stop honoured)
 *
 * Facts arrive via `--facts=<path-to-json>`. NEVER calls a provider, NEVER spawns a subprocess,
 * NEVER touches git or disk beyond reading the supplied facts file.
 */
final class AtlasSelfConstructionContinuousRuntimeCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    public const SCHEMA = 'atlas.continuous_runtime.cli.v1';

    public const RUNTIME_OWNER = 'atlas_native';

    protected $signature = 'atlas:self-construction:continuous-runtime {action : plan|replenish|worker|verify-merge|learn|cycle} {--facts=} {--json}';

    protected $description = 'Read-only continuous Self-Construction runtime CLI.';

    public function handle(
        AtlasSelfConstructionContinuousRuntimeReplenisherIntegration $replenisher,
        AtlasSelfConstructionContinuousRuntimeWorkerIntegration $worker,
        AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration $verifyMerge,
        AtlasSelfConstructionContinuousRuntimeLearningIntegration $learner,
    ): int {
        $action = (string) $this->argument('action');

        $payload = match ($action) {
            'plan' => $this->plan(),
            'replenish' => $this->replenish($replenisher),
            'worker' => $this->worker($worker),
            'verify-merge' => $this->verifyMerge($verifyMerge),
            'learn' => $this->learn($learner),
            'cycle' => $this->cycle($replenisher, $worker, $verifyMerge, $learner),
            default => null,
        };
        if ($payload === null) {
            $this->error('unknown action: '.$action);

            return self::EXIT_USAGE;
        }
        if (isset($payload['__usage_error__'])) {
            return self::EXIT_USAGE;
        }

        $this->emit($payload);

        return self::EXIT_OK;
    }

    /**
     * @return array<string,mixed>
     */
    private function plan(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'final_runtime_owner' => self::RUNTIME_OWNER,
            'actions' => ['plan', 'replenish', 'worker', 'verify-merge', 'learn', 'cycle'],
            'evidence_obligations' => [
                'tests_or_gates_result',
                'safety_stop_reason_if_any',
                'cycle_stop_reason_if_any',
            ],
            'safety' => [
                'no_provider_calls' => true,
                'no_subprocess' => true,
                'no_git' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function replenish(AtlasSelfConstructionContinuousRuntimeReplenisherIntegration $integration): array
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return ['__usage_error__' => true];
        }

        return $this->wrap($integration->integrate($facts));
    }

    /**
     * @return array<string,mixed>
     */
    private function worker(AtlasSelfConstructionContinuousRuntimeWorkerIntegration $integration): array
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return ['__usage_error__' => true];
        }
        $packet = is_array($facts['packet'] ?? null) ? $facts['packet'] : $facts;

        return $this->wrap($integration->integrate($packet));
    }

    /**
     * @return array<string,mixed>
     */
    private function verifyMerge(AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration $integration): array
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return ['__usage_error__' => true];
        }
        $evidence = (array) ($facts['worker_evidence'] ?? []);
        $verdict = (array) ($facts['verification_verdict'] ?? []);
        $rollback = (array) ($facts['rollback_plan'] ?? []);

        return $this->wrap([
            'verification_request' => $integration->buildVerificationRequest($evidence),
            'merge_decision' => $integration->buildMergeDecision($verdict, $rollback),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function learn(AtlasSelfConstructionContinuousRuntimeLearningIntegration $integration): array
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return ['__usage_error__' => true];
        }
        $outcomes = is_array($facts['outcomes'] ?? null) ? $facts['outcomes'] : $facts;

        return $this->wrap($integration->integrate($outcomes));
    }

    /**
     * @return array<string,mixed>
     */
    private function cycle(
        AtlasSelfConstructionContinuousRuntimeReplenisherIntegration $replenisher,
        AtlasSelfConstructionContinuousRuntimeWorkerIntegration $worker,
        AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration $verifyMerge,
        AtlasSelfConstructionContinuousRuntimeLearningIntegration $learner,
    ): array {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return ['__usage_error__' => true];
        }

        $health = (array) ($facts['health'] ?? []);
        $cycleId = (string) ($facts['cycle_id'] ?? 'cycle_'.bin2hex(random_bytes(4)));

        $inspector = new class($health)
        {
            /** @param array<string,mixed> $health */
            public function __construct(private array $health) {}

            /** @return array<string,mixed> */
            public function inspect(): array
            {
                return $this->health;
            }
        };

        $replWrapper = new class($replenisher)
        {
            public function __construct(private AtlasSelfConstructionContinuousRuntimeReplenisherIntegration $i) {}

            /**
             * @param  array<string,mixed>  $facts
             * @return array<string,mixed>
             */
            public function replenish(array $facts): array
            {
                return $this->i->integrate($facts);
            }
        };

        $workerWrapper = new class($worker)
        {
            public function __construct(private AtlasSelfConstructionContinuousRuntimeWorkerIntegration $i) {}

            /**
             * @param  array<string,mixed>  $packet
             * @return array<string,mixed>
             */
            public function integrate(array $packet): array
            {
                return $this->i->integrate($packet);
            }
        };

        $verifier = new class($verifyMerge)
        {
            public function __construct(private AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration $i) {}

            /**
             * @param  array<string,mixed>  $request
             * @return array<string,mixed>
             */
            public function verify(array $request): array
            {
                $req = $this->i->buildVerificationRequest($request);
                $verified = ((string) ($req['decision'] ?? '')) !== AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::DECISION_BLOCK
                    && ((string) ($req['decision'] ?? '')) !== AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration::DECISION_REJECT;

                return ['verified' => $verified, 'request' => $req];
            }
        };

        $mergeDecider = new class($verifyMerge)
        {
            public function __construct(private AtlasSelfConstructionContinuousRuntimeVerificationMergeIntegration $i) {}

            /**
             * @param  array<string,mixed>  $verification
             * @return array<string,mixed>
             */
            public function decide(array $verification): array
            {
                return $this->i->buildMergeDecision((array) ($verification['request'] ?? []), []);
            }
        };

        $learnerWrapper = new class($learner)
        {
            public function __construct(private AtlasSelfConstructionContinuousRuntimeLearningIntegration $i) {}

            /**
             * @param  array<string,mixed>  $facts
             * @return array<string,mixed>
             */
            public function record(array $facts): array
            {
                return $this->i->integrate([$facts]);
            }
        };

        $runner = new AtlasSelfConstructionContinuousRuntimeCycleRunner(
            $inspector,
            $replWrapper,
            $workerWrapper,
            $verifier,
            $mergeDecider,
            $learnerWrapper,
        );

        return $this->wrap($runner->run($cycleId));
    }

    /**
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function wrap(array $body): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'final_runtime_owner' => self::RUNTIME_OWNER,
            'evidence_obligations' => ['tests_or_gates_result'],
            'result' => $body,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadFacts(): ?array
    {
        $path = (string) $this->option('facts');
        if ($path === '' || ! is_file($path)) {
            $this->error('--facts=<path> is required for this action');

            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->error('facts payload not valid JSON: '.mb_substr($e->getMessage(), 0, 200));

            return null;
        }
        if (! is_array($decoded)) {
            $this->error('facts payload root must be a JSON object');

            return null;
        }

        return $decoded;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        unset($payload['__usage_error__']);
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->line($k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }
}
