<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime Executor kernel gear.
 *
 * Pure, deterministic implementation of the system-graph `runtime-executor`
 * step. The doc's one-line law is "Runtime executa; Kernel decide": the
 * executor applies an already-signed Decision Receipt using drivers, harnesses
 * and governed tools, and it may NEVER decide provider, policy or scope, and
 * NEVER amplify the scope the receipt granted. This service is the gating
 * boundary that answers, for one execution request against one receipt verdict:
 * is this a faithful in-scope execution (`execute`) or a violation that must be
 * refused (`refuse`)?
 *
 * Contract (from the doc "Contratos" / "Fluxo" / "Regras para IA" / "Riscos"):
 *   Entrada: "receipt assinado e contexto autorizado".
 *   Saida:   "execucao, artefatos, logs, status e referencia de evidencia".
 *   Fluxo:   "Receipt autoriza. Runtime executa dentro do escopo. Quality Gates
 *             verificam. Evidence Ledger registra."
 *   Regra para IA: "IA nao pode usar runtime como atalho para burlar politica.
 *             Se precisa tocar arquivo fora do escopo, deve pedir novo receipt."
 *   forbidden_changes: "Fazer runtime decidir provider, politica ou escopo" /
 *             "Executar fora do allowed scope do receipt".
 *   Riscos enforcados:
 *     - "Runtime virar segundo Kernel"        -> refuse:runtime_decided_kernel_field
 *                                                (the request carries a provider /
 *                                                 policy / scope decision the runtime
 *                                                 is trying to make itself).
 *     - "Comando manual escapar do receipt"   -> refuse:command_escapes_receipt
 *                                                (a command outside the receipt's
 *                                                 allowed_commands, or commands when
 *                                                 the receipt grants none).
 *     - file outside allowed scope            -> refuse:scope_amplification_requires_new_receipt
 *                                                (NOT a silent write — needs a NEW receipt).
 *     - "Terminal parecer real mas nao registrar evidence"
 *                                             -> refuse:evidence_reference_required
 *                                                (an execution that emits no evidence
 *                                                 reference is governance theater).
 *
 * The receipt-authorization verdict (no receipt / incomplete / expired / out of
 * scope) is owned upstream by the `decision-receipt` gear
 * (AtlasDecisionReceiptGuardService). This gear assumes a verdict exists and
 * enforces the runtime-side laws: it confirms the receipt actually authorized
 * the run, then refuses any execution that would step outside that authorization
 * or fail to register evidence.
 *
 * Scope semantics mirror the canonical sibling matcher
 * (AtlasDecisionReceiptGuardService): `.gitignore`-style globs, `**` crosses
 * `/`, a trailing `/` means "directory and everything under it", `!` negates,
 * forbidden wins over allowed, and an empty allowed list permits anything not
 * explicitly forbidden.
 *
 * The service NEVER applies a diff, calls a provider, mutates a codebase, runs a
 * command or touches the database. It returns the execution verdict plus the
 * downstream hand-off (quality-gates -> evidence-ledger) and an audit receipt;
 * callers either execute inside the contract or refuse.
 *
 * @see docs/engineering-knowledge-base/system-graph/runtime-executor.md
 */
final class AtlasRuntimeExecutorService
{
    /** Stable schema id for the execution verdict this gear emits. */
    public const RECEIPT_SCHEMA = 'atlas.kernel.runtime_executor.v1';

    /** Canonical verdicts (closed set). */
    public const VERDICT_EXECUTE = 'execute';
    public const VERDICT_REFUSE = 'refuse';

    /**
     * Decision fields that belong to the Kernel, never to the runtime. If an
     * execution request tries to set/override any of these, the runtime would be
     * "virando segundo Kernel" — the doc's first listed risk — and must refuse.
     *
     * @var list<string>
     */
    private const KERNEL_OWNED_FIELDS = [
        'provider',
        'model',
        'fallback',
        'policy',
        'autonomy',
        'allowed_scope',
        'forbidden_scope',
        'allowed_commands',
        'budget',
    ];

