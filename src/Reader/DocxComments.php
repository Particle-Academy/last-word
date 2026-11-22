<?php

declare(strict_types=1);

namespace LastWord\Reader;

use DOMDocument;
use DOMElement;
use LastWord\Archive\PartXml;
use LastWord\Archive\SourceArchive;
use LastWord\Schema\Comments;
use SplObjectStorage;

/**
 * Word review comments, read back into the model ({@see Comments}).
 *
 * The comments come from the part the document's `comments` relationship
 * names, threads and done state from the `commentsExtended` one; a part that
 * declares a DOCTYPE is not read ({@see PartXml}). Model ids are `c` and the
 * comment's position in that part, from 1.
 *
 * Anchors are worked out from document.xml before the body is read, in one
 * pass over its elements in document order, so a range may open and close at
 * any depth (between paragraphs, in a table, in a hyperlink). A comment's range
 * is from its first `w:commentRangeStart` to the first `w:commentRangeEnd`
 * after it; without one, to the first `w:commentReference` after it; without
 * either it never closes, and the comment is dropped rather than stretched to
 * the end of the document. A comment with no start is a point at its
 * reference, and so is one whose range holds no text the model keeps. An end
 * before its start is ignored. A reference to an id the part does not hold is
 * ignored.
 *
 * Every limit is the writer's: at most {@see Comments::MAX_COMMENTS} comments
 * and {@see Comments::MAX_PER_RUN} open at once (later ones are dropped), text
 * truncated at {@see Comments::MAX_TEXT} characters, and authors, initials and
 * dates cleaned to what validation accepts, so what is read always validates.
 * Comments in a header, footer or footnote are not read (the model holds body
 * comments only). Everything dropped or truncated is reported, by count and
 * location, never by text.
 */
