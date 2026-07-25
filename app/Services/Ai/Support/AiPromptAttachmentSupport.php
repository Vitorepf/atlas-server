<?php

declare(strict_types=1);

namespace App\Services\Ai\Support;

use Illuminate\Support\Str;

/**
 * Pure attachment / YouTube / PDF prompt section builders peeled from AiPromptBuilder.
 *
 * No I/O, no DI, no provider calls, no time side effects.
 * Host only supplies already-built options payload maps and file/page arrays.
 */
final class AiPromptAttachmentSupport
{
    /**
     * @param  array<string,mixed>  $options
     */
    public static function attachmentInstructions(array $options, string $input): string
    {
        $images = data_get($options, 'payload.attachments.images', []);
        $files = data_get($options, 'payload.attachments.files', []);
        $images = is_array($images) ? $images : [];
        $files = is_array($files) ? $files : [];

        if ($images === [] && $files === []) {
            return '';
        }

        $lines = ['# Anexos enviados'];

        if ($images !== []) {
            $lines[] = '';
            $lines[] = '## Imagens';
            $lines[] = 'Ha imagem(ns) reais anexadas a esta mensagem pelo Atlas. Analise visualmente o conteudo anexado; nao trate como apenas caminho de arquivo.';

            foreach (array_slice($images, 0, 8) as $index => $image) {
                if (! is_array($image)) {
                    continue;
                }

                $number = $index + 1;
                $mime = is_scalar($image['mime_type'] ?? null) ? (string) $image['mime_type'] : 'image';
                $bytes = is_scalar($image['bytes'] ?? null) ? (string) $image['bytes'] : 'desconhecido';
                $source = is_scalar($image['source'] ?? null) ? (string) $image['source'] : 'upload';
                $lines[] = "- imagem {$number}: {$mime}, {$bytes} bytes, origem {$source}.";
            }
        }

        if ($files !== []) {
            $lines[] = '';
            $lines[] = '## Arquivos';
            $lines[] = 'Use o conteudo textual extraido abaixo como contexto do operador. Se um arquivo nao tiver texto extraido, declare essa lacuna em vez de inventar conteudo.';

            foreach (array_slice($files, 0, 4) as $index => $file) {
                if (! is_array($file)) {
                    continue;
                }

                $number = $index + 1;
                $name = is_scalar($file['original_name'] ?? null) ? (string) $file['original_name'] : "arquivo-{$number}";
                $mime = is_scalar($file['mime_type'] ?? null) ? (string) $file['mime_type'] : 'application/octet-stream';
                $bytes = is_scalar($file['bytes'] ?? null) ? (string) $file['bytes'] : 'desconhecido';
                $excerpt = is_string($file['text_excerpt'] ?? null) ? trim((string) $file['text_excerpt']) : '';
                $truncated = (bool) ($file['text_truncated'] ?? false);
                $pdfPages = is_array($file['pdf_pages'] ?? null) ? $file['pdf_pages'] : [];
                $pdfOcrPages = is_array($file['pdf_ocr_pages'] ?? null) ? $file['pdf_ocr_pages'] : [];
                $lowerName = strtolower($name);
                $isPdf = str_contains(strtolower($mime), 'pdf') || str_ends_with($lowerName, '.pdf');
                $isOffice = str_ends_with($lowerName, '.docx') || str_ends_with($lowerName, '.xlsx') || str_ends_with($lowerName, '.pptx');

                $lines[] = '';
                $lines[] = "<attached_file index=\"{$number}\" name=\"".htmlspecialchars($name, ENT_QUOTES, 'UTF-8').'" mime="'.htmlspecialchars($mime, ENT_QUOTES, 'UTF-8')."\" bytes=\"{$bytes}\">";
                if ($isPdf && $pdfPages !== []) {
                    $pageCount = is_scalar($file['pdf_page_count'] ?? null) ? (string) $file['pdf_page_count'] : 'desconhecido';
                    $processingStatus = is_scalar($file['pdf_processing_status'] ?? null) ? (string) $file['pdf_processing_status'] : 'desconhecido';
                    $renderStatus = is_scalar($file['pdf_render_status'] ?? null) ? (string) $file['pdf_render_status'] : 'desconhecido';
                    $ocrStatus = is_scalar($file['pdf_ocr_status'] ?? null) ? (string) $file['pdf_ocr_status'] : 'desconhecido';
                    $visualStatus = is_scalar($file['pdf_visual_understanding_status'] ?? null) ? (string) $file['pdf_visual_understanding_status'] : 'desconhecido';
                    $visualStrategy = is_scalar($file['pdf_visual_page_strategy'] ?? null) ? (string) $file['pdf_visual_page_strategy'] : 'desconhecido';
                    $visualSelectedPages = is_array($file['pdf_visual_selected_pages'] ?? null) ? $file['pdf_visual_selected_pages'] : [];
                    $lines[] = "[pdf_metadata pages=\"{$pageCount}\" processing=\"{$processingStatus}\" render=\"{$renderStatus}\" ocr=\"{$ocrStatus}\" visual=\"{$visualStatus}\"]";
                    $lines[] = 'Use as paginas abaixo com citacoes tipo "p. 3". Quando houver imagem de pagina anexada ao provider, use a visao da pagina para layout, graficos, assinaturas, tabelas e prints; nao dependa apenas do texto.';
                    $lines[] = 'Ao responder com base neste PDF, cite paginas relevantes. Se a resposta depender de pagina omitida, declare a lacuna antes de concluir.';
                    $map = self::pdfDocumentMap($pdfPages, $visualSelectedPages, $visualStrategy);
                    if ($map !== '') {
                        $lines[] = $map;
                    }

                    $selectedPdfPages = self::selectPdfPagesForPrompt($pdfPages, $input, 36);
                    foreach ($selectedPdfPages as $page) {
                        if (! is_array($page)) {
                            continue;
                        }

                        $pageNumber = is_scalar($page['page'] ?? null) ? (string) $page['page'] : '?';
                        $pageExcerpt = is_string($page['text_excerpt'] ?? null) ? trim($page['text_excerpt']) : '';
                        $classification = is_scalar($page['classification'] ?? null) ? (string) $page['classification'] : 'unknown';
                        $caption = is_string($page['visual_caption'] ?? null) ? trim($page['visual_caption']) : '';
                        $tableExcerpt = is_string($page['table_excerpt'] ?? null) ? trim($page['table_excerpt']) : '';
                        $tableMarkdown = is_string($page['table_markdown'] ?? null) ? trim($page['table_markdown']) : '';
                        $tableConfidence = is_scalar($page['table_confidence'] ?? null) ? (string) $page['table_confidence'] : 'unknown';
                        $imageCount = is_scalar($page['image_count'] ?? null) ? (string) $page['image_count'] : '0';
                        $tableCount = is_scalar($page['table_count'] ?? null) ? (string) $page['table_count'] : '0';
                        $lines[] = "<pdf_page page=\"{$pageNumber}\" classification=\"".htmlspecialchars($classification, ENT_QUOTES, 'UTF-8').'">';
                        if ($caption !== '') {
                            $lines[] = '<visual_caption>'.htmlspecialchars($caption, ENT_QUOTES, 'UTF-8').'</visual_caption>';
                        }
                        $lines[] = "<page_structure images=\"{$imageCount}\" table_like_rows=\"{$tableCount}\" />";
                        if ($tableExcerpt !== '') {
                            $lines[] = "<detected_table_excerpt>\n{$tableExcerpt}\n</detected_table_excerpt>";
                        }
                        if ($tableMarkdown !== '') {
                            $lines[] = '<detected_table_markdown confidence="'.htmlspecialchars($tableConfidence, ENT_QUOTES, 'UTF-8')."\">\n{$tableMarkdown}\n</detected_table_markdown>";
                        }
                        $lines[] = $pageExcerpt !== '' ? $pageExcerpt : '[sem texto nativo extraido nesta pagina]';
                        $lines[] = '</pdf_page>';
                    }

                    foreach (array_slice($pdfOcrPages, 0, 24) as $page) {
                        if (! is_array($page)) {
                            continue;
                        }

                        $pageNumber = is_scalar($page['page'] ?? null) ? (string) $page['page'] : '?';
                        $pageExcerpt = is_string($page['text_excerpt'] ?? null) ? trim($page['text_excerpt']) : '';
                        if ($pageExcerpt === '') {
                            continue;
                        }

                        $lines[] = "<pdf_ocr_page page=\"{$pageNumber}\">";
                        $lines[] = $pageExcerpt;
                        $lines[] = '</pdf_ocr_page>';
                    }

                    if ((bool) ($file['pdf_pages_truncated'] ?? false) || count($pdfPages) > count($selectedPdfPages)) {
                        $selectedNumbers = collect($selectedPdfPages)
                            ->map(fn (mixed $page): mixed => is_array($page) ? ($page['page'] ?? null) : null)
                            ->filter()
                            ->implode(', ');
                        $lines[] = '[prompt compacto com paginas selecionadas: '.$selectedNumbers.'. Use o mapa do PDF para decidir se ha lacuna de pagina.]';
                    }
                } elseif ($isOffice) {
                    $renderStatus = is_scalar($file['office_render_status'] ?? null) ? (string) $file['office_render_status'] : 'desconhecido';
                    $pageCount = is_scalar($file['office_rendered_page_count'] ?? null) ? (string) $file['office_rendered_page_count'] : '0';
                    $processingStatus = is_scalar($file['office_processing_status'] ?? null) ? (string) $file['office_processing_status'] : 'desconhecido';
                    $lines[] = "[office_metadata processing=\"{$processingStatus}\" render=\"{$renderStatus}\" visual_pages=\"{$pageCount}\"]";
                    $lines[] = 'Se houver paginas/slides renderizados como imagem anexada ao provider, use tambem a visao do documento para layout, slides, abas de planilha, graficos e tabelas.';
                    if ($excerpt !== '') {
                        $lines[] = $excerpt;
                        if ($truncated) {
                            $lines[] = '[conteudo truncado pelo Atlas]';
                        }
                    } else {
                        $lines[] = '[sem texto extraido automaticamente deste Office]';
                    }
                } elseif ($excerpt !== '') {
                    $lines[] = $excerpt;
                    if ($truncated) {
                        $lines[] = '[conteudo truncado pelo Atlas]';
                    }
                } else {
                    $lines[] = '[sem texto extraido automaticamente deste arquivo]';
                }
                $lines[] = '</attached_file>';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    public static function youtubeKnowledgeSection(array $options): string
    {
        $videos = data_get($options, 'payload.youtube_ingestion.videos', []);
        if (! is_array($videos) || $videos === []) {
            return '';
        }

        // Canonical 3-status capability · honest signaling of translation gap.
        // `translation_required=true && translation_status != translated_ready`
        // means: we have the original-language transcript but NO translation
        // pipeline ran. The model reads foreign text and responds in pt-BR by
        // inference — that is not the same as translation. Tell the operator.
        $anyTranslationGap = collect($videos)->contains(function (mixed $video): bool {
            if (! is_array($video)) {
                return false;
            }
            $required = (bool) ($video['translation_required'] ?? false);
            $status = (string) ($video['translation_status'] ?? '');

            return $required && $status !== 'translated_ready';
        });

        $lines = [
            '# YouTube ingerido',
            'O operador colou link(s) do YouTube. Use a transcricao com timestamps como fonte primaria do video. Cite timestamps no formato [mm:ss] ou [h:mm:ss] quando usar pontos especificos.',
            'Se a transcricao/caption nao estiver disponivel, declare a lacuna com precisao e nao finja ter visto/ouvido o video inteiro.',
            'REGRA CRITICA: quando o pedido for sobre o conteudo do video e a transcricao estiver indisponivel, e proibido substituir o video por blog post, GitHub README, site oficial, artigos, conhecimento geral ou pesquisa externa, a menos que o operador peca explicitamente fontes externas. Entregue apenas metadados seguros e a lacuna.',
            'Se o status for processing, diga de forma curta que o Atlas esta transcrevendo o audio em background e que o operador pode reenviar o mesmo link em instantes para receber a analise completa.',
            'Se um <youtube_video> tiver status diferente de ready, nao diga "tenho o suficiente para analise" e nao produza analise do conteudo falado.',
            'Idioma de saida: responda em portugues brasileiro quando o operador escrever em portugues. Se o titulo oficial do video estiver em outro idioma, nao use esse titulo cru como heading principal; crie um titulo curto em portugues para a resposta e cite o original separadamente como "Titulo original: ...". Preserve nomes proprios, marcas, produtos e termos tecnicos quando a traducao prejudicar precisao.',
            'Quando o pedido for amplo ("me diga tudo", "analise", "disseca", "me fala sobre", "resuma completo"), entregue uma analise completa e estruturada, nao apenas um resumo curto. Inclua, no minimo: qualidade da fonte/transcricao, resumo executivo, mapa por timestamps, pontos importantes, exemplos demonstrados, implicacoes para o operador/Atlas, candidatos para memoria e proximas acoes concretas. So seja ultra-curto se o operador pedir explicitamente resposta curta.',
        ];

        if ($anyTranslationGap) {
            $lines[] = 'TRADUCAO HONESTA: o transcript esta em idioma estrangeiro e o Atlas NAO possui pipeline de traducao explicita ainda — voce esta lendo o transcript original e respondendo em pt-BR por inferencia. Deixe claro na resposta que a base e o transcript ORIGINAL no idioma de origem (cite o idioma quando souber), nao uma traducao certificada. Nao escreva "traduzi o video para voce" nem "aqui esta a traducao": isso seria mentira sobre o que o Atlas fez.';
        }

        foreach (array_slice($videos, 0, 2) as $index => $video) {
            if (! is_array($video)) {
                continue;
            }

            $number = $index + 1;
            $status = htmlspecialchars((string) ($video['status'] ?? 'unknown'), ENT_QUOTES, 'UTF-8');
            $metadata = is_array($video['metadata'] ?? null) ? $video['metadata'] : [];
            $caption = is_array($video['caption'] ?? null) ? $video['caption'] : [];
            $title = htmlspecialchars((string) ($metadata['title'] ?? 'sem titulo'), ENT_QUOTES, 'UTF-8');
            $channel = htmlspecialchars((string) ($metadata['channel'] ?? 'canal desconhecido'), ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars((string) ($metadata['webpage_url'] ?? $video['url'] ?? ''), ENT_QUOTES, 'UTF-8');
            $duration = is_scalar($metadata['duration_seconds'] ?? null) ? (string) $metadata['duration_seconds'] : 'desconhecida';
            $language = htmlspecialchars((string) ($caption['language'] ?? $metadata['language'] ?? 'desconhecida'), ENT_QUOTES, 'UTF-8');
            $captionKind = htmlspecialchars((string) ($caption['kind'] ?? 'desconhecida'), ENT_QUOTES, 'UTF-8');
            $timestampSource = htmlspecialchars((string) ($caption['timestamp_source'] ?? 'native'), ENT_QUOTES, 'UTF-8');

            $lines[] = '';
            $lines[] = "<youtube_video index=\"{$number}\" status=\"{$status}\" title=\"{$title}\" channel=\"{$channel}\" duration_seconds=\"{$duration}\" language=\"{$language}\" caption_kind=\"{$captionKind}\" timestamp_source=\"{$timestampSource}\" url=\"{$url}\">";
            if ($timestampSource === 'estimated') {
                $lines[] = '<timestamp_note>Transcricao veio de audio/Whisper; timestamps sao estimativas proporcionais, nao marcas nativas do YouTube.</timestamp_note>';
            }
            $chapters = is_array($metadata['chapters'] ?? null) ? $metadata['chapters'] : [];
            if ($chapters !== []) {
                $lines[] = '<official_chapters>';
                foreach (array_slice($chapters, 0, 30) as $chapter) {
                    if (! is_array($chapter)) {
                        continue;
                    }
                    $chapterStart = htmlspecialchars((string) ($chapter['start_label'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $chapterTitle = htmlspecialchars((string) ($chapter['title'] ?? ''), ENT_QUOTES, 'UTF-8');
                    if ($chapterTitle !== '') {
                        $lines[] = "- [{$chapterStart}] {$chapterTitle}";
                    }
                }
                $lines[] = '</official_chapters>';
            }
            $description = is_string($metadata['description_excerpt'] ?? null) ? trim((string) $metadata['description_excerpt']) : '';
            if ($description !== '') {
                $lines[] = '<official_description_excerpt>'.htmlspecialchars($description, ENT_QUOTES, 'UTF-8').'</official_description_excerpt>';
            }

            if (($video['status'] ?? null) !== 'ready') {
                $reason = htmlspecialchars((string) ($video['reason'] ?? 'transcricao indisponivel'), ENT_QUOTES, 'UTF-8');
                $lines[] = "<ingestion_gap>{$reason}</ingestion_gap>";
                $processing = is_array($video['processing'] ?? null) ? $video['processing'] : [];
                if ($processing !== []) {
                    $stage = htmlspecialchars((string) ($processing['stage'] ?? 'processing'), ENT_QUOTES, 'UTF-8');
                    $eta = is_scalar($processing['estimated_remaining_seconds'] ?? null) ? (string) $processing['estimated_remaining_seconds'] : '';
                    $progress = is_scalar($processing['progress'] ?? null) ? (string) $processing['progress'] : '';
                    $lines[] = "<processing_status stage=\"{$stage}\" progress=\"{$progress}\" eta_seconds=\"{$eta}\">transcricao em background</processing_status>";
                }
                $lines[] = '</youtube_video>';

                continue;
            }

            $chunks = is_array($video['chunks'] ?? null) ? $video['chunks'] : [];
            foreach (array_slice($chunks, 0, 80) as $chunk) {
                if (! is_array($chunk)) {
                    continue;
                }

                $chunkIndex = is_scalar($chunk['index'] ?? null) ? (string) $chunk['index'] : '?';
                $start = htmlspecialchars((string) ($chunk['start_label'] ?? ''), ENT_QUOTES, 'UTF-8');
                $end = htmlspecialchars((string) ($chunk['end_label'] ?? ''), ENT_QUOTES, 'UTF-8');
                $text = trim((string) ($chunk['text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                $lines[] = "<transcript_chunk index=\"{$chunkIndex}\" start=\"{$start}\" end=\"{$end}\">";
                $lines[] = $text;
                $lines[] = '</transcript_chunk>';
            }

            $lines[] = '</youtube_video>';
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int,mixed>  $pages
     * @return array<int,array<string,mixed>>
     */
    public static function selectPdfPagesForPrompt(array $pages, string $input, int $limit): array
    {
        $pages = collect($pages)
            ->filter(fn (mixed $page): bool => is_array($page))
            ->values();
        if ($pages->count() <= $limit) {
            return $pages->all();
        }

        $terms = AiPromptTextSupport::keywords($input);
        if ($terms === []) {
            return $pages->take($limit)->all();
        }

        $head = $pages->take(2)->all();
        $headNumbers = collect($head)
            ->map(fn (array $page): int => (int) ($page['page'] ?? 0))
            ->filter()
            ->all();

        $ranked = $pages
            ->reject(fn (array $page): bool => in_array((int) ($page['page'] ?? 0), $headNumbers, true))
            ->map(function (array $page) use ($terms): array {
                $text = Str::of((string) ($page['text_excerpt'] ?? ''))->lower()->ascii()->value();
                $score = 0;
                foreach ($terms as $term) {
                    $score += substr_count($text, $term);
                }

                return [
                    'page' => $page,
                    'score' => $score,
                    'number' => (int) ($page['page'] ?? 0),
                ];
            })
            ->filter(fn (array $item): bool => $item['score'] > 0)
            ->sortByDesc('score')
            ->take(max(0, $limit - count($head)))
            ->pluck('page')
            ->all();

        $selected = [...$head, ...$ranked];
        if (count($selected) < $limit) {
            $selectedNumbers = collect($selected)
                ->map(fn (array $page): int => (int) ($page['page'] ?? 0))
                ->filter()
                ->all();
            $fill = $pages
                ->reject(fn (array $page): bool => in_array((int) ($page['page'] ?? 0), $selectedNumbers, true))
                ->take($limit - count($selected))
                ->all();
            $selected = [...$selected, ...$fill];
        }

        return collect($selected)
            ->sortBy(fn (array $page): int => (int) ($page['page'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * @param  array<int,mixed>  $pages
     * @param  array<int,mixed>  $visualSelectedPages
     */
    public static function pdfDocumentMap(array $pages, array $visualSelectedPages, string $visualStrategy): string
    {
        $pages = collect($pages)
            ->filter(fn (mixed $page): bool => is_array($page))
            ->values();
        if ($pages->isEmpty()) {
            return '';
        }

        $visualPages = collect($visualSelectedPages)
            ->map(fn (mixed $page): int => (int) $page)
            ->filter(fn (int $page): bool => $page > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $scanned = $pages
            ->filter(fn (array $page): bool => in_array((string) ($page['classification'] ?? ''), ['visual_or_scanned', 'sparse'], true))
            ->pluck('page')
            ->filter()
            ->take(24)
            ->implode(', ');
        $tables = $pages
            ->filter(fn (array $page): bool => (int) ($page['table_count'] ?? 0) > 0)
            ->map(fn (array $page): string => 'p. '.(string) ($page['page'] ?? '?').' ('.(string) ($page['table_count'] ?? 0).')')
            ->take(24)
            ->implode(', ');
        $images = $pages
            ->filter(fn (array $page): bool => (int) ($page['image_count'] ?? 0) > 0)
            ->map(fn (array $page): string => 'p. '.(string) ($page['page'] ?? '?').' ('.(string) ($page['image_count'] ?? 0).')')
            ->take(24)
            ->implode(', ');
        $headings = $pages
            ->flatMap(function (array $page): array {
                $structure = is_array($page['structure'] ?? null) ? $page['structure'] : [];
                $candidates = is_array($structure['heading_candidates'] ?? null) ? $structure['heading_candidates'] : [];
                $pageNumber = (string) ($page['page'] ?? '?');

                return collect($candidates)
                    ->filter(fn (mixed $heading): bool => is_scalar($heading) && trim((string) $heading) !== '')
                    ->take(2)
                    ->map(fn (mixed $heading): string => 'p. '.$pageNumber.': '.trim((string) $heading))
                    ->all();
            })
            ->take(16)
            ->implode(' | ');

        $lines = ['<pdf_document_map visual_strategy="'.htmlspecialchars($visualStrategy, ENT_QUOTES, 'UTF-8').'">'];
        if ($visualPages !== []) {
            $lines[] = '<visual_pages>'.implode(', ', $visualPages).'</visual_pages>';
        }
        if ($scanned !== '') {
            $lines[] = '<scanned_or_sparse_pages>'.$scanned.'</scanned_or_sparse_pages>';
        }
        if ($tables !== '') {
            $lines[] = '<table_like_pages>'.$tables.'</table_like_pages>';
        }
        if ($images !== '') {
            $lines[] = '<image_or_chart_pages>'.$images.'</image_or_chart_pages>';
        }
        if ($headings !== '') {
            $lines[] = '<heading_candidates>'.htmlspecialchars($headings, ENT_QUOTES, 'UTF-8').'</heading_candidates>';
        }
        $lines[] = '</pdf_document_map>';

        return implode("\n", $lines);
    }
}
