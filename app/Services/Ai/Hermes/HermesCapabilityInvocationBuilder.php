<?php

namespace App\Services\Ai\Hermes;

use App\Services\Ai\Hermes\Support\HermesStringListNormalizer;

/**
 * Resolves an Atlas capability REQUEST against the probed Hermes manifest and
 * the operator policy, then emits ONLY the CLI tokens that survive the gate.
 *
 * This is the governance teeth of the capability registry: the factory says
 * what was *asked* ({@see HermesMissionCapabilitiesFactory}); this builder says
 * what Hermes is *allowed* to do this turn and rewrites the chat argv to match.
 * It is fail-closed by construction — a capability is emitted ONLY when it is
 * present in the manifest entries, the manifest marks it supported, the policy
 * allowlist contains its canonical id, the permission mode permits it, and any
 * required `config.yaml` confirmation is present. Anything else is dropped with
 * an auditable reason. The emittable surface is deliberately narrow: toolsets
 * fold into a single `--toolsets` csv, flags append once, and context refs are
 * returned as prompt `@`-references (never argv) and only when the value sits
 * inside the policy's allowed-paths list. Everything is recorded in a sealed
 * `atlas.hermes.capability_invocation_receipt.v1` so the Evidence Ledger can
 * prove exactly which capabilities Atlas enabled without trusting Hermes to
 * narrate it.
 */
class HermesCapabilityInvocationBuilder
{
    use HermesAdapterReceipt;

    private const EMITTABLE_CLASSES = ['toolset', 'flag', 'context_ref'];

    /**
     * @param  array<int,string>  $args
     * @param  array<string,mixed>  $missionCapabilities
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $policy
     * @return array{args:array<int,string>,prompt_context_refs:array<int,string>,receipt:array<string,mixed>}
     */
    public function apply(array $args, array $missionCapabilities, array $manifest, array $policy, string $permissionMode): array
    {
        $args = array_values($args);
        $permissionMode = $this->permissionMode($permissionMode);
        $policyEnabled = ($policy['enabled'] ?? null) !== false;
        $manifestVersion = $this->manifestVersion($manifest);

        $requestedIds = $this->requestedIds($missionCapabilities);

        $receipt = [
            'schema_version' => 'atlas.hermes.capability_invocation_receipt.v1',
            'builder' => 'hermes_capability_invocation_builder',
            'capability_authority' => 'atlas',
            'policy_enabled' => $policyEnabled,
            'permission_mode' => $permissionMode,
            'manifest_version' => $manifestVersion,
            'requested_count' => count($requestedIds),
            'resolved_count' => 0,
            'dropped_count' => 0,
            'resolved' => [],
            'dropped' => [],
            'emitted_toolsets' => [],
            'emitted_flags' => [],
            'emitted_context_ref_count' => 0,
            'any_capability_enabled_now' => false,
            'status' => 'no_capabilities',
        ];

        if ($requestedIds === []) {
            $receipt['status'] = 'no_capabilities';

            return [
                'args' => $args,
                'prompt_context_refs' => [],
                'receipt' => $this->withReceiptHash($receipt),
            ];
        }

        if (! $policyEnabled) {
            $receipt['status'] = 'policy_disabled';

            return [
                'args' => $args,
                'prompt_context_refs' => [],
                'receipt' => $this->withReceiptHash($receipt),
            ];
        }

        $resolution = $this->resolve($requestedIds, $manifest, $policy, $permissionMode);
        $resolved = $resolution['resolved'];
        $dropped = $resolution['dropped'];

        $emittedToolsets = [];
        $emittedFlags = [];
        $promptContextRefs = [];
        $receiptResolved = [];

        foreach ($resolved as $entry) {
            $emitTarget = $this->emitTarget($entry['kind']);

            if ($entry['kind'] === 'toolset' && $entry['emitted_token'] !== null) {
                $emittedToolsets[] = $entry['emitted_token'];
            } elseif ($entry['kind'] === 'flag' && $entry['emitted_token'] !== null) {
                $args = $this->emitStandaloneFlag($args, $entry['emitted_token']);
                $emittedFlags[] = $entry['emitted_token'];
            } elseif ($entry['kind'] === 'context_ref' && $entry['emitted_token'] !== null) {
                $promptContextRefs[] = $entry['emitted_token'];
            }

            $receiptResolved[] = [
                'id' => $entry['id'],
                'kind' => $entry['kind'],
                'emitted_token' => $entry['emitted_token'],
                'emit_target' => $emitTarget,
            ];
        }

        $emittedToolsets = array_values(array_unique($emittedToolsets));
        if ($emittedToolsets !== []) {
            $args = $this->emitToolsets($args, $emittedToolsets);
        }

        $promptContextRefs = array_values(array_unique($promptContextRefs));

        $receipt['resolved'] = $receiptResolved;
        $receipt['dropped'] = $dropped;
        $receipt['resolved_count'] = count($receiptResolved);
        $receipt['dropped_count'] = count($dropped);
        $receipt['emitted_toolsets'] = $emittedToolsets;
        $receipt['emitted_flags'] = array_values(array_unique($emittedFlags));
        $receipt['emitted_context_ref_count'] = count($promptContextRefs);
        $receipt['any_capability_enabled_now'] = $receipt['resolved_count'] > 0;
        $receipt['status'] = $this->status($receipt['resolved_count'], $receipt['dropped_count']);

        return [
            'args' => array_values($args),
            'prompt_context_refs' => $promptContextRefs,
            'receipt' => $this->withReceiptHash($receipt),
        ];
    }

