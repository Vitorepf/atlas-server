<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipFirstLiveBranchProofService;
use Illuminate\Console\Command;

final class AtlasSoftwareCompanyFirstLiveBranchProofCommand extends Command
{
    protected $signature = 'atlas:software-company-stewardship:first-live-branch-proof
        {--area=agentic_engineering_os : Stewardship area id}
        {--repo-root= : Git repository root; defaults to the app base path}
        {--base-ref=main : Base branch/ref}
        {--branch-ref= : Optional explicit proof branch name}
        {--proof-nonce= : Optional deterministic proof nonce}
        {--record : Persist AP-781/AP-769 receipts}
        {--json : Emit JSON only}';

    protected $description = 'AP-781 · create a real visible stewardship branch/worktree/commit proof without merging.';

    public function handle(StewardshipFirstLiveBranchProofService $service): int
    {
        $payload = $service->run([
            'area_id' => (string) $this->option('area'),
            'repo_root' => (string) ($this->option('repo-root') ?: base_path()),
            'base_ref' => (string) ($this->option('base-ref') ?: 'main'),
            'branch_ref' => (string) ($this->option('branch-ref') ?: ''),
            'proof_nonce' => (string) ($this->option('proof-nonce') ?: ''),
            'record' => (bool) $this->option('record'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? '') === StewardshipFirstLiveBranchProofService::STATUS_BLOCKED
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->components->twoColumnDetail('AP-781 first live branch proof', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Proof', (string) ($payload['proof_id'] ?? ''));
        $this->components->twoColumnDetail('Branch', (string) data_get($payload, 'branch.branch_ref', ''));
        $this->components->twoColumnDetail('Commit', (string) data_get($payload, 'branch.branch_commit', ''));
        $this->components->twoColumnDetail('Worktree', (string) data_get($payload, 'branch.worktree_path', ''));
        $this->components->twoColumnDetail('Base untouched', ((bool) data_get($payload, 'repo.main_untouched', false)) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Review packet', (string) data_get($payload, 'branch_review_packet.status', ''));

        foreach ((array) ($payload['blockers'] ?? data_get($payload, 'branch_review_packet.blockers', [])) as $blocker) {
            $this->warn('  blocker: '.(string) $blocker);
        }
        foreach ((array) ($payload['next_operator_action'] ?? []) as $action) {
            $this->line('  next: '.(string) $action);
        }

        return ($payload['status'] ?? '') === StewardshipFirstLiveBranchProofService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }
}
