<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Vox\Gate\VoxV6CertificationService;
use Illuminate\Console\Command;
use Throwable;
use App\Support\YesNo;

/**
 * Atlas Vox V6 · certify command (FINAL).
 *
 * Pergunta única: o Atlas Vox V3→V6 está pronto para dogfood real?
 *
 * Devolve o envelope canônico `atlas.vox.v6_certification.v1` produzido pelo
 * {@see VoxV6CertificationService}. READ-ONLY: não grava no ledger, não
 * promove V7, não chama API paga, não toca Voice Realtime.
 *
 * V7 (memória longitudinal) permanece **explicitamente** bloqueada por esta
 * certificação: mesmo um envelope `pass` carrega `v7_unlock_allowed=false`.
 * Destravar V7 exige nova ADR + decisão humana fora deste comando.
 *
 * Modos:
 *   - default        → texto humano em PT-BR. Exit 0 em pass/warn, 1 em fail.
 *   - --json         → envelope JSON canônico em stdout. Exit codes idem.
 *   - --strict       → trata warn como falha (exit 1).
 *
 * Uso:
 *   php artisan atlas:vox:v6-certify
 *   php artisan atlas:vox:v6-certify --json
 *   php artisan atlas:vox:v6-certify --json --strict
 */
final class AtlasVoxV6CertifyCommand extends Command
{
    protected $signature = 'atlas:vox:v6-certify
        {--json : Print machine-readable JSON envelope}
        {--strict : Treat warn as non-zero exit code (for CI gates)}';

    protected $description = 'Certify Atlas Vox V6 for dogfood. Read-only. Cobre backend, desktop, macOS, UX e safety.';

    public function handle(VoxV6CertificationService $service): int
    {
        try {
            $envelope = $service->build();
        } catch (Throwable $e) {
            $this->error('atlas:vox:v6-certify exceção fatal: '.$e->getMessage());

            return 2;
        }

        $status = (string) ($envelope['status'] ?? VoxV6CertificationService::STATUS_FAIL);

        if ($this->option('json')) {
            $this->line(json_encode(
                $envelope,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ) ?: '{}');
        } else {
            $this->renderHuman($envelope);
        }

        if ($status === VoxV6CertificationService::STATUS_FAIL) {
            return 1;
        }
        if ($status === VoxV6CertificationService::STATUS_WARN && $this->option('strict')) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function renderHuman(array $envelope): void
    {
        $status = (string) ($envelope['status'] ?? VoxV6CertificationService::STATUS_FAIL);
        $statusLabel = match ($status) {
            VoxV6CertificationService::STATUS_PASS => '<info>PASS</info>',
            VoxV6CertificationService::STATUS_WARN => '<comment>WARN</comment>',
            default => '<error>FAIL</error>',
        };
        // V6-OBSERVABILITY-FINAL · resposta direta no topo. Sem "dogfood ready"
        // (jargão). A pergunta humana é "Atlas Vox V6 está pronto?" e o
        // veredito do service responde isso em PT-BR.
        $directAnswer = match ($status) {
            VoxV6CertificationService::STATUS_PASS => '<info>Sim. Atlas Vox V6 está pronto.</info>',
            VoxV6CertificationService::STATUS_WARN => '<comment>Pronto com ressalvas — revise os itens marcados antes de uso pesado.</comment>',
            default => '<error>Ainda não — algo crítico bloqueou. Resolva os itens marcados.</error>',
        };
        $this->line('Atlas Vox V6 está pronto?');
        $this->line('');
        $this->line('  '.$directAnswer);
        $this->line('');
        $this->line('Veredito: '.($envelope['summary']['verdict_pt_br'] ?? ''));
        $this->line('');
        $this->line('  status      '.$statusLabel);
        $this->line('  pode usar   '.(($envelope['v6_ready_for_dogfood'] ?? false) ? '<info>sim</info>' : '<error>ainda não</error>'));
        // V7 (memória entre dias) é bloqueada POR DESIGN — não é erro.
        $this->line(sprintf(
            '  memória entre dias (V7)   %s',
            ($envelope['v7_unlock_allowed'] ?? false)
                ? '<error>destravada (inesperado — verificar canon)</error>'
                : '<info>bloqueada por design</info>',
        ));
        $this->line('  gerado em   '.$envelope['generated_at']);
        $this->line('');

        foreach ($envelope['checks'] as $check) {
            $marker = match ((string) ($check['status'] ?? '')) {
                VoxV6CertificationService::STATUS_PASS => '<info>✓</info>',
                VoxV6CertificationService::STATUS_WARN => '<comment>!</comment>',
                default => '<error>✗</error>',
            };
            $name = (string) ($check['check'] ?? '?');
            $msg = (string) ($check['message'] ?? '');
            $this->line(sprintf('  %s %-50s %s', $marker, $name, $msg));
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

        $byArea = $envelope['summary']['by_area'] ?? [];
        if (is_array($byArea) && $byArea !== []) {
            $this->line('');
            $this->line('  por área:');
            foreach ($byArea as $area => $counts) {
                if (! is_array($counts)) {
                    continue;
                }
                $this->line(sprintf(
                    '    %-8s  pass=%d  warn=%d  fail=%d',
                    $area,
                    (int) ($counts['pass'] ?? 0),
                    (int) ($counts['warn'] ?? 0),
                    (int) ($counts['fail'] ?? 0),
                ));
            }
        }

        $nextActions = $envelope['next_actions'] ?? [];
        if (is_array($nextActions) && $nextActions !== []) {
            $this->line('');
            $this->line('Próximas ações:');
            foreach ($nextActions as $action) {
                $this->line('  · '.$action);
            }
        }

        // V6-H · resumo humano + bloqueios V7 quando disponíveis.
        $summaryHuman = $envelope['dogfood_summary'] ?? null;
        if (is_array($summaryHuman) && ! empty($summaryHuman['sentences_pt_br'])) {
            $this->line('');
            $this->line('Resumo de uso real:');
            foreach ((array) $summaryHuman['sentences_pt_br'] as $line) {
                $this->line('  · '.$line);
            }
            $this->line(sprintf(
                '  · pronto para uso diário? %s',
                ($summaryHuman['ready_for_daily_use'] ?? false)
                    ? '<info>sim</info>'
                    : '<comment>ainda não</comment>',
            ));
        }

        $v7 = $envelope['v7_unlock_status'] ?? null;
        if (is_array($v7)) {
            $this->line('');
            $this->line('V7 (memória longitudinal):');
            $this->line(sprintf(
                '  status: %s · would_unlock_if_doctrine_allowed=%s',
                ($v7['unlocked'] ?? false) ? '<info>destravada</info>' : '<error>bloqueada</error>',
                YesNo::trueFalse($v7['would_unlock_if_doctrine_allowed'] ?? false),
            ));
            $blockers = $v7['blockers_pt_br'] ?? [];
            if (is_array($blockers) && $blockers !== []) {
                foreach ($blockers as $b) {
                    $this->line('    - '.$b);
                }
            }
        }
    }
}
