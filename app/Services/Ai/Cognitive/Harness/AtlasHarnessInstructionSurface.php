<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\Harness;

/**
 * AP-819 Surface v2 — seções de INSTRUÇÃO evoluíveis (a outra metade do paper:
 * "instruction / verification guidance sections" — os edits aceitos no paper são
 * exatamente deste tipo: "create output early", "redirect after N tool calls").
 *
 * CERTEZA POR CONSTRUÇÃO: o espaço de busca é FINITO e CURADO — cada seção tem
 * uma biblioteca declarada de variantes; validate() só aceita texto que seja a
 * default ou uma variante da biblioteca. Nada de texto livre gerado por modelo
 * entrando em prompt por caminho automático. Evolução = busca discreta num
 * espaço auditado, julgada pela mesma suite congelada + recorrência crua.
 *
 * O fio é REAL: AiPromptBuilder injeta promptSection() em TODO prompt que o
 * worker monta para QUALQUER provider. Override vive em arquivo durável;
 * reversão = restaurar o texto anterior (1 passo, perfeita).
 */
class AtlasHarnessInstructionSurface
{
    public const SCHEMA_VERSION = 'atlas.cognitive.harness_instruction_surface.v1';

    private const MAX_CHARS = 500;

    /**
     * Seções declaradas: default + biblioteca de variantes (espaço de busca
     * COMPLETO da evolução automática — adicionar variante = ato de código).
     *
     * @var array<string,array{purpose:string,default:string,variants:list<string>}>
     */
    private const SECTIONS = [
        'worker.output_discipline' => [
            'purpose' => 'Garantir que toda execução termina com o entregável concreto.',
            'default' => 'Produza o entregável CONCRETO o quanto antes; nunca encerre a resposta sem o resultado pedido. Se algo impedir a entrega completa, entregue a melhor versão parcial + o motivo em 1 linha.',
            'variants' => [
                'Comece pelo entregável: primeiro a resposta/artefato, depois a explicação. Resposta sem o artefato pedido é falha de execução.',
                'Antes de encerrar, confira: o pedido foi atendido LITERALMENTE? Se não, continue até atender ou declare o bloqueio em 1 linha — nunca encerre em silêncio.',
            ],
        ],
        'worker.tool_error_recovery' => [
            'purpose' => 'Cortar loops de erro de ferramenta (a falha clássica de harness).',
            'default' => 'Se a mesma ferramenta/abordagem falhar 2 vezes seguidas, MUDE de estratégia — nunca repita a chamada idêntica. Após 3 falhas consecutivas de qualquer tipo, pare e devolva o melhor resultado parcial com o diagnóstico.',
            'variants' => [
                'Erro de ferramenta não é convite a tentar de novo igual: ajuste parâmetros ou troque o caminho, e registre em 1 linha o que mudou entre as tentativas.',
                'Opere por orçamento de tentativas: no máximo 2 por abordagem; estourou, troque de abordagem ou finalize com diagnóstico honesto do bloqueio.',
            ],
        ],
        'worker.verification_guidance' => [
            'purpose' => 'Exigir verificação antes de declarar sucesso (anti over-claim na execução).',
            'default' => 'Antes de declarar concluído, VERIFIQUE o resultado contra o pedido (rode/cheque o que for verificável) e diga em 1 linha o que foi verificado. Nunca declare sucesso sem evidência.',
            'variants' => [
                'Conclusão exige prova: mostre a evidência mínima (saída de comando, trecho de arquivo, código de status). Sem evidência, rotule explicitamente como NÃO VERIFICADO.',
                'Ao concluir, separe FATO de SUPOSIÇÃO: o que você confirmou rodando/lendo vs o que assumiu. Marque cada um explicitamente.',
            ],
        ],
    ];

    private ?string $overridesPathOverride = null;

    /** @var array<string,array<string,mixed>>|null cache por instância (1 leitura/request) */
    private ?array $overridesCache = null;

    public function setOverridesPathForTesting(?string $path): void
    {
        $this->overridesPathOverride = $path;
        $this->overridesCache = null;
    }

