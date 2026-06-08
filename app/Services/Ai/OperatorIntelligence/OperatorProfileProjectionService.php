<?php

namespace App\Services\Ai\OperatorIntelligence;

use App\Models\OperatorLearningCandidate;
use App\Models\OperatorProfileItem;
use Illuminate\Support\Facades\File;

class OperatorProfileProjectionService
{
    public function __construct(
        private readonly OperatorProfileDigestService $digest,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function project(string $operatorId): array
    {
        $base = rtrim((string) config('atlas_operator_intelligence.projection_path'), '/').'/'.$this->operatorHash($operatorId);
        File::ensureDirectoryExists($base);

        $profilePath = $base.'/profile.md';
        $queuePath = $base.'/review-queue.md';
        $digestPath = $base.'/weekly-digest.md';

        File::put($profilePath, $this->profileMarkdown($operatorId));
        File::put($queuePath, $this->queueMarkdown($operatorId));
        File::put($digestPath, $this->digestMarkdown($operatorId));

        return [
            'ok' => true,
            'operator_id' => $operatorId,
            'operator_hash' => basename($base),
            'paths' => [
                'profile' => $profilePath,
                'review_queue' => $queuePath,
                'weekly_digest' => $digestPath,
            ],
            'safety' => [
                'repo_path' => false,
                'raw_private_context_included' => false,
            ],
        ];
    }

    private function profileMarkdown(string $operatorId): string
    {
        $items = OperatorProfileItem::query()
            ->where('operator_id', $operatorId)
            ->active()
            ->orderBy('profile_key')
            ->get();

        $lines = [
            '# Operator Profile Projection',
            '',
            'Generated projection. Postgres remains the operational source of truth.',
            '',
        ];

        foreach ($items as $item) {
            $lines[] = '- `'.$item->profile_key.'` ['.$item->taxonomy_item_id.'] '.$this->safeSummary($item);
        }

        return implode("\n", $lines)."\n";
    }

    private function queueMarkdown(string $operatorId): string
    {
        $candidates = OperatorLearningCandidate::query()
            ->where('operator_id', $operatorId)
            ->pending()
            ->latest()
            ->limit(100)
            ->get();

        $lines = ['# Operator Learning Review Queue', ''];
        foreach ($candidates as $candidate) {
            $lines[] = '- `'.$candidate->id.'` ['.$candidate->taxonomy_item_id.'] '.$this->safeClaim($candidate);
        }

        return implode("\n", $lines)."\n";
    }

    private function digestMarkdown(string $operatorId): string
    {
        $digest = $this->digest->digest(
            $operatorId,
            (int) config('atlas_operator_intelligence.digest_recent_days', 7),
            persistSnapshot: false,
        );

        $lines = [
            '# Operator Intelligence Digest',
            '',
            (string) $digest['summary'],
            '',
            '## Top Items',
            '',
        ];

        foreach ((array) $digest['top_profile_items'] as $item) {
            $lines[] = '- `'.($item['profile_key'] ?? 'unknown').'` ['.($item['taxonomy_item_id'] ?? 'unknown').'] '.($item['summary'] ?? '');
        }

        return implode("\n", $lines)."\n";
    }

    private function operatorHash(string $operatorId): string
    {
        return hash('sha256', $operatorId);
    }

    private function safeSummary(OperatorProfileItem $item): string
    {
        if (in_array($item->privacy_class, ['sensitive', 'secret'], true)) {
            return '[redacted '.$item->privacy_class.']';
        }

        return $item->summary;
    }

    private function safeClaim(OperatorLearningCandidate $candidate): string
    {
        $signal = $candidate->signal;
        if ($signal && in_array($signal->privacy_class, ['sensitive', 'secret'], true)) {
            return '[redacted '.$signal->privacy_class.']';
        }

        return $candidate->claim;
    }
}
