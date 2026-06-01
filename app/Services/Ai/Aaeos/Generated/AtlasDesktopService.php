<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Desktop surface-root guard: macOS install guardrail + desktop
 * parent-resolution + anti-mock contract validator.
 *
 * Pure, deterministic implementation of the contract declared by the canonical
 * surface-root doc `atlas-desktop`. That doc is the visual root of the Atlas
 * desktop surfaces (Cartografia desktop, Atlas Code Operating Room, backend
 * bridge, anti-mock contracts). It is a SURFACE, not the Kernel, and not a
 * primary source of truth.
 *
 * Documented rules enforced (from the doc "Contratos", "Regras para IA",
 * "macOS Build / Install Guardrail", forbidden_changes, quality_gates,
 * observability_signals):
 *
 *   R1 (macOS install guardrail). "/Applications/Atlas Code.app e o unico
 *       bundle instalado permitido." Exactly ONE bundle named precisely
 *       `Atlas Code.app` may live in `/Applications`. Any extra bundle, and any
 *       bundle whose name is not exactly `Atlas Code.app`, is a violation.
 *   R2 (no timestamped/suffixed copies). forbidden_changes + guardrail: names
 *       like `Atlas Code.app.backup-*`, `Atlas Code.app-YYYY*` or
 *       `Atlas Code.app...` (any suffix after the canonical bundle name) are
 *       forbidden — they pollute Launchpad/Finder. Each such entry is flagged
 *       with its specific reason.
 *   R3 (desktop child needs resolvable parent). "Filhos desktop ativos precisam
 *       de graph_parent: atlas-desktop." An ACTIVE desktop doc that declares any
 *       parent other than `atlas-desktop` (or whose parent does not resolve to
 *       this present root) is an orphan/misparented child.
 *   R4 (root must exist for children to hang). "Sem este parent, backend
 *       desktop e Atlas Code podem aparecer como docs orfaos." If the
 *       `atlas-desktop` root is absent from the node set, every active child
 *       parented to it orphans and the gate fails.
 *   R5 (Desktop is not Kernel / not primary source — anti-mock). "Desktop nao
 *       decide como Kernel" and "Dados exibidos precisam vir de fontes canonicas
 *       ou receipts auditaveis." A surface payload that (a) claims kernel/decider
 *       authority, or (b) presents data whose origin is a mock/invented source
 *       rather than canonical/receipt, violates the contract.
 *   R6 (gate). quality_gates `cartography-orphan-count-zero`: the desktop
 *       surface is healthy only when there are ZERO install violations, ZERO
 *       misparented active children AND ZERO anti-mock violations.
 *
 * Inactive nodes (status !== active) are excluded from the parent computation,
 * matching the doc which scopes the contract to "documento ativo".
 *
 * The service NEVER reads the filesystem, the database or a provider. Callers
 * supply the observed `/Applications` entry names and the cartography node set;
 * this service only decides. Callers choose whether to block install/publish on
 * a failing gate.
 *
 * @see docs/engineering-knowledge-base/atlas-desktop.md
 */
final class AtlasDesktopService
{
    /** Stable receipt schema id for the audit this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.aaeos.surface_root.atlas_desktop.v1';

    /** Canonical id of THIS surface root node. */
    public const ROOT_ID = 'atlas-desktop';

    /** The world root that Desktop sits beneath (Desktop is not the Kernel). */
    public const WORLD_ROOT_ID = 'atlas';

    /** The one and only bundle name permitted in /Applications. */
    public const CANONICAL_BUNDLE = 'Atlas Code.app';

    /** The quality gate this doc declares. */
    public const GATE = 'cartography-orphan-count-zero';

