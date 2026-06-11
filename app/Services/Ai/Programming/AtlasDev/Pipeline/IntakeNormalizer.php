<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Pipeline;

use App\Services\Ai\Programming\AtlasDev\Schemas\AtlasDevOperationEnvelope as OperationEnvelope;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\Preflight;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevTextMatcher;

/**
 * Surface-agnostic intake normalizer.
 *
 * Converts a raw operator intent + surface payload into an `OperationEnvelope`
 * without calling any provider. Workspace resolution is filesystem-only; git
 * status is best-effort and conservative — when unavailable, the envelope is
 * still produced with safe defaults (dirty=false, head_sha=null) and Preflight
 * marks `workspace_resolved=false` so downstream gates can block.
 *
 * No service in `AtlasDev/{Schemas,Discovery,Pipeline,Persistence,...}` may
 * know about specific surfaces (Desktop, CLI, App, API). Surface adapters
 * massage their native payload into the four primitive arguments before
 * calling this normalizer.
 */
class IntakeNormalizer
{
    public const DIRTY_POLICY_PRESERVE = 'preserve_pre_existing_changes';

    public const CLARITY_HIGH = 'high';

    public const CLARITY_MEDIUM = 'medium';

    public const CLARITY_LOW = 'low';

    public const CLARITY_BLOCKING = 'blocking';

    public const PERMISSION_PLAN_ONLY = 'plan_only';

    public const PERMISSION_WRITE_ALLOWED = 'write_allowed';

    public function __construct(
        private readonly RunIdGenerator $runIds = new RunIdGenerator,
    ) {}

    /**
     * @param  list<string>  $userConstraints
     * @param  array<string, mixed>  $surfaceHints  optional: thread_id, conversation_id, composer_mode, composer_task, provider_choice, previous_run_id, attachments
     */
    public function normalize(
        string $surfaceId,
        string $workspace,
        string $rawIntent,
        array $userConstraints = [],
        array $surfaceHints = [],
    ): OperationEnvelope {
        $runId = $this->runIds->generate();
        $workspaceAbs = $this->resolveWorkspace($workspace);
        $workspaceResolved = $workspaceAbs !== null;
        $workspaceForEnvelope = $workspaceAbs ?? $workspace;
        $workspaceHash = hash('sha256', $workspaceForEnvelope);

        $surfaceContext = new SurfaceContext(
            productSurface: $surfaceId,
            threadId: $this->stringHint($surfaceHints, 'thread_id'),
            conversationId: $this->stringHint($surfaceHints, 'conversation_id'),
            composerMode: $this->stringHint($surfaceHints, 'composer_mode'),
            composerTask: $this->stringHint($surfaceHints, 'composer_task'),
            providerChoice: $this->stringHint($surfaceHints, 'provider_choice'),
        );

        $gitState = $workspaceResolved
            ? $this->readGitState($workspaceForEnvelope)
            : new GitState(headSha: null, dirty: false, untrackedCount: 0, pendingChangesCount: 0);

        $normalizedIntent = $this->normalizeIntent($rawIntent);
        $clarity = $this->inferClarity($normalizedIntent, $userConstraints, $workspaceResolved);

        $writeAllowed = $workspaceResolved && $clarity !== self::CLARITY_BLOCKING;
        $preflight = new Preflight(
            workspaceResolved: $workspaceResolved,
            permissionMode: $writeAllowed ? self::PERMISSION_WRITE_ALLOWED : self::PERMISSION_PLAN_ONLY,
            writeAllowed: $writeAllowed,
            operatorExplicit: $this->detectOperatorExplicit($userConstraints, $surfaceHints),
        );

        // Atlas Dev is always the canonical flow id of this runtime. The
        // router/direct distinction lives in surfaceHints['flow_origin'].
        $flowOrigin = $this->resolveFlowOrigin($surfaceHints);
        $commandIntent = $this->resolveCommandIntent($surfaceHints);

        $envelope = new OperationEnvelope(
            runId: $runId,
            surfaceId: $surfaceId,
            surfaceContext: $surfaceContext,
            workspace: $workspaceForEnvelope,
            workspaceHash: $workspaceHash,
            gitState: $gitState,
            rawIntent: $rawIntent,
            normalizedIntent: $normalizedIntent,
            userConstraints: $this->normaliseConstraints($userConstraints),
            intentClarityLevel: $clarity,
            dirtyWorktreePolicy: self::DIRTY_POLICY_PRESERVE,
            preflight: $preflight,
            envelopeHash: '',
            flowId: OperationEnvelope::FLOW_ID,
            flowOrigin: $flowOrigin,
            commandIntent: $commandIntent,
        );

        $envelopeHash = CanonicalHasher::hashWithout($envelope->toCanonicalArray(), 'envelope_hash');

        return new OperationEnvelope(
            runId: $envelope->runId,
            surfaceId: $envelope->surfaceId,
            surfaceContext: $envelope->surfaceContext,
            workspace: $envelope->workspace,
            workspaceHash: $envelope->workspaceHash,
            gitState: $envelope->gitState,
            rawIntent: $envelope->rawIntent,
            normalizedIntent: $envelope->normalizedIntent,
            userConstraints: $envelope->userConstraints,
            intentClarityLevel: $envelope->intentClarityLevel,
            dirtyWorktreePolicy: $envelope->dirtyWorktreePolicy,
            preflight: $envelope->preflight,
            envelopeHash: $envelopeHash,
            flowId: $envelope->flowId,
            flowOrigin: $envelope->flowOrigin,
            commandIntent: $envelope->commandIntent,
        );
    }

