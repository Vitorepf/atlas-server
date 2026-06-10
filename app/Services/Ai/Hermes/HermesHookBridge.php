<?php

namespace App\Services\Ai\Hermes;

use App\Models\AiJob;
use App\Models\HermesHookRegistration;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasSecurity;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

/**
 * Default-OFF registrar that bridges Hermes lifecycle hooks into the Atlas
 * Evidence Ledger and lets Atlas intercept tool calls — without ever trusting
 * Hermes to narrate what it did.
 *
 * Hermes executes shell hooks declared in its `config.yaml` (`hooks: {<event>:
 * [{matcher,command,timeout}]}`). Atlas owns NONE of the operator's global
 * config: this bridge writes a single marker-delimited block
 * (`# >>> atlas-hermes-hook-bridge >>>` … `# <<< atlas-hermes-hook-bridge <<<`)
 * into a PER-INVOCATION `HERMES_HOME/config.yaml`, so {@see revoke()} can strip
 * exactly that block and leave any operator hooks outside the markers intact.
 *
 * Each managed hook entry POSTs the lifecycle event to a loopback-only Atlas
 * sink with a per-session token; the real sink ({@see HermesHookSink}) records
 * the event and, for `pre_tool_call`, returns an allow/block decision computed
 * against the mission scope. The bridge is fail-closed: `registration_allowed_now`
 * is true ONLY when the operator policy is the Atlas adapter, the permission
 * mode is write/danger, a provider consent basis exists, and the requested
 * events intersect the capability manifest. Every path returns a sealed
 * `atlas.hermes.hook_bridge_receipt.v1`; the per-session token value and the raw
 * `HERMES_HOME` path NEVER reach the receipt (sha256 only). `hooks_auto_accept`
 * is never written — Atlas decides, Hermes obeys.
 */
class HermesHookBridge
{
    use HermesAdapterReceipt;

    private const SCHEMA_VERSION = 'atlas.hermes.hook_bridge_receipt.v1';

    private const BLOCK_OPEN = '# >>> atlas-hermes-hook-bridge >>>';

    private const BLOCK_CLOSE = '# <<< atlas-hermes-hook-bridge <<<';

    /**
     * Lifecycle events Atlas wants to bridge, in priority order. Only those that
     * also appear in the capability manifest's `hooks.events[]` are registered.
     *
     * @var array<int,string>
     */
    private const DESIRED_EVENTS = [
        'pre_tool_call',
        'post_tool_call',
        'agent:start',
        'agent:step',
        'agent:end',
        'subagent_stop',
    ];

    public function __construct(
        private readonly Filesystem $files = new Filesystem(),
    ) {}

    /**
     * @param  array<string,mixed>  $mission
     * @param  array<string,mixed>  $invocation
     * @param  array<string,mixed>  $capabilityManifest
     * @param  array<string,mixed>  $sessionContext
     * @return array<string,mixed>
     */
    public function register(
        AiJob $job,
        array $mission,
        array $invocation,
        string $hookPolicy,
        string $permissionMode,
        array $capabilityManifest,
        array $sessionContext,
    ): array {
        $missionId = $this->string(data_get($mission, 'mission_id'), 120);
        $missionHash = $this->missionHash($mission);
        $manifestEvents = $this->manifestEvents($capabilityManifest);
        $consentBasis = $this->consentBasis($sessionContext);

        $receipt = $this->baseReceipt(
            hookPolicy: $hookPolicy,
            permissionMode: $permissionMode,
            missionId: $missionId,
            missionHash: $missionHash,
            capabilityManifest: $capabilityManifest,
            manifestEvents: $manifestEvents,
            consentBasis: null,
        );

        // Default OFF: any non-adapter policy writes nothing at all.
        if ($hookPolicy !== 'atlas_adapter') {
            $receipt['status'] = $hookPolicy === 'off' ? 'disabled' : 'skipped_by_policy';

            return $this->withReceiptHash($receipt);
        }

        if (! in_array($permissionMode, ['write', 'danger'], true)) {
            $receipt['status'] = 'skipped_permission';

            return $this->withReceiptHash($receipt);
        }

        if ($consentBasis === null) {
            $receipt['status'] = 'skipped_no_consent';

            return $this->withReceiptHash($receipt);
        }

        $receipt['consent_basis'] = $consentBasis;

        $traceId = $this->traceId($job, $sessionContext);
        if ($traceId === null) {
            $receipt['status'] = 'skipped_no_consent';
            $receipt['consent_basis'] = null;

            return $this->withReceiptHash($receipt);
        }

        $resolved = $this->resolveEvents($permissionMode, $capabilityManifest);
        if ($resolved['registered'] === []) {
            $receipt['events'] = $resolved['events'];
            $receipt['skipped_events'] = $resolved['skipped'];
            $receipt['status'] = 'manifest_unsupported';

            return $this->withReceiptHash($receipt);
        }

        $hermesHome = $this->hermesHome($sessionContext);
        $token = $this->sessionToken($sessionContext, $traceId);
        $sink = $this->sink($sessionContext);

        $hooksByEvent = [];
        foreach ($resolved['registered'] as $event) {
            $hooksByEvent[$event] = [$this->buildHookEntry($event, $traceId, $sink['url'], $token)];
        }

        $written = $this->writeManagedBlock($hermesHome, $hooksByEvent);

        $interceptionEnabled = in_array('pre_tool_call', $resolved['registered'], true)
            && $this->hookInterceptionAllowed($capabilityManifest);

        $receipt['hooks_registered'] = $written;
        $receipt['registration_allowed_now'] = $written;
        $receipt['interception_enabled'] = $written && $interceptionEnabled;
        $receipt['events'] = $resolved['events'];
        $receipt['skipped_events'] = $resolved['skipped'];
        $receipt['hermes_home_hash'] = $this->hashPath($hermesHome);
        $receipt['evidence_sink'] = [
            'kind' => 'atlas_local_loopback',
            'host' => $sink['host'],
            'route' => $sink['route'],
            'token_present' => $token !== '',
        ];
        $receipt['status'] = $written ? 'registered' : 'disabled';

        $sealed = $this->withReceiptHash($receipt);

        if ($written) {
            $this->persistRegistration($traceId, $missionHash, $resolved['registered'], $consentBasis, $hermesHome, $sealed['receipt_hash']);
        }

        return $sealed;
    }

