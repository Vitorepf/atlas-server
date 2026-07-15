<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;

/** C25 · policy-gated executor with append-only step receipts and undo refs. */
final class AtlasCodeHealService
{
    public const SCHEMA_VERSION = 'atlas.code.heals.v1';
    public const RECEIPT_SCHEMA_VERSION = 'atlas.code.heal_receipt.v1';
    public const UNDO_DAYS = 30;

    /** @var array<int,string> */
    public const ALLOWED_ACTIONS = [
        'cherry_pick_to_main',
        'delete_branch',
        'merge_ff',
        'stash_quarantine',
        'cite_rule_to_agent',
    ];

    public function __construct(
        private readonly AtlasCodeViolationService $violations,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /** @return array{mode:string,allowed_actions:array<int,string>} */
    public function policy(string $mode): array
    {
        $mode = strtolower(trim($mode));
        if (! in_array($mode, ['observe', 'heal'], true)) {
            throw new InvalidArgumentException('invalid_heal_policy');
        }

        return ['mode' => $mode, 'allowed_actions' => self::ALLOWED_ACTIONS];
    }

    /**
     * A tick is observe-only by default. `heal` is an explicit policy choice;
     * it executes only the scanner's declared steps and records every step.
     *
     * @return array<string,mixed>
     */
    public function tick(string $repo, string $mode = 'observe', ?string $ruleId = null, ?string $target = null, ?string $requestedAction = null): array
    {
        $policy = $this->policy($mode);
        $scan = $this->violations->capture($repo);
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'repo' => $scan['repo'],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'mode' => $policy['mode'],
            'violations' => $scan['violations'],
            'plan' => $scan['plan'],
            'step_receipts' => [],
        ];

        if ($policy['mode'] === 'observe') {
            return $base;
        }

        $selected = null;
        foreach ($scan['violations'] as $violation) {
            if (is_array($violation)
                && ($ruleId === null || (string) ($violation['rule_id'] ?? '') === trim($ruleId))
                && ($target === null || (string) ($violation['target'] ?? '') === trim($target))) {
                $selected = $violation;
                break;
            }
        }
        if (! is_array($selected)) {
            return $base + ['blocked' => 'heal_target_not_found'];
        }

        $profile = (new AtlasCodeWorkspaceProfileService())->findByReference($repo);
        $path = is_array($profile) ? trim((string) ($profile['repo_root'] ?? $profile['workspace_path'] ?? '')) : '';
        if ($path === '' || ! is_dir($path)) {
            throw new InvalidArgumentException('repository_path_missing_or_unreadable');
        }

        $healId = (string) Str::ulid();
        $receipts = [];
        foreach ((array) ($selected['plan'] ?? []) as $index => $step) {
            if (! is_array($step)) {
                continue;
            }
            $action = trim((string) ($step['action'] ?? ''));
            if ($requestedAction !== null && $action !== trim($requestedAction)) {
                continue;
            }
            if (! in_array($action, self::ALLOWED_ACTIONS, true)) {
                throw new InvalidArgumentException('heal_action_not_allowed');
            }
            $receipts[] = $this->executeStep($path, $healId, (int) $index + 1, $action, (string) ($selected['rule_id'] ?? ''), (string) ($selected['target'] ?? ''));
        }

        return $base + ['heal_id' => $healId, 'step_receipts' => $receipts];
    }

    /** @return array<string,mixed> */
    public function undo(string $healId, string $repo): array
    {
        $healId = trim($healId);
        if ($healId === '') {
            throw new InvalidArgumentException('heal_id_required');
        }
        $events = $this->ledger->eventsForCorrelation('atlas-code:heal:'.$healId, 200);
        $receipt = null;
        foreach (array_reverse($events) as $event) {
            $payload = $event instanceof AtlasLedgerEvent ? (array) $event->payload : [];
            if (($payload['schema_version'] ?? null) === self::RECEIPT_SCHEMA_VERSION && ($payload['status'] ?? null) === 'completed') {
                $receipt = $payload;
                break;
            }
        }
        if (! is_array($receipt)) {
            throw new InvalidArgumentException('heal_receipt_not_found');
        }

        $expires = strtotime((string) ($receipt['undo_expires_at'] ?? ''));
        if ($expires === false || $expires < time()) {
            throw new InvalidArgumentException('heal_undo_expired');
        }
        $profile = (new AtlasCodeWorkspaceProfileService())->findByReference($repo);
        $path = is_array($profile) ? trim((string) ($profile['repo_root'] ?? $profile['workspace_path'] ?? '')) : '';
        if ($path === '' || ! is_dir($path)) {
            throw new InvalidArgumentException('repository_path_missing_or_unreadable');
        }

        $undoRef = (array) ($receipt['undo_ref'] ?? []);
        $action = (string) ($receipt['action'] ?? '');
        $result = match ($action) {
            'delete_branch' => $this->run($path, ['git', 'branch', (string) ($undoRef['branch'] ?? ''), (string) ($undoRef['head'] ?? '')]),
            'merge_ff', 'cherry_pick_to_main' => $this->resetMain($path, (string) ($undoRef['main_head'] ?? '')),
            'stash_quarantine' => $this->run($path, ['git', 'stash', 'pop']),
            'cite_rule_to_agent' => 'guardrail_receipt_only',
            default => throw new InvalidArgumentException('heal_action_not_allowed'),
        };

        $payload = [
            'schema_version' => self::RECEIPT_SCHEMA_VERSION,
            'source' => 'atlas_code_heal',
            'heal_id' => $healId,
            'repo' => $repo,
            'step' => 'undo',
            'action' => 'undo:'.$action,
            'started_at' => gmdate('c'),
            'finished_at' => gmdate('c'),
            'status' => 'completed',
            'result' => trim($result) !== '' ? trim($result) : 'undone',
            'undo_ref' => $undoRef,
            'undo_expires_at' => gmdate('c', time() + self::UNDO_DAYS * 86400),
        ];
        $this->record($healId, $payload);

        return $payload;
    }

