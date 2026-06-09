<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasEngineeringHonestyGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AdversarialProofPanelService;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Final certificate for small semantic implementation proposals.
 *
 * It composes the existing P4 implementation gate with an independent
 * adversarial proof panel and optional external refuter commands. The result is
 * still propose-only: the certificate proves reviewability, never mergeability.
 */
final class AtlasLoopSemanticImplementationCertifier
{
    public const SCHEMA = 'atlas.loop.semantic_implementation_certification.v1';

    public function __construct(
        private readonly AtlasEngineeringHonestyGate $honestyGate,
        private readonly AdversarialProofPanelService $adversarialPanel,
    ) {}

    /**
     * @param  array<string,mixed>  $targetAcceptance
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function certify(string $workspace, array $targetAcceptance, array $options = []): array
    {
        $sealedHoldouts = $this->stringList($options['sealed_holdout_commands'] ?? []);
        $allowedFiles = $this->stringList($options['allowed_files'] ?? []);
        $objective = trim((string) ($options['objective'] ?? ''));
        $refuterCommands = $this->refuterCommands($options);
        $requiredRefuters = $this->requiredRefuters($options, count($refuterCommands));

        $deterministicGate = $this->honestyGate->evaluateImplementation($workspace, $targetAcceptance, $sealedHoldouts);
        $changedFiles = $this->changedFiles($workspace);
        $changedFileContents = $this->changedFileContents($workspace, $changedFiles);
        $panelVerdict = $this->adversarialPanel->refute($this->panelCycle(
            $workspace,
            $objective,
            $allowedFiles,
            $targetAcceptance,
            $deterministicGate,
            $changedFiles,
            $changedFileContents,
        ));
        $providerRefuters = $this->runRefuters($workspace, [
            'schema_version' => self::SCHEMA.'.refuter_packet',
            'objective' => $objective,
            'workspace' => $workspace,
            'changed_files' => $changedFiles,
            'allowed_files' => $allowedFiles,
            'target_acceptance' => $this->redactedAcceptance($targetAcceptance),
            'deterministic_gate' => $deterministicGate,
            'adversarial_panel' => $panelVerdict,
            'proposal_only' => true,
            'merged_to_main' => false,
        ], $refuterCommands, [
            'required' => $requiredRefuters,
            'provider' => isset($options['refuter_provider']) ? trim((string) $options['refuter_provider']) : null,
            'timeout_seconds' => max(1, (int) ($options['refuter_timeout_seconds'] ?? 120)),
        ]);

        $reasons = $this->reasons($deterministicGate, $panelVerdict, $providerRefuters);
        $certified = $reasons === [];
        $receipt = [
            'schema_version' => self::SCHEMA,
            'status' => $certified ? 'certified' : 'refuted',
            'certified' => $certified,
            'level' => $this->level($providerRefuters),
            'objective' => $objective,
            'workspace' => $workspace,
            'changed_files' => $changedFiles,
            'allowed_files' => $allowedFiles,
            'reasons' => $certified ? ['certified'] : $reasons,
            'deterministic_gate' => $deterministicGate,
            'adversarial_panel' => $panelVerdict,
            'provider_refuters' => $providerRefuters,
            'evidence' => [
                'target_acceptance_passed' => (bool) data_get($deterministicGate, 'report.holdouts.target_frozen_passed', false),
                'diff_earned' => data_get($deterministicGate, 'report.holdouts.diff_earned') === true,
                'sealed_holdout_passed' => data_get($deterministicGate, 'report.holdouts.sealed_holdout_passed') === true,
                'adversarial_refuted_count' => (int) ($panelVerdict['refuted_count'] ?? 0),
                'provider_refuters_required' => (int) ($providerRefuters['required'] ?? 0),
                'provider_refuters_executed' => (int) ($providerRefuters['executed'] ?? 0),
                'provider_refuters_refuted' => (int) ($providerRefuters['refuted'] ?? 0),
            ],
            'invariants' => [
                'proposal_only' => true,
                'merged_to_main' => false,
                'source_checkout_mutated' => false,
            ],
            'generated_at' => time(),
        ];

        $receiptPath = trim((string) ($options['receipt_path'] ?? ''));
        if ($receiptPath !== '') {
            $this->writeJson($receiptPath, $receipt);
            $receipt['receipt_path'] = $receiptPath;
        }

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $options
     * @return list<string>
     */
    private function refuterCommands(array $options): array
    {
        $commands = [];
        foreach (['semantic_refuter_commands', 'provider_refuter_commands', 'refuter_commands'] as $key) {
            foreach ($this->stringList($options[$key] ?? []) as $command) {
                $commands[] = $command;
            }
        }

        return array_values(array_unique($commands));
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function requiredRefuters(array $options, int $configured): int
    {
        foreach (['provider_refuters_required', 'refuters_required', 'refuters'] as $key) {
            if (isset($options[$key]) && is_numeric($options[$key])) {
                return max(0, (int) $options[$key]);
            }
        }

        return $configured;
    }

    /**
     * @param  array<string,mixed>  $targetAcceptance
     * @param  array<string,mixed>  $deterministicGate
     * @param  list<string>  $changedFiles
     * @param  array<string,string>  $changedFileContents
     * @return array<string,mixed>
     */
    private function panelCycle(
        string $workspace,
        string $objective,
        array $allowedFiles,
        array $targetAcceptance,
        array $deterministicGate,
        array $changedFiles,
        array $changedFileContents,
    ): array {
        $commands = array_values(array_unique(array_merge(
            $this->stringList($targetAcceptance['commands'] ?? []),
            array_map(
                static fn (array $r): string => (string) ($r['command'] ?? ''),
                is_array(data_get($deterministicGate, 'report.sealed_holdout_results')) ? data_get($deterministicGate, 'report.sealed_holdout_results') : [],
            ),
        )));
        $commands = array_values(array_filter($commands, static fn (string $c): bool => trim($c) !== ''));

        return [
            'cycle_id' => hash('sha256', $workspace.'|'.$objective.'|'.implode('|', $changedFiles)),
            'objective' => $objective,
            'changed_files' => $changedFiles,
            'allowed_files' => $allowedFiles,
            'selected_finding' => ['affected_files' => $allowedFiles],
            'validation' => [
                'ran' => true,
                'passed' => (bool) ($deterministicGate['certified'] ?? false),
                'commands' => $commands,
            ],
            'changed_file_contents' => $changedFileContents,
            'outcome_measured' => true,
            'outcome_metric' => [
                'outcome_met' => (bool) ($deterministicGate['certified'] ?? false),
                'target_acceptance_passed' => (bool) data_get($deterministicGate, 'report.holdouts.target_frozen_passed', false),
                'diff_earned' => data_get($deterministicGate, 'report.holdouts.diff_earned') === true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     * @param  list<string>  $commands
     * @param  array{required:int,provider:?string,timeout_seconds:int}  $config
     * @return array<string,mixed>
     */
    private function runRefuters(string $workspace, array $packet, array $commands, array $config): array
    {
        $required = max(0, (int) $config['required']);
        $timeout = max(1, (int) $config['timeout_seconds']);
        $packetPath = tempnam(sys_get_temp_dir(), 'atlas-semantic-refuter-');
        if ($packetPath === false) {
            throw new RuntimeException('semantic certification: cannot create refuter packet');
        }
        file_put_contents($packetPath, json_encode($packet, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $verdicts = [];
        try {
            foreach ($commands as $index => $command) {
                $process = Process::fromShellCommandline($command, $workspace, [
                    'ATLAS_SEMANTIC_REFUTER_PACKET' => $packetPath,
                    'ATLAS_SEMANTIC_REFUTER_INDEX' => (string) ($index + 1),
                    'ATLAS_SEMANTIC_REFUTER_PROVIDER' => (string) ($config['provider'] ?? ''),
                ], null, (float) $timeout);
                $process->run();
                $verdicts[] = $this->refuterVerdict($index + 1, $command, $process);
            }
        } finally {
            @unlink($packetPath);
        }

        $refuted = count(array_filter($verdicts, static fn (array $v): bool => (bool) ($v['refuted'] ?? false)));
        $executed = count($verdicts);
        $metRequired = $executed >= $required;

        return [
            'schema_version' => self::SCHEMA.'.provider_refuters.v1',
            'provider' => $config['provider'],
            'required' => $required,
            'configured' => count($commands),
            'executed' => $executed,
            'met_required' => $metRequired,
            'refuted' => $refuted,
            'verdicts' => $verdicts,
            'fail_closed' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function refuterVerdict(int $index, string $command, Process $process): array
    {
        $stdout = trim((string) $process->getOutput());
        $stderr = trim((string) $process->getErrorOutput());
        $json = $stdout !== '' ? json_decode($stdout, true) : null;
        $parsed = is_array($json);
        $exit = $process->getExitCode() ?? 1;
        $refuted = $exit !== 0;
        $reason = $refuted ? 'command_exit_'.$exit : 'no_refutation';

        if ($parsed) {
            $refuted = (bool) ($json['refuted'] ?? $refuted);
            $reason = trim((string) ($json['reason'] ?? $json['detail'] ?? $reason)) ?: $reason;
        }

        return [
            'index' => $index,
            'command' => $command,
            'exit_code' => $exit,
            'refuted' => $refuted,
            'reason' => $reason,
            'stdout' => $this->excerpt($stdout),
            'stderr' => $this->excerpt($stderr),
            'parsed_json' => $parsed,
        ];
    }

    /**
     * @param  array<string,mixed>  $deterministicGate
     * @param  array<string,mixed>  $panelVerdict
     * @param  array<string,mixed>  $providerRefuters
     * @return list<string>
     */
    private function reasons(array $deterministicGate, array $panelVerdict, array $providerRefuters): array
    {
        $reasons = [];
        if (! (bool) ($deterministicGate['certified'] ?? false)) {
            foreach ((array) ($deterministicGate['reasons'] ?? ['deterministic_gate_failed']) as $reason) {
                $reasons[] = 'deterministic_gate:'.(string) $reason;
            }
        }
        if (! (bool) ($panelVerdict['merge_allowed'] ?? false)) {
            $reasons[] = 'adversarial_panel:'.(string) ($panelVerdict['reason'] ?? 'refuted');
        }
        if (! (bool) ($providerRefuters['met_required'] ?? false)) {
            $reasons[] = 'provider_refuters_missing(required:'.(int) ($providerRefuters['required'] ?? 0).',executed:'.(int) ($providerRefuters['executed'] ?? 0).')';
        }
        foreach ((array) ($providerRefuters['verdicts'] ?? []) as $verdict) {
            if (is_array($verdict) && (bool) ($verdict['refuted'] ?? false)) {
                $reasons[] = 'provider_refuter_'.(int) ($verdict['index'] ?? 0).':'.(string) ($verdict['reason'] ?? 'refuted');
            }
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  array<string,mixed>  $providerRefuters
     */
    private function level(array $providerRefuters): string
    {
        if ((int) ($providerRefuters['required'] ?? 0) > 0 && (bool) ($providerRefuters['met_required'] ?? false)) {
            return 'semantic_implementation_certified_with_external_refuters';
        }

        return 'semantic_implementation_certified_with_deterministic_panel';
    }

    /**
     * @return list<string>
     */
    private function changedFiles(string $workspace): array
    {
        $files = [];
        foreach ([
            ['git', 'diff', '--name-only', '--no-ext-diff'],
            ['git', 'ls-files', '--others', '--exclude-standard'],
        ] as $argv) {
            $process = new Process($argv, $workspace, null, null, 30.0);
            $process->run();
            if (! $process->isSuccessful() && $process->getExitCode() !== 1) {
                continue;
            }
            foreach (preg_split('/\R/', trim((string) $process->getOutput())) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $files[$line] = true;
                }
            }
        }

        return array_keys($files);
    }

    /**
     * @param  list<string>  $files
     * @return array<string,string>
     */
    private function changedFileContents(string $workspace, array $files): array
    {
        $contents = [];
        foreach ($files as $file) {
            $path = $workspace.'/'.$file;
            if (is_file($path)) {
                $contents[$file] = (string) file_get_contents($path);
            }
        }

        return $contents;
    }

    /**
     * @param  array<string,mixed>  $acceptance
     * @return array<string,mixed>
     */
    private function redactedAcceptance(array $acceptance): array
    {
        return [
            'commands' => $this->stringList($acceptance['commands'] ?? []),
            'allowed_globs' => $this->stringList($acceptance['allowed_globs'] ?? []),
            'frozen_globs' => $this->stringList($acceptance['frozen_globs'] ?? []),
            'metric_kind' => (string) ($acceptance['metric_kind'] ?? ''),
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? trim($v) : '',
            is_array($value) ? $value : [],
        ), static fn (string $v): bool => $v !== ''));
    }

    private function writeJson(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('semantic certification: cannot create receipt dir '.$dir);
        }
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    private function excerpt(string $text): string
    {
        $text = trim($text);

        return mb_strlen($text) > 1200 ? mb_substr($text, 0, 1200).'…' : $text;
    }
}
