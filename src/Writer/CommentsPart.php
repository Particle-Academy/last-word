<?php

declare(strict_types=1);

namespace LastWord\Writer;

use LastWord\Helpers\Xml;
use LastWord\Schema\Comments;
use LastWord\Schema\RunWalk;

/**
 * A document's review comments as Word writes them: `w:commentRangeStart` and
 * `w:commentRangeEnd` around each range in document.xml, a CommentReference
 * run with `w:commentReference` after it, each comment in word/comments.xml,
 * and -- only when a comment is a reply or done -- word/commentsExtended.xml
 * (w15), which ties a reply to its thread by paragraph id.
 *
 * `w:id` is the comment's position in the model's list, from 0, as Word
 * numbers them. A reply opens and closes with its thread's first comment, the
 * way Word anchors one. Nothing here adds a timestamp: `w:date` is written only
 * when the model has a date.
 *
 * Built from a validated document. Unvalidated, an anchor that names no
 * comment, a reply or a comment outside the body is not written, and a range
 * is written from the first run that names it to the last.
 */
final class CommentsPart
{
    /** The key a marker pseudo-run carries in a run list: raw markup for the writer to emit. */
    public const RAW = "\0lastword:comment";

    /** @var list<array<string, mixed>> */
    private array $comments = [];

    /** @var array<string, int> id => w:id */
    private array $wId = [];

    /** @var array<string, list<string>> thread root id => reply ids, in list order */
    private array $replies = [];

    /** @var array<string, int> root id => how many body runs name it */
    private array $total = [];

    /** @var array<string, int> root id => how many of those have been written */
    private array $seen = [];

    private bool $extended = false;

