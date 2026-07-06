<?php

namespace App\Services\Ai\Compounding;

use App\Models\AiLearningCandidate;
use Illuminate\Support\Facades\Process;

/**
 * Obra #13 item 5 — compounding automático de lições de obra: refutações
 * escritas à mão nos docs de obra viram learning candidates GOVERNADOS
 * (quarentena 'hold', mesmo shape do AtlasLearningDistiller; NUNCA auto-promove).
 */
class AtlasObraLessonHarvester
{
    public const SCHEMA_VERSION = 'atlas.ai.compounding.obra_lesson.v1';

    /**
     * @return array<int,array<string,mixed>> um item por lição: lesson + candidate (null em dry-run) + created flag
     */
    public function harvest(string $docPath, bool $dryRun = false): array
    {
        $lessons = $this->extractLessons((string) file_get_contents($docPath));
        $evidenceRefs = array_values(array_filter([$docPath, $this->docCommit($docPath)]));

        $results = [];
        foreach ($lessons as $lesson) {
            $claim = sprintf('NÃO re-propor: %s — %s', $lesson['proposta'], $lesson['motivo']);
            $hash = CompoundingHash::make([
                'schema' => self::SCHEMA_VERSION,
                'doc' => $docPath,
                'claim' => $claim,
            ]);
            $exists = AiLearningCandidate::query()->where('candidate_hash', $hash)->exists();

            $candidate = null;
            if (! $dryRun && ! $exists) {
                // ponytail: mesmo shape/status de quarentena do distiller em 'hold';
                // sem AiRunOutcome porque a fonte é doc, não run (coluna é nullable).
                $payload = [
                    'schema_version' => self::SCHEMA_VERSION,
                    'run_outcome_id' => null,
                    'candidate_hash' => $hash,
                    'status' => 'held_for_evidence',
                    'decision' => 'hold',
                    'memory_type' => 'refutation_memory',
                    'scope' => 'global',
                    'claim' => $claim,
                    'confidence' => 55,
                    'promotion_allowed' => false,
                    'evidence_refs' => $evidenceRefs,
                    'payload' => ['source' => 'obra_doc', 'doc_path' => $docPath, 'lesson' => $lesson],
                    'decided_at' => now(),
                ];
                $payload['receipt_hash'] = CompoundingHash::make($payload);
                $candidate = AiLearningCandidate::query()->create($payload);
            }

            $results[] = [
                'kind' => $lesson['kind'],
                'proposta' => $lesson['proposta'],
                'motivo' => $lesson['motivo'],
                'claim' => $claim,
                'candidate_hash' => $hash,
                'already_exists' => $exists,
                'candidate_id' => $candidate?->id,
                'status' => $candidate?->status,
            ];
        }

        return $results;
    }

    /**
     * @return array<int,array{kind:string,proposta:string,motivo:string}>
     */
    private function extractLessons(string $markdown): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $lessons = [];
        $harvested = [];

        // (a) rows das tabelas sob headings NÃO-FAZER
        $inSection = false;
        foreach ($lines as $i => $line) {
            if (preg_match('/^##\s/u', $line)) {
                $inSection = (bool) preg_match('/^##\s.*N[ÃA]O-FAZER/u', $line);

                continue;
            }
            if (! $inSection || ! str_starts_with(trim($line), '|')) {
                continue;
            }
            $cells = $this->cells($line);
            if ($cells === [] || $this->isHeaderOrSeparator($cells)) {
                continue;
            }
            // 3+ colunas = |ID|Proposta|Por quê|; 2 colunas = |Proposta|Por quê|
            $proposta = count($cells) >= 3 ? $cells[1] : $cells[0];
            $motivo = count($cells) >= 3 ? $cells[2] : ($cells[1] ?? '');
            if ($proposta === '' || $motivo === '') {
                continue;
            }
            $lessons[] = ['kind' => 'nao_fazer', 'proposta' => $proposta, 'motivo' => $motivo];
            $harvested[$i] = true;
        }

        // (b) linhas/células com REFUTADA fora das tabelas já colhidas
        foreach ($lines as $i => $line) {
            if (isset($harvested[$i]) || ! str_contains($line, 'REFUTADA')) {
                continue;
            }
            // linha de tabela: usa a CÉLULA que contém REFUTADA (proposta = 1ª célula)
            if (str_starts_with(trim($line), '|')) {
                $cells = $this->cells($line);
                $cell = collect($cells)->first(fn (string $c): bool => str_contains($c, 'REFUTADA'));
                $text = trim(($cells[0] ?? '').' | '.($cell ?? ''));
            } else {
                $text = $this->clean($line);
            }
            $pos = mb_strpos($text, 'REFUTADA');
            if ($pos === false) {
                continue;
            }
            // trim() com charlist multibyte corrompe UTF-8 — usar regex
            $proposta = (string) preg_replace('/^[\s\-—~|*:⚠️✅]+|[\s\-—~|*:(\[]+$/u', '', (string) mb_substr($text, 0, $pos));
            $proposta = $proposta !== '' ? $proposta : 'proposta refutada';
            $motivo = trim((string) mb_substr($text, $pos));
            $lessons[] = [
                'kind' => 'refutada',
                'proposta' => $this->truncate($proposta, 200),
                'motivo' => $this->truncate($motivo, 400),
            ];
        }

        return $lessons;
    }

    /**
     * @return array<int,string>
     */
    private function cells(string $line): array
    {
        return array_map(fn (string $c): string => $this->clean($c), explode('|', trim(trim($line), '|')));
    }

    /**
     * @param  array<int,string>  $cells
     */
    private function isHeaderOrSeparator(array $cells): bool
    {
        $joined = implode('', $cells);
        if (preg_match('/^[-: ]*$/', $joined)) {
            return true;
        }

        return in_array($cells[0], ['ID', 'Proposta', 'Proposta refutada'], true);
    }

    private function clean(string $text): string
    {
        return trim((string) preg_replace(['/^#+\s+/u', '/(\*\*|~~|`)/u'], '', trim($text)));
    }

    private function truncate(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }

    private function docCommit(string $docPath): ?string
    {
        $result = Process::path(dirname($docPath))
            ->run(['git', 'log', '-1', '--format=%H', '--', $docPath]);
        $hash = trim($result->output());

        return ($result->successful() && $hash !== '') ? 'git:'.$hash : null;
    }
}