    /**
     * Strip ONLY the Atlas-marked block from the per-invocation config, leaving
     * any operator hooks outside the markers untouched.
     *
     * @param  array<string,mixed>  $sessionContext
     * @return array<string,mixed>
     */
    public function revoke(AiJob $job, array $sessionContext): array
    {
        $traceId = $this->traceId($job, $sessionContext);
        $hermesHome = $this->hermesHome($sessionContext);

        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'adapter' => 'hermes_hook_bridge',
            'hook_policy' => 'atlas_adapter',
            'hook_authority' => 'atlas',
            'hooks_registered' => false,
            'registration_allowed_now' => false,
            'interception_enabled' => false,
            'events' => [],
            'requested_events' => [],
            'manifest_events' => [],
            'skipped_events' => [],
            'evidence_sink' => [
                'kind' => 'atlas_local_loopback',
                'host' => null,
                'route' => null,
                'token_present' => false,
            ],
            'consent_basis' => null,
            'permission_mode' => null,
            'capability_manifest_hash' => null,
            'hermes_home_hash' => $this->hashPath($hermesHome),
            'mission_id' => null,
            'mission_hash' => null,
            'reversible' => true,
            'status' => 'revoked',
        ];

        $this->stripManagedBlock($hermesHome);

        if ($traceId !== null && DatabaseTableAvailability::has('hermes_hook_registrations')) {
            HermesHookRegistration::query()
                ->where('trace_id', $traceId)
                ->where('active', true)
                ->update([
                    'active' => false,
                    'revoked_at' => now(),
                ]);
        }