    /** @param array<string, mixed> $doc */
    public function __construct(array $doc)
    {
        $list = $doc['comments'] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            return;
        }
        $index = Comments::index($doc);
        foreach ($list as $i => $comment) {
            if (!is_array($comment) || !isset($index[$comment['id'] ?? '']) || $index[$comment['id']]['index'] !== $i) {
                continue;
            }
            $this->wId[$comment['id']] = count($this->comments);
            $this->comments[] = $comment;
        }
        foreach ($this->comments as $comment) {
            $to = $comment['replyTo'] ?? null;
            if (is_string($to) && isset($this->wId[$to]) && !$index[$to]['reply']) {
                $this->replies[$to][] = $comment['id'];
            }
            if ($to !== null || ($comment['done'] ?? false) === true) {
                $this->extended = true;
            }
        }
        foreach (RunWalk::all($doc) as ['run' => $run, 'where' => $where]) {
            if ($where !== 'body' || !is_array($run['comments'] ?? null)) {
                continue;
            }
            foreach (array_unique(array_filter($run['comments'], 'is_string')) as $id) {
                if ($this->isRoot($id)) {
                    $this->total[$id] = ($this->total[$id] ?? 0) + 1;
                }
            }
        }
    }

    public function has(): bool
    {
        return $this->comments !== [];
    }

    public function hasExtended(): bool
    {
        return $this->extended && $this->comments !== [];
    }

    /**
     * A body run list with each range's markers in place: a start before the
     * first run that names a comment, the end and the reference after the last.
     * Markers are pseudo-runs keyed {@see RAW}. A point (a run whose text is ""
     * and that carries nothing else Word draws) leaves only its markers.
     *
     * @param  list<mixed>  $runs
     * @return list<mixed>
     */
    public function marks(array $runs): array
    {
        if ($this->total === []) {
            return $runs;
        }
        $out = [];
        foreach ($runs as $run) {
            if (!is_array($run) || !is_array($run['comments'] ?? null)) {
                $out[] = $run;

                continue;
            }
            $before = '';
            $ends = '';
            $refs = '';
            foreach (array_unique(array_filter($run['comments'], 'is_string')) as $id) {
                if (!isset($this->total[$id])) {
                    continue;
                }
                $this->seen[$id] = ($this->seen[$id] ?? 0) + 1;
                $thread = [$id, ...($this->replies[$id] ?? [])];
                if ($this->seen[$id] === 1) {
                    foreach ($thread as $t) {
                        $before .= '<w:commentRangeStart w:id="' . $this->wId[$t] . '"/>';
                    }
                }
                if ($this->seen[$id] === $this->total[$id]) {
                    foreach ($thread as $t) {
                        $ends .= '<w:commentRangeEnd w:id="' . $this->wId[$t] . '"/>';
                        $refs .= '<w:r><w:rPr><w:rStyle w:val="CommentReference"/></w:rPr><w:commentReference w:id="' . $this->wId[$t] . '"/></w:r>';
                    }
                }
            }
            if ($before !== '') {
                $out[] = [self::RAW => $before];
            }
            $point = ($run['text'] ?? null) === ''
                && !array_key_exists('footnote', $run) && !array_key_exists('bookmark', $run) && !array_key_exists('field', $run);
            if (!$point) {
                $out[] = $run;
            }
            if ($ends !== '') {
                $out[] = [self::RAW => $ends . $refs];
            }
        }

        return $out;
    }

    public function commentsXml(): string
    {
        $ns = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        if ($this->extended) {
            $ns .= ' xmlns:w14="http://schemas.microsoft.com/office/word/2010/wordml" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="w14"';
        }
        $xml = Xml::declaration() . '<w:comments ' . $ns . '>';
        foreach ($this->comments as $n => $comment) {
            $xml .= '<w:comment w:id="' . $n . '" w:author="' . Xml::attr((string) ($comment['author'] ?? '')) . '"'
                . (is_string($comment['date'] ?? null) ? ' w:date="' . Xml::attr($comment['date']) . '"' : '')
                . (is_string($comment['initials'] ?? null) ? ' w:initials="' . Xml::attr($comment['initials']) . '"' : '')
                . '>';
            $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", (string) ($comment['text'] ?? '')));
            foreach ($lines as $l => $line) {
                $paraId = $this->extended && $l === count($lines) - 1 ? ' w14:paraId="' . self::paraId($n) . '" w14:textId="77777777"' : '';
                $xml .= '<w:p' . $paraId . '><w:pPr><w:pStyle w:val="CommentText"/></w:pPr>';
                if ($l === 0) {
                    $xml .= '<w:r><w:rPr><w:rStyle w:val="CommentReference"/></w:rPr><w:annotationRef/></w:r>';
                }
                if ($line !== '') {
                    $pieces = [];
                    foreach (explode("\t", $line) as $piece) {
                        $pieces[] = $piece === '' ? '' : '<w:t xml:space="preserve">' . Xml::text($piece) . '</w:t>';
                    }
                    $xml .= '<w:r>' . implode('<w:tab/>', $pieces) . '</w:r>';
                }
                $xml .= '</w:p>';
            }
            $xml .= '</w:comment>';
        }

        return $xml . '</w:comments>';
    }

    public function extendedXml(): string
    {
        $xml = Xml::declaration()
            . '<w15:commentsEx xmlns:w15="http://schemas.microsoft.com/office/word/2012/wordml" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="w15">';
        foreach ($this->comments as $n => $comment) {
            $to = $comment['replyTo'] ?? null;
            $parent = is_string($to) && isset($this->replies[$to]) && in_array($comment['id'], $this->replies[$to], true)
                ? ' w15:paraIdParent="' . self::paraId($this->wId[$to]) . '"'
                : '';
            $xml .= '<w15:commentEx w15:paraId="' . self::paraId($n) . '"' . $parent . ' w15:done="' . (($comment['done'] ?? false) === true ? '1' : '0') . '"/>';
        }

        return $xml . '</w15:commentsEx>';
    }

    /** A comment's last paragraph's w14:paraId: its w:id + 1, eight hex digits (below 0x80000000, as Word requires). */
    private static function paraId(int $n): string
    {
        return sprintf('%08X', $n + 1);
    }

    private function isRoot(string $id): bool
    {
        if (!isset($this->wId[$id])) {
            return false;
        }

        return !array_key_exists('replyTo', $this->comments[$this->wId[$id]]);
    }
}
