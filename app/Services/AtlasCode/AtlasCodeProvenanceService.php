<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use App\Models\AiTrace;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Symfony\Component\Process\Process;
use Throwable;
use InvalidArgumentException;

/** C23 · read-only commit identity and provenance projection. */
final class AtlasCodeProvenanceService
{
    public const SCHEMA_VERSION = 'atlas.code.provenance.v1';

    /** @var array<string,string> */
    private const DEFAULT_AGENT_MAP = [
        'vitordsny@gmail.com' => 'voce',
    ];

    public function __construct(
        private readonly ?AtlasCodeWorkspaceProfileService $profiles = null,
        private readonly int $timeoutSeconds = 20,
        /** @var array<string,string>|null */
        private readonly ?array $agentMap = null,
    ) {}

    public function agentForAuthor(string $authorEmail): string
    {
        $email = strtolower(trim($authorEmail));
        $map = $this->agentMap ?? self::DEFAULT_AGENT_MAP;

        return $map[$email] ?? 'autonomo:desconhecido';
    }

    /**
     * Pure projection of one ledger payload. It deliberately accepts only
     * explicit provenance keys; free-form ledger text is never presented as
     * an operator quote.
     *
     * @return array{commit_hash:?string,trace_id:?string,operator_quote:?string,obra:array<int,mixed>,gates:array<int,mixed>}
     */
    public function projectLedgerPayload(array $payload, ?string $eventTraceId = null): array
    {
        return [
            'commit_hash' => $this->firstHash($payload),
            'trace_id' => $this->stringOrNull($eventTraceId) ?? $this->firstString($payload, ['trace_id']),
            'operator_quote' => $this->firstString($payload, ['operator_quote']),
            'obra' => $this->firstArray($payload, ['obra']),
            'gates' => $this->firstArray($payload, ['gates']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function capture(string $hash, ?string $repo = null): array
    {
        $requestedHash = strtolower(trim($hash));
        if (preg_match('/^[0-9a-f]{7,64}$/', $requestedHash) !== 1) {
            throw new InvalidArgumentException('invalid_commit_hash');
        }

        $profileService = $this->profiles ?? new AtlasCodeWorkspaceProfileService();
        $profile = $profileService->findByReference($repo ?: 'atlas-native');
        if (! is_array($profile)) {
            throw new InvalidArgumentException('repository_profile_not_found');
        }

        $path = trim((string) ($profile['repo_root'] ?? $profile['workspace_path'] ?? ''));
        if ($path === '' || ! is_dir($path)) {
            throw new InvalidArgumentException('repository_path_missing_or_unreadable');
        }

        $line = $this->run($path, [
            'git', 'show', '-s', '--format=%H%x1f%an%x1f%ae%x1f%at%x1f%s', $requestedHash,
        ]);
        $parts = explode("\x1f", trim($line), 5);
        if (count($parts) !== 5 || ! preg_match('/^[0-9a-f]{40}$/', $parts[0])) {
            throw new InvalidArgumentException('commit_not_found');
        }

        [$fullHash, $authorName, $authorEmail, $authoredAt, $message] = $parts;
        if (! str_starts_with(strtolower($fullHash), $requestedHash)) {
            throw new InvalidArgumentException('commit_not_found');
        }

        $result = [
            'schema_version' => self::SCHEMA_VERSION,
            'repo' => (string) ($profile['slug'] ?? ($repo ?: 'atlas-native')),
            'hash' => strtolower($fullHash),
            'commit_message' => trim($message),
            'author_name' => trim($authorName),
            'author_email' => trim($authorEmail),
            'authored_at' => (int) $authoredAt,
            'agent' => $this->agentForAuthor($authorEmail),
        ];

        $ledger = $this->findLedgerEvent(strtolower($fullHash));
        if (! $ledger instanceof AtlasLedgerEvent) {
            return $result;
        }

        $projected = $this->projectLedgerPayload($ledger->payload ?? [], $ledger->trace_id);
        if ($projected['operator_quote'] !== null) {
            $result['operator_quote'] = $projected['operator_quote'];
        }
        if ($projected['trace_id'] !== null) {
            $result['trace_id'] = $projected['trace_id'];
        }
        if ($projected['obra'] !== []) {
            $result['obra'] = $projected['obra'];
        }
        if ($projected['gates'] !== []) {
            $result['gates'] = $projected['gates'];
        }

        if (isset($result['trace_id'])) {
            try {
                $trace = AiTrace::query()
                    ->where('id', $result['trace_id'])
                    ->orWhere('trace_key', $result['trace_id'])
                    ->first();
                if ($trace instanceof AiTrace) {
                    $result['trace_agent'] = $trace->agent_slug;
                    if (! isset($result['operator_quote']) && is_string($trace->operator_input) && trim($trace->operator_input) !== '') {
                        $result['operator_quote'] = trim($trace->operator_input);
                    }
                }
            } catch (Throwable) {
                // A stale or unavailable trace makes provenance partial, never invented.
            }
        }

        return $result;
    }

    private function findLedgerEvent(string $hash): ?AtlasLedgerEvent
    {
        try {
            $corrections = AtlasLedgerEvent::query()
                ->where('event_type', LedgerEventType::CodeProvenanceCorrected->value)
                ->latest('occurred_at')
                ->limit(200)
                ->get()
                ->map(fn (AtlasLedgerEvent $event): ?string => $this->firstString($event->payload ?? [], ['corrects_event_id']))
                ->filter()
                ->all();

            return AtlasLedgerEvent::query()
                ->where('event_type', LedgerEventType::CodeProvenanceRecorded->value)
                ->latest('occurred_at')
                ->limit(200)
                ->get()
                ->first(function (AtlasLedgerEvent $event) use ($hash, $corrections): bool {
                    return ! in_array((string) $event->event_id, $corrections, true)
                        && $this->firstHash($event->payload ?? []) === $hash;
                });
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<int,string> $command */
    private function run(string $cwd, array $command): string
    {
        try {
            $process = new Process($command, $cwd, null, null, $this->timeoutSeconds);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new InvalidArgumentException('commit_not_found');
            }

            return $process->getOutput();
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidArgumentException) {
                throw $exception;
            }
            throw new InvalidArgumentException('commit_not_found');
        }
    }

    /** @param array<string,mixed> $payload */
    private function firstHash(array $payload): ?string
    {
        foreach (['commit_hash', 'commit_sha', 'hash'] as $key) {
            $value = $this->firstString($payload, [$key]);
            if ($value !== null && preg_match('/^[0-9a-f]{7,64}$/i', $value) === 1) {
                return strtolower($value);
            }
        }

        foreach ($payload as $value) {
            if (is_array($value)) {
                $found = $this->firstHash($value);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @param array<string,mixed> $payload @param array<int,string> $keys */
    private function firstString(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        foreach ($payload as $value) {
            if (is_array($value)) {
                $found = $this->firstString($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    /** @param array<string,mixed> $payload @param array<int,string> $keys */
    private function firstArray(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            if (is_array($payload[$key] ?? null)) {
                return $payload[$key];
            }
        }
        foreach ($payload as $value) {
            if (is_array($value)) {
                $found = $this->firstArray($value, $keys);
                if ($found !== []) {
                    return $found;
                }
            }
        }

        return [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
