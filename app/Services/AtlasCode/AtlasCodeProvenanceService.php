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
    public const SCHEMA_VERSION = 'atlas.code.provenance.v2';

    /** @var array<string,string> Git status letters → the vocabulary the app renders. */
    private const STATUS_MAP = [
        'A' => 'added',
        'M' => 'modified',
        'D' => 'deleted',
        'R' => 'renamed',
        'C' => 'copied',
        'T' => 'type_changed',
    ];

    /** @var array<string,string> */
    private const DEFAULT_AGENT_MAP = [
        'vitordsny@gmail.com' => 'voce',
    ];

    public function __construct(
        private readonly ?AtlasCodeWorkspaceProfileService $profiles = null,
        private readonly int $timeoutSeconds = 20,
        /** @var array<string,string>|null */
        private readonly ?array $agentMap = null,
        private readonly ?AtlasCodeRepoLocator $locator = null,
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
     * Pure parser for `git show --format= --raw --numstat -M`.
     *
     * Git prints two blocks for the same commit, in the same file order: the
     * raw block (`:modes shas STATUS<TAB>path`) carries the status letter, the
     * numstat block (`add<TAB>del<TAB>path`) carries the counts. Renames make
     * the numstat path unreliable (`src/{old => new}.ts`), so the blocks are
     * zipped by position — the one thing Git guarantees. Counts are dropped,
     * never guessed, when the blocks disagree; a binary file has no counts at
     * all and says so with null instead of a fabricated zero.
     *
     * @return array<int, array{path:string, status:string, additions:?int, deletions:?int, renamed_from:?string}>
     */
    public function parseFileChanges(string $output): array
    {
        $raw = [];
        $numbers = [];

        foreach (preg_split('/\r?\n/', $output) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            if (str_starts_with($line, ':')) {
                $columns = explode("\t", $line);
                if (count($columns) < 2) {
                    continue;
                }
                $meta = preg_split('/\s+/', trim($columns[0])) ?: [];
                $letter = strtoupper(substr((string) end($meta), 0, 1));
                $isRename = in_array($letter, ['R', 'C'], true) && isset($columns[2]);
                $raw[] = [
                    'path' => trim($isRename ? $columns[2] : $columns[1]),
                    'status' => self::STATUS_MAP[$letter] ?? 'unknown',
                    'renamed_from' => $isRename ? trim($columns[1]) : null,
                ];

                continue;
            }

            $columns = explode("\t", $line);
            if (count($columns) < 3) {
                continue;
            }
            // '-' marks a binary file: Git measured nothing, so neither do we.
            $numbers[] = [
                'additions' => $columns[0] === '-' ? null : (int) $columns[0],
                'deletions' => $columns[1] === '-' ? null : (int) $columns[1],
            ];
        }

        $aligned = count($raw) === count($numbers);

        return array_values(array_map(static function (array $entry, int $index) use ($numbers, $aligned): array {
            return [
                'path' => $entry['path'],
                'status' => $entry['status'],
                'additions' => $aligned ? $numbers[$index]['additions'] : null,
                'deletions' => $aligned ? $numbers[$index]['deletions'] : null,
                'renamed_from' => $entry['renamed_from'],
            ];
        }, $raw, array_keys($raw)));
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

        // Mesma frota do radar e do grafo: um commit aberto na tela abre aqui.
        $located = ($this->locator ?? new AtlasCodeRepoLocator($this->profiles))->locate($repo ?: 'atlas-native');
        $path = $located['path'];

        // The body (%b) comes last: it is multi-line by nature, so nothing may
        // follow it in the record.
        $line = $this->run($path, [
            'git', 'show', '-s', '--format=%H%x1f%an%x1f%ae%x1f%at%x1f%s%x1f%b', $requestedHash,
        ]);
        $parts = explode("\x1f", trim($line), 6);
        if (count($parts) !== 6 || ! preg_match('/^[0-9a-f]{40}$/', $parts[0])) {
            throw new InvalidArgumentException('commit_not_found');
        }

        [$fullHash, $authorName, $authorEmail, $authoredAt, $message, $body] = $parts;
        if (! str_starts_with(strtolower($fullHash), $requestedHash)) {
            throw new InvalidArgumentException('commit_not_found');
        }

        $result = [
            'schema_version' => self::SCHEMA_VERSION,
            'repo' => $located['slug'],
            'hash' => strtolower($fullHash),
            'commit_message' => trim($message),
            'author_name' => trim($authorName),
            'author_email' => trim($authorEmail),
            'authored_at' => (int) $authoredAt,
            'agent' => $this->agentForAuthor($authorEmail),
            // What the commit actually touched — the answer to "o que mudou".
            // An empty list is honest (merge or empty commit), never padded.
            'files' => $this->parseFileChanges($this->run($path, [
                'git', 'show', '--format=', '--raw', '--numstat', '-M', $requestedHash,
            ])),
        ];

        if (trim($body) !== '') {
            $result['commit_body'] = trim($body);
        }

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