final class DocxComments
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const NS_W14 = 'http://schemas.microsoft.com/office/word/2010/wordml';

    private const NS_W15 = 'http://schemas.microsoft.com/office/word/2012/wordml';

    /**
     * The part's comments by position: file id, author, initials, date, text, paraId.
     *
     * @var list<array{author: string, initials: ?string, date: ?string, text: string, truncated: bool, done: bool}>
     */
    private array $comments = [];

    /** @var array<string, int> w:id => position */
    private array $byFileId = [];

    /** @var array<int, int> reply position => root position */
    private array $rootOf = [];

    /** How many `w:comment` elements the part holds, kept or not. */
    private int $inPart = 0;

    /** @var SplObjectStorage<DOMElement, list<int>> a w:r => positions of the ranges it is in */
    private SplObjectStorage $runs;

    /** @var SplObjectStorage<DOMElement, list<int>> a w:commentReference => positions it is the point of */
    private SplObjectStorage $points;

    /** @var array<int, true> positions some model run carries */
    private array $anchored = [];

    /** @var array<int, true> positions dropped for overlapping too deep */
    private array $dropped = [];

    /** Parent links followed while resolving threads: the work a hostile reply chain costs. */
    private int $threadSteps = 0;

    /** How many parent links resolving the threads followed (for tests: it must stay linear in the comments). */
    public function threadSteps(): int
    {
        return $this->threadSteps;
    }

    private function __construct()
    {
        $this->runs = new SplObjectStorage();
        $this->points = new SplObjectStorage();
    }

    /** The document's comments, or null when it has no (readable) comments part. */
    public static function load(SourceArchive $zip, ?string $relsXml): ?self
    {
        $targets = self::targets($relsXml);
        if (!isset($targets['comments'])) {
            return null;
        }
        $dom = PartXml::dom($zip->read($targets['comments']));
        if ($dom === null || $dom->documentElement === null) {
            return null;
        }
        $self = new self();
        $byParaId = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if (!($node instanceof DOMElement) || $node->localName !== 'comment' || $node->namespaceURI !== self::NS_W) {
                continue;
            }
            $self->inPart++;
            $id = $node->getAttributeNS(self::NS_W, 'id');
            if ($id === '' || isset($self->byFileId[$id]) || count($self->comments) >= Comments::MAX_COMMENTS) {
                continue;
            }
            [$text, $paraId] = self::body($node);
            $clean = Comments::cleanText($text);
            $truncated = mb_strlen($clean, 'UTF-8') > Comments::MAX_TEXT;
            $author = Comments::cleanName($node->getAttributeNS(self::NS_W, 'author'), Comments::MAX_AUTHOR);
            $initials = Comments::cleanName($node->getAttributeNS(self::NS_W, 'initials'), Comments::MAX_INITIALS);
            $date = $node->getAttributeNS(self::NS_W, 'date');
            $self->byFileId[$id] = count($self->comments);
            if ($paraId !== null) {
                $byParaId[$paraId] ??= count($self->comments);
            }
            $self->comments[] = [
                'author' => $author !== '' ? $author : 'Unknown',
                'initials' => $initials !== '' ? $initials : null,
                'date' => Comments::isDate($date) ? $date : null,
                'text' => $truncated ? mb_substr($clean, 0, Comments::MAX_TEXT, 'UTF-8') : $clean,
                'truncated' => $truncated,
                'done' => false,
            ];
        }
        if (isset($targets['commentsExtended'])) {
            $self->threads($zip->read($targets['commentsExtended']), $byParaId);
        }

        return $self;
    }

    /**
     * Work out every anchor in the body, before it is read.
     */
    public function index(DOMElement $body): void
    {
        $elements = [];
        $starts = $ends = $refs = [];
        foreach ($body->getElementsByTagNameNS(self::NS_W, '*') as $i => $el) {
            $elements[] = $el;
            $name = $el->localName;
            if ($name !== 'commentRangeStart' && $name !== 'commentRangeEnd' && $name !== 'commentReference') {
                continue;
            }
            $pos = $this->byFileId[$el->getAttributeNS(self::NS_W, 'id')] ?? null;
            if ($pos === null || isset($this->rootOf[$pos])) {
                continue; // an orphan, or a reply: replies are anchored by their thread
            }
            match ($name) {
                'commentRangeStart' => $starts[$pos] ??= $i,
                'commentRangeEnd' => $ends[$pos][] = $i,
                default => $refs[$pos][] = $i,
            };
        }

        $openAt = $closeAt = [];
        foreach (array_keys($this->comments) as $pos) {
            if (isset($this->rootOf[$pos])) {
                continue;
            }
            $start = $starts[$pos] ?? null;
            $after = static fn (array $list): ?int => array_values(array_filter($list, static fn (int $i): bool => $start === null || $i > $start))[0] ?? null;
            $ref = $after($refs[$pos] ?? []);
            if ($ref !== null) {
                $this->points[$elements[$ref]] = [...($this->points[$elements[$ref]] ?? []), $pos];
            }
            if ($start === null) {
                continue;
            }
            $end = $after($ends[$pos] ?? []) ?? $ref;
            if ($end !== null) {
                $openAt[$start][] = $pos;
                $closeAt[$end][] = $pos;
            }
        }
        if ($openAt === []) {
            return;
        }

        $open = [];
        foreach ($elements as $i => $el) {
            foreach ($openAt[$i] ?? [] as $pos) {
                if (count($open) >= Comments::MAX_PER_RUN) {
                    $this->dropped[$pos] = true;

                    continue;
                }
                $open[$pos] = true;
            }
            foreach ($closeAt[$i] ?? [] as $pos) {
                unset($open[$pos]);
            }
            if ($open !== [] && $el->localName === 'r') {
                $positions = array_keys($open);
                sort($positions);
                $this->runs[$el] = $positions;
            }
        }
    }

    /**
     * The model ids a run read from `$r` carries, or null for none. Recorded as
     * anchored: call it only for a run the model keeps.
     *
     * @return list<string>|null
     */
    public function idsFor(?DOMElement $r): ?array
    {
        if ($r === null || !$this->runs->offsetExists($r)) {
            return null;
        }
        $ids = [];
        foreach ($this->runs[$r] as $pos) {
            $this->anchored[$pos] = true;
            $ids[] = 'c' . ($pos + 1);
        }

        return $ids;
    }

    /**
     * The ids a `w:commentReference` is the point of: comments whose range kept no
     * text (or that had no range). Null for none.
     *
     * @return list<string>|null
     */
    public function pointAt(DOMElement $reference): ?array
    {
        if (!$this->points->offsetExists($reference)) {
            return null;
        }
        $ids = [];
        foreach ($this->points[$reference] as $pos) {
            if (!isset($this->anchored[$pos]) && !isset($this->dropped[$pos])) {
                $this->anchored[$pos] = true;
                $ids[] = 'c' . ($pos + 1);
            }
        }

        return $ids !== [] ? $ids : null;
    }

    /**
     * The model's `comments` once the body is read: every anchored comment and
     * the replies to it, in the part's order; and what was dropped or cut short.
     *
     * @return array{comments: list<array<string, mixed>>, unrepresented: list<array{where: string, kind: string, count: int}>}
     */
    public function finish(): array
    {
        $out = [];
        $truncated = [];
        foreach ($this->comments as $pos => $c) {
            $root = $this->rootOf[$pos] ?? $pos;
            if (!isset($this->anchored[$root])) {
                continue;
            }
            $comment = ['id' => 'c' . ($pos + 1), 'author' => $c['author']];
            if ($c['initials'] !== null) {
                $comment['initials'] = $c['initials'];
            }
            if ($c['date'] !== null) {
                $comment['date'] = $c['date'];
            }
            $comment['text'] = $c['text'];
            if ($root !== $pos) {
                $comment['replyTo'] = 'c' . ($root + 1);
            }
            if ($c['done']) {
                $comment['done'] = true;
            }
            if ($c['truncated']) {
                $truncated[] = count($out);
            }
            $out[] = $comment;
        }
        $notes = [];
        if ($this->inPart - count($out) > 0) {
            $notes[] = ['where' => '/comments', 'kind' => 'comment', 'count' => $this->inPart - count($out)];
        }
        foreach ($truncated as $k) {
            $notes[] = ['where' => "/comments/{$k}/text", 'kind' => 'comment', 'count' => 1];
        }

        return ['comments' => $out, 'unrepresented' => $notes];
    }

    /**
     * Threads and done state from commentsExtended: a comment whose parent
     * chain reaches a comment with no parent replies to THAT one (Word threads
     * are one level, so a reply to a reply joins the thread); a chain that
     * loops or names a paragraph no comment ends with is no thread.
     *
     * @param  array<string, int>  $byParaId
     */
    private function threads(?string $xml, array $byParaId): void
    {
        $dom = PartXml::dom($xml);
        if ($dom === null || $dom->documentElement === null) {
            return;
        }
        $parent = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if (!($node instanceof DOMElement) || $node->localName !== 'commentEx' || $node->namespaceURI !== self::NS_W15) {
                continue;
            }
            $pos = $byParaId[strtoupper($node->getAttributeNS(self::NS_W15, 'paraId'))] ?? null;
            if ($pos === null) {
                continue;
            }
            $done = $node->getAttributeNS(self::NS_W15, 'done');
            $this->comments[$pos]['done'] = $done === '1' || $done === 'true';
            if ($node->hasAttributeNS(self::NS_W15, 'paraIdParent')) {
                $parent[$pos] = $byParaId[strtoupper($node->getAttributeNS(self::NS_W15, 'paraIdParent'))] ?? -1;
            }
        }
        foreach ($parent as $pos => $p) {
            $seen = [$pos => true];
            while ($p >= 0 && isset($parent[$p]) && !isset($seen[$p])) {
                $this->threadSteps++;
                $seen[$p] = true;
                $p = $parent[$p];
            }
            if ($p >= 0 && !isset($parent[$p]) && $p !== $pos) {
                $this->rootOf[$pos] = $p;
            }
        }
    }

    /**
     * A comment's text, one line per paragraph, and its last paragraph's w14:paraId.
     *
     * @return array{0: string, 1: ?string}
     */
    private static function body(DOMElement $comment): array
    {
        $lines = [];
        $paraId = null;
        foreach ($comment->getElementsByTagNameNS(self::NS_W, 'p') as $p) {
            $line = '';
            foreach ($p->getElementsByTagNameNS(self::NS_W, '*') as $el) {
                $line .= match ($el->localName) {
                    't' => $el->textContent,
                    'tab' => "\t",
                    'br', 'cr' => "\n",
                    default => '',
                };
            }
            $lines[] = $line;
            $id = $p->getAttributeNS(self::NS_W14, 'paraId');
            $paraId = $id !== '' ? strtoupper($id) : null;
        }

        return [implode("\n", $lines), $paraId];
    }

    /**
     * The comments and commentsExtended parts the document's relationships name.
     *
     * @return array<string, string> kind => archive name
     */
    private static function targets(?string $relsXml): array
    {
        $dom = PartXml::dom($relsXml);
        if ($dom === null || $dom->documentElement === null) {
            return [];
        }
        $out = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if (!($node instanceof DOMElement) || $node->localName !== 'Relationship'
                || strcasecmp($node->getAttribute('TargetMode'), 'External') === 0) {
                continue;
            }
            $type = $node->getAttribute('Type');
            $kind = str_ends_with($type, '/comments') ? 'comments' : (str_ends_with($type, '/commentsExtended') ? 'commentsExtended' : null);
            $target = str_replace('\\', '/', $node->getAttribute('Target'));
            if ($kind === null || $target === '' || isset($out[$kind])) {
                continue;
            }
            $out[$kind] = str_starts_with($target, '/') ? ltrim($target, '/') : 'word/' . $target;
        }

        return $out;
    }
}
