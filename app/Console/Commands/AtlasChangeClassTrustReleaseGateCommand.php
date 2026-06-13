<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Governance\ChangeClassTrustReleaseGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class AtlasChangeClassTrustReleaseGateCommand extends Command
{
    protected $signature = 'atlas:governance:change-class-trust-release-gate
        {--fixture=live : live, mature, regressed or blocked-sensitive}
        {--change-class= : Change class to assess}
        {--log= : Override trust ladder JSONL path}
        {--seed-ref=* : Record one or more clean evidence refs before assessing}
        {--receipt= : Optional path to write receipt JSON}
        {--write-receipt : Write to configured receipt path when --receipt is omitted}
        {--strict : Exit non-zero unless the gate certifies}
        {--json : Print canonical JSON}';

    protected $description = 'L6-14: certify class-scoped trust release and regression revocation at the Admission boundary.';

    public function handle(ChangeClassTrustReleaseGateService $gate): int
    {
        $payload = $gate->evaluate(array_filter([
            'fixture' => trim((string) $this->option('fixture')),
            'change_class' => $this->stringOption('change-class'),
            'log_path' => $this->stringOption('log'),
            'seed_refs' => (array) $this->option('seed-ref'),
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

        $this->components->twoColumnDetail('Change class', (string) ($payload['change_class'] ?? ''));
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Certified', (bool) ($payload['certified'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Clean streak', (string) data_get($payload, 'assessments.after.snapshot.clean_streak', 0));
        $this->components->twoColumnDetail('Earned autonomy', (string) data_get($payload, 'assessments.after.snapshot.earned_autonomy', ''));
        $this->components->twoColumnDetail('Admission', (string) data_get($payload, 'assessments.after.admission.decision', ''));
        $this->components->twoColumnDetail('Blockers', implode(', ', (array) ($payload['blockers'] ?? [])) ?: 'none');

        return $exit;
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function receiptPath(): string
    {
        $explicit = $this->stringOption('receipt');
        if ($explicit !== null) {
            return $explicit;
        }

        return (bool) $this->option('write-receipt')
            ? (string) config('atlas.ai.trust_ladder.release_gate.receipt_path', storage_path('app/atlas/evidence/change-class-trust-release-gate.json'))
            : '';
    }
}
