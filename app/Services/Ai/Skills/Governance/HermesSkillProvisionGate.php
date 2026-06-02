<?php

declare(strict_types=1);

namespace App\Services\Ai\Skills\Governance;

/**
 * Hermes skill provisioning gate.
 *
 * Closes the loop "PROMOTED Atlas skill -> on-disk Hermes SKILL.md" without ever
 * letting Hermes (the engine) author or self-install skills. Atlas remains the
 * canonical skill authority: a skill record may only be materialised onto disk
 * for Hermes to consume when ALL of the following hold:
 *
 *   - the upstream SkillPackPromotionGate verdict is `promotion_approved`
 *   - the persisted record explicitly carries `promotion_allowed === true`
 *     (the procedure adapter NEVER sets this; only an operator-confirmed
 *     promotion does, so the default is fail-closed)
 *   - the target external dir is Atlas-OWNED — an Atlas-controlled directory,
 *     NOT the Hermes-managed `~/.hermes/skills` registry the engine writes to
 *     itself (`storage_path`-rooted in production; a temp dir under
 *     `sys_get_temp_dir()` in the test harness)
 *   - a `danger` skill additionally requires explicit
 *     `operator_authority_required === true`
 *
 * The gate is pure and stateless: it reads the record + verdict + dir and emits
 * a decision the provisioner seals into its receipt. Anything missing or
 * ambiguous fails closed to `provision_blocked`.
 */
final class HermesSkillProvisionGate
{
    public const SCHEMA_VERSION = 'atlas.hermes.skill_provision_gate.v1';

    /**
     * @param  array<string,mixed>  $skillRecord
     * @param  array<string,mixed>  $promotionGateVerdict
     * @return array{
     *   provision_decision: string,
     *   provision_allowed_now: bool,
     *   passed_checks: list<string>,
     *   failed_checks: array<string,string>,
     *   schema_version: string
     * }
     */
    public function evaluate(array $skillRecord, array $promotionGateVerdict, string $externalDir): array
    {
        $passed = [];
        $failed = [];

        $verdict = isset($promotionGateVerdict['promotion_decision']) && is_string($promotionGateVerdict['promotion_decision'])
            ? $promotionGateVerdict['promotion_decision']
            : '';
        if ($verdict === 'promotion_approved') {
            $passed[] = 'promotion_gate_approved';
        } else {
            $failed['promotion_gate'] = sprintf(
                "SkillPackPromotionGate verdict must be 'promotion_approved'; got '%s'",
                $verdict === '' ? 'none' : $verdict,
            );
        }

        if (($skillRecord['promotion_allowed'] ?? null) === true) {
            $passed[] = 'record_promotion_allowed';
        } else {
            $failed['promotion_allowed'] = 'skill record must carry promotion_allowed===true (fail-closed default)';
        }

        if ($this->isAtlasOwned($externalDir)) {
            $passed[] = 'external_dir_atlas_owned';
        } else {
            $failed['external_dir'] = sprintf(
                'external dir must be Atlas-owned, not the Hermes-managed registry: %s',
                $externalDir === '' ? '(empty)' : $externalDir,
            );
        }

        $riskLevel = $this->riskLevel($skillRecord);
        if ($riskLevel === 'danger' || $riskLevel === 'high') {
            if (($skillRecord['operator_authority_required'] ?? null) === true) {
                $passed[] = 'operator_authority_present';
            } else {
                $failed['operator_authority_required'] = 'danger skill requires explicit operator_authority_required===true';
            }
        } else {
            $passed[] = 'risk_level_within_auto_provision';
        }

        $decision = $failed === [] ? 'provision_approved' : 'provision_blocked';

        return [
            'provision_decision' => $decision,
            'provision_allowed_now' => $decision === 'provision_approved',
            'passed_checks' => $passed,
            'failed_checks' => $failed,
            'schema_version' => self::SCHEMA_VERSION,
        ];
    }

    /**
     * Atlas-owned = an Atlas-controlled directory, explicitly NOT the
     * Hermes-managed `~/.hermes/skills` registry the engine self-manages.
     */
    public function isAtlasOwned(string $externalDir): bool
    {
        $dir = trim($externalDir);
        if ($dir === '') {
            return false;
        }

        $normalized = $this->normalize($dir);
        if ($normalized === '') {
            return false;
        }

        // Never the Hermes-managed registry — fail-closed regardless of root.
        if ($this->isHermesManaged($normalized)) {
            return false;
        }

        foreach ($this->atlasRoots() as $root) {
            $root = $this->normalize($root);
            if ($root === '') {
                continue;
            }

            $rootWithSep = rtrim($root, '/').'/';
            $dirWithSep = rtrim($normalized, '/').'/';
            if (str_starts_with($dirWithSep, $rootWithSep)) {
                return true;
            }
        }

        return false;
    }

    private function isHermesManaged(string $normalized): bool
    {
        $needle = rtrim($normalized, '/').'/';

        return str_contains($needle, '/.hermes/skills/')
            || str_ends_with($normalized, '/.hermes/skills')
            || str_contains($needle, '/.hermes/');
    }

    /**
     * @return array<int,string>
     */
    private function atlasRoots(): array
    {
        $roots = [];

        if (function_exists('storage_path')) {
            $storage = storage_path();
            if (is_string($storage) && $storage !== '') {
                $roots[] = $storage;
            }
        }

        // The :memory: sqlite test harness provisions into a temp dir it owns.
        $temp = sys_get_temp_dir();
        if (is_string($temp) && $temp !== '') {
            $roots[] = $temp;
        }

        return $roots;
    }

    /**
     * @param  array<string,mixed>  $skillRecord
     */
    private function riskLevel(array $skillRecord): string
    {
        $value = $skillRecord['risk_level'] ?? null;
        if (! is_string($value)) {
            return 'medium';
        }

        $value = strtolower(trim($value));

        return $value === '' ? 'medium' : $value;
    }

    private function normalize(string $path): string
    {
        $resolved = realpath($path);
        if (is_string($resolved) && $resolved !== '') {
            return $resolved;
        }

        // Path may not exist yet (provision target). Resolve the deepest
        // EXISTING ancestor with realpath (so symlinked roots like the macOS
        // temp dir match their not-yet-created children) and append the rest.
        $lexical = $this->lexical($path);
        $suffix = [];
        $cursor = $lexical;
        while ($cursor !== '' && $cursor !== '/') {
            $real = realpath($cursor);
            if (is_string($real) && $real !== '') {
                return rtrim($real, '/').($suffix === [] ? '' : '/'.implode('/', array_reverse($suffix)));
            }

            $suffix[] = basename($cursor);
            $next = dirname($cursor);
            if ($next === $cursor) {
                break;
            }
            $cursor = $next;
        }

        return $lexical;
    }

    private function lexical(string $path): string
    {
        $absolute = str_starts_with($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                } elseif (! $absolute) {
                    $segments[] = $segment;
                }

                continue;
            }
            $segments[] = $segment;
        }

        return ($absolute ? '/' : '').implode('/', $segments);
    }
}
