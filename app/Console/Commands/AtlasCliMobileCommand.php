<?php

namespace App\Console\Commands;

use App\Http\Resources\MobileDeviceResource;
use App\Models\AtlasMobileDevice;
use App\Models\AuditEvent;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Mobile\ContextBundleService;
use App\Services\Ai\Mobile\MobileHealthService;
use App\Services\Ai\Mobile\MobileMaintenanceService;
use App\Services\Ai\Mobile\MobilePairingService;
use App\Services\Ai\Mobile\MobilePushService;
use App\Services\Ai\Mobile\MobileReliabilityMonitor;
use App\Services\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasCliMobileCommand extends Command
{
    private const PUSH_REPLAY_PRIOR_DRY_RUN_MAX_AGE_MINUTES = 15;

    protected $signature = 'atlas:cli:mobile
        {action=devices : devices, pair, revoke, push-test, replay-push, flush-push, receipts, expire-stale, cleanup, status or alert-check}
        {arg? : Device id for revoke or label for pair}
        {--label= : Human-readable device label}
        {--device= : Device id for push-test}
        {--limit=100 : Max Expo receipts to check}
        {--apply : Run cleanup/expire-stale destructively (default is dry-run)}
        {--confirm-external-dispatch : Confirm replay-push --apply may send real mobile push notifications}
        {--reason= : Operator reason for replay-push --apply}
        {--json : Print machine-readable JSON}';

    protected $description = 'Manage Atlas mobile devices, pairing codes and maintenance tasks.';

    public function handle(
        MobilePairingService $pairing,
        AtlasInboxService $inbox,
        ContextBundleService $bundles,
        MobilePushService $push,
        MobileMaintenanceService $maintenance,
        MobileHealthService $health,
        MobileReliabilityMonitor $reliability,
        AuditLogService $audit,
    ): int {
        if (! Schema::hasTable('atlas_mobile_devices') || ! Schema::hasTable('mobile_pairing_codes')) {
            $this->error('Tabelas mobile ainda nao existem. Rode migrations.');

            return self::FAILURE;
        }

        $action = Str::of((string) $this->argument('action'))->lower()->trim()->value();

        return match ($action) {
            'devices', 'list' => $this->devices(),
            'pair' => $this->pair($pairing),
            'revoke' => $this->revoke($pairing),
            'push-test' => $this->pushTest($inbox, $bundles, $push),
            'replay-push', 'replay-pending-push' => $this->replayPush($push, $audit),
            'flush-push' => $this->flushPush($push),
            'receipts', 'push-receipts' => $this->receipts($push),
            'expire-stale' => $this->expireStale($maintenance),
            'cleanup' => $this->cleanup($maintenance),
            'status', 'health' => $this->status($health),
            'alert-check', 'alerts' => $this->alertCheck($reliability),
            default => $this->invalid($action),
        };
    }

    private function devices(): int
    {
        $devices = AtlasMobileDevice::query()->latest('paired_at')->get();
        $payload = MobileDeviceResource::collection($devices)->resolve();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['devices' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($devices->isEmpty()) {
            $this->line('Nenhum device mobile pareado.');

            return self::SUCCESS;
        }

        $this->table(['id', 'label', 'platform', 'push', 'last seen', 'revoked'], collect($payload)->map(fn (array $row): array => [
            $row['id'],
            $row['device_label'],
            $row['platform'],
            $row['has_push_token'] ? 'yes' : 'no',
            $row['last_seen_at'] ?? '-',
            $row['revoked_at'] ?? '-',
        ])->all());

        return self::SUCCESS;
    }

    private function pair(MobilePairingService $pairing): int
    {
        $label = (string) ($this->option('label') ?: $this->argument('arg') ?: 'Atlas mobile device');
        $result = $pairing->initiate($label);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Codigo de pareamento Atlas Mobile');
        $this->line('');
        $this->line('  '.$result['code']);
        $this->line('');
        $this->line('Pairing ID: '.$result['pairing_id']);
        $this->line('Expira em: '.$result['expires_at']);

        return self::SUCCESS;
    }

    private function revoke(MobilePairingService $pairing): int
    {
        $id = $this->argument('arg');
        if (! is_string($id) || $id === '') {
            $this->error('Informe o device id para revogar.');

            return self::FAILURE;
        }

        $device = AtlasMobileDevice::query()->find($id);
        if (! $device) {
            $this->error('Device nao encontrado.');

            return self::FAILURE;
        }

        $revoked = $pairing->revoke($device);
        $payload = (new MobileDeviceResource($revoked))->resolve();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['device' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Device revogado: '.$revoked->id);

        return self::SUCCESS;
    }

    private function pushTest(AtlasInboxService $inbox, ContextBundleService $bundles, MobilePushService $push): int
    {
        $deviceId = $this->option('device') ?: $this->argument('arg');
        $device = is_string($deviceId) && $deviceId !== ''
            ? AtlasMobileDevice::query()->whereKey($deviceId)->whereNull('revoked_at')->first()
            : AtlasMobileDevice::query()->whereNull('revoked_at')->whereNotNull('expo_push_token')->latest('last_seen_at')->first();

        if (! $device) {
            $this->error('Nenhum device ativo com push token encontrado.');

            return self::FAILURE;
        }

        if ($device->expo_push_token === null) {
            $this->error('Device nao tem Expo push token registrado.');

            return self::FAILURE;
        }

        $bundle = $bundles->create([
            'user_id' => $device->user_id,
            'purpose' => 'push_test',
            'title' => 'Teste de push Atlas',
            'summary' => 'Teste manual de push mobile.',
            'body_for_thread' => 'Este contexto foi criado por atlas mobile push-test.',
            'raw_payload' => ['test' => true],
        ]);

        $item = $inbox->create([
            'user_id' => $device->user_id,
            'type' => 'alert',
            'severity' => 'info',
            'title' => 'Teste de push Atlas',
            'summary' => 'Se voce recebeu isto, o canal mobile esta conectado.',
            'body' => 'Push-test manual criado pelo Atlas CLI.',
            'initiator' => 'operator',
            'context_bundle_id' => $bundle->id,
            'payload' => ['test' => true],
            'push_policy' => ['send' => 'none'],
        ]);

        $sent = $push->sendToDevice($device, $item);
        $payload = [
            'ok' => $sent,
            'device_id' => $device->id,
            'inbox_item_id' => $item->id,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $sent ? self::SUCCESS : self::FAILURE;
        }

        $sent
            ? $this->info('Push-test enviado para '.$device->device_label.' ('.$device->id.').')
            : $this->error('Push-test falhou. Veja mobile_push_deliveries.');

        return $sent ? self::SUCCESS : self::FAILURE;
    }

    private function flushPush(MobilePushService $push): int
    {
        $count = $push->flushBatched();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['sent_batches' => $count], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Push batches enviados: {$count}");

        return self::SUCCESS;
    }

    private function replayPush(MobilePushService $push, AuditLogService $audit): int
    {
        $apply = (bool) $this->option('apply');
        $reason = trim((string) $this->option('reason'));
        if ($apply && (! (bool) $this->option('confirm-external-dispatch') || $reason === '')) {
            return $this->renderReplayPushFailure('confirmation_required', [
                'message' => 'replay-push --apply exige --confirm-external-dispatch e --reason.',
                'rules' => [
                    'dry_run_default' => true,
                    'external_notification_possible' => true,
                    'confirm_external_dispatch_required' => true,
                    'operator_reason_required' => true,
                ],
            ]);
        }

        if ($apply && ! $this->hasRecentReplayPushDryRunReceipt()) {
            return $this->renderReplayPushFailure('prior_dry_run_required', [
                'message' => 'replay-push --apply exige dry-run recente com candidatos.',
                'rules' => [
                    'prior_dry_run_required' => true,
                    'prior_dry_run_max_age_minutes' => self::PUSH_REPLAY_PRIOR_DRY_RUN_MAX_AGE_MINUTES,
                    'prior_dry_run_candidate_required' => true,
                ],
            ]);
        }

        $result = $push->replayPendingDispatches((int) $this->option('limit'), ! $apply);
        $this->recordReplayPushReceipt($audit, $result, $apply, $reason);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $verb = $result['dry_run'] ? 'seriam reprocessados' : 'reprocessados';
        $this->info("Push pendentes {$verb}: {$result['candidate_count']}; deliveries criados: {$result['dispatched_count']}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function renderReplayPushFailure(string $status, array $extra): int
    {
        $payload = ['status' => $status, ...$extra];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }

        $this->error((string) ($extra['message'] ?? $status));

        return self::FAILURE;
    }

    private function hasRecentReplayPushDryRunReceipt(): bool
    {
        if (! Schema::hasTable('audit_events')) {
            return false;
        }

        return AuditEvent::query()
            ->where('event_type', 'mobile.push_replay.requested')
            ->where('actor_type', 'operator')
            ->where('actor_id', 'cli')
            ->where('occurred_at', '>=', now()->subMinutes(self::PUSH_REPLAY_PRIOR_DRY_RUN_MAX_AGE_MINUTES))
            ->latest('occurred_at')
            ->limit(20)
            ->get()
            ->contains(function (AuditEvent $event): bool {
                return (bool) data_get($event->evidence, 'dry_run')
                    && (int) data_get($event->evidence, 'candidate_count', 0) > 0;
            });
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function recordReplayPushReceipt(AuditLogService $audit, array $result, bool $apply, string $reason): void
    {
        $audit->record('mobile.push_replay.requested', [
            'subject_type' => 'mobile_push_replay',
            'actor_type' => 'operator',
            'actor_id' => 'cli',
            'severity' => $apply ? 'warning' : 'info',
            'summary' => $apply
                ? 'Operator applied pending mobile push replay via CLI.'
                : 'Operator ran pending mobile push replay CLI dry-run.',
            'evidence' => [
                'schema_version' => 'atlas.mobile.push_replay.operator_receipt.v1',
                'dry_run' => (bool) $result['dry_run'],
                'limit' => (int) $result['limit'],
                'candidate_count' => (int) $result['candidate_count'],
                'dispatched_count' => (int) $result['dispatched_count'],
                'mobile_enabled' => (bool) $result['mobile_enabled'],
                'external_dispatch_confirmed' => $apply,
                'operator_reason_hash' => $apply ? hash('sha256', $reason) : null,
                'item_ids' => collect((array) $result['items'])->pluck('id')->values()->all(),
            ],
            'privacy' => [
                'classification' => 'operational',
                'raw_push_tokens_exposed' => false,
                'raw_device_ids_exposed' => false,
                'operator_reason_stored_as_hash' => true,
            ],
        ]);
    }

    private function receipts(MobilePushService $push): int
    {
        $result = $push->fetchReceipts((int) $this->option('limit'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Receipts checados: {$result['checked']} ok={$result['receipt_ok']} error={$result['receipt_error']} missing={$result['missing']}");

        return self::SUCCESS;
    }

    private function expireStale(MobileMaintenanceService $maintenance): int
    {
        $apply = (bool) $this->option('apply');
        $result = $maintenance->expireStale(! $apply);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $verb = $result['dry_run'] ? 'expirariam' : 'expirados';
        $this->info("Inbox items {$verb}: {$result['transitioned']}".($result['dry_run'] ? ' (dry-run, use --apply)' : ''));

        return self::SUCCESS;
    }

    private function cleanup(MobileMaintenanceService $maintenance): int
    {
        $apply = (bool) $this->option('apply');
        $result = $maintenance->cleanup(! $apply);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $verb = $result['dry_run'] ? 'seriam removidos' : 'removidos';
        $this->info("Cleanup {$verb}".($result['dry_run'] ? ' (dry-run, use --apply)' : '').':');
        $this->line("  pairing_codes:    {$result['pairing_codes']}");
        $this->line("  inbox_items:      {$result['inbox_items']}");
        $this->line("  context_bundles:  {$result['context_bundles']}");
        $this->line("  push_deliveries:  {$result['push_deliveries']}");

        return self::SUCCESS;
    }

    private function status(MobileHealthService $health): int
    {
        $userId = (string) (config('atlas.mobile.cli_default_user_id') ?: 'vitor');
        $snapshot = $health->snapshot($userId);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Mobile gateway status: {$snapshot['status']}");
        $this->line('Generated at: '.$snapshot['generated_at']);
        $this->line('');
        $this->line('Expo:');
        $this->line('  circuit:           '.$snapshot['expo']['circuit']['state'].($snapshot['expo']['circuit']['opens_until'] ? ' (until '.$snapshot['expo']['circuit']['opens_until'].')' : ''));
        $this->line('  last success at:   '.($snapshot['expo']['last_success_at'] ?? '-'));
        $this->line('  last failure at:   '.($snapshot['expo']['last_failure_at'] ?? '-'));
        $this->line('  pending receipts:  '.$snapshot['expo']['pending_receipts']);
        $this->line('');
        $this->line('Push (24h):');
        $this->line('  total:             '.$snapshot['push_24h']['total']);
        $this->line('  sent:              '.$snapshot['push_24h']['sent']);
        $this->line('  failed:            '.$snapshot['push_24h']['failed']);
        $this->line('  deferred:          '.$snapshot['push_24h']['deferred']);
        $this->line('  success rate:      '.($snapshot['push_24h']['success_rate'] === null ? '-' : (string) $snapshot['push_24h']['success_rate']));
        $this->line('');
        $this->line('Devices:');
        $this->line('  active:            '.$snapshot['devices']['active']);
        $this->line('  with push token:   '.$snapshot['devices']['with_push_token']);
        $this->line('  last seen at:      '.($snapshot['devices']['last_seen_at'] ?? '-'));
        $this->line('');
        $this->line('Inbox:');
        $this->line('  unread:            '.$snapshot['inbox']['unread']);
        $this->line('  active:            '.$snapshot['inbox']['active']);

        return self::SUCCESS;
    }

    private function alertCheck(MobileReliabilityMonitor $reliability): int
    {
        $apply = (bool) $this->option('apply');
        $result = $reliability->check($apply);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Mobile reliability status: {$result['status']}".($result['dry_run'] ? ' (dry-run, use --apply)' : ''));
        $this->line('Generated at: '.$result['generated_at']);
        $this->line('');
        foreach ($result['checks'] as $check) {
            $this->line("  {$check['name']}: {$check['status']} - {$check['reason']}");
        }
        $this->line('');
        $this->line('Alert: '.(($result['alert']['sent'] ?? false) ? 'sent' : 'not sent').' ('.($result['alert']['reason'] ?? 'unknown').')');
        $this->line('Transition: '.(($result['transition']['recorded'] ?? false) ? ($result['transition']['event_type'] ?? 'recorded') : ($result['transition']['reason'] ?? 'not recorded')));

        return self::SUCCESS;
    }

    private function invalid(string $action): int
    {
        $this->error("Acao invalida para atlas mobile: {$action}");

        return self::FAILURE;
    }
}
