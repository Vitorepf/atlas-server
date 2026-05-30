<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Rsi\EarnedAutonomy;

use App\Services\Ai\Foundry\Rsi\ImmutableInvariantRegistryService;
use App\Services\Ai\Mission\MissionCanonicalHash;

/**
 * Earned Autonomy · Risk Classifier (PURE, no side effects).
 *
 * Classifies a proposed self-improvement change into an ORDERED risk class from
 * the frozen ladder (ascending rank; lower rank = safer):
 *
 *   rank 0  cosmetic            — comments / docstrings / whitespace, non-behavioural.
 *   rank 1  non_sacred_logic    — behaviour change in a NON-sacred component.
 *   rank 2  ledger_or_schema    — touches an append-only ledger writer / schema shape.
 *   rank 3  gate_or_invariant_touch — touches ANY sacred path OR matches a
 *           weakening / eligibility / auto-canonize signature. THE CEILING:
 *           rank 3 can NEVER auto_apply at any tier (special-cased in the composer).
 *
 * The classification RULES are sacred and CONSERVATIVE-BY-DEFAULT: an ambiguous
 * or unreadable change is assigned the HIGHEST plausible class (never
 * under-classified). gate_or_invariant_touch is assigned whenever the change
 * touches any file owned by the immutable invariant registry / guard (via
 * ImmutableInvariantRegistryService::isSacredPath) OR any *Gate* / *Governor* /
 * *Policy* class file OR matches a weakening / eligibility / auto-canonize
 * signature in any added or removed line.
 *
 * This service is PURE: deterministic, read-only, never calls a provider, never
 * writes state, never applies anything. It only reads the registry's sacred-path
 * set (itself read-only). rankOf() fails CLOSED: an unknown class maps to the
 * highest rank (3).
 */
final class RiskClassifierService
{
    public const SCHEMA = 'atlas.foundry.rsi.earned_autonomy.risk_class.v1';

    public const RISK_CLASS_COSMETIC = 'cosmetic';

    public const RISK_CLASS_NON_SACRED_LOGIC = 'non_sacred_logic';

    public const RISK_CLASS_LEDGER_OR_SCHEMA = 'ledger_or_schema';

    public const RISK_CLASS_GATE_OR_INVARIANT_TOUCH = 'gate_or_invariant_touch';

    /**
     * Frozen ascending rank map (lower rank = safer). Unknown classes are NOT in
     * this map and rankOf() fails closed to the highest rank (3).
     *
     * @var array<string,int>
     */
    private const RANKS = [
        self::RISK_CLASS_COSMETIC => 0,
        self::RISK_CLASS_NON_SACRED_LOGIC => 1,
        self::RISK_CLASS_LEDGER_OR_SCHEMA => 2,
        self::RISK_CLASS_GATE_OR_INVARIANT_TOUCH => 3,
    ];

    /**
     * Sacred ceiling (rank 3) trigger signatures — a weakening / eligibility /
     * auto-canonize intent in ANY added or removed line forces
     * gate_or_invariant_touch regardless of which file it lands in. Mirrors the
     * guard's AUTO_CANONIZE / ELIGIBILITY_OVERRIDE families so the classifier and
     * the live guard agree on what "touches an invariant" means. Matched
     * case-insensitively.
     *
     * @var list<string>
     */
    private const SACRED_SIGNATURES = [
        'auto_apply',
        'auto_canonize',
        'auto_canonical',
        'autocanonize',
        'auto_merge',
        'auto_promote',
        'skip_human_gate',
        'bypass_operator',
        'force_eligible',
        'force_promote',
        'override_invariant',
        'disable_invariant_guard',
        'bypass_invariant',
        'rsi_unsafe_mode',
        'allow_sacred_edit',
        'sacred_gates',
        'issacredpath',
        'weakening_signature',
    ];

    /**
     * Path-name fragments that mark a sacred GATE-class file even when the
     * registry does not yet enumerate that exact path. A *Gate* / *Governor* /
     * *Policy* class is structural authority — touching it is rank 3. Matched
     * case-insensitively against the basename (so unrelated dirs do not
     * false-trigger; e.g. only the file name carries the marker).
     *
     * @var list<string>
     */
    private const SACRED_NAME_FRAGMENTS = [
        'gate',
        'governor',
        'policy',
    ];