    /**
     * @param  array<int,string>  $requestedIds
     * @param  array<string,mixed>  $manifest
     * @param  array<string,mixed>  $policy
     * @return array{resolved:array<int,array<string,mixed>>,dropped:array<int,array<string,string>>}
     */
    private function resolve(array $requestedIds, array $manifest, array $policy, string $permissionMode): array
    {
        $allow = HermesStringListNormalizer::invocationValues($policy['allow'] ?? null, 200);
        $allowByMode = is_array($policy['allow_by_mode'] ?? null) ? $policy['allow_by_mode'] : [];
        $confirmedConfigIds = HermesStringListNormalizer::invocationValues($policy['config_confirmed'] ?? null, 200);
        $allowedPaths = HermesStringListNormalizer::invocationValues($policy['allowed_paths'] ?? null, 200);

        $resolved = [];
        $dropped = [];

        foreach ($requestedIds as $id) {
            $entry = $this->lookup($id, $manifest);

            // 1. unknown_capability — not present in the probed manifest entries.
            if ($entry === null) {
                $dropped[] = ['id' => $id, 'reason' => 'unknown_capability'];

                continue;
            }

            // 2. unsupported_by_hermes_manifest — manifest says the binary cannot do it.
            if (($entry['supported'] ?? false) !== true) {
                $dropped[] = ['id' => $id, 'reason' => 'unsupported_by_hermes_manifest'];

                continue;
            }

            // 3. blocked_by_permission_mode — policy.allow_by_mode says this mode may not.
            if ($this->blockedByMode($id, $permissionMode, $allowByMode)) {
                $dropped[] = ['id' => $id, 'reason' => 'blocked_by_permission_mode'];

                continue;
            }

            // 4. not_in_policy_allowlist — operator allowlist does not contain the id.
            if (! in_array($id, $allow, true)) {
                $dropped[] = ['id' => $id, 'reason' => 'not_in_policy_allowlist'];

                continue;
            }

            // 5. requires_config_yaml_not_present — needs config.yaml confirmation Atlas has not given.
            if (($entry['requires_config'] ?? false) === true && ! in_array($id, $confirmedConfigIds, true)) {
                $dropped[] = ['id' => $id, 'reason' => 'requires_config_yaml_not_present'];

                continue;
            }

            $class = is_string($entry['capability_class'] ?? null) ? $entry['capability_class'] : '';

            // context_ref only for values inside the policy allowed-paths list.
            if ($class === 'context_ref' && ! $this->contextRefAllowed($entry, $allowedPaths)) {
                $dropped[] = ['id' => $id, 'reason' => 'context_ref_not_in_allowed_paths'];

                continue;
            }

            $resolved[] = [
                'id' => $id,
                'kind' => $class !== '' ? $class : 'feature',
                'emitted_token' => $this->emittedToken($entry, $class),
            ];
        }

        return ['resolved' => $resolved, 'dropped' => $dropped];
    }

