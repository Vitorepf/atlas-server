<?php

namespace Tests\Unit\Ai\Cli\Repl;

use App\Services\Ai\Cli\Repl\ReplComposer;
use Tests\TestCase;

class ReplComposerTest extends TestCase
{
    public function test_inserts_chars_and_advances_cursor(): void
    {
        $c = new ReplComposer();
        $c->insertChar('a')->insertChar('b')->insertChar('c');

        $this->assertSame('abc', $c->text());
        $this->assertSame(3, $c->cursor());
    }

    public function test_inserts_handles_utf8_codepoints_correctly(): void
    {
        $c = new ReplComposer();
        $c->insertChar('á');
        $c->insertChar('é');
        $c->insertChar('🚀');

        $this->assertSame('áé🚀', $c->text());
        $this->assertSame(3, $c->cursor(), 'Cursor deve contar codepoints, nao bytes.');
        $this->assertSame(3, $c->textLength());
    }

    public function test_moves_cursor_left_and_right_within_bounds(): void
    {
        $c = (new ReplComposer())->insertText('hello');
        $c->moveCursorLeft()->moveCursorLeft();
        $this->assertSame(3, $c->cursor());

        $c->moveCursorRight();
        $this->assertSame(4, $c->cursor());

        for ($i = 0; $i < 10; $i++) {
            $c->moveCursorRight();
        }
        $this->assertSame(5, $c->cursor(), 'Nao deve passar do fim.');

        for ($i = 0; $i < 100; $i++) {
            $c->moveCursorLeft();
        }
        $this->assertSame(0, $c->cursor(), 'Nao deve passar do inicio.');
    }

    public function test_moves_cursor_to_line_start_and_end(): void
    {
        $c = (new ReplComposer())->insertText('hello');
        $c->moveCursorToLineStart();
        $this->assertSame(0, $c->cursor());
        $c->moveCursorToLineEnd();
        $this->assertSame(5, $c->cursor());
    }

    public function test_word_boundary_navigation(): void
    {
        $c = (new ReplComposer())->insertText('foo bar baz');
        $this->assertSame(11, $c->cursor());

        $c->moveCursorWordLeft();
        $this->assertSame(8, $c->cursor(), 'Word left deve parar no inicio de "baz".');

        $c->moveCursorWordLeft();
        $this->assertSame(4, $c->cursor());

        $c->moveCursorWordLeft();
        $this->assertSame(0, $c->cursor());

        $c->moveCursorWordRight();
        $this->assertSame(4, $c->cursor());

        $c->moveCursorWordRight();
        $this->assertSame(8, $c->cursor());

        $c->moveCursorWordRight();
        $this->assertSame(11, $c->cursor());
    }

    public function test_delete_char_before_at_middle_of_text(): void
    {
        $c = (new ReplComposer())->insertText('abc');
        $c->moveCursorLeft();
        $c->deleteCharBefore();

        $this->assertSame('ac', $c->text());
        $this->assertSame(1, $c->cursor());
    }

    public function test_delete_char_after_at_middle_of_text(): void
    {
        $c = (new ReplComposer())->insertText('abc');
        $c->moveCursorToLineStart();
        $c->deleteCharAfter();

        $this->assertSame('bc', $c->text());
        $this->assertSame(0, $c->cursor());
    }

    public function test_delete_word_before_with_trailing_spaces(): void
    {
        $c = (new ReplComposer())->insertText('foo bar baz');
        $c->deleteWordBefore();
        $this->assertSame('foo bar ', $c->text());
        $c->deleteWordBefore();
        $this->assertSame('foo ', $c->text());
        $c->deleteWordBefore();
        $this->assertSame('', $c->text());
        $c->deleteWordBefore();
        $this->assertSame('', $c->text(), 'No-op em texto vazio.');
    }