    /** @return array<string,mixed> */
    private function executeStep(string $cwd, string $healId, int $step, string $action, string $ruleId, string $target): array
    {
        $startedAt = gmdate('c');
        $undoRef = ['action' => $action];
        try {
            $result = match ($action) {
                'delete_branch' => $this->deleteBranch($cwd, $target, $undoRef),
                'merge_ff' => $this->mergeFastForward($cwd, $target, $undoRef),
                'cherry_pick_to_main' => $this->cherryPick($cwd, $target, $undoRef),
                'stash_quarantine' => $this->stash($cwd, $healId, $undoRef),
                'cite_rule_to_agent' => 'rule='.$ruleId,
                default => throw new InvalidArgumentException('heal_action_not_allowed'),
            };
            $payload = [
                'schema_version' => self::RECEIPT_SCHEMA_VERSION,
                'source' => 'atlas_code_heal',
                'heal_id' => $healId,
                'step' => $step,
                'action' => $action,
                'rule_id' => $ruleId,
                'target' => $target,
                'started_at' => $startedAt,
                'finished_at' => gmdate('c'),
                'status' => 'completed',
                'result' => trim($result) !== '' ? trim($result) : 'completed',
                'undo_ref' => $undoRef,
                'undo_expires_at' => gmdate('c', time() + self::UNDO_DAYS * 86400),
            ];
        } catch (Throwable $exception) {
            $payload = [
                'schema_version' => self::RECEIPT_SCHEMA_VERSION,
                'source' => 'atlas_code_heal',
                'heal_id' => $healId,
                'step' => $step,
                'action' => $action,
                'rule_id' => $ruleId,
                'target' => $target,
                'started_at' => $startedAt,
                'finished_at' => gmdate('c'),
                'status' => 'failed',
                'result' => $exception->getMessage(),
                'undo_ref' => $undoRef,
                'undo_expires_at' => gmdate('c', time() + self::UNDO_DAYS * 86400),
            ];
        }
        $this->record($healId, $payload);

        return $payload;
    }

    /** @param array<string,mixed> $undoRef */
    private function deleteBranch(string $cwd, string $branch, array &$undoRef): string
    {
        $this->assertBranch($branch);
        if ($branch === 'main') {
            throw new InvalidArgumentException('main_branch_is_immutable');
        }
        $head = trim($this->run($cwd, ['git', 'rev-parse', 'refs/heads/'.$branch]));
        $undoRef += ['branch' => $branch, 'head' => $head];
        return $this->run($cwd, ['git', 'branch', '-D', $branch]);
    }

    /** @param array<string,mixed> $undoRef */
    private function mergeFastForward(string $cwd, string $target, array &$undoRef): string
    {
        $this->assertBranch($target);
        $mainHead = trim($this->run($cwd, ['git', 'rev-parse', 'main']));
        $undoRef['main_head'] = $mainHead;
        $this->run($cwd, ['git', 'switch', 'main']);
        return $this->run($cwd, ['git', 'merge', '--ff-only', $target]);
    }

    /** @param array<string,mixed> $undoRef */
    private function cherryPick(string $cwd, string $target, array &$undoRef): string
    {
        $this->assertRef($target);
        $mainHead = trim($this->run($cwd, ['git', 'rev-parse', 'main']));
        $undoRef['main_head'] = $mainHead;
        $this->run($cwd, ['git', 'switch', 'main']);
        return $this->run($cwd, ['git', 'cherry-pick', $target]);
    }

    /** @param array<string,mixed> $undoRef */
    private function stash(string $cwd, string $healId, array &$undoRef): string
    {
        $undoRef['stash'] = 'stash@{0}';
        return $this->run($cwd, ['git', 'stash', 'push', '--include-untracked', '--message=atlas-heal-'.$healId]);
    }

    private function resetMain(string $cwd, string $head): string
    {
        $this->assertRef($head);
        return $this->run($cwd, ['git', 'reset', '--hard', $head]);
    }

    /** @param array<string,mixed> $payload */
    private function record(string $healId, array $payload): void
    {
        $this->ledger->record(LedgerEventType::OperationCompleted, $payload, [
            'correlation_id' => 'atlas-code:heal:'.$healId,
            'envelope_id' => 'atlas-code:heal:'.$healId,
            'scope_type' => 'atlas_code_heal',
            'scope_id' => $healId,
            'emitter_stage' => 'atlas.code.heal',
            'emitter_version' => self::SCHEMA_VERSION,
        ]);
    }

    private function assertBranch(string $ref): void
    {
        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $ref) !== 1 || str_contains($ref, '..') || str_starts_with($ref, '-')) {
            throw new InvalidArgumentException('invalid_git_ref');
        }
    }

    private function assertRef(string $ref): void
    {
        if (trim($ref) === '' || preg_match('/^[A-Za-z0-9._\/-]+$/', $ref) !== 1 || str_contains($ref, '..')) {
            throw new InvalidArgumentException('invalid_git_ref');
        }
    }

    /** @param array<int,string> $command */
    private function run(string $cwd, array $command): string
    {
        $process = new Process($command, $cwd, null, null, 30);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new InvalidArgumentException(trim($process->getErrorOutput()) ?: 'git_command_failed');
        }

        return $process->getOutput();
    }
}
