<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use InvalidArgumentException;

/** E5 · guardrail consulted before a mutating code action. */
final class AtlasCodePreflightService
{
    public const SCHEMA_VERSION = 'atlas.code.preflight.v1';

    public function __construct(
        private readonly AtlasCodeViolationService $violations,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /** @param array<string,mixed> $facts @return array<string,mixed> */
    public function check(array $facts, string $action, string $target): array
    {
        $action = trim($action);
        $target = trim($target);
        if ($action === '' || $target === '') {
            throw new InvalidArgumentException('preflight_action_and_target_required');
        }

        $scan = $this->violations->scan($facts);
        return $this->decide($scan['violations'], $action, $target);
    }

    /** @param array<int,array<string,mixed>> $violations @return array<string,mixed> */
    private function decide(array $violations, string $action, string $target): array
    {
        if ($action === 'create_branch' && $target !== 'main') {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'allowed' => false,
                'action' => $action,
                'target' => $target,
                'rule_id' => 'main_only',
                'reason' => 'rule_guardrail',
                'notifications' => ['enabled' => false],
            ];
        }
        foreach ($violations as $violation) {
            if (! is_array($violation) || (string) ($violation['target'] ?? '') !== $target) {
                continue;
            }
            foreach ((array) ($violation['plan'] ?? []) as $step) {
                if (is_array($step) && (string) ($step['action'] ?? '') === $action) {
                    return [
                        'schema_version' => self::SCHEMA_VERSION,
                        'allowed' => false,
                        'action' => $action,
                        'target' => $target,
                        'rule_id' => (string) ($violation['rule_id'] ?? ''),
                        'reason' => 'rule_guardrail',
                        'notifications' => ['enabled' => false],
                    ];
                }
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'allowed' => true,
            'action' => $action,
            'target' => $target,
            'rule_id' => null,
            'reason' => 'no_matching_violation',
            'notifications' => ['enabled' => false],
        ];
    }

    /** @return array<string,mixed> */
    public function guard(string $repo, string $action, string $target): array
    {
        $profile = (new AtlasCodeWorkspaceProfileService())->findByReference($repo);
        if (! is_array($profile)) {
            throw new InvalidArgumentException('repository_profile_not_found');
        }
        $path = trim((string) ($profile['repo_root'] ?? $profile['workspace_path'] ?? ''));
        if ($path === '' || ! is_dir($path)) {
            throw new InvalidArgumentException('repository_path_missing_or_unreadable');
        }

        $scan = $this->violations->capture($repo);
        $result = $this->decide((array) ($scan['violations'] ?? []), $action, $target);

        if ($result['allowed'] === false) {
            $this->ledger->record(LedgerEventType::OperationBlocked, [
                ...$result,
                'source' => 'atlas_code_preflight',
                'repo' => $repo,
            ], [
                'correlation_id' => 'atlas-code:preflight:'.$repo.':'.$target,
                'envelope_id' => 'atlas-code:preflight:'.$repo.':'.$target,
                'scope_type' => 'atlas_code_preflight',
                'scope_id' => $repo,
                'emitter_stage' => 'atlas.code.preflight',
                'emitter_version' => self::SCHEMA_VERSION,
            ]);
        }

        return $result + ['repo' => $repo];
    }
}
