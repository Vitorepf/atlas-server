<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Resilience;

final class AtlasLoopProcessTopologyProbe
{
    public const SCHEMA_VERSION = 'atlas.loop.process_topology.v1';

    private const PS_COMMAND = 'ps -axo pid=,ppid=,etime=,etimes=,pcpu=,pmem=,command=';

    /** @var list<string> */
    private array $commandsIssued = [];

    /** @var null|callable():string */
    private $clock;

    /**
     * @param  null|callable():string  $clock
     */
    public function __construct(
        private readonly ?string $psPayload = null,
        private readonly ?string $host = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    /**
     * @return array{schema_version:string,captured_at_ts:string,host:string,processes:list<array<string,mixed>>,tree:array<string,list<int>>}
     */
    public function snapshot(): array
    {
        return $this->sortKeys([
            'schema_version' => self::SCHEMA_VERSION,
            'captured_at_ts' => $this->timestamp(),
            'host' => $this->host(),
            'processes' => $this->processes($this->psPayload ?? $this->capturePs()),
            'tree' => [],
        ]);
    }

    public function psCommand(): string
    {
        return self::PS_COMMAND;
    }

    /**
     * @return list<string>
     */
    public function commandsIssued(): array
    {
        return $this->commandsIssued;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function processes(string $payload): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $payload) ?: [] as $line) {
            $row = $this->parseLine($line);
            if ($row === null || $row['role'] === 'other') {
                continue;
            }
            $rows[] = $row;
        }

        usort($rows, static fn (array $a, array $b): int => $a['pid'] <=> $b['pid']);
        $tree = $this->tree($rows);

        foreach ($rows as $i => $row) {
            $rows[$i] = $this->sortKeys($row);
        }

        $this->lastTree = $tree;

        return $rows;
    }

    /** @var array<string,list<int>> */
    private array $lastTree = [];

    /**
     * @return null|array{pid:int,ppid:int,etime_s:int,cpu:float,mem:float,role:string,campaign_id_or_null:?string,command:string}
     */
    private function parseLine(string $line): ?array
    {
        if (trim($line) === '') {
            return null;
        }
        if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\S+)\s+(\d+)\s+([0-9.]+)\s+([0-9.]+)\s+(.+)$/', $line, $m) !== 1) {
            return null;
        }

        $command = trim($m[7]);
        $role = $this->role($command);

        return [
            'pid' => (int) $m[1],
            'ppid' => (int) $m[2],
            'etime_s' => max(0, (int) $m[4]),
            'cpu' => (float) $m[5],
            'mem' => (float) $m[6],
            'role' => $role,
            'campaign_id_or_null' => $this->campaignId($command),
            'command' => $command,
        ];
    }

    private function role(string $command): string
    {
        return match (true) {
            preg_match('/\batlas:loop:campaign\b/', $command) === 1 => 'supervisor',
            preg_match('/\batlas:loop:run-scenario\b/', $command) === 1 => 'grind',
            preg_match('/\bhermes\b.*\bchat\b/', $command) === 1 => 'hermes',
            str_contains($command, 'atlas-loop-watchdog.sh') => 'watchdog',
            preg_match('/\batlas:loop:keepalive\b/', $command) === 1 => 'keepalive',
            preg_match('/\batlas:loop:hung-supervisor-guard\b/', $command) === 1 => 'hung-guard',
            default => 'other',
        };
    }

    private function campaignId(string $command): ?string
    {
        if (preg_match('/--campaign-id(?:=|\s+)([^\s]+)/', $command, $m) !== 1) {
            return null;
        }

        return $m[1] !== '' ? $m[1] : null;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,list<int>>
     */
    private function tree(array $rows): array
    {
        $pids = array_fill_keys(array_map(static fn (array $row): int => (int) $row['pid'], $rows), true);
        $tree = [];
        foreach ($rows as $row) {
            $ppid = (int) $row['ppid'];
            if (! isset($pids[$ppid])) {
                continue;
            }
            $key = (string) $ppid;
            $tree[$key] ??= [];
            $tree[$key][] = (int) $row['pid'];
        }

        ksort($tree, SORT_NUMERIC);
        foreach ($tree as $parent => $children) {
            sort($children, SORT_NUMERIC);
            $tree[$parent] = array_values($children);
        }

        return $tree;
    }

    private function capturePs(): string
    {
        $this->commandsIssued[] = self::PS_COMMAND;

        return (string) shell_exec(self::PS_COMMAND);
    }

    private function timestamp(): string
    {
        if (is_callable($this->clock)) {
            return (string) ($this->clock)();
        }

        return gmdate(DATE_ATOM);
    }

    private function host(): string
    {
        return $this->host ?: (gethostname() ?: 'unknown');
    }

    /**
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>
     */
    private function sortKeys(array $value): array
    {
        if (array_key_exists('processes', $value) && array_key_exists('tree', $value)) {
            $value['tree'] = $this->lastTree;
        }
        if (array_is_list($value)) {
            foreach ($value as $key => $item) {
                if (is_array($item)) {
                    $value[$key] = $this->sortKeys($item);
                }
            }

            return $value;
        }

        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortKeys($item);
            }
        }

        return $value;
    }
}
