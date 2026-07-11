<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeImplementation;

use Closure;

/** Executes a proposal only inside an isolated disposable filesystem root. */
final class AtlasSelfConstructionHermeticSandboxApplyService
{
    private const MANIFEST = '.atlas-native-manifest.json';

    public function __construct(
        private readonly ?AtlasSelfConstructionNativePatchMaterializer $materializer = null,
        private readonly ?Closure $manifestWriter = null,
    ) {}

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function execute(array $input): array
    {
        $key = trim((string) ($input['idempotency_key'] ?? ''));
        if ($key === '') {
            return ['applied' => false, 'dry_run' => false, 'reason' => 'idempotency_key_missing'];
        }
        $sandbox = sys_get_temp_dir().'/atlas-native-sandbox-'.substr(hash('sha256', $key), 0, 24);
        if (! is_dir($sandbox) && ! mkdir($sandbox, 0o700, true) && ! is_dir($sandbox)) {
            return ['applied' => false, 'dry_run' => false, 'reason' => 'sandbox_create_failed'];
        }
        if (is_link($sandbox)) {
            return ['applied' => false, 'dry_run' => false, 'reason' => 'sandbox_symlink_detected'];
        }
        chmod($sandbox, 0o700);

        $boundManifest = $this->reconcile($key);
        if (is_array($boundManifest) && ! $this->claimIdentityMatches($boundManifest, $input)) {
            return ['applied' => false, 'dry_run' => false, 'reason' => 'manifest_claim_mismatch', 'sandbox_root' => $sandbox];
        }

        $proposal = ($this->materializer ?? new AtlasSelfConstructionNativePatchMaterializer)
            ->materialize((array) ($input['patch_plan'] ?? []));
        if (($proposal['accepted'] ?? false) !== true) {
            return ['applied' => false, 'dry_run' => false, 'reason' => 'materialization_refused', 'sandbox_root' => $sandbox];
        }
        foreach ((array) ($proposal['files'] ?? []) as $file) {
            if (! is_array($file) || $this->hasSymlinkComponent($sandbox, (string) ($file['path'] ?? ''))) {
                return ['applied' => false, 'dry_run' => false, 'reason' => 'sandbox_symlink_detected', 'sandbox_root' => $sandbox];
            }
        }

        $idempotencyReceipt = hash('sha256', $key.'|'.(string) json_encode($proposal, JSON_UNESCAPED_SLASHES));
        $alreadyApplied = ($proposal['files'] ?? []) !== [];
        foreach ((array) ($proposal['files'] ?? []) as $file) {
            if (! is_array($file)) {
                $alreadyApplied = false;
                break;
            }
            $path = $sandbox.'/'.(string) ($file['path'] ?? '');
            if (! is_file($path) || hash_file('sha256', $path) !== hash('sha256', (string) ($file['contents'] ?? ''))) {
                $alreadyApplied = false;
                break;
            }
        }
        if ($alreadyApplied) {
            $replayed = [
                'applied' => true,
                'replayed' => true,
                'dry_run' => false,
                'sandbox_root' => $sandbox,
                'idempotency_receipt' => $idempotencyReceipt,
                'diffs' => $proposal['diffs'] ?? [],
            ];
            if (! $this->persistAppliedManifest($sandbox, $key, $input, $proposal, [], $idempotencyReceipt)) {
                return array_replace($replayed, ['applied' => false, 'reason' => 'reconciliation_uncertain']);
            }

            return $replayed;
        }

        $files = [];
        foreach ((array) ($proposal['files'] ?? []) as $file) {
            if (! is_array($file)) {
                continue;
            }
            $contents = (string) ($file['contents'] ?? '');
            $files[] = $file + [
                'mode' => 'create',
                'patch_artifact_hash' => hash('sha256', $contents),
            ];
        }
        $apply = (new AtlasSelfConstructionNativeScopedPatchApplyRunner($sandbox))->apply(
            ['decision' => 'allow'],
            ['allowed_files' => (array) ($input['allowed_files'] ?? []), 'files' => $files],
        );
        if (($apply['refused'] ?? true) === true || ($apply['applied_files'] ?? []) === []) {
            return ['applied' => false, 'dry_run' => false, 'reason' => 'sandbox_apply_failed', 'sandbox_root' => $sandbox, 'apply_receipt' => $apply];
        }

        $result = [
            'applied' => true,
            'replayed' => false,
            'dry_run' => false,
            'sandbox_root' => $sandbox,
            'apply_receipt' => $apply,
            'diffs' => $proposal['diffs'] ?? [],
            'idempotency_receipt' => $idempotencyReceipt,
        ];
        $persisted = $this->persistAppliedManifest($sandbox, $key, $input, $proposal, $apply, $idempotencyReceipt);

        if (! $persisted) {
            return array_replace($result, ['applied' => false, 'reason' => 'reconciliation_uncertain']);
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    public function reconcile(string $key): ?array
    {
        $path = sys_get_temp_dir().'/atlas-native-sandbox-'.substr(hash('sha256', $key), 0, 24).'/'.self::MANIFEST;
        if (! is_file($path) || is_link($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ($decoded['schema'] ?? null) !== 'atlas.native_manifest.v1'
            || ! in_array(($decoded['state'] ?? null), ['provider_staged', 'applied'], true)
            || ! hash_equals($key, (string) ($decoded['idempotency_key'] ?? ''))) {
            return null;
        }
        $providerHash = $this->hash((array) ($decoded['provider_receipt'] ?? []));
        $identity = ['task_packet_id' => (string) ($decoded['task_packet_id'] ?? ''), 'lease_id' => (string) ($decoded['lease_id'] ?? '')];
        if (! hash_equals($providerHash, (string) ($decoded['provider_hash'] ?? ''))
            || ! hash_equals($this->hash($identity), (string) ($decoded['identity_hash'] ?? ''))) {
            return null;
        }
        if (($decoded['state'] ?? null) === 'applied') {
            if (! hash_equals($this->hash((array) ($decoded['apply_receipt'] ?? [])), (string) ($decoded['apply_hash'] ?? ''))
                || ! hash_equals($this->hash((array) ($decoded['evidence_receipt'] ?? [])), (string) ($decoded['evidence_hash'] ?? ''))
                || ! $this->postimagesMatch(dirname($path), (array) ($decoded['postimage_hashes'] ?? []))) {
                return null;
            }
        }

        return $decoded;
    }

    /** @param array<string,mixed> $receipt */
    public function stageProvider(string $key, array $receipt, array $claim = []): bool
    {
        $sandbox = sys_get_temp_dir().'/atlas-native-sandbox-'.substr(hash('sha256', $key), 0, 24);
        if (! is_dir($sandbox) && ! mkdir($sandbox, 0o700, true) && ! is_dir($sandbox)) {
            return false;
        }
        if (is_link($sandbox)) {
            return false;
        }
        $existing = $this->reconcile($key);
        $hash = hash('sha256', (string) json_encode($receipt, JSON_UNESCAPED_SLASHES));
        if (is_array($existing)) {
            return hash_equals((string) ($existing['provider_hash'] ?? ''), $hash)
                && $this->claimIdentityMatches($existing, $claim);
        }

        $identity = ['task_packet_id' => (string) ($claim['task_packet_id'] ?? ''), 'lease_id' => (string) ($claim['lease_id'] ?? '')];

        return $this->writeManifest($sandbox, [
            'schema' => 'atlas.native_manifest.v1',
            'state' => 'provider_staged',
            'idempotency_key' => $key,
            'task_packet_id' => (string) ($claim['task_packet_id'] ?? ''),
            'lease_id' => (string) ($claim['lease_id'] ?? ''),
            'identity_hash' => $this->hash($identity),
            'provider_receipt' => $receipt,
            'provider_hash' => $hash,
            'apply_hash' => null,
            'evidence_hash' => null,
        ]);
    }

    /** @param array<string,mixed> $input @param array<string,mixed> $proposal @param array<string,mixed> $apply */
    private function persistAppliedManifest(string $sandbox, string $key, array $input, array $proposal, array $apply, string $idempotencyReceipt): bool
    {
        $provider = (array) ($input['provider_receipt'] ?? []);
        $existing = $this->reconcile($key);
        if (is_array($existing) && ! hash_equals((string) ($existing['provider_hash'] ?? ''), $this->hash($provider))) {
            return false;
        }
        $postimages = [];
        foreach ((array) ($proposal['files'] ?? []) as $file) {
            if (! is_array($file) || trim((string) ($file['path'] ?? '')) === '') {
                continue;
            }
            $path = (string) $file['path'];
            $postimages[$path] = hash('sha256', (string) ($file['contents'] ?? ''));
        }
        ksort($postimages);
        $applyReceipt = $apply !== [] ? $apply : ['replayed' => true, 'postimage_hashes' => $postimages];
        $evidenceReceipt = ['idempotency_receipt' => $idempotencyReceipt, 'postimage_hashes' => $postimages];

        $identity = [
            'task_packet_id' => (string) ($input['task_packet_id'] ?? $existing['task_packet_id'] ?? ''),
            'lease_id' => (string) ($input['lease_id'] ?? $existing['lease_id'] ?? ''),
        ];

        return $this->writeManifest($sandbox, [
            'schema' => 'atlas.native_manifest.v1', 'state' => 'applied', 'idempotency_key' => $key,
            'task_packet_id' => $identity['task_packet_id'], 'lease_id' => $identity['lease_id'],
            'identity_hash' => $this->hash($identity),
            'provider_receipt' => $provider, 'provider_hash' => $this->hash($provider),
            'apply_receipt' => $applyReceipt, 'apply_hash' => $this->hash($applyReceipt),
            'evidence_receipt' => $evidenceReceipt, 'evidence_hash' => $this->hash($evidenceReceipt),
            'postimage_hashes' => $postimages,
        ]);
    }

    /** @param array<string,string> $postimages */
    private function postimagesMatch(string $sandbox, array $postimages): bool
    {
        if ($postimages === []) {
            return false;
        }
        foreach ($postimages as $relative => $expected) {
            $path = $sandbox.'/'.(string) $relative;
            if ($this->hasSymlinkComponent($sandbox, (string) $relative)
                || ! is_file($path)
                || is_link($path)
                || ! hash_equals((string) $expected, (string) hash_file('sha256', $path))) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $claim */
    private function claimIdentityMatches(array $manifest, array $claim): bool
    {
        foreach (['task_packet_id', 'lease_id'] as $field) {
            $expected = (string) ($manifest[$field] ?? '');
            $actual = (string) ($claim[$field] ?? '');
            if ($expected !== '' && ! hash_equals($expected, $actual)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $manifest */
    private function writeManifest(string $sandbox, array $manifest): bool
    {
        if ($this->manifestWriter instanceof Closure) {
            return (bool) ($this->manifestWriter)($sandbox, $manifest);
        }
        $path = $sandbox.'/'.self::MANIFEST;
        if (is_link($path)) {
            return false;
        }
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (file_put_contents($tmp, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
            return false;
        }
        chmod($tmp, 0o600);

        return rename($tmp, $path);
    }

    private function hasSymlinkComponent(string $root, string $relative): bool
    {
        $cursor = $root;
        foreach (explode('/', trim($relative, '/')) as $component) {
            $cursor .= '/'.$component;
            if (is_link($cursor)) {
                return true;
            }
            if (file_exists($cursor)) {
                $real = realpath($cursor);
                $realRoot = realpath($root);
                if ($real === false || $realRoot === false || ($real !== $realRoot && ! str_starts_with($real, $realRoot.'/'))) {
                    return true;
                }
            }
        }

        return false;
    }
}