    public function test_clear_line_resets_text_and_cursor_only(): void
    {
        $c = new ReplComposer();
        $c->insertText('algum texto');
        $c->attachImage(['path' => '/tmp/a.png']);
        $c->clearLine();

        $this->assertSame('', $c->text());
        $this->assertSame(0, $c->cursor());
        $this->assertCount(1, $c->images(), 'Imagens devem ser preservadas.');
    }

    public function test_attach_and_remove_images(): void
    {
        $c = new ReplComposer();
        $c->attachImage(['path' => '/tmp/a.png', 'sha256' => 'aaa']);
        $c->attachImage(['path' => '/tmp/b.png', 'sha256' => 'bbb']);
        $c->attachImage(['path' => '/tmp/c.png', 'sha256' => 'ccc']);

        $this->assertCount(3, $c->images());
        $removed = $c->removeImageAt(1);
        $this->assertSame('/tmp/b.png', $removed['path']);
        $this->assertCount(2, $c->images());
        $this->assertSame('/tmp/a.png', $c->images()[0]['path']);
        $this->assertSame('/tmp/c.png', $c->images()[1]['path']);

        $c->removeLastImage();
        $this->assertCount(1, $c->images());
        $this->assertSame('/tmp/a.png', $c->images()[0]['path']);

        $c->clearAllImages();
        $this->assertSame([], $c->images());
    }

    public function test_attach_image_dedupes_by_sha256(): void
    {
        $c = new ReplComposer();
        $first = ['path' => '/tmp/a.png', 'sha256' => 'duplicado'];
        $duplicate = ['path' => '/tmp/copy-of-a.png', 'sha256' => 'duplicado'];

        $this->assertTrue($c->attachImage($first));
        $this->assertFalse($c->attachImage($duplicate), 'Imagem com mesmo SHA256 deve ser ignorada.');
        $this->assertCount(1, $c->images());
        $this->assertSame('/tmp/a.png', $c->images()[0]['path']);
    }

    public function test_attach_images_returns_added_and_skipped_counts(): void
    {
        $c = new ReplComposer();
        $c->attachImage(['path' => '/tmp/a.png', 'sha256' => 'aaa']);

        $result = $c->attachImages([
            ['path' => '/tmp/b.png', 'sha256' => 'bbb'],
            ['path' => '/tmp/a-clone.png', 'sha256' => 'aaa'],
            ['path' => '/tmp/c.png', 'sha256' => 'ccc'],
        ]);

        $this->assertSame(2, $result['added']);
        $this->assertSame(1, $result['skipped']);
        $this->assertCount(3, $c->images());
    }

    public function test_remove_image_at_negative_index_targets_from_end(): void
    {
        $c = new ReplComposer();
        $c->attachImage(['path' => '/tmp/a.png']);
        $c->attachImage(['path' => '/tmp/b.png']);

        $removed = $c->removeImageAt(-1);
        $this->assertSame('/tmp/b.png', $removed['path']);
        $this->assertCount(1, $c->images());
    }

    public function test_remove_image_at_invalid_index_is_noop(): void
    {
        $c = new ReplComposer();
        $c->attachImage(['path' => '/tmp/a.png']);

        $this->assertNull($c->removeImageAt(5));
        $this->assertCount(1, $c->images());
        $this->assertNull($c->removeImageAt(-99));
        $this->assertCount(1, $c->images());
    }

    public function test_up_arrow_enters_image_selection_mode(): void
    {
        $c = new ReplComposer();
        $c->attachImage(['path' => '/tmp/a.png']);
        $c->attachImage(['path' => '/tmp/b.png']);

        $this->assertFalse($c->isImageSelectionActive());
        $c->moveCursorUp();
        $this->assertTrue($c->isImageSelectionActive());
        $this->assertSame(1, $c->selectedImageIndex(), 'Deve selecionar a ultima imagem ao subir.');
    }

    public function test_up_arrow_with_no_images_is_noop(): void
    {
        $c = (new ReplComposer())->insertText('abc');
        $c->moveCursorUp();

        $this->assertFalse($c->isImageSelectionActive());
        $this->assertSame(3, $c->cursor());
    }