    public function overridesPath(): string
    {
        if ($this->overridesPathOverride !== null) {
            return $this->overridesPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'harness_instruction_overrides.json';
    }

    /**
     * @return array<string,array{purpose:string,default:string,variants:list<string>}>
     */
    public function sections(): array
    {
        return self::SECTIONS;
    }

    /**
     * O texto VIVO de uma seção (override aplicado ?? default).
     */
    public function text(string $section): ?string
    {
        $declared = self::SECTIONS[$section] ?? null;
        if ($declared === null) {
            return null;
        }
        $override = $this->readOverrides()[$section]['text'] ?? null;

        return is_string($override) && $override !== '' ? $override : $declared['default'];
    }

    /**
     * Espaço de busca COMPLETO de uma seção: default + variantes.
     *
     * @return list<string>
     */
    public function searchSpace(string $section): array
    {
        $declared = self::SECTIONS[$section] ?? null;
        if ($declared === null) {
            return [];
        }

        return array_values(array_unique([$declared['default'], ...$declared['variants']]));
    }

    /**
     * CERTEZA POR CONSTRUÇÃO: só aceita texto do espaço de busca declarado.
     *
     * @return array{valid:bool,reason:?string}
     */
    public function validate(string $section, mixed $text): array
    {
        if (! isset(self::SECTIONS[$section])) {
            return ['valid' => false, 'reason' => 'section_not_in_instruction_surface'];
        }
        if (! is_string($text) || trim($text) === '') {
            return ['valid' => false, 'reason' => 'text_must_be_non_empty_string'];
        }
        if (mb_strlen($text) > self::MAX_CHARS) {
            return ['valid' => false, 'reason' => 'text_exceeds_max_chars:'.self::MAX_CHARS];
        }
        if (! in_array($text, $this->searchSpace($section), true)) {
            return ['valid' => false, 'reason' => 'text_not_in_declared_search_space'];
        }

        return ['valid' => true, 'reason' => null];
    }

    /**
     * @return array{applied:bool,reason:?string,section:?string,previous:?string}
     */
    public function applyOverride(string $section, mixed $text, string $proposalId): array
    {
        $verdict = $this->validate($section, $text);
        if (! $verdict['valid']) {
            return ['applied' => false, 'reason' => $verdict['reason'], 'section' => $section, 'previous' => null];
        }

        $previous = (string) $this->text($section);
        $overrides = $this->readOverrides();
        $overrides[$section] = [
            'text' => (string) $text,
            'previous' => $previous,
            'proposal_id' => $proposalId,
            'applied_at' => now()->toJSON(),
        ];
        $this->writeOverrides($overrides);

        return ['applied' => true, 'reason' => null, 'section' => $section, 'previous' => $previous];
    }

    /**
     * @return array{reversed:bool,reason:?string,section:?string,restored:?string}
     */
    public function reverseOverride(string $section): array
    {
        $overrides = $this->readOverrides();
        $entry = $overrides[$section] ?? null;
        if ($entry === null) {
            return ['reversed' => false, 'reason' => 'override_not_found', 'section' => $section, 'restored' => null];
        }

        unset($overrides[$section]);
        $this->writeOverrides($overrides);

        return ['reversed' => true, 'reason' => null, 'section' => $section, 'restored' => $this->text($section)];
    }

    /**
     * A seção de prompt VIVA que o AiPromptBuilder injeta em todo prompt de
     * provider — o fio real da evolução de instruções.
     */
    public function promptSection(): string
    {
        $lines = [];
        foreach (array_keys(self::SECTIONS) as $section) {
            $text = $this->text($section);
            if (is_string($text) && $text !== '') {
                $lines[] = '- '.$text;
            }
        }

        return "# Disciplina operacional (Harness Surface v2 — auto-evoluída, auditada)\n\n".implode("\n", $lines);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function readOverrides(): array
    {
        if ($this->overridesCache !== null) {
            return $this->overridesCache;
        }
        $path = $this->overridesPath();
        if (! is_file($path)) {
            return $this->overridesCache = [];
        }
        $decoded = json_decode((string) @file_get_contents($path), true);

        return $this->overridesCache = (is_array($decoded) ? $decoded : []);
    }

    /**
     * @param  array<string,array<string,mixed>>  $overrides
     */
    private function writeOverrides(array $overrides): void
    {
        $path = $this->overridesPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
        file_put_contents($path, json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $this->overridesCache = $overrides;
    }
}
