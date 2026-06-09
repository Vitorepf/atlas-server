<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopSemanticImplementationCertifier;
use Illuminate\Console\Command;
use JsonException;

/**
 * Standalone semantic implementation certification for an already-materialized
 * candidate workspace. It never applies, promotes, or merges a diff.
 */
final class AtlasLoopCertifyImplementationCommand extends Command
{
    protected $signature = 'atlas:loop:certify-implementation
        {--workspace= : Git workspace containing the candidate diff}
        {--acceptance= : Acceptance JSON object}
        {--acceptance-file= : Path to acceptance JSON object}
        {--objective= : Human objective for the evidence receipt}
        {--allowed-file=* : Allowed changed file for adversarial scope proof}
        {--holdout=* : Sealed holdout command}
        {--refuter-command=* : External/provider refuter command; gets ATLAS_SEMANTIC_REFUTER_PACKET}
        {--refuters= : Required external/provider refuter count}
        {--refuter-provider= : Advisory provider key for refuter receipt}
        {--receipt= : Optional path to write the full receipt JSON}
        {--json : Print canonical JSON}';

    protected $description = 'Certify a small semantic implementation proposal with deterministic gate, adversarial panel and external refuters.';

    public function handle(AtlasLoopSemanticImplementationCertifier $certifier): int
    {
        $workspace = rtrim(trim((string) $this->option('workspace')), '/');
        if ($workspace === '' || ! is_dir($workspace)) {
            $this->error('Missing or invalid --workspace.');

            return self::FAILURE;
        }

        try {
            $acceptance = $this->acceptance();
        } catch (JsonException $e) {
            $this->error('Invalid acceptance JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        $receipt = $certifier->certify($workspace, $acceptance, [
            'objective' => trim((string) ($this->option('objective') ?: '')),
            'allowed_files' => $this->stringOptionList('allowed-file'),
            'sealed_holdout_commands' => $this->stringOptionList('holdout'),
            'semantic_refuter_commands' => $this->stringOptionList('refuter-command'),
            'provider_refuters_required' => $this->intOption('refuters'),
            'refuter_provider' => trim((string) ($this->option('refuter-provider') ?: '')) ?: null,
            'receipt_path' => trim((string) ($this->option('receipt') ?: '')),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($receipt, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return (bool) ($receipt['certified'] ?? false) ? self::SUCCESS : self::FAILURE;
        }

        $this->components->twoColumnDetail('Semantic implementation certification', (string) ($receipt['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Level', (string) ($receipt['level'] ?? '-'));
        $this->components->twoColumnDetail('Changed files', (string) count((array) ($receipt['changed_files'] ?? [])));
        $this->components->twoColumnDetail('External refuters', (string) data_get($receipt, 'provider_refuters.executed', 0).'/'.(string) data_get($receipt, 'provider_refuters.required', 0));
        if (! (bool) ($receipt['certified'] ?? false)) {
            foreach ((array) ($receipt['reasons'] ?? []) as $reason) {
                $this->warn((string) $reason);
            }
        }

        return (bool) ($receipt['certified'] ?? false) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     *
     * @throws JsonException
     */
    private function acceptance(): array
    {
        $json = trim((string) ($this->option('acceptance') ?: ''));
        $file = trim((string) ($this->option('acceptance-file') ?: ''));
        if ($json === '' && $file !== '' && is_file($file)) {
            $json = (string) file_get_contents($file);
        }
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return list<string>
     */
    private function stringOptionList(string $key): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $v): string => is_string($v) ? trim($v) : '',
            (array) $this->option($key),
        ), static fn (string $v): bool => $v !== ''));
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }
}
