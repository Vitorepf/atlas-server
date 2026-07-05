<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use Illuminate\Support\Facades\File;

/**
 * Helpers de certificacao doc-vs-realidade compartilhados (check/read/relative/status/
 * summary) — viviam clonados byte a byte entre os certifiers de ContextIntelligence e
 * ConversationOps (hotspot de 123 linhas no jscpd 05/07).
 */
trait CertificationScaffoldHelpers
{
    private function check(string $id, bool $ok, string $summary, array $evidence, string $remediation): array
    {
        return [
            'id' => $id,
            'status' => $ok ? 'pass' : 'fail',
            'severity' => 'critical',
            'summary' => $summary,
            'evidence' => $evidence,
            'remediation' => $ok ? null : $remediation,
        ];
    }

    private function read(string $path): ?string
    {
        if (! File::exists($path)) {
            return null;
        }

        $contents = File::get($path);

        return is_string($contents) ? $contents : null;
    }

    private function relative(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }

    private function status(array $checks): string
    {
        return collect($checks)->contains(fn (array $check): bool => ($check['status'] ?? null) === 'fail')
            ? self::STATUS_BLOCKED
            : self::STATUS_PASSED;
    }

    private function summary(array $checks): array
    {
        return [
            'total' => count($checks),
            'pass' => count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) === 'pass')),
            'fail' => count(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) === 'fail')),
        ];
    }

    /**
     * OBRA #5 S2 — montador comum do payload de state-certification: o prefixo
     * schema/status/generated_at/summary/checks/blockers e IDENTICO nos auditores
     * de estado; as chaves especificas da area entram em $extra NA ORDEM original
     * (a ordem importa: o certification_hash de cada area cobre o payload inteiro).
     *
     * @param  list<array<string, mixed>>  $checks
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function stateCertificationPayload(string $schemaVersion, array $checks, array $extra = []): array
    {
        return array_merge([
            'schema_version' => $schemaVersion,
            'status' => $this->status($checks),
            'generated_at' => \Carbon\CarbonImmutable::now()->toJSON(),
            'summary' => $this->summary($checks),
            'checks' => $checks,
            'blockers' => array_values(array_filter($checks, static fn (array $check): bool => ($check['status'] ?? null) === 'fail')),
        ], $extra);
    }
}