    /**
     * Ledger / schema writer fragments. A path whose name contains 'Ledger', or
     * an added line that declares a schema / appends to a ledger, is rank 2.
     * Matched case-insensitively.
     *
     * @var list<string>
     */
    private const LEDGER_SCHEMA_SIGNATURES = [
        'schema =',
        '_schema.v',
        '.schema.v',
        'file::append',
    ];

    private ?ImmutableInvariantRegistryService $registry;

    public function __construct(?ImmutableInvariantRegistryService $registry = null)
    {
        $this->registry = $registry ?? app(ImmutableInvariantRegistryService::class);
    }

    /**
     * Classify a proposal diff descriptor into an ordered risk class.
     *
     * Input $diff: the SAME descriptor shape RsiInvariantGuardService::screen()
     * consumes:
     *   - changed_paths: list<string> (repo-relative or absolute file paths)
     *   - removed_lines: optional map<path, list<string>> (lines the diff deletes)
     *   - added_lines:   optional list<string> (lines the diff adds, whole-diff)
     *
     * Deterministic, no provider. Evaluated highest-class-first; the FIRST
     * matching rule wins (conservative — never under-classify). An empty /
     * unreadable changed_paths fails CLOSED to gate_or_invariant_touch.
     *
     * @param  array<string,mixed>  $diff
     * @return array<string,mixed>
     */
    public function classify(array $diff): array
    {
        $changedPaths = $this->normalizePathList($diff['changed_paths'] ?? null);
        $addedLines = $this->normalizeLineList($diff['added_lines'] ?? null);
        $removedLines = $this->flattenRemovedLines($diff['removed_lines'] ?? null);

        $reasons = [];

        // Fail CLOSED: an empty / unreadable change set is never "safe to classify
        // low". Ambiguous => highest plausible class.
        if ($changedPaths === []) {
            $reasons[] = $this->reason(
                'diff_malformed',
                'diff carries no readable changed_paths; fail-closed to gate_or_invariant_touch',
            );

            return $this->emit(self::RISK_CLASS_GATE_OR_INVARIANT_TOUCH, true, $reasons);
        }

        $allLines = strtolower(implode("\n", array_merge($addedLines, $removedLines)));

        // (rank 3) Any sacred path touched OR any weakening / eligibility /
        // auto-canonize signature present => gate_or_invariant_touch (the ceiling).
        $sacredTouch = false;
        foreach ($changedPaths as $path) {
            if ($this->registry->isSacredPath($path)) {
                $sacredTouch = true;
                $reasons[] = $this->reason(
                    'sacred_path_touched',
                    "changed path '{$path}' is owned by the immutable invariant registry",
                );

                continue;
            }
            if ($this->isSacredNameFragment($path)) {
                $sacredTouch = true;
                $reasons[] = $this->reason(
                    'gate_class_file_touched',
                    "changed path '{$path}' is a *Gate*/*Governor*/*Policy* authority file",
                );
            }
        }

        foreach (self::SACRED_SIGNATURES as $sig) {
            if (str_contains($allLines, $sig)) {
                $sacredTouch = true;
                $reasons[] = $this->reason(
                    'weakening_or_eligibility_signature',
                    "diff line matches sacred signature '{$sig}'",
                );
            }
        }

        if ($sacredTouch) {
            return $this->emit(self::RISK_CLASS_GATE_OR_INVARIANT_TOUCH, true, $reasons);
        }

        // (rank 2) Ledger / schema writer: a path named *Ledger* or an added line
        // declaring a schema / appending to a ledger.
        $ledgerOrSchema = false;
        foreach ($changedPaths as $path) {
            if (str_contains(strtolower($this->basename($path)), 'ledger')) {
                $ledgerOrSchema = true;
                $reasons[] = $this->reason(
                    'ledger_writer_path',
                    "changed path '{$path}' is an append-only ledger writer",
                );
            }
        }
        $addedJoined = strtolower(implode("\n", $addedLines));
        foreach (self::LEDGER_SCHEMA_SIGNATURES as $sig) {
            if (str_contains($addedJoined, $sig)) {
                $ledgerOrSchema = true;
                $reasons[] = $this->reason(
                    'ledger_or_schema_signature',
                    "added line matches ledger/schema signature '{$sig}'",
                );
            }
        }

        if ($ledgerOrSchema) {
            return $this->emit(self::RISK_CLASS_LEDGER_OR_SCHEMA, false, $reasons);
        }

        // (rank 1) Non-sacred logic: a .php product file with behavioural added
        // lines (an added line that is NOT a comment / docblock / whitespace).
        $touchesPhp = false;
        foreach ($changedPaths as $path) {
            if (str_ends_with(strtolower($path), '.php')) {
                $touchesPhp = true;
                break;
            }
        }
        if ($touchesPhp && $this->hasBehaviouralAddedLine($addedLines)) {
            $reasons[] = $this->reason(
                'behavioural_php_change',
                'diff adds behavioural (non-comment) lines to a .php product file',
            );

            return $this->emit(self::RISK_CLASS_NON_SACRED_LOGIC, false, $reasons);
        }

        // (rank 0) Cosmetic: only comments / whitespace / docblocks added.
        $reasons[] = $this->reason(
            'cosmetic_only',
            'diff adds only comments / whitespace / docblocks; non-behavioural',
        );

        return $this->emit(self::RISK_CLASS_COSMETIC, false, $reasons);
    }

