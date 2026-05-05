<?php

namespace App\Services\Ai\Cli\Repl;

/**
 * Estado puro do composer do REPL: texto + cursor + imagens + seleção.
 *
 * Domain object sem dependencia de TTY ou IO. Tudo testavel com inputs/outputs
 * diretos. UTF-8 first-class via mb_*. Cursor e em chars (codepoints), nao bytes.
 */
class ReplComposer
{
    private const UNDO_HISTORY_LIMIT = 100;

    private string $text = '';

    private int $cursor = 0;

    /** @var array<int, array<string,mixed>> */
    private array $images = [];

    private ?int $selectedImageIndex = null;

    /** @var array<int, array<string,mixed>> */
    private array $undoStack = [];

    /** @var array<int, array<string,mixed>> */
    private array $redoStack = [];

    private ?int $selectionAnchor = null;

    public function text(): string
    {
        return $this->text;
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    /** @return array<int, array<string,mixed>> */
    public function images(): array
    {
        return $this->images;
    }

    public function selectedImageIndex(): ?int
    {
        return $this->selectedImageIndex;
    }

    public function isImageSelectionActive(): bool
    {
        return $this->selectedImageIndex !== null;
    }

    public function hasImages(): bool
    {
        return $this->images !== [];
    }

    public function isEmpty(): bool
    {
        return $this->text === '' && $this->images === [];
    }

    public function textLength(): int
    {
        return mb_strlen($this->text);
    }

    public function textBefore(): string
    {
        return mb_substr($this->text, 0, $this->cursor);
    }

    public function textAfter(): string
    {
        return mb_substr($this->text, $this->cursor);
    }

    public function setText(string $text): self
    {
        $this->text = $text;
        $length = mb_strlen($text);
        if ($this->cursor > $length) {
            $this->cursor = $length;
        }
        $this->clearImageSelection();
        $this->clearTextSelection();

        return $this;
    }

    public function selectionAnchor(): ?int
    {
        return $this->selectionAnchor;
    }

    public function hasTextSelection(): bool
    {
        return $this->selectionAnchor !== null && $this->selectionAnchor !== $this->cursor;
    }

    /** @return array{0:int,1:int}|null */
    public function selectionRange(): ?array
    {
        if (! $this->hasTextSelection()) {
            return null;
        }
        $start = min($this->selectionAnchor, $this->cursor);
        $end = max($this->selectionAnchor, $this->cursor);

        return [$start, $end];
    }

    public function selectAll(): self
    {
        $this->clearImageSelection();
        $length = $this->textLength();
        if ($length === 0) {
            $this->selectionAnchor = null;

            return $this;
        }
        $this->selectionAnchor = 0;
        $this->cursor = $length;

        return $this;
    }

    public function selectLine(): self
    {
        return $this->selectAll();
    }

    public function clearTextSelection(): self
    {
        $this->selectionAnchor = null;

        return $this;
    }

    public function deleteSelection(): bool
    {
        $range = $this->selectionRange();
        if ($range === null) {
            return false;
        }
        [$start, $end] = $range;
        $this->text = mb_substr($this->text, 0, $start).mb_substr($this->text, $end);
        $this->cursor = $start;
        $this->selectionAnchor = null;

        return true;
    }

    public function extendSelectionLeft(): self
    {
        if ($this->selectionAnchor === null) {
            $this->selectionAnchor = $this->cursor;
        }
        if ($this->cursor > 0) {
            $this->cursor--;
        }

        return $this;
    }

    public function extendSelectionRight(): self
    {
        if ($this->selectionAnchor === null) {
            $this->selectionAnchor = $this->cursor;
        }
        if ($this->cursor < $this->textLength()) {
            $this->cursor++;
        }

        return $this;
    }

    public function extendSelectionWordLeft(): self
    {
        if ($this->selectionAnchor === null) {
            $this->selectionAnchor = $this->cursor;
        }
        $this->cursor = $this->wordBoundaryBefore($this->cursor);

        return $this;
    }

    public function extendSelectionWordRight(): self
    {
        if ($this->selectionAnchor === null) {
            $this->selectionAnchor = $this->cursor;
        }
        $this->cursor = $this->wordBoundaryAfter($this->cursor);

        return $this;
    }

    public function extendSelectionToLineStart(): self
    {
        if ($this->selectionAnchor === null) {
            $this->selectionAnchor = $this->cursor;
        }
        $this->cursor = 0;

        return $this;
    }

    public function extendSelectionToLineEnd(): self
    {
        if ($this->selectionAnchor === null) {
            $this->selectionAnchor = $this->cursor;
        }
        $this->cursor = $this->textLength();

        return $this;
    }

    public function insertChar(string $char): self
    {
        if ($this->isImageSelectionActive()) {
            $this->clearImageSelection();
        }
        if ($this->hasTextSelection()) {
            $this->deleteSelection();
        }
        $this->text = mb_substr($this->text, 0, $this->cursor).$char.mb_substr($this->text, $this->cursor);
        $this->cursor += mb_strlen($char);

        return $this;
    }

    public function insertText(string $text): self
    {
        if ($text === '') {
            return $this;
        }

        return $this->insertChar($text);
    }

    public function moveCursorLeft(): self
    {
        if ($this->isImageSelectionActive()) {
            return $this->moveImageSelection(-1);
        }
        if ($this->hasTextSelection()) {
            [$start] = $this->selectionRange();
            $this->cursor = $start;
            $this->selectionAnchor = null;

            return $this;
        }
        if ($this->cursor > 0) {
            $this->cursor--;
        }

        return $this;
    }

    public function moveCursorRight(): self
    {
        if ($this->isImageSelectionActive()) {
            return $this->moveImageSelection(1);
        }
        if ($this->hasTextSelection()) {
            [, $end] = $this->selectionRange();
            $this->cursor = $end;
            $this->selectionAnchor = null;

            return $this;
        }
        if ($this->cursor < $this->textLength()) {
            $this->cursor++;
        }

        return $this;
    }

    public function moveCursorUp(): self
    {
        if ($this->isImageSelectionActive()) {
            return $this;
        }
        if ($this->images === []) {
            return $this;
        }
        $this->selectedImageIndex = count($this->images) - 1;

        return $this;
    }

    public function moveCursorDown(): self
    {
        if ($this->isImageSelectionActive()) {
            $this->clearImageSelection();
        }

        return $this;
    }

    public function moveCursorToLineStart(): self
    {
        if ($this->isImageSelectionActive()) {
            $this->clearImageSelection();
        }
        $this->cursor = 0;
        $this->selectionAnchor = null;

        return $this;
    }

    public function moveCursorToLineEnd(): self
    {
        if ($this->isImageSelectionActive()) {
            $this->clearImageSelection();
        }
        $this->cursor = $this->textLength();
        $this->selectionAnchor = null;

        return $this;
    }

    public function moveCursorWordLeft(): self
    {
        if ($this->isImageSelectionActive()) {
            return $this->moveImageSelection(-1);
        }
        $this->cursor = $this->wordBoundaryBefore($this->cursor);
        $this->selectionAnchor = null;

        return $this;
    }

    public function moveCursorWordRight(): self
    {
        if ($this->isImageSelectionActive()) {
            return $this->moveImageSelection(1);
        }
        $this->cursor = $this->wordBoundaryAfter($this->cursor);
        $this->selectionAnchor = null;

        return $this;
    }

    public function deleteCharBefore(): self
    {
        if ($this->isImageSelectionActive()) {
            return $this->removeSelectedImage();
        }
        if ($this->hasTextSelection()) {
            $this->deleteSelection();

            return $this;
        }
        if ($this->cursor === 0) {
            if ($this->images !== []) {
                $this->selectedImageIndex = count($this->images) - 1;

                return $this->removeSelectedImage();
            }

            return $this;
        }
        $this->text = mb_substr($this->text, 0, $this->cursor - 1).mb_substr($this->text, $this->cursor);
        $this->cursor--;

        return $this;
    }

    public function deleteCharAfter(): self
    {
        if ($this->isImageSelectionActive()) {
            return $this->removeSelectedImage();
        }
        if ($this->hasTextSelection()) {
            $this->deleteSelection();

            return $this;
        }
        $length = $this->textLength();
        if ($this->cursor >= $length) {
            return $this;
        }
        $this->text = mb_substr($this->text, 0, $this->cursor).mb_substr($this->text, $this->cursor + 1);

        return $this;
    }

    public function deleteWordBefore(): self
    {
        if ($this->isImageSelectionActive()) {
            return $this->removeSelectedImage();
        }
        if ($this->cursor === 0) {
            return $this;
        }
        $boundary = $this->wordBoundaryBefore($this->cursor);
        $this->text = mb_substr($this->text, 0, $boundary).mb_substr($this->text, $this->cursor);
        $this->cursor = $boundary;

        return $this;
    }

    public function clearLine(): self
    {
        if ($this->isImageSelectionActive()) {
            $this->clearImageSelection();
        }
        $this->text = '';
        $this->cursor = 0;
        $this->selectionAnchor = null;

        return $this;
    }

    /**
     * Anexa uma imagem se ainda nao existe outra com o mesmo SHA256.
     *
     * @param  array<string,mixed>  $image
     * @return bool true se foi adicionada, false se ja existia (duplicata).
     */
    public function attachImage(array $image): bool
    {
        $this->clearImageSelection();
        $sha256 = is_string($image['sha256'] ?? null) ? $image['sha256'] : null;
        if ($sha256 !== null && $this->hasImageWithSha($sha256)) {
            return false;
        }
        $this->images[] = $image;

        return true;
    }

    /**
     * @param  array<int, array<string,mixed>>  $images
     * @return array{added:int,skipped:int}
     */
    public function attachImages(array $images): array
    {
        $added = 0;
        $skipped = 0;
        foreach ($images as $image) {
            if (! is_array($image)) {
                continue;
            }
            if ($this->attachImage($image)) {
                $added++;
            } else {
                $skipped++;
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    public function hasImageWithSha(string $sha256): bool
    {
        foreach ($this->images as $existing) {
            if (is_array($existing) && ($existing['sha256'] ?? null) === $sha256) {
                return true;
            }
        }

        return false;
    }

    public function removeImageAt(int $index): ?array
    {
        if ($this->images === []) {
            return null;
        }
        if ($index < 0) {
            $index = count($this->images) + $index;
        }
        if ($index < 0 || $index >= count($this->images)) {
            return null;
        }
        $removed = $this->images[$index];
        array_splice($this->images, $index, 1);
        $this->reconcileSelectionAfterRemoval($index);

        return $removed;
    }

    public function removeLastImage(): ?array
    {
        return $this->removeImageAt(-1);
    }

    public function removeSelectedImage(): self
    {
        if ($this->selectedImageIndex === null) {
            return $this;
        }
        $this->removeImageAt($this->selectedImageIndex);

        return $this;
    }

    public function clearAllImages(): self
    {
        $this->images = [];
        $this->clearImageSelection();

        return $this;
    }

    public function selectImage(int $index): self
    {
        if ($this->images === []) {
            $this->selectedImageIndex = null;

            return $this;
        }
        if ($index < 0) {
            $index = count($this->images) + $index;
        }
        $index = max(0, min(count($this->images) - 1, $index));
        $this->selectedImageIndex = $index;

        return $this;
    }

    public function moveImageSelection(int $delta): self
    {
        if ($this->selectedImageIndex === null) {
            return $this;
        }
        $next = $this->selectedImageIndex + $delta;
        if ($next < 0) {
            $this->selectedImageIndex = 0;

            return $this;
        }
        if ($next >= count($this->images)) {
            $this->clearImageSelection();
            $this->cursor = 0;

            return $this;
        }
        $this->selectedImageIndex = $next;

        return $this;
    }

    public function clearImageSelection(): self
    {
        $this->selectedImageIndex = null;

        return $this;
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'text' => $this->text,
            'cursor' => $this->cursor,
            'images' => $this->images,
            'selected_image_index' => $this->selectedImageIndex,
        ];
    }

    public function checkpoint(): self
    {
        $this->undoStack[] = $this->snapshot();
        if (count($this->undoStack) > self::UNDO_HISTORY_LIMIT) {
            array_shift($this->undoStack);
        }
        $this->redoStack = [];

        return $this;
    }

    public function undo(): bool
    {
        if ($this->undoStack === []) {
            return false;
        }
        $this->redoStack[] = $this->snapshot();
        $this->restoreFromSnapshot(array_pop($this->undoStack));

        return true;
    }

    public function redo(): bool
    {
        if ($this->redoStack === []) {
            return false;
        }
        $this->undoStack[] = $this->snapshot();
        $this->restoreFromSnapshot(array_pop($this->redoStack));

        return true;
    }

    public function canUndo(): bool
    {
        return $this->undoStack !== [];
    }

    public function canRedo(): bool
    {
        return $this->redoStack !== [];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function restoreFromSnapshot(array $snapshot): void
    {
        $this->text = is_string($snapshot['text'] ?? null) ? $snapshot['text'] : '';
        $this->cursor = is_int($snapshot['cursor'] ?? null) ? $snapshot['cursor'] : 0;
        $this->images = is_array($snapshot['images'] ?? null) ? $snapshot['images'] : [];
        $this->selectedImageIndex = $snapshot['selected_image_index'] ?? null;
        if (! is_int($this->selectedImageIndex)) {
            $this->selectedImageIndex = null;
        }
    }

    private function reconcileSelectionAfterRemoval(int $removedIndex): void
    {
        if ($this->selectedImageIndex === null) {
            return;
        }
        if ($this->images === []) {
            $this->selectedImageIndex = null;

            return;
        }
        if ($removedIndex === $this->selectedImageIndex) {
            $this->selectedImageIndex = min($this->selectedImageIndex, count($this->images) - 1);

            return;
        }
        if ($removedIndex < $this->selectedImageIndex) {
            $this->selectedImageIndex--;
        }
    }

    private function wordBoundaryBefore(int $position): int
    {
        $i = $position;
        while ($i > 0 && $this->isWhitespaceAt($i - 1)) {
            $i--;
        }
        while ($i > 0 && ! $this->isWhitespaceAt($i - 1)) {
            $i--;
        }

        return $i;
    }

    private function wordBoundaryAfter(int $position): int
    {
        $length = $this->textLength();
        $i = $position;
        while ($i < $length && ! $this->isWhitespaceAt($i)) {
            $i++;
        }
        while ($i < $length && $this->isWhitespaceAt($i)) {
            $i++;
        }

        return $i;
    }

    private function isWhitespaceAt(int $position): bool
    {
        $char = mb_substr($this->text, $position, 1);

        return $char === '' || preg_match('/\s/u', $char) === 1;
    }
}
