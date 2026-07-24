<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\LongHorizon\LongHorizonContinuityPackEmitterService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Support\YesNo;

final class AtlasLongHorizonContinuityPackCommand extends Command
{
    protected $signature = 'atlas:long-horizon:continuity-pack
        {--scope-type=long_horizon : Long-horizon scope type}
        {--scope-id=fable-lista-6 : Long-horizon scope id}
        {--evidence-root= : Evidence root to reference}
        {--doc= : Canonical doc path to reference}
        {--max-evidence= : Maximum evidence refs}
        {--stale-after-days= : Days until the pack becomes stale}
        {--required-evidence= : Comma-separated required evidence kinds}
        {--strict-replay : Require replay manifest in certification}
        {--receipt= : Optional path to write receipt JSON}
        {--write-receipt : Write to configured receipt path when --receipt is omitted}
        {--strict : Exit non-zero unless continuity pack certification is ready}
        {--json : Print canonical JSON}';

    protected $description = 'L6-12: emit a scoped continuation pack, replay manifest and continuity certification receipt.';

    public function handle(LongHorizonContinuityPackEmitterService $emitter): int
    {
        $payload = $emitter->emit(array_filter([
            'scope_type' => trim((string) $this->option('scope-type')),
            'scope_id' => trim((string) $this->option('scope-id')),
            'evidence_root' => $this->nonEmptyOption('evidence-root'),
            'doc_path' => $this->nonEmptyOption('doc'),
            'max_evidence_refs' => $this->intOption('max-evidence'),
            'stale_after_days' => $this->intOption('stale-after-days'),
            'required_evidence_kinds' => $this->nonEmptyOption('required-evidence'),
            'strict_replay' => (bool) $this->option('strict-replay'),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        $receiptPath = $this->receiptPath();
        if ($receiptPath !== '') {
            File::ensureDirectoryExists(dirname($receiptPath));
            File::put($receiptPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            $payload['receipt_path'] = $receiptPath;
        }

        $exit = (bool) $this->option('strict') && ! (bool) ($payload['certified'] ?? false)
            ? self::FAILURE
            : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->twoColumnDetail('Continuity pack', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Certified', (bool) YesNo::format($payload['certified'] ?? false));
        $this->components->twoColumnDetail('Pack', (string) data_get($payload, 'continuation_pack.uuid', 'n/a'));
        $this->components->twoColumnDetail('Replay', (string) data_get($payload, 'replay_manifest.replay_status', 'n/a'));
        $this->components->twoColumnDetail('Certification', (string) data_get($payload, 'certification.status', 'n/a'));

        return $exit;
    }

    private function receiptPath(): string
    {
        $explicit = trim((string) ($this->option('receipt') ?: ''));
        if ($explicit !== '') {
            return $explicit;
        }

        return (bool) $this->option('write-receipt')
            ? (string) config('atlas.long_horizon.continuity_pack_emitter.receipt_path', storage_path('app/atlas/evidence/long-horizon-continuity-pack.json'))
            : '';
    }

    private function nonEmptyOption(string $key): ?string
    {
        $value = trim((string) ($this->option($key) ?: ''));

        return $value !== '' ? $value : null;
    }

    private function intOption(string $key): ?int
    {
        $value = trim((string) ($this->option($key) ?: ''));

        return $value !== '' ? (int) $value : null;
    }
}