    /**
     * Pull `flow_origin` from surface hints, validate against the canonical
     * enum, and fall back to `direct` for anything missing or unrecognised.
     *
     * @param  array<string, mixed>  $hints
     */
    private function resolveFlowOrigin(array $hints): string
    {
        $candidate = $hints['flow_origin'] ?? null;
        if (! is_string($candidate) || $candidate === '') {
            return OperationEnvelope::FLOW_ORIGIN_DIRECT;
        }
        if (! in_array($candidate, OperationEnvelope::FLOW_ORIGINS, true)) {
            // Conservative: an unknown origin coming from a surface adapter
            // is treated as direct so the runtime never trusts a forged
            // router stamp.
            return OperationEnvelope::FLOW_ORIGIN_DIRECT;
        }

        return $candidate;
    }

    /**
     * Pull `command_intent` from surface hints. The value is opaque metadata
     * (e.g. "dev", "debug", "review") populated by the Atlas AI Router; it
     * MUST NOT be used to rewrite raw_intent / normalized_intent downstream.
     *
     * @param  array<string, mixed>  $hints
     */
    private function resolveCommandIntent(array $hints): ?string
    {
        $candidate = $hints['command_intent'] ?? null;
        if (! is_string($candidate)) {
            return null;
        }
        $trimmed = trim($candidate);

        return $trimmed === '' ? null : $trimmed;
    }

    private function resolveWorkspace(string $workspace): ?string
    {
        if ($workspace === '') {
            return null;
        }
        if (! is_dir($workspace)) {
            return null;
        }
        $absolute = realpath($workspace);

        return $absolute === false ? null : $absolute;
    }

    private function readGitState(string $workspace): GitState
    {
        $head = $this->tryReadHeadSha($workspace);

        // We deliberately do NOT shell out to `git status` here because Atlas
        // Dev intake must work without any subprocess. A later scope-guard
        // phase reads diffs once it actually needs them.
        return new GitState(
            headSha: $head,
            dirty: false,
            untrackedCount: 0,
            pendingChangesCount: 0,
        );
    }

