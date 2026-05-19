<?php

declare(strict_types=1);

namespace App\Services\Ai\Vox\Gate;

use App\Services\Ai\Vox\Dogfood\VoxDogfoodService;
use App\Services\Ai\Vox\Metrics\VoxMetricsService;
use App\Services\Ai\Vox\VoxSchema;
use Carbon\CarbonImmutable;

/**
 * Atlas Vox V7 · unlock gate — DETERMINISTIC AND READ-ONLY.
 *
 * Decide se há evidência suficiente para sequer **considerar** destravar V7
 * (memória longitudinal). O gate NÃO destrava nada por si só: é uma camada
 * de medição que devolve verdade observável + lista de bloqueios. A decisão
 * final precisa de uma nova ADR + aprovação humana explícita (ver
 * {@see VoxV6CertificationService::build()} — `v7_unlock_allowed` segue
 * `false` enquanto o canon V6-F estiver em vigor).
 *
 * O gate verifica:
 *   1. Uso real (≥ 100 sessões totais OU ≥ 30 dias reais de uso)
 *   2. `raw_audio_persisted_count == 0` (Lei 0.5 dura)
 *   3. `destructive_action_without_receipt == 0` (Lei 0.9 dura)
 *   4. Taxa de erro aceitável (success_rate ≥ 0.70)
 *   5. Regret rate aceitável (< 0.20)
 *   6. Correções recorrentes suficientes (≥ 10 entradas no dicionário
 *      preferido) — sinaliza padrões de fala medidos
 *   7. Marca explícita de aprovação do operador em
 *      `~/.atlas/vox/v7_unlock_approved.json` (lida fora do controle de
 *      processo do Atlas, intencionalmente fora-de-banda)
 *
 * Output canônico: `atlas.vox.v7_unlock_gate.v1`.
 *
 * Honestidade: enquanto o canon V6-F travar o destrave, este serviço **sempre**
 * devolve `unlocked=false` mesmo quando todos os critérios estão met. A flag
 * `would_unlock_if_doctrine_allowed` indica que tecnicamente o operador já
 * teria evidência — mas o destrave segue exigindo nova ADR + ato humano
 * fora do código.
 */
final class VoxV7UnlockGateService
{
    public const SCHEMA = 'atlas.vox.v7_unlock_gate.v1';
    public const VERSION = '0.1.0';

    public const REQUIRED_MIN_SESSIONS = 100;
    public const REQUIRED_MIN_REAL_USAGE_DAYS = 30;
    public const REQUIRED_MIN_SUCCESS_RATE = 0.70;
    public const REQUIRED_MAX_REGRET_RATE = 0.20;
    public const REQUIRED_MIN_DICTIONARY_CORRECTIONS = 10;

    /** Caminho canon onde o operador deixa o marker fora-de-banda. */
    public const HUMAN_APPROVAL_MARKER_FILENAME = 'v7_unlock_approved.json';

    public function __construct(
        private readonly VoxMetricsService $metrics,
        private readonly VoxDogfoodService $dogfood,
    ) {}