        return $this->withReceiptHash($receipt);
    }

    /**
     * Intersect the desired events with the manifest's `hooks.events[]`.
     *
     * @param  array<string,mixed>  $capabilityManifest
     * @return array{registered:array<int,string>,events:array<int,array<string,mixed>>,skipped:array<int,array<string,string>>}
     */
    private function resolveEvents(string $permissionMode, array $capabilityManifest): array
    {
        $manifestEvents = $this->manifestEvents($capabilityManifest);

        $registered = [];
        $events = [];
        $skipped = [];

        foreach (self::DESIRED_EVENTS as $event) {
            if (in_array($event, $manifestEvents, true)) {
                $registered[] = $event;
                $events[] = ['event' => $event, 'registered' => true, 'reason' => null];

                continue;
            }

            $events[] = ['event' => $event, 'registered' => false, 'reason' => 'not_in_manifest'];
            $skipped[] = ['event' => $event, 'reason' => 'not_in_manifest'];
        }

        return [
            'registered' => $registered,
            'events' => $events,
            'skipped' => $skipped,
        ];
    }

    /**
     * Marker-delimited YAML that Atlas owns. The block is a complete `hooks:`
     * mapping wrapped by open/close markers so it can be detected and stripped.
     *
     * @param  array<string,array<int,array<string,mixed>>>  $hooksByEvent
     */
    private function renderManagedBlock(array $hooksByEvent): string
    {
        $yaml = class_exists(Yaml::class)
            ? Yaml::dump(['hooks' => $hooksByEvent], 8, 2)
            : json_encode(['hooks' => $hooksByEvent], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return self::BLOCK_OPEN."\n".rtrim((string) $yaml, "\n")."\n".self::BLOCK_CLOSE."\n";
    }

    /**
     * @return array<string,mixed>
     */
    private function buildHookEntry(string $event, string $traceId, string $sinkUrl, string $token): array
    {
        // The hook command pipes the JSON stdin Hermes provides to a loopback
        // POST carrying the per-session token; for pre_tool_call the sink's
        // {"decision":"block"|"allow"} stdout is what Hermes reads back.
        $command = sprintf(
            'curl -sS --max-time 5 -X POST -H "Content-Type: application/json" -H "X-Atlas-Hook-Token: %s" --data-binary @- %s',
            $token,
            AtlasSecurity::shellQuote($sinkUrl),
        );

        return [
            'matcher' => '*',
            'command' => $command,
            'timeout' => $event === 'pre_tool_call' ? 6 : 5,
        ];
    }

    /**
     * @param  array<string,array<int,array<string,mixed>>>  $hooksByEvent
     */
    private function writeManagedBlock(string $hermesHome, array $hooksByEvent): bool
    {
        if ($hooksByEvent === [] || trim($hermesHome) === '') {
            return false;
        }

        $configPath = $this->configPath($hermesHome);
        $this->files->ensureDirectoryExists(dirname($configPath), 0700);

        $existing = $this->files->exists($configPath) ? (string) $this->files->get($configPath) : '';
        $withoutManaged = $this->withoutManagedBlock($existing);

        $managed = $this->renderManagedBlock($hooksByEvent);

        $base = rtrim($withoutManaged, "\n");
        $body = $base === '' ? $managed : $base."\n\n".$managed;

        $this->files->put($configPath, $body);
        @chmod($configPath, 0600);

        return true;
    }

    private function stripManagedBlock(string $hermesHome): void
    {
        if (trim($hermesHome) === '') {
            return;
        }

        $configPath = $this->configPath($hermesHome);
        if (! $this->files->exists($configPath)) {
            return;
        }

        $existing = (string) $this->files->get($configPath);
        $stripped = $this->withoutManagedBlock($existing);

        $this->files->put($configPath, $stripped);
        @chmod($configPath, 0600);
    }

    private function withoutManagedBlock(string $contents): string
    {
        if ($contents === '' || ! str_contains($contents, self::BLOCK_OPEN)) {
            return $contents;
        }

        $pattern = '/'.preg_quote(self::BLOCK_OPEN, '/').'.*?'.preg_quote(self::BLOCK_CLOSE, '/').'\n?/s';
        $stripped = preg_replace($pattern, '', $contents);

        return is_string($stripped) ? rtrim($stripped, "\n")."\n" : $contents;
    }

    private function configPath(string $hermesHome): string
    {
        return rtrim($hermesHome, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'config.yaml';
    }

    /**
     * @return array<string,mixed>
     */
    private function baseReceipt(
        string $hookPolicy,
        string $permissionMode,
        ?string $missionId,
        ?string $missionHash,
        array $capabilityManifest,
        array $manifestEvents,
        ?string $consentBasis,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'adapter' => 'hermes_hook_bridge',
            'hook_policy' => $hookPolicy,
            'hook_authority' => 'atlas',
            'hooks_registered' => false,
            'registration_allowed_now' => false,
            'interception_enabled' => false,
            'events' => [],
            'requested_events' => self::DESIRED_EVENTS,
            'manifest_events' => $manifestEvents,
            'skipped_events' => [],
            'evidence_sink' => [
                'kind' => 'atlas_local_loopback',
                'host' => null,
                'route' => null,
                'token_present' => false,
            ],
            'consent_basis' => $consentBasis,
            'permission_mode' => $permissionMode,
            'capability_manifest_hash' => $this->hashValue($capabilityManifest),
            'hermes_home_hash' => null,
            'mission_id' => $missionId,
            'mission_hash' => $missionHash,
            'reversible' => true,
            'status' => 'disabled',
        ];
    }

    private function persistRegistration(
        string $traceId,
        ?string $missionHash,
        array $events,
        string $consentBasis,
        string $hermesHome,
        string $receiptHash,
    ): void {
        if (! DatabaseTableAvailability::has('hermes_hook_registrations')) {
            return;
        }

        HermesHookRegistration::query()->create([
            'trace_id' => $traceId,
            'mission_hash' => $missionHash,
            'events_json' => array_values($events),
            'consent_basis' => Str::limit($consentBasis, 80, ''),
            'hermes_home_hash' => $this->hashPath($hermesHome),
            'receipt_hash' => $receiptHash,
            'active' => true,
            'revoked_at' => null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $capabilityManifest
     * @return array<int,string>
     */
    private function manifestEvents(array $capabilityManifest): array
    {
        $events = data_get($capabilityManifest, 'hooks.events');
        $events = is_array($events) ? $events : [];

        return collect($events)
            ->filter(fn (mixed $event): bool => is_string($event) && trim($event) !== '')
            ->map(fn (mixed $event): string => trim((string) $event))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $capabilityManifest
     */
    private function hookInterceptionAllowed(array $capabilityManifest): bool
    {
        $flag = data_get($capabilityManifest, 'hooks.hook_interception');

        // Default permissive WITHIN an already write/danger + consented session:
        // interception follows from a registered pre_tool_call hook unless the
        // manifest explicitly forbids it.
        return $flag === null ? true : (bool) $flag;
    }

    /**
     * @param  array<string,mixed>  $sessionContext
     */
    private function consentBasis(array $sessionContext): ?string
    {
        $acceptHooks = data_get($sessionContext, 'consent.accept_hooks')
            ?? data_get($sessionContext, 'accept_hooks')
            ?? data_get($sessionContext, 'provider.accept_hooks');

        if ($acceptHooks === true) {
            return 'provider_accept_hooks';
        }

        $basis = $this->string(data_get($sessionContext, 'consent.basis') ?? data_get($sessionContext, 'consent_basis'), 80);

        return $basis;
    }

    /**
     * @param  array<string,mixed>  $sessionContext
     */
    private function traceId(AiJob $job, array $sessionContext): ?string
    {
        $trace = $this->string(data_get($sessionContext, 'trace_id'), 80)
            ?? (is_string($job->trace_id) ? $this->string($job->trace_id, 80) : null);

        return $trace;
    }

    /**
     * @param  array<string,mixed>  $sessionContext
     */
    private function hermesHome(array $sessionContext): string
    {
        $home = data_get($sessionContext, 'hermes_home')
            ?? data_get($sessionContext, 'HERMES_HOME')
            ?? data_get($sessionContext, 'env.HERMES_HOME');

        return is_string($home) ? trim($home) : '';
    }

    /**
     * @param  array<string,mixed>  $sessionContext
     */
    private function sessionToken(array $sessionContext, string $traceId): string
    {
        $token = data_get($sessionContext, 'sink.token')
            ?? data_get($sessionContext, 'hook_token')
            ?? data_get($sessionContext, 'token');

        if (is_string($token) && trim($token) !== '') {
            return trim($token);
        }

        // Deterministic per-session token derived from the trace; never logged.
        return substr(hash('sha256', 'hermes_hook_token|'.$traceId), 0, 48);
    }

    /**
     * @param  array<string,mixed>  $sessionContext
     * @return array{url:string,host:string,route:string}
     */
    private function sink(array $sessionContext): array
    {
        $host = $this->string(data_get($sessionContext, 'sink.host'), 120) ?? '127.0.0.1';
        $port = data_get($sessionContext, 'sink.port');
        $port = is_numeric($port) ? (int) $port : null;
        $traceId = $this->string(data_get($sessionContext, 'trace_id'), 80) ?? 'no_trace';
        $route = '/internal/hermes/hooks/'.rawurlencode($traceId);

        $authority = $port !== null ? $host.':'.$port : $host;
        $url = 'http://'.$authority.$route;

        return [
            'url' => $url,
            'host' => $host,
            'route' => $route,
        ];
    }

    private function missionHash(array $mission): ?string
    {
        $hash = data_get($mission, 'mission_hash');
        if (is_string($hash) && trim($hash) !== '') {
            return trim($hash);
        }

        $id = $this->string(data_get($mission, 'mission_id'), 120);

        return $id === null ? null : hash('sha256', $id);
    }

    private function hashPath(string $path): ?string
    {
        $path = trim($path);

        return $path === '' ? null : hash('sha256', $path);
    }

    private function string(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }
}
