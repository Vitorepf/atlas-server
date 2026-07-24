<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasCodeForgeReviewCompletionService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Atlas Code Forge Review & Completion Gate v1 · CLI.
 *
 * php artisan atlas:code:forge-review --obra=<uuid> --run=<id> --json [--strict]
 * php artisan atlas:code:forge-review --obra=<uuid> --run=<id> --approve --reviewer=<id> --reason="..." --json --strict
 * php artisan atlas:code:forge-review --obra=<uuid> --run=<id> --reject --reviewer=<id> --reason="..." --json --strict
 * php artisan atlas:code:forge-review --obra=<uuid> --run=<id> --rollback --reviewer=<id> --reason="..." --json --strict
 */
final class AtlasCodeForgeReviewCommand extends Command
{
    use EmitsCanonicalJson;

    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:code:forge-review
        {--obra= : UUID da Obra (obrigatorio)}
        {--run= : Fast Path run id (obrigatorio)}
        {--approve : Aprovar review}
        {--reject : Rejeitar review}
        {--rollback : Rollback de promotion vinculada ao review}
        {--reviewer= : Identificador do reviewer humano}
        {--reason= : Motivo registrado no audit}
        {--json : Imprime JSON canonico}
        {--strict : Exit non-zero quando blocked/rejected/rollback_failed}';

    protected $description = 'Atlas Code Forge Review & Completion Gate v1 CLI (approve/reject/rollback ou show packet).';

    public function handle(AtlasCodeForgeReviewCompletionService $service): int
    {
        $obraId = $this->stringOption('obra');
        $runId = $this->stringOption('run');

        if ($obraId === null || $runId === null) {
            $payload = [
                'schema_version' => AtlasCodeForgeReviewCompletionService::REVIEW_PACKET_SCHEMA,
                'status' => 'blocked',
                'blocker' => $obraId === null ? 'obra_required' : 'fast_path_run_id_required',
                'reason' => 'atlas:code:forge-review exige --obra e --run.',
                'external_provider_call' => false,
            ];
            $this->emit($payload);

            return (bool) $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $project = AtlasProject::query()->whereKey($obraId)->first();
        if ($project === null) {
            $payload = [
                'schema_version' => AtlasCodeForgeReviewCompletionService::REVIEW_PACKET_SCHEMA,
                'status' => 'blocked',
                'blocker' => 'obra_not_found',
                'obra_id' => $obraId,
                'fast_path_run_id' => $runId,
                'external_provider_call' => false,
            ];
            $this->emit($payload);

            return (bool) $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $options = array_filter([
            'reviewer' => $this->stringOption('reviewer'),
            'reason' => $this->stringOption('reason'),
        ], static fn (mixed $v): bool => $v !== null);

        $modes = array_filter([
            'approve' => (bool) $this->option('approve'),
            'reject' => (bool) $this->option('reject'),
            'rollback' => (bool) $this->option('rollback'),
        ]);

        if (count($modes) > 1) {
            $payload = [
                'schema_version' => AtlasCodeForgeReviewCompletionService::REVIEW_PACKET_SCHEMA,
                'status' => 'blocked',
                'blocker' => 'multiple_decision_flags',
                'reason' => 'Passe apenas um de --approve, --reject ou --rollback.',
                'external_provider_call' => false,
            ];
            $this->emit($payload);

            return (bool) $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        if (isset($modes['approve'])) {
            $result = $service->approve($project, $runId, $options);
        } elseif (isset($modes['reject'])) {
            $result = $service->reject($project, $runId, $options);
        } elseif (isset($modes['rollback'])) {
            $result = $service->rollback($project, $runId, $options);
        } else {
            $packet = $service->packet($project, $runId);
            $claim = $service->completionClaim($project, $runId);
            $http = (int) ($packet['http_status'] ?? 200);
            unset($packet['http_status']);
            $payload = [
                'schema_version' => 'atlas.code.forge_review_completion_response.v1',
                'review_packet' => $packet,
                'completion_claim' => $claim,
            ];
            $this->emit($payload);

            return $this->exitForReadStatus($http, (string) ($packet['review_status'] ?? 'unknown'));
        }

        $payload = [
            'schema_version' => 'atlas.code.forge_review_completion_response.v1',
            'status' => (string) ($result['status'] ?? 'blocked'),
            'blocker' => $result['blocker'] ?? null,
            'reason' => $result['reason'] ?? null,
            'review_response' => $result['review_response'] ?? null,
            'rollback' => $result['rollback'] ?? null,
            'review_packet' => $result['packet'] ?? null,
            'completion_claim' => $result['completion_claim'] ?? null,
        ];
        $this->emit($payload);

        return $this->exitForDecision((string) ($result['status'] ?? 'blocked'));
    }

    private function exitForReadStatus(int $httpStatus, string $reviewStatus): int
    {
        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        return $httpStatus === 200 ? self::SUCCESS : self::FAILURE;
    }

    private function exitForDecision(string $status): int
    {
        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        return in_array($status, ['approved', 'rolled_back'], true) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Code Forge Review</>', (string) ($payload['schema_version'] ?? ''));
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? data_get($payload, 'review_packet.review_status', '—')));
        $this->components->twoColumnDetail('Blocker', (string) ($payload['blocker'] ?? '—'));
    }

}