    /**
     * Build the V7 unlock gate envelope. Always honest, never invents.
     *
     * @return array{
     *   schema: string,
     *   version: string,
     *   unlocked: bool,
     *   would_unlock_if_doctrine_allowed: bool,
     *   requires_human_adr: bool,
     *   criteria_status: array<string,array<string,mixed>>,
     *   criteria_met: list<string>,
     *   blockers: list<string>,
     *   blockers_pt_br: list<string>,
     *   human_approval: array{marker_present:bool, marker_path:?string, approved_by:?string, approved_at:?string, blocked_reason_pt_br:string},
     *   summary_pt_br: string,
     *   generated_at: string
     * }
     */
    public function evaluate(): array
    {
        $metricsSnap = $this->metrics->snapshot();
        $dogfoodReport = $this->dogfood->report();
        $humanApproval = $this->readHumanApprovalMarker();

        $criteria = $this->evaluateCriteria($metricsSnap, $dogfoodReport, $humanApproval);
        $blockers = [];
        $blockersPtBr = [];
        $met = [];
        foreach ($criteria as $key => $row) {
            if ($row['ok']) {
                $met[] = $key;
                continue;
            }
            $blockers[] = $key;
            $blockersPtBr[] = (string) ($row['blocker_pt_br'] ?? $key);
        }

        $wouldUnlock = $blockers === [];
        // Doutrina V6-F continua em vigor: mesmo critérios met + marker presente
        // NÃO destravam V7 por código. Exige ADR nova.
        $unlocked = false;

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'unlocked' => $unlocked,
            'would_unlock_if_doctrine_allowed' => $wouldUnlock,
            'requires_human_adr' => true,
            'criteria_status' => $criteria,
            'criteria_met' => $met,
            'blockers' => $blockers,
            'blockers_pt_br' => $blockersPtBr,
            'human_approval' => $humanApproval,
            'summary_pt_br' => $this->summaryPtBr($wouldUnlock, $blockers, $humanApproval),
            'generated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $metricsSnap
     * @param  array<string,mixed>  $dogfoodReport
     * @param  array{marker_present:bool,marker_path:?string,approved_by:?string,approved_at:?string,blocked_reason_pt_br:string}  $humanApproval
     * @return array<string,array<string,mixed>>
     */
    private function evaluateCriteria(array $metricsSnap, array $dogfoodReport, array $humanApproval): array
    {
        $totalSessions = (int) ($metricsSnap['summary']['total_sessions'] ?? 0);
        $realUsageDays = (int) ($metricsSnap['summary']['real_usage_days'] ?? 0);
        $dictionaryCorrections = (int) ($metricsSnap['summary']['dictionary_correction_count'] ?? 0);
        $rawAudio = (int) ($metricsSnap['hard_gates']['raw_audio_persisted_count'] ?? 0);
        $destructiveNoReceipt = (int) ($metricsSnap['hard_gates']['destructive_action_without_receipt'] ?? 0);

        $dogfoodTotal = (int) ($dogfoodReport['sessions_total'] ?? 0);
        $successRate = (float) ($dogfoodReport['success_rate'] ?? 0.0);
        $regretRate = (float) ($dogfoodReport['regret_rate'] ?? 0.0);

        $reachedSessions = $totalSessions >= self::REQUIRED_MIN_SESSIONS;
        $reachedDays = $realUsageDays >= self::REQUIRED_MIN_REAL_USAGE_DAYS;
        $reachedUsage = $reachedSessions || $reachedDays;

        // Success/regret rate só pode ser avaliado quando há dogfood real.
        // Sem dogfood, marcamos como bloqueado por insuficiência amostral.
        $successOk = $dogfoodTotal > 0 && $successRate >= self::REQUIRED_MIN_SUCCESS_RATE;
        $regretOk = $dogfoodTotal === 0 ? false : $regretRate < self::REQUIRED_MAX_REGRET_RATE;

        return [
            'usage_volume' => [
                'target' => sprintf(
                    '≥ %d sessões OU ≥ %d dias reais de uso',
                    self::REQUIRED_MIN_SESSIONS,
                    self::REQUIRED_MIN_REAL_USAGE_DAYS,
                ),
                'observed' => [
                    'total_sessions' => $totalSessions,
                    'real_usage_days' => $realUsageDays,
                ],
                'ok' => $reachedUsage,
                'blocker_pt_br' => sprintf(
                    'Uso real insuficiente: %d sessões, %d dias com Vox aberto. '
                    .'Precisamos de ≥ %d sessões OU ≥ %d dias.',
                    $totalSessions,
                    $realUsageDays,
                    self::REQUIRED_MIN_SESSIONS,
                    self::REQUIRED_MIN_REAL_USAGE_DAYS,
                ),
            ],
            'no_raw_audio_persisted' => [
                'target' => 'raw_audio_persisted_count == 0',
                'observed' => ['raw_audio_persisted_count' => $rawAudio],
                'ok' => $rawAudio === 0,
                'blocker_pt_br' => sprintf(
                    'Áudio bruto foi persistido em %d evento(s). V7 nunca destrava com Lei 0.5 ferida.',
                    $rawAudio,
                ),
            ],
            'no_destructive_without_receipt' => [
                'target' => 'destructive_action_without_receipt == 0',
                'observed' => ['destructive_action_without_receipt' => $destructiveNoReceipt],
                'ok' => $destructiveNoReceipt === 0,
                'blocker_pt_br' => sprintf(
                    'Ação destrutiva sem receipt registrada %d vez(es). Não destrava V7 enquanto não for zero.',
                    $destructiveNoReceipt,
                ),
            ],
            'success_rate_acceptable' => [
                'target' => sprintf('success_rate ≥ %.2f (com dogfood real)', self::REQUIRED_MIN_SUCCESS_RATE),
                'observed' => [
                    'dogfood_sessions_total' => $dogfoodTotal,
                    'success_rate' => $successRate,
                ],
                'ok' => $successOk,
                'blocker_pt_br' => $dogfoodTotal === 0
                    ? 'Sem dogfood registrado ainda — sem como medir taxa de sucesso. Use o Vox por dias e marque sessões via /ai/vox/dogfood/session.'
                    : sprintf(
                        'Taxa de sucesso = %.2f, abaixo do mínimo %.2f.',
                        $successRate,
                        self::REQUIRED_MIN_SUCCESS_RATE,
                    ),
            ],
            'regret_rate_acceptable' => [
                'target' => sprintf('regret_rate < %.2f (com dogfood real)', self::REQUIRED_MAX_REGRET_RATE),
                'observed' => [
                    'dogfood_sessions_total' => $dogfoodTotal,
                    'regret_rate' => $regretRate,
                ],
                'ok' => $regretOk,
                'blocker_pt_br' => $dogfoodTotal === 0
                    ? 'Sem dogfood registrado — sem como medir arrependimento. V7 exige amostra real.'
                    : sprintf(
                        'Taxa de arrependimento = %.2f, igual/superior ao limite %.2f. Investigue padrões antes de V7.',
                        $regretRate,
                        self::REQUIRED_MAX_REGRET_RATE,
                    ),
            ],
            'recurring_dictionary_corrections' => [
                'target' => sprintf('≥ %d correções pessoais registradas', self::REQUIRED_MIN_DICTIONARY_CORRECTIONS),
                'observed' => ['dictionary_correction_count' => $dictionaryCorrections],
                'ok' => $dictionaryCorrections >= self::REQUIRED_MIN_DICTIONARY_CORRECTIONS,
                'blocker_pt_br' => sprintf(
                    'Apenas %d correções pessoais aplicadas. V7 (memória longitudinal) precisa de padrões reais — meta ≥ %d.',
                    $dictionaryCorrections,
                    self::REQUIRED_MIN_DICTIONARY_CORRECTIONS,
                ),
            ],
            'explicit_human_approval' => [
                'target' => 'marcador ~/.atlas/vox/'.self::HUMAN_APPROVAL_MARKER_FILENAME.' presente e válido',
                'observed' => [
                    'marker_present' => $humanApproval['marker_present'],
                    'marker_path' => $humanApproval['marker_path'],
                    'approved_by' => $humanApproval['approved_by'],
                ],
                'ok' => $humanApproval['marker_present'] === true,
                'blocker_pt_br' => $humanApproval['marker_present']
                    ? 'Marcador presente.'
                    : 'Marcador humano ausente. Crie ~/.atlas/vox/'.self::HUMAN_APPROVAL_MARKER_FILENAME.' com {"approved_by":"Vitor","approved_at":"<ISO 8601>","reason":"..."} para registrar aprovação explícita.',
            ],
        ];
    }

    /**
     * @return array{marker_present:bool, marker_path:?string, approved_by:?string, approved_at:?string, blocked_reason_pt_br:string}
     */
    private function readHumanApprovalMarker(): array
    {
        $home = getenv('HOME');
        if (! is_string($home) || $home === '') {
            return [
                'marker_present' => false,
                'marker_path' => null,
                'approved_by' => null,
                'approved_at' => null,
                'blocked_reason_pt_br' => 'HOME indefinido — não consigo localizar marcador.',
            ];
        }
        $path = $home.'/.atlas/vox/'.self::HUMAN_APPROVAL_MARKER_FILENAME;
        $shortPath = '~/.atlas/vox/'.self::HUMAN_APPROVAL_MARKER_FILENAME;
        if (! is_file($path)) {
            return [
                'marker_present' => false,
                'marker_path' => $shortPath,
                'approved_by' => null,
                'approved_at' => null,
                'blocked_reason_pt_br' => 'Sem marcador. Doutrina V6-F mantém V7 bloqueada por código.',
            ];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [
                'marker_present' => false,
                'marker_path' => $shortPath,
                'approved_by' => null,
                'approved_at' => null,
                'blocked_reason_pt_br' => 'Marcador presente mas vazio — ignorado.',
            ];
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [
                'marker_present' => false,
                'marker_path' => $shortPath,
                'approved_by' => null,
                'approved_at' => null,
                'blocked_reason_pt_br' => 'Marcador presente mas JSON inválido — ignorado.',
            ];
        }
        if (! is_array($decoded)) {
            return [
                'marker_present' => false,
                'marker_path' => $shortPath,
                'approved_by' => null,
                'approved_at' => null,
                'blocked_reason_pt_br' => 'Marcador presente mas não é objeto JSON — ignorado.',
            ];
        }
        $approvedBy = is_string($decoded['approved_by'] ?? null) ? trim($decoded['approved_by']) : '';
        $approvedAt = is_string($decoded['approved_at'] ?? null) ? trim($decoded['approved_at']) : '';
        if ($approvedBy === '' || $approvedAt === '') {
            return [
                'marker_present' => false,
                'marker_path' => $shortPath,
                'approved_by' => null,
                'approved_at' => null,
                'blocked_reason_pt_br' => 'Marcador presente mas faltam approved_by/approved_at — ignorado.',
            ];
        }

        return [
            'marker_present' => true,
            'marker_path' => $shortPath,
            'approved_by' => $approvedBy,
            'approved_at' => $approvedAt,
            'blocked_reason_pt_br' => 'Marcador honra a regra mas doutrina V6-F ainda exige nova ADR para destravar.',
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @param  array{marker_present:bool,marker_path:?string,approved_by:?string,approved_at:?string,blocked_reason_pt_br:string}  $humanApproval
     */
    private function summaryPtBr(bool $wouldUnlock, array $blockers, array $humanApproval): string
    {
        if (! $wouldUnlock) {
            $count = count($blockers);

            return sprintf(
                'V7 segue bloqueada. %d critério(s) ainda não cumprido(s). Use o Vox no dia a dia, registre dogfood e zere bloqueios duros antes de pensar em destravar.',
                $count,
            );
        }
        if ($humanApproval['marker_present']) {
            return 'Critérios técnicos cumpridos e marcador humano presente — mas doutrina V6-F mantém V7 bloqueada até nova ADR. Abra a ADR para discutir destrave.';
        }

        return 'Critérios técnicos cumpridos. Falta o marcador humano explícito; V7 segue bloqueada por doutrina V6-F mesmo assim.';
    }
}
