<?php

namespace App\Console\Commands;

use App\Models\AiPermissionSession;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

class AtlasCliPermissionsCommand extends Command
{
    protected $signature = 'atlas:cli:permissions
        {action=status : status, approve, revoke or list}
        {scope? : Optional mode, tool or path}
        {--workspace= : Workspace path. Defaults to current directory}
        {--mode=write : read, write or danger}
        {--tool=* : Tool names covered by the session}
        {--path=* : Paths covered by the session}
        {--for=2h : Duration: 10m, 2h or 1d}
        {--reason=}
        {--all : Revoke all active sessions for workspace}
        {--json : Print machine-readable JSON}';

    protected $description = 'Manage Atlas CLI permission sessions for tool runtime.';

    public function handle(): int
    {
        if (! DatabaseTableAvailability::has('ai_permission_sessions')) {
            $this->error('Tabela ai_permission_sessions ainda nao existe. Rode migrations.');

            return self::FAILURE;
        }

        $action = Str::of((string) $this->argument('action'))->lower()->trim()->value();

        return match ($action) {
            'status', 'list' => $this->status(),
            'approve' => $this->approve(),
            'revoke' => $this->revoke(),
            default => $this->invalid($action),
        };
    }

    private function status(): int
    {
        $sessions = $this->activeQuery()->latest('created_at')->get();
        $payload = $sessions->map(fn (AiPermissionSession $session): array => $this->row($session))->all();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['permission_sessions' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ($payload === []) {
            $this->line('Nenhuma permission session ativa.');

            return self::SUCCESS;
        }

        $this->table(['id', 'mode', 'tools', 'paths', 'expires'], collect($payload)->map(fn (array $row): array => [
            $row['id'],
            $row['mode'],
            implode(', ', (array) $row['allowed_tools']) ?: '*',
            implode(', ', (array) $row['allowed_paths']) ?: '*',
            $row['expires_at'] ?: 'never',
        ])->all());

        return self::SUCCESS;
    }

    private function approve(): int
    {
        $scope = $this->argument('scope');
        $mode = is_string($scope) && in_array($scope, ['read', 'write', 'danger'], true)
            ? $scope
            : $this->mode();
        $duration = $this->durationSeconds((string) $this->option('for'));
        if ($mode === 'danger') {
            $duration = min($duration, 30 * 60);
        }

        $paths = array_values(array_filter((array) $this->option('path'), 'is_string'));
        $tools = array_values(array_filter((array) $this->option('tool'), 'is_string'));
        if (is_string($scope) && $scope !== '' && ! in_array($scope, ['read', 'write', 'danger'], true)) {
            if (str_contains($scope, '.') || str_contains($scope, ':')) {
                $tools[] = $scope;
            } else {
                $paths[] = $scope;
            }
        }

        $session = AiPermissionSession::query()->create([
            'workspace' => $this->workspace(),
            'mode' => $mode,
            'allowed_tools' => array_values(array_unique($tools)) ?: null,
            'allowed_paths' => array_values(array_unique($paths)) ?: null,
            'denied_patterns' => ['**/.env', '**/secrets/*', '**/*token*'],
            'expires_at' => now()->addSeconds($duration),
            'granted_by' => 'operator_cli',
            'reason' => is_string($this->option('reason')) ? $this->option('reason') : null,
        ]);

        $payload = $this->row($session);

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['ok' => true, 'permission_session' => $payload], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Permission session criada: '.$session->id);
            $this->line('Modo: '.$session->mode.' | expira: '.$session->expires_at?->toDateTimeString());
        }

        return self::SUCCESS;
    }

    private function revoke(): int
    {
        $query = $this->activeQuery();
        $scope = $this->argument('scope');

        if (! (bool) $this->option('all') && is_string($scope) && $scope !== '') {
            $query->where('id', $scope);
        }

        $count = 0;
        $query->get()->each(function (AiPermissionSession $session) use (&$count): void {
            $session->update(['expires_at' => now()]);
            $count++;
        });

        if ((bool) $this->option('json')) {
            $this->line(json_encode(['ok' => true, 'revoked' => $count], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info("Permission sessions revogadas: {$count}");
        }

        return self::SUCCESS;
    }

    private function activeQuery()
    {
        return AiPermissionSession::query()
            ->where('workspace', $this->workspace())
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    /**
     * @return array<string,mixed>
     */
    private function row(AiPermissionSession $session): array
    {
        return [
            'id' => $session->id,
            'workspace' => $session->workspace,
            'mode' => $session->mode,
            'allowed_tools' => $session->allowed_tools ?? [],
            'allowed_paths' => $session->allowed_paths ?? [],
            'expires_at' => $session->expires_at?->toJSON(),
            'reason' => $session->reason,
        ];
    }

    private function mode(): string
    {
        $mode = Str::of((string) $this->option('mode'))->lower()->trim()->value();

        return in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'write';
    }

    private function durationSeconds(string $value): int
    {
        if (preg_match('/^(\d+)\s*([mhd])$/i', trim($value), $matches) !== 1) {
            return 2 * 60 * 60;
        }

        $amount = max(1, (int) $matches[1]);

        return match (strtolower($matches[2])) {
            'd' => $amount * 86400,
            'h' => $amount * 3600,
            default => $amount * 60,
        };
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function invalid(string $action): int
    {
        $this->error("Acao invalida para atlas permissions: {$action}");

        return self::FAILURE;
    }
}