    /**
     * Decide whether one execution request may run against one already-authorized
     * Decision Receipt, enforcing every runtime-executor law in priority order.
     *
     * @param array<string,mixed>|null $receipt The signed receipt that authorizes
     *        the work. null / [] means "no authorized contract" — the strongest
     *        refusal (the doc: "Receipt autoriza"; without it nothing runs).
     *        Recognized keys:
     *          active            : bool   (default true) receipt still authorizes.
     *          allowed_scope     : list<string> glob patterns the runtime may touch.
     *          forbidden_scope   : list<string> glob patterns the runtime may never touch.
     *          allowed_commands  : list<string> exact commands the receipt sanctions.
     *        (Receipt completeness / expiry / signature are gated upstream by the
     *         decision-receipt gear; here `active` is the single authorization flag.)
     * @param array<string,mixed> $request The work the runtime is asked to perform.
     *        Recognized keys:
     *          files            : list<string> posix-relative paths to write/patch.
     *          commands         : list<string> commands to run.
     *          decided_fields   : array<string,mixed> kernel-owned fields the request
     *                             is (illegitimately) trying to set itself.
     *          evidence_ref     : string  reference under which the run will be
     *                             recorded in the Evidence Ledger. Absent/empty =>
     *                             governance theater, refused.
     *
     * @return array<string,mixed> the verdict + downstream hand-off + audit receipt
     */
    public function decide(?array $receipt, array $request): array
    {
        $files = $this->stringList($request['files'] ?? []);
        $commands = $this->stringList($request['commands'] ?? []);
        $evidenceRef = $this->stringOrNull($request['evidence_ref'] ?? null);

        // Rule 0 — no authorized receipt: "Receipt autoriza." Nothing runs without it.
        if ($receipt === null || $receipt === [] || ($receipt['active'] ?? true) === false) {
            $reason = ($receipt === null || $receipt === [])
                ? 'no_receipt'
                : 'receipt_not_active';

            return $this->refusal($reason, [
                'files' => $files,
                'commands' => $commands,
            ]);
        }

        // Rule 1 — runtime must not decide Kernel-owned fields: "Runtime executa;
        // Kernel decide." A request carrying a provider/policy/scope decision is
        // the runtime "virando segundo Kernel" and is refused before any work.
        $kernelDecisions = $this->kernelOwnedDecisions($request['decided_fields'] ?? []);
        if ($kernelDecisions !== []) {
            return $this->refusal('runtime_decided_kernel_field', [
                'kernel_owned_fields' => $kernelDecisions,
            ]);
        }

        // Rule 2 — scope cannot be amplified: any file outside allowed scope (or
        // inside forbidden scope) means "deve pedir novo receipt" — it is NOT a
        // silent write. Forbidden wins over allowed (defense in depth).
        $allowed = $this->patternList($receipt['allowed_scope'] ?? []);
        $forbidden = $this->patternList($receipt['forbidden_scope'] ?? []);
        $outOfScope = [];
        foreach ($files as $file) {
            if ($this->isOutOfScope($file, $allowed, $forbidden)) {
                $outOfScope[] = $file;
            }
        }
        if ($outOfScope !== []) {
            return $this->refusal('scope_amplification_requires_new_receipt', [
                'out_of_scope_files' => $outOfScope,
                'allowed_scope' => $allowed,
                'forbidden_scope' => $forbidden,
            ]);
        }

        // Rule 3 — manual commands must not escape the receipt: a command the
        // receipt did not sanction is "comando manual escapar do receipt".
        $allowedCommands = $this->stringList($receipt['allowed_commands'] ?? []);
        $escapingCommands = array_values(array_filter(
            $commands,
            fn (string $cmd): bool => ! in_array($cmd, $allowedCommands, true),
        ));
        if ($escapingCommands !== []) {
            return $this->refusal('command_escapes_receipt', [
                'escaping_commands' => $escapingCommands,
                'allowed_commands' => $allowedCommands,
            ]);
        }

        // Rule 4 — a real execution must register evidence: a terminal that "parece
        // real mas nao registra evidence" cannot be allowed to claim a run.
        if (($files !== [] || $commands !== []) && $evidenceRef === null) {
            return $this->refusal('evidence_reference_required', [
                'files' => $files,
                'commands' => $commands,
            ]);
        }

        // All runtime laws satisfied: execution is authorized strictly inside the
        // contract, and hands off down the documented pipeline.
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => self::VERDICT_EXECUTE,
            'allowed' => true,
            'reason' => $files === [] && $commands === [] ? 'noop_within_contract' : 'execute_within_contract',
            'detail' => [
                'files' => $files,
                'commands' => $commands,
                'allowed_scope' => $allowed,
                'forbidden_scope' => $forbidden,
                'evidence_ref' => $evidenceRef,
            ],
            // The doc's "Fluxo": Runtime executes, then Quality Gates verify, then
            // Evidence Ledger records. The executor never closes the loop itself.
            'next' => [
                'quality_gates' => 'pending',
                'evidence_ledger' => $evidenceRef ?? null,
            ],
        ];
    }

    /**
     * Convenience predicate: may this request run against this receipt?
     * false => the runtime must refuse and (for scope) request a new receipt.
     *
     * @param array<string,mixed>|null $receipt
     * @param array<string,mixed> $request
     */
    public function mayExecute(?array $receipt, array $request): bool
    {
        return $this->decide($receipt, $request)['verdict'] === self::VERDICT_EXECUTE;
    }

    /**
     * Which Kernel-owned decision fields is the request illegitimately trying to
     * set? A non-empty result means the runtime is becoming a second Kernel.
     *
     * @param mixed $decidedFields
     * @return list<string>
     */
    private function kernelOwnedDecisions(mixed $decidedFields): array
    {
        if (! is_array($decidedFields)) {
            return [];
        }

        $hit = [];
        foreach (self::KERNEL_OWNED_FIELDS as $field) {
            if (! array_key_exists($field, $decidedFields)) {
                continue;
            }
            if (! $this->isEmptyValue($decidedFields[$field])) {
                $hit[] = $field;
            }
        }

        return $hit;
    }

    /**
     * A path is out of scope when it matches the forbidden scope, or when an
     * allowed scope is declared and the path does not match it.
     *
     * @param list<string> $allowed
     * @param list<string> $forbidden
     */
    private function isOutOfScope(string $rawPath, array $allowed, array $forbidden): bool
    {
        $path = $this->normalizePath($rawPath);
        if ($path === '') {
            return true;
        }
        if ($this->matchedBy($path, $forbidden)) {
            return true; // forbidden wins.
        }
        if ($allowed !== [] && ! $this->matchedBy($path, $allowed)) {
            return true; // declared allow-list that does not cover the path.
        }

        return false;
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    /**
     * @param list<string> $patterns
     */
    private function matchedBy(string $normalizedPath, array $patterns): bool
    {
        if ($patterns === []) {
            return false;
        }
        $matched = false;
        foreach ($patterns as $raw) {
            $pattern = trim($raw);
            if ($pattern === '') {
                continue;
            }
            $negated = false;
            if (str_starts_with($pattern, '!')) {
                $negated = true;
                $pattern = ltrim(substr($pattern, 1));
            }
            if ($this->matchSinglePattern($normalizedPath, $this->normalizePattern($pattern))) {
                $matched = ! $negated;
            }
        }

        return $matched;
    }

    private function matchSinglePattern(string $path, string $pattern): bool
    {
        return preg_match($this->patternToRegex($pattern), $path) === 1;
    }

    private function patternToRegex(string $pattern): string
    {
        $out = '';
        $i = 0;
        $len = strlen($pattern);
        while ($i < $len) {
            $c = $pattern[$i];
            if ($c === '*' && $i + 1 < $len && $pattern[$i + 1] === '*') {
                $out .= '.*';
                $i += 2;
                if ($i < $len && $pattern[$i] === '/') {
                    $out .= '/?';
                    $i++;
                }

                continue;
            }
            if ($c === '*') {
                $out .= '[^/]*';
                $i++;

                continue;
            }
            if ($c === '?') {
                $out .= '[^/]';
                $i++;

                continue;
            }
            if ($c === '[') {
                $j = $i + 1;
                while ($j < $len && $pattern[$j] !== ']') {
                    $j++;
                }
                if ($j < $len) {
                    $out .= '['.substr($pattern, $i + 1, $j - $i - 1).']';
                    $i = $j + 1;

                    continue;
                }
            }
            $out .= preg_quote($c, '#');
            $i++;
        }

        return '#^'.$out.'$#u';
    }

    private function normalizePath(string $raw): string
    {
        $clean = trim($raw);
        if ($clean === '') {
            return '';
        }
        $clean = preg_replace('#^\./+#', '', $clean) ?? $clean;
        $clean = ltrim($clean, '/');
        $clean = str_replace('\\', '/', $clean);
        $clean = preg_replace('#/+#', '/', $clean) ?? $clean;

        return $clean;
    }

    private function normalizePattern(string $raw): string
    {
        $pattern = trim($raw);
        $pattern = preg_replace('#^\./+#', '', $pattern) ?? $pattern;
        $pattern = ltrim($pattern, '/');
        $pattern = str_replace('\\', '/', $pattern);
        if (str_ends_with($pattern, '/')) {
            $pattern .= '**';
        }

        return $pattern;
    }

    /**
     * @return list<string>
     */
    private function patternList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $values),
            static fn (string $v): bool => $v !== '',
        )));
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $v): string => is_string($v) ? trim($v) : '', $values),
            static fn (string $v): bool => $v !== '',
        ));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param array<string,mixed> $detail
     *
     * @return array<string,mixed>
     */
    private function refusal(string $reason, array $detail): array
    {
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => self::VERDICT_REFUSE,
            'allowed' => false,
            'reason' => $reason,
            'detail' => $detail,
            // A refused execution never advances the pipeline.
            'next' => [
                'quality_gates' => 'not_reached',
                'evidence_ledger' => null,
            ],
        ];
    }
}
