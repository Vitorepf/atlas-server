<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Vox\Gate\VoxV6QualityBenchService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Vox V6 · quality bench (FPG-C).
 *
 * Pergunta única: "Atlas Vox V6 entrega qualidade suficiente para uso diário,
 * e onde ainda dá pra melhorar SEM sair de V6?"
 *
 * Read-only. Não escreve no ledger. Não chama provider. Não destrava V7.
 *
 * Modos:
 *   - default        → texto humano PT-BR. Exit 0 (pass/warn), 1 (fail), 2 (exceção).
 *   - --json         → envelope JSON canônico atlas.vox.v6_quality_bench.v1.
 *   - --strict       → trata warn como exit 1 (CI gate).
 */
final class AtlasVoxQualityBenchCommand extends Command
{
    protected $signature = 'atlas:vox:quality-bench
        {--json : Print machine-readable JSON envelope}
        {--strict : Treat warn as non-zero exit code}';

    protected $description = 'Atlas Vox V6 quality bench. Mede auto mode, prompt compiler, restrições, perigos. READ-ONLY.';

    public function handle(VoxV6QualityBenchService $bench): int
    {
        try {
            $envelope = $bench->build();
        } catch (Throwable $e) {
            $this->error('atlas:vox:quality-bench exceção: '.$e->getMessage());

            return 2;
        }

        $status = (string) ($envelope['status'] ?? VoxV6QualityBenchService::STATUS_FAIL);

        if ($this->option('json')) {
            $this->line(json_encode(
                $envelope,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ) ?: '{}');
        } else {
            $this->renderHuman($envelope);
        }

        if ($status === VoxV6QualityBenchService::STATUS_FAIL) {
            return 1;
        }
        if ($status === VoxV6QualityBenchService::STATUS_WARN && $this->option('strict')) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function renderHuman(array $envelope): void
    {
        $status = (string) ($envelope['status'] ?? '?');
        $statusLabel = match ($status) {
            VoxV6QualityBenchService::STATUS_PASS => '<info>PASS</info>',
            VoxV6QualityBenchService::STATUS_WARN => '<comment>WARN</comment>',
            default => '<error>FAIL</error>',
        };
        $this->line('Atlas Vox V6 · Quality Bench');
        $this->line('  schema     '.$envelope['schema']);
        $this->line('  status     '.$statusLabel);
        $this->line('  score      '.sprintf('%.0f%%', ((float) $envelope['score_overall']) * 100));
        $this->line(sprintf(
            '  daily use  %s',
            ($envelope['ready_for_daily_use'] ?? false) ? '<info>sim</info>' : '<comment>ainda não</comment>',
        ));
        $this->line(sprintf(
            '  V7 unlock  %s',
            ($envelope['v7_unlock_allowed'] ?? false) ? '<error>destravado</error>' : '<info>bloqueado (canon)</info>',
        ));
        $this->line('');
        $this->line($envelope['summary_pt_br'] ?? '');
        $this->line('');

        $this->line('Scores:');
        foreach ((array) ($envelope['scores'] ?? []) as $key => $value) {
            $this->line(sprintf('  %-30s %s%%', $key, str_pad((string) (int) round(((float) $value) * 100), 3, ' ', STR_PAD_LEFT)));
        }
        $totals = $envelope['totals'] ?? [];
        if (is_array($totals)) {
            $this->line('');
            $this->line(sprintf('  casos totais: %d', (int) ($totals['cases'] ?? 0)));
        }

        $warnings = (array) ($envelope['v6_ceiling_warnings_pt_br'] ?? []);
        if ($warnings !== []) {
            $this->line('');
            $this->line('Onde V6 ainda pode melhorar:');
            foreach ($warnings as $w) {
                $this->line('  - '.$w);
            }
        }

        $improvements = (array) ($envelope['improvements_pt_br'] ?? []);
        if ($improvements !== []) {
            $this->line('');
            $this->line('Próximos passos sugeridos:');
            foreach ($improvements as $w) {
                $this->line('  · '.$w);
            }
        }

        $stt = $envelope['stt_environment_ready'] ?? [];
        if (is_array($stt)) {
            $this->line('');
            $this->line('Microfone / STT:');
            $this->line(sprintf(
                '  · modelo Whisper: %s',
                ($stt['whisper_model_present'] ?? false) ? 'presente' : 'ausente (download manual)',
            ));
            $this->line(sprintf(
                '  · raw audio policy: %s',
                ($stt['raw_audio_policy_ok'] ?? false) ? 'OK' : 'FALHA',
            ));
            $this->line(sprintf(
                '  · entitlement audio-input: %s',
                ($stt['audio_input_entitlement_ok'] ?? false) ? 'OK' : 'pendente',
            ));
            if (! empty($stt['input_device_selection_hint_pt_br'])) {
                $this->line('  · '.$stt['input_device_selection_hint_pt_br']);
            }
        }

        if (! empty($envelope['airpods_guidance_pt_br'])) {
            $this->line('');
            $this->line('AirPods no Mac:');
            $this->line('  '.$envelope['airpods_guidance_pt_br']);
        }
    }
}
