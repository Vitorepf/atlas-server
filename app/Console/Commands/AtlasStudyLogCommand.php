<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\OperatorIntelligence\OperatorSignalCaptureService;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;

/**
 * A porta pela qual o operador declara as PRÓPRIAS palavras.
 *
 * Por que ela precisou existir: `OperatorLearningRuntimeCaptureService` exige
 * `payload.operator_text` e recusa tudo que não o traga — com recibo contável
 * (`operator_text_not_declared`), não em silêncio. Essa guarda é deliberada: antes
 * dela, o detector aprendeu o PREÂMBULO de um prompt de subagente como se fosse
 * padrão do operador, e as três primeiras `operator_skill_proposals` eram a máquina
 * se ouvindo. A lei que ficou é "não declarado = desconhecido, e desconhecido não se
 * aprende" — não aprender é recuperável, gravar a voz da máquina como regra dele não é.
 *
 * O efeito colateral da lei era o órgão inteiro ficar mudo, porque o único caller de
 * `captureFromTrace` é `AiGatewayService:668` — ou seja, a captura só acontecia dentro
 * de uma chamada de provider. Registrar uma decisão não pode custar tokens.
 *
 * Este comando escreve pela porta declarada (`OperatorSignalCaptureService::capture`),
 * sem gateway e sem provider, marcando `metadata.operator_text_declared` — que é o
 * campo exato que `OperatorPatternDetector:77` exige para minerar padrão.
 *
 * O que ele NÃO faz: julgar se a decisão foi boa. Ele registra o COMPROMISSO — o que
 * foi decidido, por quê, e com quanta certeza — ANTES de o resultado ser conhecido.
 * É essa ordem que separa aprendizado de racionalização: quem escreve o motivo depois
 * de ver o resultado está explicando a sorte, não o princípio.
 */
class AtlasStudyLogCommand extends Command
{
    protected $signature = 'atlas:study:log
        {--spot= : A situação — o que estava na mesa/tela quando você decidiu}
        {--decision= : O que você decidiu fazer}
        {--why= : O PRINCÍPIO por trás, não a descrição da jogada}
        {--confidence= : principled|guess — decidiu por princípio, ou chutou}
        {--term= : Termo do vocabulário que ancora (ex.: um verbete do Dicionário)}
        {--domain=poker : Domínio de estudo}
        {--operator= : Id do operador}
        {--dry-run : Mostra o que seria gravado, sem gravar}
        {--json : Saída canônica}';

    protected $description = 'Registra uma decisão do operador ANTES do resultado — o compromisso que torna a prática deliberada.';

    public function handle(OperatorSignalCaptureService $capture): int
    {
        $spot = trim((string) $this->option('spot'));
        $decision = trim((string) $this->option('decision'));
        $why = trim((string) $this->option('why'));
        $confidence = trim((string) $this->option('confidence')) ?: 'principled';

        // Fail-closed nos três que carregam o aprendizado. `--term` é opcional porque
        // nem todo princípio tem verbete; os outros três não têm substituto: sem spot
        // não há transferência (é o que muda no teste futuro), sem decisão não há o que
        // julgar, e sem o porquê o registro vira diário, não estudo.
        $faltando = array_keys(array_filter([
            'spot' => $spot === '',
            'decision' => $decision === '',
            'why' => $why === '',
        ]));
        if ($faltando !== []) {
            return $this->recusa('missing_required', [
                'missing' => $faltando,
                'hint' => 'o porquê é o PRINCÍPIO ("contra esse perfil a range é polarizada"), não a jogada ("dei fold")',
            ]);
        }

        if (! in_array($confidence, ['principled', 'guess'], true)) {
            return $this->recusa('invalid_confidence', [
                'given' => $confidence,
                'allowed' => ['principled', 'guess'],
                'why_it_matters' => 'chutar e acertar não é aprender; sem esse campo os dois "acertou" viram o mesmo número',
            ]);
        }

        if (! DatabaseTableAvailability::has('operator_learning_signals')) {
            return $this->recusa('missing_operator_tables', ['table' => 'operator_learning_signals']);
        }

        $result = $capture->capture([
            'operator_id' => $this->option('operator') ?: null,
            'claim' => $why,
            'raw_excerpt' => $spot."\n".$decision."\n".$why,
            'taxonomy_item_id' => (string) ($this->option('term') ?: ''),
            'signal_kind' => 'operator_decision',
            'source_type' => 'operator_declared',
            'scope_type' => 'domain',
            'scope_id' => (string) $this->option('domain'),
            'evidence_refs' => ['spot:'.$spot, 'decision:'.$decision],
            // O eixo que separa sorte de princípio. `guess` entra baixo de propósito:
            // um palpite que acertou não deve pesar como regra do operador.
            'confidence' => $confidence === 'principled' ? 0.9 : 0.25,
            'inference_type' => 'explicit',
            'dry_run' => (bool) $this->option('dry-run'),
            'metadata' => [
                // O campo que o detector exige. É a diferença entre "o operador disse"
                // e "o harness inferiu" — e é por isso que ele viaja explícito.
                'operator_text_declared' => true,
                'commitment_before_outcome' => true,
                'confidence_kind' => $confidence,
                'study_domain' => (string) $this->option('domain'),
            ],
        ]);

        return $this->responde(array_merge($result, [
            'schema_version' => 'atlas.study.log.v1',
            'confidence_kind' => $confidence,
        ]));
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function recusa(string $reason, array $extra = []): int
    {
        $this->responde(array_merge(['ok' => false, 'reason' => $reason], $extra));

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function responde(array $payload): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return ($payload['ok'] ?? false) === true ? self::SUCCESS : self::FAILURE;
    }
}