    /**
     * Rank of a risk class. Fails CLOSED: an unknown / unrecognised class maps to
     * the highest rank (3, gate_or_invariant_touch).
     */
    public function rankOf(string $riskClass): int
    {
        return self::RANKS[$riskClass] ?? 3;
    }

    /**
     * Test seam: swap the registry so sacred-path checks run against a sandbox
     * fixture set. Mirrors the setRegistryForTesting / setBaseDirForTesting
     * convention.
     */
    public function setRegistryForTesting(?ImmutableInvariantRegistryService $registry): void
    {
        $this->registry = $registry ?? app(ImmutableInvariantRegistryService::class);
    }

    /**
     * @param  list<array<string,string>>  $reasons
     * @return array<string,mixed>
     */
    private function emit(string $riskClass, bool $sacredTouch, array $reasons): array
    {
        $payload = [
            'schema_version' => self::SCHEMA,
            'risk_class' => $riskClass,
            'risk_rank' => $this->rankOf($riskClass),
            'sacred_touch' => $sacredTouch,
            'reasons' => array_values($reasons),
            'provider_invoked' => false,
        ];

        $payload['classification_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,string>
     */
    private function reason(string $code, string $detail): array
    {
        return ['code' => $code, 'detail' => $detail];
    }

    /**
     * A behavioural added line is one that is NOT pure comment / docblock /
     * whitespace. Conservative: anything that is not provably a comment counts as
     * behavioural.
     *
     * @param  list<string>  $addedLines
     */
    private function hasBehaviouralAddedLine(array $addedLines): bool
    {
        foreach ($addedLines as $line) {
            if (! $this->isCommentOrBlank($line)) {
                return true;
            }
        }

        return false;
    }

    private function isCommentOrBlank(string $line): bool
    {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return true;
        }

        return str_starts_with($trimmed, '//')
            || str_starts_with($trimmed, '#')
            || str_starts_with($trimmed, '/*')
            || str_starts_with($trimmed, '*')
            || str_starts_with($trimmed, '*/');
    }

    private function isSacredNameFragment(string $path): bool
    {
        $base = strtolower($this->basename($path));
        foreach (self::SACRED_NAME_FRAGMENTS as $fragment) {
            if (str_contains($base, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function basename(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $parts = explode('/', $normalized);

        return (string) end($parts);
    }

    /**
     * @return list<string>
     */
    private function normalizePathList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $p) {
            if (is_string($p) && trim($p) !== '') {
                $out[] = trim($p);
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    private function normalizeLineList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $line) {
            if (is_string($line)) {
                $out[] = $line;
            }
        }

        return array_values($out);
    }

    /**
     * Flatten the removed_lines map<path, list<string>> into a flat list of
     * removed line strings (paths are irrelevant for signature matching here).
     *
     * @return list<string>
     */
    private function flattenRemovedLines(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $lines) {
            if (! is_array($lines)) {
                continue;
            }
            foreach ($lines as $line) {
                if (is_string($line)) {
                    $out[] = $line;
                }
            }
        }

        return array_values($out);
    }
}