    public function test_down_arrow_exits_image_selection(): void
    {
        $c = new ReplComposer();
        $c->attachImage(['path' => '/tmp/a.png']);
        $c->moveCursorUp();
        $this->assertTrue($c->isImageSelectionActive());

        $c->moveCursorDown();
        $this->assertFalse($c->isImageSelectionActive());
    }

    public function test_left_right_navigates_between_images_in_selection_mode(): void
    {
        $c = new ReplComposer();
        $c->attachImage(['path' => '/tmp/a.png']);
        $c->attachImage(['path' => '/tmp/b.png']);
        $c->attachImage(['path' => '/tmp/c.png']);
        $c->moveCursorUp();

        $this->assertSame(2, $c->selectedImageIndex());

        $c->moveCursorLeft();
        $this->assertSame(1, $c->selectedImageIndex());

        $c->moveCursorLeft();
        $this->assertSame(0, $c->selectedImageIndex());

        $c->moveCursorLeft();
        $this->assertSame(0, $c->selectedImageIndex(), 'No-op no inicio.');

        $c->moveCursorRight();
        $this->assertSame(1, $c->selectedImageIndex());
    }

    public function test_right_past_last_image_exits_selection(): void
    {
        $c = new ReplComposer();
        $c->insertText('algum texto');
        $c->attachImage(['path' => '/tmp/a.png']);
        $c->moveCursorUp();
        $this->assertTrue($c->isImageSelectionActive());

        $c->moveCursorRight();
        $this->assertFalse($c->isImageSelectionActive());
        $this->assertSame(0, $c->cursor());
    }

    public function test_backspace_in_selection_mode_removes_selected_image(): void
    {
        $c = new ReplComposer();
        $c->attachImage(['path' => '/tmp/a.png']);
        $c->attachImage(['path' => '/tmp/b.png']);
        $c->moveCursorUp();
        $c->moveCursorLeft();

        $this->assertSame(0, $c->selectedImageIndex());
        $c->deleteCharBefore();

        $this->assertCount(1, $c->images());
        $this->assertSame('/tmp/b.png', $c->images()[0]['path']);
    }

    public function test_typing_char_cancels_selection_and_inserts(): void
    {
        $c = new ReplComposer();
        $c->insertText('abc');
        $c->attachImage(['path' => '/tmp/a.png']);
        $c->moveCursorUp();
        $this->assertTrue($c->isImageSelectionActive());

        $c->insertChar('x');

        $this->assertFalse($c->isImageSelectionActive());
        $this->assertSame('abcx', $c->text());
        $this->assertSame(4, $c->cursor());
    }

    public function test_backspace_on_empty_text_with_images_removes_last_image(): void
    {
        $c = new ReplComposer();
        $c->attachImage(['path' => '/tmp/a.png']);
        $c->attachImage(['path' => '/tmp/b.png']);

        $c->deleteCharBefore();
        $this->assertCount(1, $c->images());
        $this->assertSame('/tmp/a.png', $c->images()[0]['path']);
    }

    public function test_undo_restores_previous_snapshot(): void
    {
        $c = new ReplComposer();
        $c->checkpoint();
        $c->insertText('hello');
        $c->checkpoint();
        $c->insertText(' world');

        $this->assertTrue($c->undo());
        $this->assertSame('hello', $c->text());

        $this->assertTrue($c->undo());
        $this->assertSame('', $c->text());
    }

    public function test_redo_replays_undone_change(): void
    {
        $c = new ReplComposer();
        $c->checkpoint();
        $c->insertText('hello');

        $c->undo();
        $this->assertSame('', $c->text());

        $this->assertTrue($c->redo());
        $this->assertSame('hello', $c->text());
    }

    public function test_can_undo_and_can_redo_reflect_state(): void
    {
        $c = new ReplComposer();
        $this->assertFalse($c->canUndo());
        $this->assertFalse($c->canRedo());

        $c->checkpoint();
        $c->insertText('x');

        $this->assertTrue($c->canUndo());
        $this->assertFalse($c->canRedo());

        $c->undo();
        $this->assertTrue($c->canRedo());
    }