    /**
     * Validate the observed `/Applications` bundle entries against the macOS
     * install guardrail (R1, R2).
     *
     * @param list<string> $applicationsEntries the bare names found directly in
     *        /Applications (e.g. "Atlas Code.app", "Atlas Code.app.backup-2026").
     *
     * @return array{
     *     bundle_count:int,
     *     canonical_present:bool,
     *     violations:list<array{name:string,reason:string}>,
     *     install_ok:bool
     * }
     */
    public function auditMacInstall(array $applicationsEntries): array
    {
        $names = [];
        foreach ($applicationsEntries as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            $name = trim($entry);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        $violations = [];
        $canonicalCount = 0;

        foreach ($names as $name) {
            // Only consider Atlas Code bundles. Unrelated apps in /Applications
            // are out of scope for this guardrail.
            if (! $this->isAtlasCodeBundle($name)) {
                continue;
            }

            if ($name === self::CANONICAL_BUNDLE) {
                $canonicalCount++;

                continue;
            }

            // It looks like an Atlas Code bundle but is NOT the canonical name:
            // classify the specific forbidden shape (R2).
            $violations[] = [
                'name' => $name,
                'reason' => $this->classifyForbiddenBundle($name),
            ];
        }

        // R1 — more than one canonical bundle (duplicate install) is itself a
        // violation; /Applications must hold exactly one.
        if ($canonicalCount > 1) {
            for ($i = 1; $i < $canonicalCount; $i++) {
                $violations[] = [
                    'name' => self::CANONICAL_BUNDLE,
                    'reason' => 'duplicate_canonical_bundle',
                ];
            }
        }

        return [
            'bundle_count' => $canonicalCount + count(array_filter(
                $violations,
                static fn (array $v): bool => $v['reason'] !== 'duplicate_canonical_bundle',
            )),
            'canonical_present' => $canonicalCount >= 1,
            'violations' => array_values($violations),
            'install_ok' => $violations === [] && $canonicalCount === 1,
        ];
    }

    /**
     * Validate desktop cartography children against the parent-resolution
     * contract (R3, R4).
     *
     * @param list<array<string,mixed>> $nodes each node:
     *        { id|graph_id: string, parent|graph_parent: ?string,
     *          status: string (default active) }
     *
     * @return array{
     *     root_present:bool,
     *     node_count:int,
     *     orphan_count:int,
     *     orphans:list<array{id:string,parent:?string,reason:string}>,
     *     cartography_ok:bool
     * }
     */
    public function auditDesktopCartography(array $nodes): array
    {
        $normalized = $this->normalizeNodes($nodes);

        $knownIds = [];
        foreach ($normalized as $node) {
            $knownIds[$node['id']] = true;
        }

        $rootPresent = isset($knownIds[self::ROOT_ID]);

        $orphans = [];

        foreach ($normalized as $node) {
            $id = $node['id'];
            $parent = $node['parent'];
            $active = $node['active'];

            // The world root and the desktop root itself are structural; they
            // are never desktop-children orphans under this surface contract.
            if ($id === self::WORLD_ROOT_ID) {
                continue;
            }
            if ($id === self::ROOT_ID) {
                // The desktop root legitimately hangs off the world root.
                if ($parent !== null && $parent !== self::WORLD_ROOT_ID && ! isset($knownIds[$parent])) {
                    $orphans[] = [
                        'id' => $id,
                        'parent' => $parent,
                        'reason' => 'root_parent_unresolved',
                    ];
                }

                continue;
            }

            // Only ACTIVE desktop children are bound by the contract.
            if (! $active) {
                continue;
            }

            // R4 — node cannot be its own parent.
            if ($parent !== null && $parent === $id) {
                $orphans[] = [
                    'id' => $id,
                    'parent' => $parent,
                    'reason' => 'self_parent',
                ];

                continue;
            }

            // An active desktop child with no parent at all cannot hang.
            if ($parent === null) {
                $orphans[] = [
                    'id' => $id,
                    'parent' => null,
                    'reason' => 'missing_parent',
                ];

                continue;
            }

            // R4 — child parented to atlas-desktop while the root is absent.
            if ($parent === self::ROOT_ID && ! $rootPresent) {
                $orphans[] = [
                    'id' => $id,
                    'parent' => $parent,
                    'reason' => 'desktop_root_missing',
                ];

                continue;
            }

            // R3 — generic unresolved parent.
            if (! isset($knownIds[$parent])) {
                $orphans[] = [
                    'id' => $id,
                    'parent' => $parent,
                    'reason' => 'parent_unresolved',
                ];
            }
        }

        return [
            'root_present' => $rootPresent,
            'node_count' => count($normalized),
            'orphan_count' => count($orphans),
            'orphans' => array_values($orphans),
            'cartography_ok' => $orphans === [],
        ];
    }

    /**
     * Validate a desktop surface payload against the anti-mock / not-Kernel
     * contract (R5).
     *
     * @param array<string,mixed> $surface
     *        recognised keys:
     *          - claims_kernel_authority|decides_as_kernel: bool — surface must
     *            NOT decide as the Kernel.
     *          - data_origin|source_kind: string — origin of displayed data;
     *            must be one of the canonical/receipt kinds.
     *
     * @return array{
     *     violations:list<array{field:string,reason:string}>,
     *     anti_mock_ok:bool
     * }
     */
    public function auditSurfacePayload(array $surface): array
    {
        $violations = [];

        // R5a — Desktop nao decide como Kernel.
        $claimsKernel = (bool) ($surface['claims_kernel_authority']
            ?? $surface['decides_as_kernel']
            ?? false);
        if ($claimsKernel) {
            $violations[] = [
                'field' => 'claims_kernel_authority',
                'reason' => 'desktop_claims_kernel_authority',
            ];
        }

        // R5b — dados exibidos precisam vir de fontes canonicas ou receipts.
        $origin = $surface['data_origin'] ?? $surface['source_kind'] ?? null;
        if (is_string($origin) && trim($origin) !== '') {
            $kind = strtolower(trim($origin));
            $allowed = ['canonical', 'receipt', 'evidence', 'atlas-server'];
            if (! in_array($kind, $allowed, true)) {
                $violations[] = [
                    'field' => 'data_origin',
                    'reason' => 'non_canonical_data_origin',
                ];
            }
        }

        return [
            'violations' => array_values($violations),
            'anti_mock_ok' => $violations === [],
        ];
    }

    /**
     * Full surface-root decision combining R1-R6 into the single
     * `cartography-orphan-count-zero` gate.
     *
     * @param list<string>              $applicationsEntries observed /Applications names
     * @param list<array<string,mixed>> $nodes               desktop cartography nodes
     * @param array<string,mixed>       $surface             desktop surface payload
     *
     * @return array<string,mixed>
     */
    public function audit(array $applicationsEntries, array $nodes, array $surface = []): array
    {
        $install = $this->auditMacInstall($applicationsEntries);
        $cartography = $this->auditDesktopCartography($nodes);
        $antiMock = $this->auditSurfacePayload($surface);

        // R6 — every sub-contract must hold.
        $gatePass = $install['install_ok']
            && $cartography['cartography_ok']
            && $antiMock['anti_mock_ok'];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'gate' => self::GATE,
            'gate_pass' => $gatePass,
            'root_id' => self::ROOT_ID,
            'install' => $install,
            'cartography' => $cartography,
            'anti_mock' => $antiMock,
            'auditable' => true,
        ];
    }

