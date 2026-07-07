<?php

declare(strict_types=1);

namespace App\Services\Ai\Obra;

use Throwable;

/**
 * WO-17-T1 — per-obra state, the spine of "retomei e ele sabia".
 *
 * Two on-disk artifacts under storage/atlas/obras/ (outside the workers' working
 * tree, like the rest of storage/atlas — remember `rg --no-ignore`):
 *   - `current`      : the ACTIVE obra id, one line. Set by an EXPLICIT command only,
 *                      NEVER inferred (a wrong guess poisons every resumption).
 *   - `<obra-id>.json`: the obra's rolling state — phase, the last sessions (files
 *                       touched + result + HEAD at capture), pendencies, decisions.
 *                       The Stop hook appends each session's footprint here.
 *
 * At resume, AtlasOpenBrainContextPackService reads current()+read() and injects the
 * "você estava no slice N, provou X, falta Y, cuidado com Z" section into the pack.
 *
 * Fail-open by construction: a missing/corrupt file yields null/[] — resumption
 * degrades to "no state", never an error (this runs on the interactive path).
 */
final class AtlasObraStateService
{
    /** Keep the state file bounded — only the most recent sessions matter for resume. */
    private const MAX_SESSIONS = 10;

    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? storage_path('atlas/obras'), '/');
    }

    public function currentId(): ?string
    {
        try {
            $path = $this->dir.'/current';
            if (! is_file($path)) {
                return null;
            }
            $id = trim((string) file_get_contents($path));

            return $id !== '' ? $this->sanitizeId($id) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function setCurrent(string $id): string
    {
        $id = $this->sanitizeId($id);
        $this->ensureDir();
        file_put_contents($this->dir.'/current', $id.PHP_EOL, LOCK_EX);

        return $id;
    }

    public function clearCurrent(): void
    {
        try {
            $path = $this->dir.'/current';
            if (is_file($path)) {
                @unlink($path);
            }
        } catch (Throwable) {
            // fail-open
        }
    }

    public function statePath(string $id): string
    {
        return $this->dir.'/'.$this->sanitizeId($id).'.json';
    }

    /**
     * @return array<string,mixed>|null
     */
    public function read(string $id): ?array
    {
        try {
            $path = $this->statePath($id);
            if (! is_file($path)) {
                return null;
            }
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The ACTIVE obra's state, or null when none is set / it has no state yet.
     *
     * @return array<string,mixed>|null
     */
    public function current(): ?array
    {
        $id = $this->currentId();

        return $id === null ? null : $this->read($id);
    }

    /**
     * Merge one session's footprint into the obra state (called by the Stop hook).
     * Additive + bounded: appends the session, keeps the last MAX_SESSIONS, refreshes
     * updated_at + head. Explicit fields (phase/pendencies/decisions) passed in $extra
     * overwrite; absent ⇒ preserved. Fail-open: returns the (unchanged) state on fault.
     *
     * @param  array<string,mixed>  $session  {session_id, files, result, request}
     * @param  array<string,mixed>  $extra  optional {phase, pendencies, decisions}
     * @return array<string,mixed>
     */
    public function recordSession(string $id, array $session, array $extra = []): array
    {
        $id = $this->sanitizeId($id);
        $state = $this->read($id) ?? [
            'obra_id' => $id,
            'created_at' => $this->now(),
            'phase' => null,
            'sessions' => [],
            'pendencies' => [],
            'decisions' => [],
        ];

        try {
            $entry = [
                'session_id' => (string) ($session['session_id'] ?? ''),
                'at' => $this->now(),
                'head' => $this->gitHead(),
                'files' => array_values(array_slice(array_filter(array_map(
                    static fn ($f): string => trim((string) $f),
                    (array) ($session['files'] ?? []),
                )), 0, 40)),
                'result' => $session['result'] ?? null,
                'request' => mb_substr(trim((string) ($session['request'] ?? '')), 0, 200),
            ];

            $sessions = array_values((array) ($state['sessions'] ?? []));
            $sessions[] = $entry;
            $state['sessions'] = array_slice($sessions, -self::MAX_SESSIONS);
            $state['updated_at'] = $entry['at'];
            $state['head'] = $entry['head'];

            foreach (['phase', 'pendencies', 'decisions'] as $field) {
                if (array_key_exists($field, $extra)) {
                    $state[$field] = $extra[$field];
                }
            }

            $this->ensureDir();
            file_put_contents(
                $this->statePath($id),
                json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                LOCK_EX,
            );
        } catch (Throwable) {
            // fail-open — a state-write fault must never break session capture.
        }

        return $state;
    }

    /** Current repo HEAD (short), or '' when unavailable — the drift anchor. */
    public function gitHead(): string
    {
        try {
            $out = @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD 2>/dev/null');

            return trim((string) $out);
        } catch (Throwable) {
            return '';
        }
    }

    private function sanitizeId(string $id): string
    {
        // ids land in a filename — keep it to a safe, obra-id-shaped charset.
        $id = preg_replace('/[^A-Za-z0-9._-]/', '-', trim($id)) ?? '';

        return $id !== '' ? $id : 'obra';
    }

    private function ensureDir(): void
    {
        if (! is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    private function now(): string
    {
        return now()->toISOString();
    }
}