    public function test_redo_is_invalidated_by_new_change(): void
    {
        $c = new ReplComposer();
        $c->checkpoint();
        $c->insertText('alpha');
        $c->undo();

        $this->assertTrue($c->canRedo());

        $c->checkpoint();
        $c->insertText('beta');

        $this->assertFalse($c->canRedo(), 'Mudanca apos undo deve invalidar redo stack.');
    }

    public function test_select_all_marks_full_text_range(): void
    {
        $c = (new ReplComposer())->insertText('hello');
        $c->selectAll();

        $this->assertTrue($c->hasTextSelection());
        $this->assertSame([0, 5], $c->selectionRange());
        $this->assertSame(5, $c->cursor());
        $this->assertSame(0, $c->selectionAnchor());
    }

    public function test_select_all_on_empty_text_is_noop(): void
    {
        $c = new ReplComposer();
        $c->selectAll();
        $this->assertFalse($c->hasTextSelection());
    }

    public function test_extend_selection_right_creates_range(): void
    {
        $c = (new ReplComposer())->insertText('hello');
        $c->moveCursorToLineStart();
        $c->extendSelectionRight();
        $c->extendSelectionRight();
        $c->extendSelectionRight();

        $this->assertSame([0, 3], $c->selectionRange());
    }

    public function test_extend_selection_left_creates_reverse_range(): void
    {
        $c = (new ReplComposer())->insertText('hello');
        $c->extendSelectionLeft();
        $c->extendSelectionLeft();

        $this->assertTrue($c->hasTextSelection());
        $this->assertSame([3, 5], $c->selectionRange());
    }

    public function test_arrow_left_collapses_selection_to_start(): void
    {
        $c = (new ReplComposer())->insertText('hello');
        $c->selectAll();
        $c->moveCursorLeft();

        $this->assertFalse($c->hasTextSelection());
        $this->assertSame(0, $c->cursor());
    }

    public function test_arrow_right_collapses_selection_to_end(): void
    {
        $c = (new ReplComposer())->insertText('hello');
        $c->moveCursorToLineStart();
        $c->extendSelectionRight();
        $c->extendSelectionRight();
        $c->moveCursorRight();

        $this->assertFalse($c->hasTextSelection());
        $this->assertSame(2, $c->cursor());
    }

    public function test_typing_replaces_selection(): void
    {
        $c = (new ReplComposer())->insertText('hello world');
        $c->selectAll();
        $c->insertChar('x');

        $this->assertSame('x', $c->text());
        $this->assertFalse($c->hasTextSelection());
    }

    public function test_backspace_removes_selection_when_present(): void
    {
        $c = (new ReplComposer())->insertText('foo bar baz');
        $c->moveCursorToLineEnd();
        $c->extendSelectionWordLeft();

        $c->deleteCharBefore();

        $this->assertSame('foo bar ', $c->text());
        $this->assertFalse($c->hasTextSelection());
    }

    public function test_extend_selection_word_right_jumps_word(): void
    {
        $c = (new ReplComposer())->insertText('foo bar baz');
        $c->moveCursorToLineStart();
        $c->extendSelectionWordRight();

        $this->assertSame([0, 4], $c->selectionRange());
    }

    public function test_clear_line_clears_selection(): void
    {
        $c = (new ReplComposer())->insertText('hello');
        $c->selectAll();
        $c->clearLine();

        $this->assertFalse($c->hasTextSelection());
        $this->assertSame('', $c->text());
    }

    public function test_snapshot_returns_complete_state(): void
    {
        $c = new ReplComposer();
        $c->insertText('hi');
        $c->attachImage(['path' => '/tmp/a.png']);
        $snapshot = $c->snapshot();

        $this->assertSame('hi', $snapshot['text']);
        $this->assertSame(2, $snapshot['cursor']);
        $this->assertCount(1, $snapshot['images']);
        $this->assertNull($snapshot['selected_image_index']);
    }
}
