<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Commands\Concerns\ResolvesJsonOptionWithComponentsError;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementForgeActivationService;
use Illuminate\Console\Command;

/**
 * Atlas Self-Improvement → Forge Activation CLI.
 *
 * Plans (or executes accept/reject on) an activation that turns an approved
 * Self-Improvement proposal into a real Forge Obra. NEVER auto-executes Fast
 * Path; NEVER calls a provider; --strict exits non-zero when the activation
 * is `blocked` or has hard fails.
 *
 * Doc: docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md
 */
final class AtlasSelfImprovementActivateForgeCommand extends Command
{
    use ReadsNonEmptyStringOption;
    use ResolvesJsonOptionWithComponentsError;

    protected function jsonOptionMissingFileLabel(): string
    {
        return 'proposal file not found';
    }

    protected $signature = 'atlas:self-improvement:activate-forge
        {--proposal= : Inline JSON or @path with the proposal payload}
        {--proposal-id= : Activation/proposal id to load from local store}
        {--obra-title= : Optional Obra title override}
        {--reviewer= : Reviewer id (required for --approve / --reject)}
        {--reason= : Decision reason (required for --approve / --reject)}
        {--approve : Accept an existing activation (requires --proposal-id)}
        {--reject : Reject an existing activation (requires --proposal-id)}
        {--dry-run : Plan without persisting any Obra; activation records as dry_run_planned}
        {--workspace= : Workspace root override}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when activation is blocked or fails human review}';

    protected $description = 'Atlas Self-Improvement → Forge Activation v1 (read+governed). Never calls provider; never auto-executes Fast Path.';

    public function handle(AtlasSelfImprovementForgeActivationService $service): int
    {
        $approve = (bool) $this->option('approve');
        $reject = (bool) $this->option('reject');
        $strict = (bool) $this->option('strict');
        $proposalId = $this->stringOption('proposal-id');

        if ($approve && $reject) {
            $this->emit([
                'schema_version' => AtlasSelfImprovementForgeActivationService::SCHEMA_VERSION,
                'status' => 'blocked',
                'blocker' => 'cannot_approve_and_reject_in_same_call',
            ]);

            return $strict ? self::FAILURE : self::SUCCESS;
        }

        if ($approve || $reject) {
            if ($proposalId === null) {
                $this->emit([
                    'schema_version' => AtlasSelfImprovementForgeActivationService::SCHEMA_VERSION,
                    'status' => 'blocked',
                    'blocker' => 'proposal_id_required_for_decision',
                ]);

                return $strict ? self::FAILURE : self::SUCCESS;
            }
            $reviewer = $this->stringOption('reviewer');
            $reason = $this->stringOption('reason');
            if ($reviewer === null || $reason === null) {
                $this->emit([
                    'schema_version' => AtlasSelfImprovementForgeActivationService::SCHEMA_VERSION,
                    'status' => 'blocked',
                    'blocker' => 'reviewer_and_reason_required_for_decision',
                ]);

                return $strict ? self::FAILURE : self::SUCCESS;
            }

            $payload = $approve
                ? $service->accept($proposalId, ['reviewer' => $reviewer, 'reason' => $reason])
                : $service->reject($proposalId, ['reviewer' => $reviewer, 'reason' => $reason]);

            $this->emit($payload);

            return $this->strictExit($payload, $strict);
        }

        $proposalPayload = $this->resolveJsonOption('proposal');
        $payload = $service->plan([
            'proposal' => $proposalPayload,
            'proposal_id' => $proposalId,
            'obra_title' => $this->stringOption('obra-title'),
            'workspace' => $this->stringOption('workspace'),
            'dry_run' => (bool) $this->option('dry-run'),
        ]);
        $this->emit($payload);

        return $this->strictExit($payload, $strict);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function strictExit(array $payload, bool $strict): int
    {
        if (! $strict) {
            return self::SUCCESS;
        }
        $status = (string) ($payload['status'] ?? '');

        return in_array($status, [
            AtlasSelfImprovementForgeActivationService::STATUS_ACCEPTED,
            AtlasSelfImprovementForgeActivationService::STATUS_OBRA_CREATED,
            AtlasSelfImprovementForgeActivationService::STATUS_DRY_RUN,
            AtlasSelfImprovementForgeActivationService::STATUS_PENDING_HUMAN_REVIEW,
            AtlasSelfImprovementForgeActivationService::STATUS_NEEDS_REVISION,
        ], true) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>|null
     */


    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }
        $this->components->twoColumnDetail('schema_version', (string) ($payload['schema_version'] ?? '—'));
        $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? '—'));
        $this->components->twoColumnDetail('next_action', (string) ($payload['next_action'] ?? '—'));
        $blockers = $payload['blockers'] ?? [];
        if (is_array($blockers) && $blockers !== []) {
            $this->components->bulletList($blockers);
        }
        if (isset($payload['created_obra_id'])) {
            $this->components->twoColumnDetail('created_obra_id', (string) $payload['created_obra_id']);
        }
    }
}
