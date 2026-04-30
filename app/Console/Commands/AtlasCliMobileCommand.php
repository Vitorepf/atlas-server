<?php

namespace App\Console\Commands;

use App\Http\Resources\MobileDeviceResource;
use App\Models\AtlasMobileDevice;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\Mobile\ContextBundleService;
use App\Services\Ai\Mobile\MobilePairingService;
use App\Services\Ai\Mobile\MobilePushService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AtlasCliMobileCommand extends Command
{
    protected $signature = 'atlas:cli:mobile
        {action=devices : devices, pair, revoke, push-test, flush-push or receipts}
        {arg? : Device id for revoke or label for pair}
        {--label= : Human-readable device label}
        {--device= : Device id for push-test}
        {--limit=100 : Max Expo receipts to check}
        {--json : Print machine-readable JSON}';

    protected $description = 'Manage Atlas mobile devices and pairing codes.';

    public function handle(
        MobilePairingService $pairing,
        AtlasInboxService $inbox,
        ContextBundleService $bundles,
        MobilePushService $push,
    ): int
    {
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
            'flush-push' => $this->flushPush($push),
            'receipts', 'push-receipts' => $this->receipts($push),
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

    private function invalid(string $action): int
    {
        $this->error("Acao invalida para atlas mobile: {$action}");

        return self::FAILURE;
    }
}