    private function tryReadHeadSha(string $workspace): ?string
    {
        $gitDir = rtrim($workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.git';
        if (! is_dir($gitDir) && ! is_file($gitDir)) {
            return null;
        }
        $headPath = $gitDir.DIRECTORY_SEPARATOR.'HEAD';
        if (! is_file($headPath) || ! is_readable($headPath)) {
            return null;
        }
        $contents = @file_get_contents($headPath);
        if (! is_string($contents)) {
            return null;
        }
        $contents = trim($contents);
        if (str_starts_with($contents, 'ref: ')) {
            $ref = trim(substr($contents, 5));
            $refPath = $gitDir.DIRECTORY_SEPARATOR.$ref;
            if (is_file($refPath) && is_readable($refPath)) {
                $sha = trim((string) @file_get_contents($refPath));

                return $this->isShaLike($sha) ? $sha : null;
            }

            return null;
        }

        return $this->isShaLike($contents) ? $contents : null;
    }

    private function isShaLike(string $value): bool
    {
        return $value !== '' && preg_match('/^[0-9a-f]{7,64}$/', $value) === 1;
    }

    private function normalizeIntent(string $rawIntent): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $rawIntent));

        return $normalized;
    }

    /**
     * @param  list<string>  $userConstraints
     */
    private function inferClarity(string $normalized, array $userConstraints, bool $workspaceResolved): string
    {
        if ($normalized === '') {
            return self::CLARITY_BLOCKING;
        }
        if (! $workspaceResolved) {
            // Without a workspace we cannot resolve any reference; intake stays
            // low-clarity so plan-only gating activates downstream.
            return self::CLARITY_LOW;
        }

        $tokens = $this->countTokens($normalized);
        $haystack = strtolower($normalized."\n".implode("\n", array_map(static fn ($c): string => (string) $c, $userConstraints)));

        $hasReferenceMarker = AtlasDevTextMatcher::containsAny($haystack, [
            '.php', '.ts', '.tsx', '.js', '.jsx', '.md',
            'storage/', 'app/', 'tests/', 'docs/', 'config/',
        ]);
        $hasQuestionWord = $this->startsWithAny($normalized, ['explique ', 'explain ', 'o que ', 'what ', 'onde ', 'where ', 'por que ', 'why ']);
        $hasActionVerb = AtlasDevTextMatcher::containsAny($haystack, [
            'corrija', 'corrigir', 'fix', 'ajuste', 'remova', 'remove',
            'adicione', 'adicionar', 'add ', 'crie', 'create', 'rename',
            'refator', 'refactor', 'extract', 'extraia',
        ]);

        if ($hasReferenceMarker && ($hasActionVerb || $hasQuestionWord)) {
            return self::CLARITY_HIGH;
        }
        if ($hasActionVerb || $hasQuestionWord) {
            return self::CLARITY_MEDIUM;
        }
        if ($tokens <= 2) {
            return self::CLARITY_LOW;
        }

        return self::CLARITY_MEDIUM;
    }

    private function countTokens(string $normalized): int
    {
        if ($normalized === '') {
            return 0;
        }
        $parts = preg_split('/\s+/u', $normalized) ?: [];

        return count(array_filter($parts, static fn ($p): bool => $p !== ''));
    }

    /**
     * @param  list<string>  $needles
     */
    private function startsWithAny(string $value, array $needles): bool
    {
        $lower = strtolower($value);
        foreach ($needles as $needle) {
            if ($needle === '') {
                continue;
            }
            if (str_starts_with($lower, strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $userConstraints
     * @param  array<string, mixed>  $surfaceHints
     */
    private function detectOperatorExplicit(array $userConstraints, array $surfaceHints): bool
    {
        if (($surfaceHints['operator_explicit'] ?? false) === true) {
            return true;
        }
        foreach ($userConstraints as $constraint) {
            if (! is_string($constraint)) {
                continue;
            }
            $lower = strtolower($constraint);
            if (str_contains($lower, 'operator_explicit') && str_contains($lower, 'true')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $hints
     */
    private function stringHint(array $hints, string $key): ?string
    {
        $value = $hints[$key] ?? null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * @param  list<string>  $constraints
     * @return list<string>
     */
    private function normaliseConstraints(array $constraints): array
    {
        return AtlasDevStringListNormalizer::uniqueTrimmedStrings($constraints);
    }
}
