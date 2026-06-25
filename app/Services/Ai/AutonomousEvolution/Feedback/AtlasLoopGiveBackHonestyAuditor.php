<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Feedback;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use DateInterval;
use DateTimeImmutable;
use Symfony\Component\Process\Process;

class AtlasLoopGiveBackHonestyAuditor
{
    /** @var \Closure(array<int,string>, string, int): list<array{sha:string, committed_at:string}> */
    private \Closure $historyLookup;

    public function __construct(
        private readonly object $reader = new AtlasLoopGiveBackReader,
        private readonly ?AgentControlPlaneTaskPacketQueueRepository $queue = null,
        ?callable $historyLookup = null,
    ) {
        $this->historyLookup = $historyLookup instanceof \Closure
            ? $historyLookup
            : \Closure::fromCallable($historyLookup ?? [$this, 'lookupGitHistory']);
    }

    /**
     * @return list<array{
     *   packet_id:string,
     *   reason:string,
     *   reason_category:string,
     *   verdict:'honest'|'suspect'|'unverifiable',
     *   evidence_pointer:string
     * }>
     */
    public function audit(int $limit = 200, int $windowHours = 168): array
    {
        $results = [];

        foreach ($this->recentOutcomes($limit) as $record) {
            if (! is_array($record) || trim((string) ($record['outcome'] ?? '')) !== 'give_back') {
                continue;
            }

            $packetId = trim((string) ($record['packet_id'] ?? ''));
            $reason = trim((string) ($record['reason'] ?? ''));
            $recordedAt = trim((string) ($record['recorded_at'] ?? ''));
            $reasonCategory = $this->reasonCategory($reason);

            if ($packetId === '' || $reasonCategory === 'other' || $recordedAt === '') {
                $results[] = $this->result($packetId, $reason, $reasonCategory, 'unverifiable', 'no_evidence');

                continue;
            }

            $allowedFiles = $this->allowedFiles($packetId);
            if ($allowedFiles === []) {
                $results[] = $this->result($packetId, $reason, $reasonCategory, 'unverifiable', 'no_evidence');

                continue;
            }

            $commits = ($this->historyLookup)($allowedFiles, $recordedAt, $windowHours);
            $results[] = $commits !== []
                ? $this->result($packetId, $reason, $reasonCategory, 'suspect', (string) ($commits[0]['sha'] ?? 'no_evidence'))
                : $this->result($packetId, $reason, $reasonCategory, 'honest', 'no_evidence');
        }

        return $results;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentOutcomes(int $limit): array
    {
        if (method_exists($this->reader, 'recentOutcomes')) {
            $result = $this->reader->recentOutcomes($limit);

            return is_array($result) ? array_values($result) : [];
        }

        if (method_exists($this->reader, 'recent')) {
            $result = $this->reader->recent($limit);

            return is_array($result) ? array_values($result) : [];
        }

        if (method_exists($this->reader, 'read')) {
            $result = $this->reader->read($limit);

            return is_array($result) ? array_values($result) : [];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function allowedFiles(string $packetId): array
    {
        $queue = $this->queue ?? new AgentControlPlaneTaskPacketQueueRepository;
        $record = $queue->get($packetId);
        if (! is_array($record) || (bool) ($record['corrupt'] ?? false)) {
            return [];
        }

        $allowed = data_get($record, 'task_packet.normalized_scope.allowed_files', []);
        if (! is_array($allowed) || $allowed === []) {
            $allowed = data_get($record, 'task_packet.allowed_files', []);
        }

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            is_array($allowed) ? $allowed : []
        ), static fn (string $value): bool => $value !== ''));
    }

    private function reasonCategory(string $reason): string
    {
        $normalized = strtolower(str_replace('-', '_', trim($reason)));

        foreach (['wrong_site', 'wrong_path', 'out_of_scope', 'allowed_files_mismatch'] as $category) {
            if ($normalized === $category || str_contains($normalized, $category)) {
                return $category;
            }
        }

        return 'other';
    }

    /**
     * @param  list<string>  $allowedFiles
     * @return list<array{sha:string, committed_at:string}>
     */
    private function lookupGitHistory(array $allowedFiles, string $recordedAt, int $windowHours): array
    {
        if ($allowedFiles === []) {
            return [];
        }

        try {
            $since = new DateTimeImmutable($recordedAt);
            $until = $since->add(new DateInterval('PT'.max(1, $windowHours).'H'));
        } catch (\Exception) {
            return [];
        }

        $process = new Process([
            'git',
            'log',
            '--format=%H|%cI',
            '--since='.$since->format(DATE_ATOM),
            '--until='.$until->format(DATE_ATOM),
            '--',
            ...$allowedFiles,
        ], base_path());
        $process->run();
        if (! $process->isSuccessful()) {
            return [];
        }

        $commits = [];
        foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            [$sha, $committedAt] = array_pad(explode('|', $line, 2), 2, '');
            if ($sha === '' || $committedAt === '') {
                continue;
            }

            $commits[] = [
                'sha' => $sha,
                'committed_at' => $committedAt,
            ];
        }

        return $commits;
    }

    /**
     * @return array{packet_id:string, reason:string, reason_category:string, verdict:'honest'|'suspect'|'unverifiable', evidence_pointer:string}
     */
    private function result(
        string $packetId,
        string $reason,
        string $reasonCategory,
        string $verdict,
        string $evidencePointer,
    ): array {
        return [
            'packet_id' => $packetId,
            'reason' => $reason,
            'reason_category' => $reasonCategory,
            'verdict' => $verdict,
            'evidence_pointer' => $evidencePointer,
        ];
    }
}