    /**
     * @param  array<int,string>  $args
     * @param  array<int,string>  $names
     * @return array<int,string>
     */
    private function emitToolsets(array $args, array $names): array
    {
        $args = array_values($args);
        $existing = [];
        $index = null;

        $count = count($args);
        for ($i = 0; $i < $count; $i++) {
            if ($args[$i] === '--toolsets' && isset($args[$i + 1])) {
                $existing = preg_split('/\s*,\s*/', (string) $args[$i + 1]) ?: [];
                $index = $i + 1;

                break;
            }

            if (is_string($args[$i]) && str_starts_with($args[$i], '--toolsets=')) {
                $existing = preg_split('/\s*,\s*/', substr($args[$i], strlen('--toolsets='))) ?: [];
                $index = $i;

                $merged = $this->dedupeNonEmpty(array_merge($existing, $names));
                $args[$i] = '--toolsets='.implode(',', $merged);

                return array_values($args);
            }
        }

        $merged = $this->dedupeNonEmpty(array_merge($existing, $names));

        if ($index !== null) {
            $args[$index] = implode(',', $merged);

            return array_values($args);
        }

        $args[] = '--toolsets';
        $args[] = implode(',', $merged);

        return array_values($args);
    }

    /**
     * @param  array<int,string>  $args
     * @return array<int,string>
     */
    private function emitStandaloneFlag(array $args, string $flag): array
    {
        if (in_array($flag, $args, true)) {
            return array_values($args);
        }

        $args[] = $flag;

        return array_values($args);
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>|null
     */
    private function lookup(string $id, array $manifest): ?array
    {
        $entries = $manifest['entries'] ?? null;
        if (! is_array($entries)) {
            return null;
        }

        foreach ($entries as $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $missionCapabilities
     * @return array<int,string>
     */
    private function requestedIds(array $missionCapabilities): array
    {
        $ids = $missionCapabilities['requested_capability_ids'] ?? null;

        return HermesStringListNormalizer::invocationValues($ids, 200);
    }

    /**
     * @param  array<string,mixed>  $allowByMode
     */
    private function blockedByMode(string $id, string $permissionMode, array $allowByMode): bool
    {
        // allow_by_mode lists ids that are *only* permitted in the named mode(s).
        // If an id is named under any stricter mode but NOT under the active mode,
        // the active mode is not authorised to enable it.
        $modesNamingId = [];
        foreach ($allowByMode as $mode => $ids) {
            $modeName = is_string($mode) ? $mode : (string) $mode;
            if (in_array($id, HermesStringListNormalizer::invocationValues($ids, 200), true)) {
                $modesNamingId[] = $modeName;
            }
        }

        if ($modesNamingId === []) {
            return false;
        }

        return ! in_array($permissionMode, $modesNamingId, true);
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<int,string>  $allowedPaths
     */
    private function contextRefAllowed(array $entry, array $allowedPaths): bool
    {
        $token = $this->contextRefValue($entry);
        if ($token === null) {
            return false;
        }

        foreach ($allowedPaths as $allowedPath) {
            if ($allowedPath !== '' && str_starts_with($token, $allowedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function emittedToken(array $entry, string $class): ?string
    {
        if (! in_array($class, self::EMITTABLE_CLASSES, true)) {
            return null;
        }

        if ($class === 'context_ref') {
            $value = $this->contextRefValue($entry);

            return $value === null ? null : '@'.$value;
        }

        $token = $entry['hermes_token'] ?? null;

        return is_string($token) && trim($token) !== '' ? trim($token) : null;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function contextRefValue(array $entry): ?string
    {
        $token = $entry['hermes_token'] ?? null;
        if (is_string($token) && trim($token) !== '') {
            return trim($token);
        }

        $detail = is_array($entry['detail'] ?? null) ? $entry['detail'] : [];
        foreach (['value', 'path', 'file'] as $key) {
            $candidate = $detail[$key] ?? null;
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        $key = $entry['capability_key'] ?? null;

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    private function emitTarget(string $kind): string
    {
        return match ($kind) {
            'toolset' => '--toolsets',
            'flag' => 'flag',
            'context_ref' => 'prompt_context_ref',
            default => 'governance_only',
        };
    }

    private function status(int $resolvedCount, int $droppedCount): string
    {
        if ($resolvedCount === 0 && $droppedCount === 0) {
            return 'no_capabilities';
        }

        if ($resolvedCount === 0) {
            return 'all_dropped';
        }

        return $droppedCount > 0 ? 'partially_dropped' : 'resolved';
    }

    /**
     * @param  array<string,mixed>  $manifest
     */
    private function manifestVersion(array $manifest): ?int
    {
        $version = $manifest['manifest_version'] ?? null;

        return is_numeric($version) ? (int) $version : null;
    }

    private function permissionMode(string $permissionMode): string
    {
        $mode = trim($permissionMode);

        return in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'read';
    }

    /**
     * @param  array<int,string>  $values
     * @return array<int,string>
     */
    private function dedupeNonEmpty(array $values): array
    {
        return collect($values)
            ->map(fn (mixed $value): string => is_string($value) ? trim($value) : '')
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

}
