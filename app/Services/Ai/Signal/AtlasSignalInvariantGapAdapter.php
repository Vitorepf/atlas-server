<?php

declare(strict_types=1);

namespace App\Services\Ai\Signal;

/**
 * Traduz achado de invariante de sinal para a forma de lacuna que o
 * AtlasExternalBrainCapabilityGapTaskChainCompiler já sabe compilar.
 *
 * O compilador existe e ordena cadeias por dependência desde sempre; o que
 * faltava era alguém entregar as lacunas a ele. 286 tabelas vazias são um
 * backlog que nenhum humano vai transformar em 286 prompts.
 *
 * As quatro classes de achado e por que cada uma vira a cadeia que vira:
 *
 *   • `table_without_owner` — existe a forma, não existe quem escreva. Primeiro
 *     provar QUEM deveria escrever (missing_context), depois ligar o produtor a
 *     um caminho de chamada real (no_runtime_integration).
 *   • `gate_field_without_producer` — o portão exige um campo que ninguém
 *     produz, então ele passa por vacuidade: mesma dupla, mais o endurecimento
 *     do portão no meio (weak_gate), porque um portão satisfeito por ausência é
 *     exatamente um portão fraco.
 *   • `producer_without_clock` — o produtor existe e roda quando alguém lembra:
 *     só falta o relógio (no_runtime_integration).
 *   • `orphan_cadence` — a cadência existe e não deposita nada: provar o que
 *     deveria depositar (missing_context) e ligar (no_runtime_integration).
 *
 * `allowed_files_hint` só sai do achado — nunca é inventado aqui. Sem alvo
 * concreto, o compilador marca o nó `not_muscle_ready`, que é a resposta
 * honesta: uma tabela vazia não diz sozinha quem deveria enchê-la.
 *
 * Puro e determinístico: sem I/O, sem escrita, sem enfileirar nada.
 */
final class AtlasSignalInvariantGapAdapter
{
    public const SCHEMA = 'atlas.signal.invariant_gap_adapter.v1';

    /** Classe de achado → cadeia de bloqueadores, na ordem que o compilador já impõe. */
    private const BLOCKER_CHAIN_BY_CLASS = [
        'table_without_owner' => ['missing_context', 'no_runtime_integration'],
        'gate_field_without_producer' => ['missing_context', 'weak_gate', 'no_runtime_integration'],
        'producer_without_clock' => ['no_runtime_integration'],
        'orphan_cadence' => ['missing_context', 'no_runtime_integration'],
    ];

    private const EXPECTED_DELTA_BY_CLASS = [
        'table_without_owner' => 'a tabela passa a ter um produtor real e uma primeira linha provada',
        'gate_field_without_producer' => 'o campo exigido pelo portão passa a ter produtor, e o portão deixa de passar por ausência',
        'producer_without_clock' => 'o produtor passa a rodar por cadência, não por lembrança',
        'orphan_cadence' => 'a cadência passa a depositar saída verificável em vez de rodar no vazio',
    ];

    /**
     * @return list<string>
     */
    public function supportedClasses(): array
    {
        return array_keys(self::BLOCKER_CHAIN_BY_CLASS);
    }

    /**
     * @param  list<array<string,mixed>>  $findings  {class, subject, allowed_files_hint?}
     * @return array{gaps: list<array{gap_id:string, blockers:list<array<string,mixed>>}>, skipped: list<array<string,mixed>>}
     */
    public function toGaps(array $findings): array
    {
        $gaps = [];
        $skipped = [];
        $seen = [];

        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                $skipped[] = ['reason' => 'malformed_finding'];

                continue;
            }

            $class = trim((string) ($finding['class'] ?? ''));
            $subject = trim((string) ($finding['subject'] ?? ''));

            // Classe desconhecida NÃO vira lacuna genérica: uma cadeia inventada
            // seria backlog fabricado, que é pior que backlog faltando.
            if (! array_key_exists($class, self::BLOCKER_CHAIN_BY_CLASS) || $subject === '') {
                $skipped[] = ['class' => $class, 'subject' => $subject, 'reason' => $class === '' || ! array_key_exists($class, self::BLOCKER_CHAIN_BY_CLASS) ? 'unsupported_class' : 'missing_subject'];

                continue;
            }

            $gapId = $class.':'.$subject;
            if (isset($seen[$gapId])) {
                $skipped[] = ['class' => $class, 'subject' => $subject, 'reason' => 'duplicate_finding'];

                continue;
            }
            $seen[$gapId] = true;

            $hint = array_values(array_filter(
                array_map('strval', (array) ($finding['allowed_files_hint'] ?? [])),
                static fn (string $p): bool => trim($p) !== '',
            ));

            // Um unblocker declarado é o que faz duas lacunas colapsarem num nó só
            // — é o ponto do compilador. Mas ele identifica o TRABALHO, não a
            // lacuna: sem o sufixo de tipo, os 2-3 bloqueadores desta mesma lacuna
            // receberiam o mesmo id e virariam UM nó, engolindo a cadeia inteira.
            $declaredUnblocker = trim((string) ($finding['unblocker_id'] ?? ''));

            $blockers = [];
            foreach (self::BLOCKER_CHAIN_BY_CLASS[$class] as $type) {
                $blockers[] = [
                    'type' => $type,
                    'unblocker_id' => ($declaredUnblocker !== '' ? $declaredUnblocker : $gapId).':'.$type,
                    'allowed_files_hint' => $hint,
                    'expected_delta' => self::EXPECTED_DELTA_BY_CLASS[$class],
                ];
            }

            $gaps[] = ['gap_id' => $gapId, 'blockers' => $blockers];
        }

        return ['gaps' => $gaps, 'skipped' => $skipped];
    }
}
