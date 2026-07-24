<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Vox\Gate\VoxV5CertificationService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Atlas Vox V5 · certify command.
 *
 * Pergunta única: a camada Symbiotic Interlocutor está pronta para uso real?
 *
 * Responde com o envelope canônico `atlas.vox.v5_certification.v1` produzido
 * pelo {@see VoxV5CertificationService}. Read-only: não grava no ledger, não
 * promove V6/V7, não invoca CLI, não chama API paga.
 *
 * Modos:
 *   - default        → texto humano em PT-BR, exit 0 em pass/warn, 1 em fail.
 *   - --json         → envelope JSON canônico em stdout. Exit codes idem.
 *   - --strict       → trata warn como falha (exit 1).
 *
 * Uso:
 *   php artisan atlas:vox:v5-certify
 *   php artisan atlas:vox:v5-certify --json
 *   php artisan atlas:vox:v5-certify --json --strict
 */
final class AtlasVoxV5CertifyCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:vox:v5-certify
        {--json : Print machine-readable JSON envelope}
        {--strict : Treat warn as non-zero exit code (for CI gates)}';

    protected $description = 'Certify the Atlas Vox V5 Symbiotic Interlocutor for real-world dogfood. Read-only.';

    public function handle(VoxV5CertificationService $service): int
    {
        try {
            $envelope = $service->build();
        } catch (Throwable $e) {
            $this->error('atlas:vox:v5-certify exceção fatal: '.$e->getMessage());

            return 2;
        }

        $status = (string) ($envelope['status'] ?? VoxV5CertificationService::STATUS_FAIL);

        if ($this->option('json')) {
            $this->jsonLine($envelope);
        } else {
            $this->renderHuman($envelope);
        }

        if ($status === VoxV5CertificationService::STATUS_FAIL) {
            return 1;
        }
        if ($status === VoxV5CertificationService::STATUS_WARN && $this->option('strict')) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array{
     *   schema:string, version:string, status:string,
     *   checks: list<array<string,mixed>>,
     *   summary: array<string,mixed>,
     *   generated_at:string
     * }  $envelope
     */
    private function renderHuman(array $envelope): void
    {
        $statusLabel = match ($envelope['status']) {
            VoxV5CertificationService::STATUS_PASS => '<info>PASS</info>',
            VoxV5CertificationService::STATUS_WARN => '<comment>WARN</comment>',
            default => '<error>FAIL</error>',
        };
        $this->line('Atlas Vox V5 · Symbiotic Interlocutor · certificação');
        $this->line('  schema      '.$envelope['schema']);
        $this->line('  versão      '.$envelope['version']);
        $this->line('  status      '.$statusLabel);
        $this->line('  gerado em   '.$envelope['generated_at']);
        $this->line('');
        $this->line('Veredito: '.($envelope['summary']['verdict_pt_br'] ?? ''));
        $this->line('');

        foreach ($envelope['checks'] as $check) {
            $marker = match ((string) ($check['status'] ?? '')) {
                VoxV5CertificationService::STATUS_PASS => '<info>✓</info>',
                VoxV5CertificationService::STATUS_WARN => '<comment>!</comment>',
                default => '<error>✗</error>',
            };
            $name = (string) ($check['check'] ?? '?');
            $msg = (string) ($check['message'] ?? '');
            $this->line(sprintf('  %s %-44s %s', $marker, $name, $msg));
        }

        $totals = $envelope['summary']['totals'] ?? [];
        if (is_array($totals) && $totals !== []) {
            $this->line('');
            $this->line(sprintf(
                '  totais: pass=%d  warn=%d  fail=%d',
                (int) ($totals['pass'] ?? 0),
                (int) ($totals['warn'] ?? 0),
                (int) ($totals['fail'] ?? 0),
            ));
        }
    }
}
