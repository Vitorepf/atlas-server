<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Models\HermesCapabilityCandidate;
use App\Services\Ai\Hermes\HermesCapabilityProbe;
use App\Services\Ai\Hermes\HermesCapabilityRegistry;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;
use App\Support\YesNo;

/**
 * Operator + automation surface for the Hermes Capability Registry keystone.
 *
 * Read-only by DEFAULT: this command probes the locally installed Hermes CLI,
 * diffs the probed surface against the last recorded manifest, and lists the
 * quarantined CapabilityCandidates Hermes has exposed. It NEVER enables a
 * capability — every candidate stays quarantined and ATLS remains the capability
 * authority. `--write` only PERSISTS the manifest + candidates (still disabled);
 * it never flips an `enabled` flag. The probe action returns FAILURE solely when
 * the Hermes binary is offline, so docs-health/maturity pipelines can gate on it.
 * Auto-discovered from app/Console/Commands.
 *
 * @see docs/engineering-knowledge-base/atlas-hermes-executive-runtime.md
 */
class AtlasHermesCapabilitiesCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:hermes:capabilities
        {action=probe : probe|diff|candidates}
        {--json : Emit JSON}
        {--write : Persist manifest + candidates}';

    protected $description = 'Read-only Hermes Capability Registry surface: probe the local Hermes CLI into the pinned atlas.hermes.capability_manifest.v1, diff it against the last recorded manifest, and list quarantined CapabilityCandidates. Never enables a capability; --write only persists the manifest and quarantined candidates. probe returns FAILURE only when the Hermes binary is offline.';

    public function handle(HermesCapabilityProbe $probe, HermesCapabilityRegistry $registry): int
    {
        return match ($this->action()) {
            'diff' => $this->handleDiff($probe, $registry),
            'candidates' => $this->handleCandidates(),
            default => $this->handleProbe($probe, $registry),
        };
    }

    private function handleProbe(HermesCapabilityProbe $probe, HermesCapabilityRegistry $registry): int
    {
        $manifest = $probe->probe();

        $registryReceipt = null;
        if ((bool) $this->option('write')) {
            $registryReceipt = $registry->record($manifest, ['source' => 'atlas:hermes:capabilities']);
        }

        $offline = ($manifest['probe_status'] ?? null) === 'binary_offline';

        if ($this->wantsJson()) {
            $payload = [
                'action' => 'probe',
                'written' => $registryReceipt !== null,
                'manifest' => $manifest,
            ];
            if ($registryReceipt !== null) {
                $payload['registry_receipt'] = $registryReceipt;
            }
            $this->jsonLine($payload);

            return $offline ? self::FAILURE : self::SUCCESS;
        }

        $this->renderManifest($manifest, $registryReceipt);

        return $offline ? self::FAILURE : self::SUCCESS;
    }

    private function handleDiff(HermesCapabilityProbe $probe, HermesCapabilityRegistry $registry): int
    {
        $current = $probe->probe();
        $previous = $registry->latestManifest();
        $diff = $registry->diff(is_array($previous) ? $previous : [], $current);

        if ($this->wantsJson()) {
            $this->jsonLine([
                'action' => 'diff',
                'has_previous_manifest' => is_array($previous),
                'probe_status' => $current['probe_status'] ?? null,
                'added_count' => count($diff['added']),
                'removed_count' => count($diff['removed']),
                'changed_count' => count($diff['changed']),
                'added' => array_values($diff['added']),
                'removed' => array_values($diff['removed']),
                'changed' => array_values($diff['changed']),
            ]);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('action', 'diff');
        $this->components->twoColumnDetail('previous manifest', is_array($previous) ? 'present' : 'none recorded yet');
        $this->components->twoColumnDetail('probe status', (string) ($current['probe_status'] ?? 'unknown'));
        $this->components->twoColumnDetail('added', (string) count($diff['added']));
        $this->components->twoColumnDetail('removed', (string) count($diff['removed']));
        $this->components->twoColumnDetail('changed', (string) count($diff['changed']));

        $this->renderDiffSection('ADDED (newly exposed by Hermes — quarantined on --write)', $diff['added']);
        $this->renderDiffSection('REMOVED (no longer exposed by Hermes)', $diff['removed']);
        $this->renderDiffSection('CHANGED (detail/support/token drift)', $diff['changed']);

        if ($diff['added'] === [] && $diff['removed'] === [] && $diff['changed'] === []) {
            $this->components->info('No capability drift against the last recorded manifest.');
        }

        return self::SUCCESS;
    }

    private function handleCandidates(): int
    {
        if (! DatabaseTableAvailability::has('hermes_capability_candidates')) {
            if ($this->wantsJson()) {
                $this->jsonLine([
                    'action' => 'candidates',
                    'available' => false,
                    'reason' => 'capability tables not migrated',
                    'capability_authority' => 'atlas',
                    'candidates' => [],
                ]);

                return self::SUCCESS;
            }

            $this->warn('capability tables not migrated');

            return self::SUCCESS;
        }

        $candidates = HermesCapabilityCandidate::query()
            ->orderBy('capability_class')
            ->orderBy('capability_key')
            ->get();

        $rows = $candidates->map(fn (HermesCapabilityCandidate $candidate): array => [
            'id' => (string) $candidate->id,
            'capability_class' => (string) $candidate->capability_class,
            'capability_key' => (string) $candidate->capability_key,
            'status' => (string) $candidate->status,
            'gate_status' => (string) $candidate->gate_status,
            'risk_level' => (string) $candidate->risk_level,
            'enabled' => (bool) $candidate->enabled,
            'review_required' => (bool) $candidate->review_required,
        ])->all();

        if ($this->wantsJson()) {
            $this->jsonLine([
                'action' => 'candidates',
                'available' => true,
                'capability_authority' => 'atlas',
                'enabled_now' => false,
                'count' => count($rows),
                'enabled_count' => collect($rows)->where('enabled', true)->count(),
                'candidates' => $rows,
            ]);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('action', 'candidates');
        $this->components->twoColumnDetail('capability authority', 'atlas');
        $this->components->twoColumnDetail('quarantined candidates', (string) count($rows));
        $this->components->twoColumnDetail('enabled now', collect($rows)->where('enabled', true)->isNotEmpty() ? 'TRUE (BUG)' : 'false (all quarantined)');

        if ($rows === []) {
            $this->newLine();
            $this->components->info('No CapabilityCandidates recorded yet (run probe --write).');

            return self::SUCCESS;
        }

        $this->table(
            ['class', 'key', 'risk', 'enabled', 'gate status'],
            collect($rows)->map(static fn (array $row): array => [
                (string) $row['capability_class'],
                Str::limit((string) $row['capability_key'], 40),
                (string) $row['risk_level'],
                $row['enabled'] ? 'YES (BUG)' : 'no',
                Str::limit((string) $row['gate_status'], 44),
            ])->all(),
        );

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>|null  $registryReceipt
     */
    private function renderManifest(array $manifest, ?array $registryReceipt): void
    {
        $entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];

        $this->components->twoColumnDetail('action', 'probe');
        $this->components->twoColumnDetail('schema', (string) ($manifest['schema_version'] ?? ''));
        $this->components->twoColumnDetail('hermes version', (string) ($manifest['hermes_version'] ?? '[unknown]'));
        $this->components->twoColumnDetail('probe status', (string) ($manifest['probe_status'] ?? 'unknown'));
        $this->components->twoColumnDetail('binary present', YesNo::format($manifest['binary_present'] ?? false));
        $this->components->twoColumnDetail('entries', (string) count($entries));
        $this->components->twoColumnDetail('written', $registryReceipt !== null ? 'yes' : 'no (read-only)');

        if ($registryReceipt !== null) {
            $this->components->twoColumnDetail('manifest version', (string) ($registryReceipt['manifest_version'] ?? ''));
            $this->components->twoColumnDetail('candidates persisted', (string) ($registryReceipt['persisted_count'] ?? 0));
            $this->components->twoColumnDetail('enabled now', ($registryReceipt['enabled_now'] ?? false) ? 'TRUE (BUG)' : 'false');
        }

        if (($manifest['probe_status'] ?? null) === 'binary_offline') {
            $this->newLine();
            $this->warn('Hermes binary offline — degraded manifest emitted (default-safe). probe returns FAILURE for health gating.');

            return;
        }

        if ($entries === []) {
            return;
        }

        $this->table(
            ['class', 'key', 'token', 'supported', 'cfg'],
            collect($entries)
                ->filter(static fn (mixed $entry): bool => is_array($entry))
                ->map(static fn (array $entry): array => [
                    (string) ($entry['capability_class'] ?? ''),
                    Str::limit((string) ($entry['capability_key'] ?? ''), 32),
                    (string) ($entry['hermes_token'] ?? '—'),
                    YesNo::format($entry['supported'] ?? false),
                    YesNo::format($entry['requires_config'] ?? false),
                ])
                ->all(),
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $entries
     */
    private function renderDiffSection(string $heading, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $this->newLine();
        $this->line('<options=bold>'.$heading.'</>');
        $this->table(
            ['class', 'key', 'token', 'supported'],
            collect($entries)
                ->filter(static fn (mixed $entry): bool => is_array($entry))
                ->map(static fn (array $entry): array => [
                    (string) ($entry['capability_class'] ?? ''),
                    Str::limit((string) ($entry['capability_key'] ?? ''), 40),
                    (string) ($entry['hermes_token'] ?? '—'),
                    YesNo::format($entry['supported'] ?? false),
                ])
                ->all(),
        );
    }

    private function action(): string
    {
        $action = strtolower(trim((string) $this->argument('action')));

        return in_array($action, ['probe', 'diff', 'candidates'], true) ? $action : 'probe';
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
}