    /**
     * Convenience predicate: does everything pass the gate?
     *
     * @param list<string>              $applicationsEntries
     * @param list<array<string,mixed>> $nodes
     * @param array<string,mixed>       $surface
     */
    public function gatePasses(array $applicationsEntries, array $nodes, array $surface = []): bool
    {
        return $this->audit($applicationsEntries, $nodes, $surface)['gate_pass'] === true;
    }

    /**
     * Is this /Applications entry an Atlas Code bundle (canonical or a polluting
     * variant)? Matches the canonical name and any name that starts with it.
     */
    private function isAtlasCodeBundle(string $name): bool
    {
        if ($name === self::CANONICAL_BUNDLE) {
            return true;
        }

        // Variants the guardrail targets all start with the canonical name and
        // then carry an extra suffix (".backup-2026", "-2026-05-31", "...").
        return str_starts_with($name, self::CANONICAL_BUNDLE) && $name !== self::CANONICAL_BUNDLE;
    }

    /**
     * Classify the specific forbidden bundle shape for an Atlas Code bundle
     * whose name is not exactly the canonical name (R2).
     */
    private function classifyForbiddenBundle(string $name): string
    {
        $suffix = substr($name, strlen(self::CANONICAL_BUNDLE));

        // "Atlas Code.app.backup-*"
        if (str_starts_with($suffix, '.backup-') || str_starts_with($suffix, '.backup')) {
            return 'timestamped_backup_bundle';
        }

        // "Atlas Code.app-YYYY*" (suffix starts with a hyphen then a digit)
        if (preg_match('/^-\d/', $suffix) === 1) {
            return 'timestamped_suffixed_bundle';
        }

        // "Atlas Code.app..." / "Atlas Code.app.<anything>"
        if (str_starts_with($suffix, '.')) {
            return 'dotted_suffixed_bundle';
        }

        // Any other extra-suffix bundle name.
        return 'non_canonical_bundle_name';
    }

    /**
     * @param list<array<string,mixed>> $nodes
     * @return list<array{id:string,parent:?string,active:bool}>
     */
    private function normalizeNodes(array $nodes): array
    {
        $clean = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $id = $this->stringOrNull($node['id'] ?? $node['graph_id'] ?? null);
            if ($id === null) {
                continue;
            }

            $parent = $this->stringOrNull($node['parent'] ?? $node['graph_parent'] ?? null);

            $status = $node['status'] ?? $node['graph_status'] ?? 'active';
            $active = is_string($status)
                ? strtolower(trim($status)) === 'active'
                : true;

            $clean[] = [
                'id' => $id,
                'parent' => $parent,
                'active' => $active,
            ];
        }

        return array_values($clean);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
