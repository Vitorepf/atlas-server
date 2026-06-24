<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * ProofProvenanceAuditor — the structural guard of the Conversion OS's ONE moral line.
 *
 * The única linha de não-cruzar (canonical, repeated by the operator): persuade YES, fabricate false
 * evidence NO — no before/after of someone who never used it, no testimonial/endorsement attributed to a
 * real person who never said it, no invented study/statistic cited as real. Real proof comes from the
 * producer (TransformationStudio), never from the copy engine.
 *
 * No instrument in the OS surfaced WHICH claims a page makes that REQUIRE real backing before launch.
 * This does: it extracts the specific ATTRIBUTION claims (named authority, media mention, attributed
 * testimonial-with-result, cited statistic) and lists each as "requires real provenance". It is a
 * pre-launch CHECKLIST, not a fabrication detector (deterministically it cannot know if a real Dr. X
 * exists) and not a quality score — so it cannot be gamed: it only ever says "these specific claims must
 * be backed by real evidence from the producer." Structural truth: the claim is present in the text or
 * it is not. Provider-free, niche-agnostic. Warnings-only, like the leak detector.
 */
class ProofProvenanceAuditor
{
    /** Each: key, human name, and the patterns whose presence asserts something that needs real backing. */
    private const CLAIMS = [
        [
            'key' => 'named_authority',
            'name' => 'Autoridade nomeada',
            'need' => 'a pessoa/instituição precisa existir e ter de fato endossado — senão é endosso fabricado.',
            'patterns' => [
                '/\b[Dd][Rr]\.?\s+\p{Lu}[\p{L}]+/u', '/\b(?i:prof)(?:\.|essor)\s+\p{Lu}[\p{L}]+/u',
                '/\b\p{Lu}[\p{L}]+\s+(?:university|institute|hospital|clinic|universidade|instituto|hospital|clínica)\b/u',
                '/\b(?:harvard|stanford|yale|mit|mayo clinic|cleveland clinic|johns hopkins|nih|fda|usp|unicamp)\b/iu',
            ],
        ],
        [
            'key' => 'media_mention',
            'name' => 'Menção de mídia ("as seen on")',
            'need' => 'a veiculação tem que ter acontecido de verdade — logo de mídia falso é fabricação + risco de conta.',
            'patterns' => [
                '/\bas seen (?:on|in)\b/iu', '/\bas featured (?:on|in)\b/iu', '/\bvisto n[ao]\b/iu', '/\bdestaque n[ao]\b/iu',
                '/\b(?:forbes|cnn|bbc|nbc|cbs|abc news|the new york times|globo|veja|exame|bloomberg|wall street journal)\b/iu',
            ],
        ],
        [
            'key' => 'attributed_testimonial',
            'name' => 'Depoimento atribuído com resultado',
            'need' => 'a pessoa e o resultado têm que ser reais (depoimento verificável do produtor), nunca inventados.',
            'patterns' => [
                '/[“"][^”"]{12,}[”"]\s*[—\-–]\s*\p{Lu}[\p{L}]+/u',     // "quote" — Name
                '/\b(?:lost|dropped|made|earned|perdi|perdeu|ganhei|ganhou|faturei)\b[^.\n]{0,40}?[—\-–]\s*\p{Lu}[\p{L}]+/iu',
                '/[—\-–]\s*\p{Lu}[\p{L}]+,\s*\d{1,2}\b/u',            // — Name, 42  (testimonial signature)
            ],
        ],
        [
            'key' => 'cited_statistic',
            'name' => 'Estatística citada como fato',
            'need' => 'o estudo/estatística precisa existir e ser citável — número inventado apresentado como pesquisa é fabricação.',
            'patterns' => [
                '/\b(?:studies?|research|a study|estudos?|pesquisa)\b[^.\n]{0,40}?\b\d/iu',
                '/\b\d{1,3}\s?%[^.\n]{0,30}?\b(?:of (?:women|men|people|patients|users)|das (?:mulheres|pessoas|pacientes))/iu',
                '/\b(?:clinically proven|scientifically proven|clinicamente comprovado|cientificamente comprovado)\b/iu',
            ],
        ],
    ];

    /**
     * A claim is "anchored" when the copy carries at least one concrete, checkable specific (a year, a
     * full date, a journal/DOI/URL, or a multi-digit figure). A BARE claim — an assertion with nothing
     * verifiable anywhere — is the highest fabrication/compliance risk: there is literally nothing to
     * check it against, so it must be backed by real producer evidence or cut first.
     */
    private const ANCHOR = '/\b(?:19|20)\d{2}\b|\bdoi\b|https?:\/\/|\b\d{1,2}\/\d{1,2}\/\d{2,4}\b|\b\d{3,}\b|\bvol\.?\s?\d|\bn[ºo]\.?\s?\d|\bjournal\b|\bpublished in\b|\bpeer[- ]reviewed\b/iu';

    /**
     * @return array{requires_proof:array<int,array{key:string,name:string,need:string,evidence:string,anchored:bool}>,count:int,bare_count:int,clean:bool}
     */
    public function audit(string $copy): array
    {
        $text = (string) $copy;
        $anchored = preg_match(self::ANCHOR, $text) === 1;
        $found = [];
        foreach (self::CLAIMS as $claim) {
            foreach ($claim['patterns'] as $re) {
                if (preg_match($re, $text, $m) === 1) {
                    $found[] = [
                        'key' => $claim['key'],
                        'name' => $claim['name'],
                        'need' => $claim['need'],
                        'evidence' => trim(mb_strimwidth((string) $m[0], 0, 60, '…')),
                        'anchored' => $anchored,
                    ];
                    break; // one hit per claim type is enough to flag "needs backing"
                }
            }
        }
        // Bare claims (no checkable anchor in the copy) sort first — most urgent to back or cut.
        usort($found, static fn ($a, $b): int => ($a['anchored'] ? 1 : 0) <=> ($b['anchored'] ? 1 : 0));
        $bare = count(array_filter($found, static fn ($f): bool => ! $f['anchored']));

        return ['requires_proof' => $found, 'count' => count($found), 'bare_count' => $bare, 'clean' => $found === []];
    }
}
