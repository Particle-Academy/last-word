<?php

declare(strict_types=1);

namespace LastWord\Schema;

use LastWord\Exchange\Portable;

/**
 * Word tracked changes (revisions) in the model: what each one looks like, the
 * rules the validator holds them to, and the read-side normalisation that
 * keeps whatever a file holds inside those rules.
 *
 * A run carries `revision`, an object with up to five entries:
 *
 *   insert    the run was typed with tracking on            (w:ins)
 *   delete    the run was deleted; its text is still here   (w:del, w:delText)
 *   moveFrom  the run was moved away from here               (w:moveFrom)
 *   moveTo    the run was moved here                         (w:moveTo)
 *   format    the run's formatting changed; `from` is the
 *             formatting it had before                       (w:rPrChange)
 *
 * A paragraph or heading carries `revision` with `insert`, `delete` (its
 * paragraph MARK was inserted or deleted: a new paragraph, or one joined to the
 * next) and `format` (w:pPrChange, `from` holding the paragraph properties it had
 * before). A list item carries `insert` and `delete` only.
 *
 * Every entry has `id`, `author` and optionally `date`; a move entry has `name`,
 * which ties a move's source to its destination. Runs that carry the same id are
 * ONE revision: they are consecutive runs of one paragraph with the same link.
 * The runs of one move name, in one direction, are consecutive in reading order.
 *
 * Where: body text (paragraphs, headings, list items, table cells, quotes) and
 * footnotes. A move is body text only. Headers and footers carry none: Word
 * shares a header part between sections and this package reads some of them as
 * one-line bands, which have no runs to carry a revision.
 *
 * `author` is display text a person or a file chose, never an identity: nothing
 * in this package trusts it. It is still held to a strict alphabet, because a
 * person reads it to decide whose change this is: no control characters, no bidi
 * controls (an override can make one name display as another), no zero-width
 * characters, no line or paragraph separators, and no leading or trailing space
 * of any kind. The sets are explicit codepoint classes, not a runtime's own
 * `trim()`, so every port can spell the same rule. They are the same sets the
 * review comments use.
 */
final class Revisions
{
    /** Revisions in one document: distinct ids, every kind together. */
    public const MAX_REVISIONS = 10000;

    /** Characters (code points) in an author. */
    public const MAX_AUTHOR = 255;

    /** A revision id or a move name: a short ASCII name. */
    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D';

    /** The kinds a run may carry, in the order read() writes them. */
    public const RUN_KINDS = ['insert', 'delete', 'moveFrom', 'moveTo', 'format'];

    /** The kinds a paragraph or heading may carry. */
    public const PARAGRAPH_KINDS = ['insert', 'delete', 'format'];

    /** The kinds a list item may carry. */
    public const ITEM_KINDS = ['insert', 'delete'];

    /** What a run's `format.from` may hold: the run's formatting keys, less `link`. */
    public const RUN_FORMAT_KEYS = ['bold', 'italic', 'underline', 'strike', 'code', 'smallCaps', 'color', 'highlight', 'size', 'font', 'letterSpacing'];

    /** What a paragraph's `format.from` may hold. */
    public const PARAGRAPH_FORMAT_KEYS = ['align', 'spaceBefore', 'spaceAfter', 'lineHeight', 'indentLeft', 'indentRight', 'keepNext', 'shading', 'borders'];

    /** An ISO 8601 date-time, as `w:date` (xsd:dateTime) holds it. */
    private const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,7})?(?:Z|[+-](\d{2}):(\d{2}))?$/D';

    /**
     * Refused anywhere in an author: C0 and C1 controls, bidi marks, embeddings,
     * overrides and isolates, zero-width characters and joiners, the byte-order
     * mark, line and paragraph separators, and the two noncharacters.
     */
    private const NAME_REFUSED = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{180E}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{FFFE}\x{FFFF}]/u';

    /** Space characters refused at either end of an author (inside, they are fine). */
    private const EDGE_SPACE = '\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}';

    /** What read() calls an author it could not keep: absent, or nothing left once cleaned. */
    public const UNKNOWN_AUTHOR = 'Unknown';

    private const FLAGS = ['bold', 'italic', 'underline', 'strike', 'code', 'smallCaps'];

    private const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    // ─── JSON Schema ────────────────────────────────────────────────────

    /** @return array<string, mixed> `$defs.revisionEntry` */
    public static function entryJsonSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['id', 'author'],
            'additionalProperties' => false,
            'properties' => [
                'id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$', 'description' => 'Unique in the document; runs that carry the same id are one revision. read() names them r1, r2 ... in reading order.'],
                'author' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_AUTHOR, 'description' => 'Display name. No control or bidi characters and no leading or trailing space. A label, never an identity.'],
                'date' => ['type' => 'string', 'description' => 'ISO 8601 date-time, e.g. "2026-11-21T09:30:00Z". Omit it for none: the writer never adds a timestamp of its own.'],
            ],
        ];
    }

    /** @return array<string, mixed> a run's `revision` */
    public static function runJsonSchema(): array
    {
        $entry = ['$ref' => '#/$defs/revisionEntry'];
        $move = [
            'type' => 'object',
            'required' => ['id', 'author', 'name'],
            'additionalProperties' => false,
            'properties' => self::entryJsonSchema()['properties'] + [
                'name' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$', 'description' => 'Ties the move\'s source to its destination: the same name on a moveFrom and a moveTo.'],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'A Word tracked change on this run. insert: typed with tracking on. delete: deleted, its text kept here (with insert: inserted, then deleted). moveFrom / moveTo: moved away from here / to here, paired by name, body text only. format: formatting changed, "from" is what it was. Not in a header or footer.',
            'properties' => [
                'insert' => $entry,
                'delete' => $entry,
                'moveFrom' => $move,
                'moveTo' => $move,
                'format' => self::formatJsonSchema(self::RUN_FORMAT_KEYS, 'The run\'s formatting before the change, as run keys: {} for none, {"italic": true} for italic. Only the keys it had.'),
            ],
        ];
    }

    /**
     * @param  list<string>  $kinds
     * @return array<string, mixed> a paragraph's, heading's or list item's `revision`
     */
    public static function paragraphJsonSchema(array $kinds): array
    {
        $entry = ['$ref' => '#/$defs/revisionEntry'];
        $properties = [
            'insert' => $entry,
            'delete' => $entry,
        ];
        if (in_array('format', $kinds, true)) {
            $properties['format'] = self::formatJsonSchema(self::PARAGRAPH_FORMAT_KEYS, 'The paragraph\'s properties before the change: {} for none.');
        }

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'A Word tracked change on the paragraph MARK. insert: a new paragraph. delete: joined to the next one when accepted.'
                . (in_array('format', $kinds, true) ? ' format: paragraph properties changed, "from" is what they were.' : ''),
            'properties' => $properties,
        ];
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function formatJsonSchema(array $keys, string $from): array
    {
        return [
            'type' => 'object',
            'required' => ['id', 'author', 'from'],
            'additionalProperties' => false,
            'properties' => self::entryJsonSchema()['properties'] + [
                'from' => ['type' => 'object', 'propertyNames' => ['enum' => $keys], 'description' => $from],
            ],
        ];
    }

    // ─── Character rules ────────────────────────────────────────────────

    public static function isId(mixed $id): bool
    {
        return is_string($id) && preg_match(self::ID_PATTERN, $id) === 1;
    }

    public static function isAuthor(mixed $name): bool
    {
        if (!is_string($name) || $name === '' || preg_match('//u', $name) !== 1) {
            return false;
        }
        if (mb_strlen($name, 'UTF-8') > self::MAX_AUTHOR || preg_match(self::NAME_REFUSED, $name) === 1) {
            return false;
        }

        return preg_match('/^[' . self::EDGE_SPACE . ']|[' . self::EDGE_SPACE . ']$/u', $name) !== 1;
    }

    /** An author cleaned into one {@see isAuthor()} accepts, or '' when nothing is left. */
    public static function cleanAuthor(string $name): string
    {
        $name = mb_scrub($name, 'UTF-8');
        $name = (string) preg_replace(self::NAME_REFUSED, '', $name);
        $name = (string) preg_replace('/^[' . self::EDGE_SPACE . ']+|[' . self::EDGE_SPACE . ']+$/u', '', $name);
        if (mb_strlen($name, 'UTF-8') > self::MAX_AUTHOR) {
            $name = (string) preg_replace('/[' . self::EDGE_SPACE . ']+$/u', '', mb_substr($name, 0, self::MAX_AUTHOR, 'UTF-8'));
        }

        return $name;
    }

    public static function isDate(mixed $date): bool
    {
        if (!is_string($date) || preg_match(self::DATE_PATTERN, $date, $m) !== 1) {
            return false;
        }
        [$y, $mo, $d, $h, $mi, $s] = array_map('intval', array_slice($m, 1, 6));
        if (!checkdate($mo, $d, $y) || $h > 23 || $mi > 59 || $s > 59) {
            return false;
        }

        return !isset($m[7]) || ((int) $m[7] <= 14 && (int) $m[8] <= 59);
    }

    // ─── The walk ───────────────────────────────────────────────────────

    /**
     * The document with every run-bearing owner (a paragraph, a heading, a list
     * item) passed to `$onOwner` and every run to `$onRun`, in reading order: an
     * owner before its runs, a footnote's owners right after the run that carries
     * it, then the page's bands. `where` is `body`, `footnote` or `band`; `kind`
     * is `paragraph`, `heading` or `item`. Nesting deeper than
     * {@see Portable::MAX_DEPTH} is not walked, as the validator's other checks.
     *
     * @param  array<string, mixed>  $doc
     * @param  null|callable(array<string, mixed>, string, string, string): array<string, mixed>  $onOwner  (owner, path, where, kind) => owner
     * @param  null|callable(array<string, mixed>, string, string, string, int): array<string, mixed>  $onRun  (run, path, where, ownerPath, index) => run
     * @return array<string, mixed>
     */
    public static function map(array $doc, ?callable $onOwner, ?callable $onRun): array
    {
        $walker = new self($onOwner, $onRun);
        if (is_array($doc['blocks'] ?? null) && array_is_list($doc['blocks'])) {
            $doc['blocks'] = $walker->blocks($doc['blocks'], 'blocks', 'body', 0);
        }
        if (is_array($doc['page'] ?? null)) {
            $doc['page'] = $walker->page($doc['page'], 'page');
        }

        return $doc;
    }

    /** @var null|callable */
    private $onOwner;

    /** @var null|callable */
    private $onRun;

    private function __construct(?callable $onOwner, ?callable $onRun)
    {
        $this->onOwner = $onOwner;
        $this->onRun = $onRun;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return array<string, mixed>
     */
    private function page(array $page, string $at): array
    {
        foreach (Schema::PAGE_BANDS as $kind) {
            $band = $page[$kind] ?? null;
            if (is_array($band) && is_array($band['blocks'] ?? null) && array_is_list($band['blocks'])) {
                $page[$kind]['blocks'] = $this->blocks($band['blocks'], "{$at}.{$kind}.blocks", 'band', 0);
            }
        }

        return $page;
    }

    /**
     * @param  list<mixed>  $blocks
     * @return list<mixed>
     */
    private function blocks(array $blocks, string $path, string $where, int $depth): array
    {
        if ($depth > Portable::MAX_DEPTH) {
            return $blocks;
        }
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                continue;
            }
            $at = "{$path}[{$i}]";
            $type = $block['type'] ?? null;
            if ($type === 'heading' || $type === 'paragraph') {
                $block = $this->owner($block, $at, $where, $type, $depth);
            } elseif ($type === 'list' && is_array($block['items'] ?? null) && array_is_list($block['items'])) {
                $block['items'] = $this->items($block['items'], "{$at}.items", $where, $depth);
            } elseif ($type === 'quote' && is_array($block['blocks'] ?? null) && array_is_list($block['blocks'])) {
                $block['blocks'] = $this->blocks($block['blocks'], "{$at}.blocks", $where, $depth + 1);
            } elseif ($type === 'table') {
                foreach (is_array($block['rows'] ?? null) ? $block['rows'] : [] as $r => $row) {
                    foreach (is_array($row) && is_array($row['cells'] ?? null) ? $row['cells'] : [] as $c => $cell) {
                        if (is_array($cell) && is_array($cell['blocks'] ?? null) && array_is_list($cell['blocks'])) {
                            $block['rows'][$r]['cells'][$c]['blocks'] = $this->blocks($cell['blocks'], "{$at}.rows[{$r}].cells[{$c}].blocks", $where, $depth + 1);
                        }
                    }
                }
            } elseif ($type === 'pageBreak' && is_array($block['page'] ?? null)) {
                $block['page'] = $this->page($block['page'], "{$at}.page");
            }
            $blocks[$i] = $block;
        }

        return $blocks;
    }

    /**
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private function items(array $items, string $path, string $where, int $depth): array
    {
        if ($depth > Portable::MAX_DEPTH) {
            return $items;
        }
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                continue;
            }
            $at = "{$path}[{$i}]";
            $item = $this->owner($item, $at, $where, 'item', $depth);
            if (is_array($item['children'] ?? null) && array_is_list($item['children'])) {
                $item['children'] = $this->items($item['children'], "{$at}.children", $where, $depth + 1);
            }
            $items[$i] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $owner
     * @return array<string, mixed>
     */
    private function owner(array $owner, string $at, string $where, string $kind, int $depth): array
    {
        if ($this->onOwner !== null) {
            $owner = ($this->onOwner)($owner, $at, $where, $kind);
        }
        if (!is_array($owner['runs'] ?? null) || !array_is_list($owner['runs'])) {
            return $owner;
        }
        foreach ($owner['runs'] as $j => $run) {
            if (!is_array($run)) {
                continue;
            }
            $runPath = "{$at}.runs[{$j}]";
            if ($this->onRun !== null) {
                $run = ($this->onRun)($run, $runPath, $where, $at, $j);
            }
            if (is_array($run['footnote'] ?? null) && is_array($run['footnote']['blocks'] ?? null) && array_is_list($run['footnote']['blocks'])) {
                $run['footnote']['blocks'] = $this->blocks($run['footnote']['blocks'], "{$runPath}.footnote.blocks", $where === 'body' ? 'footnote' : $where, $depth + 1);
            }
            $owner['runs'][$j] = $run;
        }

        return $owner;
    }

    // ─── Validation ─────────────────────────────────────────────────────

    /**
     * Every rule a revision is held to. Linear in the document: each id and
     * each move name is looked up in a map, never searched for.
     *
     * @param  array<string, mixed>  $doc
     * @return list<array{path: string, message: string, code: string}>
     */
    public static function validate(array $doc): array
    {
        $errors = [];
        /** @var array<string, array{sig: string, owner: string, index: int, link: ?string, arrival: ?string, run: bool}> $ids */
        $ids = [];
        /** @var array<string, array{from: ?array{0: int, 1: string}, to: ?array{0: int, 1: string}, broken: bool}> $moves */
        $moves = [];
        $ordinal = 0; // body runs in reading order: what a move range is consecutive in

        $seeId = static function (string $id, string $sig, string $owner, int $index, ?string $link, ?string $arrival, bool $run, string $path) use (&$ids, &$errors): void {
            if (!isset($ids[$id])) {
                $ids[$id] = ['sig' => $sig, 'owner' => $owner, 'index' => $index, 'link' => $link, 'arrival' => $arrival, 'run' => $run];

                return;
            }
            $seen = $ids[$id];
            if ($seen['sig'] !== $sig || !$run || !$seen['run'] || str_starts_with($sig, 'format|')) {
                $errors[] = self::error($path, "revision id \"{$id}\" is used by another revision; every revision needs its own id", 'revision_id_duplicate');

                return;
            }
            if ($seen['owner'] !== $owner || $seen['index'] !== $index - 1 || $seen['link'] !== $link || $seen['arrival'] !== $arrival) {
                $errors[] = self::error($path, "the runs of revision \"{$id}\" must be consecutive runs of one paragraph with the same link (and, for a deletion, the same insertion); give this run its own id", 'revision_range_broken');
                $ids[$id]['owner'] = ''; // report a break once, not once per run after it

                return;
            }
            $ids[$id]['index'] = $index;
        };

        self::map(
            $doc,
            static function (array $owner, string $path, string $where, string $kind) use (&$errors, $seeId): array {
                if (!array_key_exists('revision', $owner)) {
                    return $owner;
                }
                $at = "{$path}.revision";
                if ($where === 'band') {
                    $errors[] = self::error($at, 'a header or footer cannot carry tracked changes', 'revision_in_band');

                    return $owner;
                }
                $allowed = $kind === 'item' ? self::ITEM_KINDS : self::PARAGRAPH_KINDS;
                $rev = $owner['revision'];
                if (!is_array($rev) || array_is_list($rev) && $rev !== []) {
                    $errors[] = self::error($at, 'revision must be an object: {insert?, delete?' . ($kind === 'item' ? '' : ', format?') . '}', 'revision_invalid');

                    return $owner;
                }
                foreach ($rev as $key => $entry) {
                    $key = (string) $key;
                    if (!in_array($key, $allowed, true)) {
                        $errors[] = self::error("{$at}.{$key}", $kind === 'item'
                            ? 'a list item\'s revision holds insert and delete only'
                            : 'a paragraph\'s revision holds insert, delete and format only', 'revision_invalid');

                        continue;
                    }
                    if (self::entryErrors($entry, "{$at}.{$key}", $key, self::PARAGRAPH_FORMAT_KEYS, $errors)) {
                        $seeId($entry['id'], 'owner', $path, -1, null, null, false, "{$at}.{$key}.id");
                    }
                }
                if (self::ownerText($owner) === '') {
                    $errors[] = self::error($at, 'a paragraph with a tracked change needs text: an empty one is not read back', 'revision_text_empty');
                }

                return $owner;
            },
            static function (array $run, string $path, string $where, string $ownerPath, int $index) use (&$errors, &$moves, &$ordinal, $seeId): array {
                if ($where === 'body') {
                    $ordinal++;
                }
                if (!array_key_exists('revision', $run)) {
                    return $run;
                }
                $at = "{$path}.revision";
                if ($where === 'band') {
                    $errors[] = self::error($at, 'a header or footer cannot carry tracked changes', 'revision_in_band');

                    return $run;
                }
                $rev = $run['revision'];
                if (!is_array($rev) || $rev === [] || array_is_list($rev)) {
                    $errors[] = self::error($at, 'revision must be an object with at least one of insert, delete, moveFrom, moveTo, format', 'revision_invalid');

                    return $run;
                }
                $isNote = array_key_exists('footnote', $run);
                $link = is_string($run['link'] ?? null) && $run['link'] !== '' ? $run['link'] : null;
                $valid = [];
                foreach ($rev as $key => $entry) {
                    $key = (string) $key;
                    if (!in_array($key, self::RUN_KINDS, true)) {
                        $errors[] = self::error("{$at}.{$key}", 'a run\'s revision holds insert, delete, moveFrom, moveTo and format only', 'revision_invalid');

                        continue;
                    }
                    if (self::entryErrors($entry, "{$at}.{$key}", $key, self::RUN_FORMAT_KEYS, $errors)) {
                        $valid[$key] = $entry;
                    }
                }
                $has = static fn (string $k): bool => array_key_exists($k, $rev);
                $combination = match (true) {
                    $has('insert') && $has('moveTo') => 'a run cannot be both inserted and moved here',
                    $has('delete') && $has('moveFrom') => 'a run cannot be both deleted and moved away',
                    $has('moveFrom') && ($has('insert') || $has('moveTo')) => 'moved-away text is already gone: moveFrom goes with nothing but format',
                    $isNote && ($has('moveFrom') || $has('moveTo') || $has('format')) => 'a footnote mark takes insert and delete only',
                    default => null,
                };
                if ($combination !== null) {
                    $errors[] = self::error($at, $combination, 'revision_combination_invalid');
                }
                if (!$isNote && ($run['text'] ?? null) === '') {
                    $errors[] = self::error($at, 'a run with a tracked change needs text: an empty one is not read back', 'revision_text_empty');
                }
                if (($has('moveFrom') || $has('moveTo')) && $where !== 'body') {
                    $errors[] = self::error($at, 'a move is body text only, not a footnote', 'revision_move_outside_body');
                }

                $arrival = isset($valid['insert']) ? $valid['insert']['id'] : (isset($valid['moveTo']) ? $valid['moveTo']['id'] : null);
                foreach ($valid as $key => $entry) {
                    $sig = $key . '|' . json_encode([$entry['author'], $entry['date'] ?? null, $entry['name'] ?? null]);
                    $departure = $key === 'delete' || $key === 'moveFrom';
                    $seeId($entry['id'], $sig, $ownerPath, $index, $link, $departure ? $arrival : null, true, "{$at}.{$key}.id");
                    if (($key === 'moveFrom' || $key === 'moveTo') && $where === 'body') {
                        $dir = $key === 'moveFrom' ? 'from' : 'to';
                        $name = $entry['name'];
                        $moves[$name] ??= ['from' => null, 'to' => null, 'broken' => false, 'text' => ['from' => hash_init('sha256'), 'to' => hash_init('sha256')]];
                        $last = $moves[$name][$dir];
                        if ($last !== null && $last[0] !== $ordinal - 1 && $last[0] !== $ordinal && !$moves[$name]['broken']) {
                            $errors[] = self::error("{$at}.{$key}.name", "the runs moved as \"{$name}\" must be consecutive in reading order; use another move", 'revision_range_broken');
                            $moves[$name]['broken'] = true;
                        }
                        $moves[$name][$dir] = [$ordinal, "{$at}.{$key}"];
                        hash_update($moves[$name]['text'][$dir], is_string($run['text'] ?? null) ? $run['text'] : '');
                    }
                }

                return $run;
            },
        );

        foreach ($moves as $name => $move) {
            if ($move['from'] === null || $move['to'] === null) {
                $where = ($move['from'] ?? $move['to'])[1];
                $errors[] = self::error("{$where}.name", "move \"{$name}\" needs both a moveFrom and a moveTo", 'revision_move_unpaired');
            } elseif (hash_final($move['text']['from']) !== hash_final($move['text']['to'])) {
                // A move is one passage in two places: different text is a deletion and an insertion.
                $errors[] = self::error("{$move['to'][1]}.name", "move \"{$name}\" arrives with different text than it left with; use a delete and an insert", 'revision_move_mismatch');
            }
        }
        if (count($ids) > self::MAX_REVISIONS) {
            $errors[] = self::error('blocks', 'a document holds at most ' . self::MAX_REVISIONS . ' tracked changes; this one has ' . count($ids), 'revision_count_exceeded');
        }

        return $errors;
    }

    /**
     * Checks one entry; true when it is sound enough to group by id.
     *
     * @param  list<string>  $formatKeys
     * @param  list<array{path: string, message: string, code: string}>  $errors
     */
    private static function entryErrors(mixed $entry, string $at, string $kind, array $formatKeys, array &$errors): bool
    {
        if (!is_array($entry) || array_is_list($entry) && $entry !== []) {
            $errors[] = self::error($at, "{$kind} must be an object: {id, author, date?" . ($kind === 'format' ? ', from' : (str_starts_with($kind, 'move') ? ', name' : '')) . '}', 'revision_invalid');

            return false;
        }
        $keys = ['id', 'author', 'date'];
        if ($kind === 'moveFrom' || $kind === 'moveTo') {
            $keys[] = 'name';
        }
        if ($kind === 'format') {
            $keys[] = 'from';
        }
        foreach (array_keys($entry) as $key) {
            if (!in_array($key, $keys, true)) {
                $errors[] = self::error("{$at}.{$key}", "{$kind} takes " . implode(', ', $keys) . ' only', 'revision_invalid');
            }
        }
        $ok = true;
        if (!self::isId($entry['id'] ?? null)) {
            $errors[] = self::error("{$at}.id", 'a revision id is 1-64 ASCII letters, digits, "_", "." or "-", starting with a letter or digit', 'revision_id_invalid');
            $ok = false;
        }
        if (!self::isAuthor($entry['author'] ?? null)) {
            $errors[] = self::error("{$at}.author", 'author is required: 1-' . self::MAX_AUTHOR . ' characters, with no control, bidi or zero-width characters and no space at either end', 'revision_author_invalid');
            $ok = false;
        }
        if (array_key_exists('date', $entry) && !self::isDate($entry['date'])) {
            $errors[] = self::error("{$at}.date", 'date is an ISO 8601 date-time, e.g. "2026-11-21T09:30:00Z"', 'revision_date_invalid');
        }
        if (in_array('name', $keys, true) && !self::isId($entry['name'] ?? null)) {
            $errors[] = self::error("{$at}.name", 'a move name is 1-64 ASCII letters, digits, "_", "." or "-", starting with a letter or digit, and the same on the moveFrom and the moveTo', 'revision_name_invalid');
            $ok = false;
        }
        if ($kind === 'format') {
            $problem = self::fromProblem($entry['from'] ?? null, $formatKeys);
            if ($problem !== null) {
                $errors[] = self::error("{$at}.from", $problem, 'revision_from_invalid');
            }
        }

        return $ok;
    }

    /** @param list<string> $keys */
    private static function fromProblem(mixed $from, array $keys): ?string
    {
        if (!is_array($from) || array_is_list($from) && $from !== []) {
            return 'from is required: an object of the formatting before the change, {} for none';
        }
        foreach ($from as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, $keys, true)) {
                return "from holds formatting keys only (" . implode(', ', $keys) . "), not \"{$key}\"";
            }
            if (!self::fromValueOk($key, $value)) {
                return "from.{$key} is not a valid {$key}";
            }
        }

        return null;
    }

    private static function fromValueOk(string $key, mixed $value): bool
    {
        $number = is_int($value) || is_float($value) && is_finite($value);

        return match ($key) {
            'bold', 'italic', 'underline', 'strike', 'code', 'smallCaps', 'keepNext' => is_bool($value),
            'color', 'highlight', 'shading' => is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/D', $value) === 1,
            'size', 'lineHeight' => $number && $value > 0,
            'font' => is_string($value) && $value !== '' && preg_match(self::NAME_REFUSED, $value) !== 1 && mb_strlen($value, 'UTF-8') <= 255,
            'letterSpacing', 'spaceAfter' => $number,
            'spaceBefore', 'indentLeft', 'indentRight' => $number && $value >= 0,
            'align' => in_array($value, self::ALIGNMENTS, true),
            'borders' => is_array($value),
            default => false,
        };
    }

    /** @param array<string, mixed> $owner */
    private static function ownerText(array $owner): string
    {
        $text = '';
        foreach (is_array($owner['runs'] ?? null) ? $owner['runs'] : [] as $run) {
            if (is_array($run) && (is_string($run['text'] ?? null) || array_key_exists('footnote', $run))) {
                $text .= array_key_exists('footnote', $run) ? '*' : $run['text'];
            }
        }

        return $text;
    }

    // ─── Repair ─────────────────────────────────────────────────────────

    /**
     * What is safe to repair, and nothing else: an author's refused characters
     * stripped, a bad date dropped (it is optional), unknown keys dropped, a
     * missing id given a fresh one. Never a revision dropped: dropping a delete
     * would make deleted text live, so anything else stays an error.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<array{path: string, message: string}>  $notes
     * @return array<string, mixed>
     */
    public static function repair(array $doc, array &$notes): array
    {
        $used = [];
        self::map($doc, static function (array $owner) use (&$used): array {
            foreach (is_array($owner['revision'] ?? null) ? $owner['revision'] : [] as $entry) {
                if (is_array($entry) && self::isId($entry['id'] ?? null)) {
                    $used[$entry['id']] = true;
                }
            }

            return $owner;
        }, static function (array $run) use (&$used): array {
            foreach (is_array($run['revision'] ?? null) ? $run['revision'] : [] as $entry) {
                if (is_array($entry) && self::isId($entry['id'] ?? null)) {
                    $used[$entry['id']] = true;
                }
            }

            return $run;
        });
        $next = 0;
        $fresh = static function () use (&$used, &$next): string {
            do {
                $id = 'r' . ++$next;
            } while (isset($used[$id]));
            $used[$id] = true;

            return $id;
        };
        $fix = static function (array $rev, string $at, array $formatKeys) use (&$notes, $fresh): array {
            foreach ($rev as $kind => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $path = "{$at}.{$kind}";
                $keys = ['id', 'author', 'date', ...(in_array($kind, ['moveFrom', 'moveTo'], true) ? ['name'] : []), ...($kind === 'format' ? ['from'] : [])];
                foreach (array_keys($entry) as $key) {
                    if (!in_array($key, $keys, true)) {
                        unset($entry[$key]);
                        $notes[] = ['path' => "{$path}.{$key}", 'message' => "dropped unknown revision key \"{$key}\""];
                    }
                }
                if (!array_key_exists('id', $entry)) {
                    $entry = ['id' => $fresh()] + $entry;
                    $notes[] = ['path' => "{$path}.id", 'message' => 'gave the revision an id'];
                }
                if (is_string($entry['author'] ?? null) && !self::isAuthor($entry['author'])) {
                    $clean = self::cleanAuthor($entry['author']);
                    if ($clean !== '') {
                        $entry['author'] = $clean;
                        $notes[] = ['path' => "{$path}.author", 'message' => 'removed control, bidi, zero-width or edge-space characters from the author'];
                    }
                }
                if (array_key_exists('date', $entry) && !self::isDate($entry['date'])) {
                    unset($entry['date']);
                    $notes[] = ['path' => "{$path}.date", 'message' => 'dropped a date that is not an ISO 8601 date-time'];
                }
                if ($kind === 'format' && is_array($entry['from'] ?? null)) {
                    foreach ($entry['from'] as $key => $value) {
                        if (!in_array((string) $key, $formatKeys, true) || !self::fromValueOk((string) $key, $value)) {
                            unset($entry['from'][$key]);
                            $notes[] = ['path' => "{$path}.from.{$key}", 'message' => 'dropped a from key that is not valid formatting'];
                        }
                    }
                }
                $rev[$kind] = $entry;
            }

            return $rev;
        };

        return self::map($doc, static function (array $owner, string $path) use ($fix): array {
            if (is_array($owner['revision'] ?? null) && !array_is_list($owner['revision'])) {
                $owner['revision'] = $fix($owner['revision'], "{$path}.revision", self::PARAGRAPH_FORMAT_KEYS);
            }

            return $owner;
        }, static function (array $run, string $path) use ($fix): array {
            if (is_array($run['revision'] ?? null) && !array_is_list($run['revision'])) {
                $run['revision'] = $fix($run['revision'], "{$path}.revision", self::RUN_FORMAT_KEYS);
            }

            return $run;
        });
    }

    // ─── Accepting ──────────────────────────────────────────────────────

    /**
     * Blocks with every tracked change accepted, as Word's "Accept All" leaves
     * them: what every reader of the model that has no notion of revisions
     * (markdown, portable content, describe, the default read) must see, so
     * deleted text never reads as live text.
     *
     *  - A deleted or moved-away run is gone; every other run loses `revision`.
     *  - A paragraph, heading or list item whose MARK is deleted joins the one
     *    after it, and the joined paragraph has the FOLLOWING one's properties
     *    (type, level, alignment, list membership): a paragraph's properties
     *    live in its mark, and the mark that survives is the next one's
     *    (ECMA-376 Part 1, 17.13.5.15 "del (Deleted Paragraph)": the paragraph's
     *    content is combined with the following paragraph). The next one may be
     *    a paragraph, a heading or a list's first item; the last item of a list
     *    joins a paragraph or heading after the list.
     *  - Nothing to join (a table or an image next, the end of a cell, a quote,
     *    a footnote or the body) and the paragraph stays where it is, unless
     *    accepting left it with no runs, when it goes. A list item with nested
     *    items keeps its place: the model cannot join it into its first nested
     *    item without re-parenting the rest.
     *
     * Same keys out as in: a block joined into the next is null. Also how many
     * revisions each block carried.
     *
     * @param  array<int, mixed>  $blocks
     * @return array{0: array<int, mixed>, 1: array<int, int>}
     */
    public static function acceptBlocks(array $blocks): array
    {
        $counts = [];
        foreach ($blocks as $i => $block) {
            $ids = [];
            if (is_array($block)) {
                $count = static function (array $item) use (&$ids): array {
                    if (array_key_exists('revision', $item)) {
                        self::collect($item['revision'], $ids);
                    }

                    return $item;
                };
                self::map(['blocks' => [$block]], $count, $count);
            }
            $counts[$i] = count($ids);
        }

        return [self::acceptList($blocks, 0), $counts];
    }

    /**
     * One block, accepted ({@see acceptBlocks()}); null when nothing of it is left.
     *
     * @param  array<string, mixed>  $block
     * @return array{0: ?array<string, mixed>, 1: int}
     */
    public static function acceptBlock(array $block): array
    {
        [$blocks, $counts] = self::acceptBlocks([$block]);

        return [$blocks[0], $counts[0]];
    }

    /** Set on an owner whose mark is deleted, while a list of blocks is being accepted. */
    private const MARK_GONE = "\0lastword:mark-deleted";

    /**
     * @param  array<int, mixed>  $blocks
     * @return array<int, mixed>
     */
    private static function acceptList(array $blocks, int $depth): array
    {
        if ($depth > Portable::MAX_DEPTH) {
            return $blocks;
        }
        foreach ($blocks as $i => $block) {
            if (is_array($block)) {
                $blocks[$i] = self::acceptOne($block, $depth);
            }
        }
        $keys = array_keys($blocks);
        foreach ($keys as $n => $i) {
            $block = $blocks[$i];
            if (!is_array($block)) {
                continue;
            }
            $nextKey = $keys[$n + 1] ?? null;
            $next = $nextKey !== null && is_array($blocks[$nextKey]) ? $blocks[$nextKey] : null;
            $type = $block['type'] ?? null;
            if (($type === 'paragraph' || $type === 'heading') && isset($block[self::MARK_GONE])) {
                $runs = is_array($block['runs'] ?? null) ? $block['runs'] : [];
                $nextType = $next['type'] ?? null;
                if ($nextType === 'paragraph' || $nextType === 'heading') {
                    $blocks[$nextKey]['runs'] = self::joinRuns($runs, is_array($next['runs'] ?? null) ? $next['runs'] : []);
                    $blocks[$i] = null;

                    continue;
                }
                if ($nextType === 'list' && is_array($next['items'] ?? null) && isset($next['items'][0]) && is_array($next['items'][0])) {
                    $first = $next['items'][0];
                    $blocks[$nextKey]['items'][0]['runs'] = self::joinRuns($runs, is_array($first['runs'] ?? null) ? $first['runs'] : []);
                    $blocks[$i] = null;

                    continue;
                }
                unset($blocks[$i][self::MARK_GONE]);
                if ($runs === []) {
                    $blocks[$i] = null;
                }

                continue;
            }
            if ($type === 'list' && is_array($block['items'] ?? null) && $block['items'] !== []) {
                $last = array_key_last($block['items']);
                $item = $block['items'][$last];
                if (is_array($item) && isset($item[self::MARK_GONE])) {
                    $nextType = $next['type'] ?? null;
                    if ($nextType === 'paragraph' || $nextType === 'heading') {
                        $blocks[$nextKey]['runs'] = self::joinRuns(is_array($item['runs'] ?? null) ? $item['runs'] : [], is_array($next['runs'] ?? null) ? $next['runs'] : []);
                        unset($blocks[$i]['items'][$last]);
                    } elseif (($item['runs'] ?? []) === []) {
                        unset($blocks[$i]['items'][$last]);
                    } else {
                        unset($blocks[$i]['items'][$last][self::MARK_GONE]);
                    }
                    $blocks[$i]['items'] = array_values($blocks[$i]['items']);
                    if ($blocks[$i]['items'] === []) {
                        $blocks[$i] = null;
                    }
                }
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function acceptOne(array $block, int $depth): array
    {
        switch ($block['type'] ?? null) {
            case 'paragraph':
            case 'heading':
                return self::acceptOwner($block, $depth);
            case 'list':
                if (is_array($block['items'] ?? null) && array_is_list($block['items'])) {
                    $block['items'] = self::acceptItems($block['items'], $depth, true);
                }

                return $block;
            case 'quote':
                if (is_array($block['blocks'] ?? null) && array_is_list($block['blocks'])) {
                    $block['blocks'] = self::kept(self::acceptList($block['blocks'], $depth + 1));
                }

                return $block;
            case 'table':
                foreach (is_array($block['rows'] ?? null) ? $block['rows'] : [] as $r => $row) {
                    foreach (is_array($row) && is_array($row['cells'] ?? null) ? $row['cells'] : [] as $c => $cell) {
                        if (is_array($cell) && is_array($cell['blocks'] ?? null) && array_is_list($cell['blocks'])) {
                            $block['rows'][$r]['cells'][$c]['blocks'] = self::kept(self::acceptList($cell['blocks'], $depth + 1));
                        }
                    }
                }

                return $block;
            default:
                return $block;
        }
    }

    /**
     * A paragraph, heading or list item: its runs accepted (a footnote's blocks
     * too), its own `revision` gone, and {@see MARK_GONE} when its mark was deleted.
     *
     * @param  array<string, mixed>  $owner
     * @return array<string, mixed>
     */
    private static function acceptOwner(array $owner, int $depth): array
    {
        if (is_array($owner['runs'] ?? null) && array_is_list($owner['runs'])) {
            $runs = self::acceptRuns($owner['runs']);
            foreach ($runs as $j => $run) {
                if (is_array($run) && is_array($run['footnote'] ?? null) && is_array($run['footnote']['blocks'] ?? null) && array_is_list($run['footnote']['blocks'])) {
                    $runs[$j]['footnote']['blocks'] = self::kept(self::acceptList($run['footnote']['blocks'], $depth + 1));
                }
            }
            $owner['runs'] = $runs;
        }
        if (array_key_exists('revision', $owner)) {
            if (is_array($owner['revision']) && array_key_exists('delete', $owner['revision'])) {
                $owner[self::MARK_GONE] = true;
            }
            unset($owner['revision']);
        }

        return $owner;
    }

    /**
     * List items accepted, each joined to the next sibling when its mark is
     * deleted. The last one keeps the flag at the top level, where the list's
     * caller may join it to the paragraph after the list.
     *
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    private static function acceptItems(array $items, int $depth, bool $top): array
    {
        if ($depth > Portable::MAX_DEPTH) {
            return $items;
        }
        foreach ($items as $k => $item) {
            if (!is_array($item)) {
                continue;
            }
            $item = self::acceptOwner($item, $depth);
            if (is_array($item['children'] ?? null) && array_is_list($item['children'])) {
                $item['children'] = self::acceptItems($item['children'], $depth + 1, false);
                if ($item['children'] === []) {
                    unset($item['children']);
                }
            }
            $items[$k] = $item;
        }
        $out = [];
        $n = count($items);
        for ($k = 0; $k < $n; $k++) {
            $item = $items[$k];
            if (!is_array($item) || !isset($item[self::MARK_GONE])) {
                $out[] = $item;

                continue;
            }
            $runs = is_array($item['runs'] ?? null) ? $item['runs'] : [];
            if (!empty($item['children'])) {
                unset($item[self::MARK_GONE]); // keeps its place: see acceptBlocks()
                $out[] = $item;

                continue;
            }
            if ($k + 1 < $n && is_array($items[$k + 1])) {
                $items[$k + 1]['runs'] = self::joinRuns($runs, is_array($items[$k + 1]['runs'] ?? null) ? $items[$k + 1]['runs'] : []);

                continue;
            }
            if ($top) {
                $out[] = $item; // the caller may join it to the paragraph after the list

                continue;
            }
            if ($runs !== []) {
                unset($item[self::MARK_GONE]);
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * Two run lists as one paragraph's: where they meet, two runs with the same
     * formatting become one, as the reader merges them.
     *
     * @param  list<mixed>  $a
     * @param  list<mixed>  $b
     * @return list<mixed>
     */
    private static function joinRuns(array $a, array $b): array
    {
        $a = array_values($a);
        $b = array_values($b);
        $last = $a !== [] ? $a[count($a) - 1] : null;
        $first = $b[0] ?? null;
        if (is_array($last) && is_array($first) && is_string($last['text'] ?? null) && is_string($first['text'] ?? null)
            && !array_key_exists('footnote', $last) && !array_key_exists('footnote', $first)) {
            $x = $last;
            $y = $first;
            unset($x['text'], $y['text']);
            if ($x == $y) {
                $a[count($a) - 1]['text'] .= $first['text'];
                array_shift($b);
            }
        }

        return [...$a, ...$b];
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return list<mixed>
     */
    private static function kept(array $blocks): array
    {
        return array_values(array_filter($blocks, static fn (mixed $b): bool => $b !== null));
    }

    /**
     * Runs with every tracked change accepted: deleted and moved-away runs gone,
     * `revision` off the rest.
     *
     * @param  list<mixed>  $runs
     * @return list<mixed>
     */
    public static function acceptRuns(array $runs): array
    {
        $out = [];
        foreach ($runs as $run) {
            if (is_array($run) && array_key_exists('revision', $run)) {
                $rev = is_array($run['revision']) ? $run['revision'] : [];
                if (array_key_exists('delete', $rev) || array_key_exists('moveFrom', $rev)) {
                    continue;
                }
                unset($run['revision']);
            }
            $out[] = $run;
        }

        return $out;
    }

    /** @param array<string, true> $ids */
    private static function collect(mixed $rev, array &$ids): void
    {
        foreach (is_array($rev) ? $rev : [] as $kind => $entry) {
            $ids[(string) $kind . '|' . (is_array($entry) && is_scalar($entry['id'] ?? null) ? (string) $entry['id'] : '#' . count($ids))] = true; // no usable id: counted on its own
        }
    }

    /** True when the document carries any `revision`. */
    public static function present(array $doc): bool
    {
        $found = false;
        self::map($doc, static function (array $owner) use (&$found): array {
            $found = $found || array_key_exists('revision', $owner);

            return $owner;
        }, static function (array $run) use (&$found): array {
            $found = $found || array_key_exists('revision', $run);

            return $run;
        });

        return $found;
    }

    /**
     * "Tracked changes: 6 (2 insertions, 1 deletion, 1 move, 2 formatting
     * changes)", counted as read()'s `trackedChanges` counts a file: a move once
     * per name. Null without any.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function describe(array $doc): ?string
    {
        $seen = ['insert' => [], 'delete' => [], 'move' => [], 'format' => []];
        $count = static function (mixed $rev) use (&$seen): void {
            foreach (is_array($rev) ? $rev : [] as $kind => $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                if ($kind === 'moveFrom' || $kind === 'moveTo') {
                    $seen['move'][(string) ($entry['name'] ?? '')] = true;
                } elseif (isset($seen[$kind])) {
                    $seen[$kind][(string) ($entry['id'] ?? '')] = true;
                }
            }
        };
        self::map($doc, static function (array $owner) use ($count): array {
            $count($owner['revision'] ?? null);

            return $owner;
        }, static function (array $run) use ($count): array {
            $count($run['revision'] ?? null);

            return $run;
        });
        $n = array_map('count', $seen);
        $total = array_sum($n);
        if ($total === 0) {
            return null;
        }
        $parts = [];
        foreach (['insert' => ['insertion', 'insertions'], 'delete' => ['deletion', 'deletions'], 'move' => ['move', 'moves'], 'format' => ['formatting change', 'formatting changes']] as $kind => [$one, $many]) {
            if ($n[$kind] > 0) {
                $parts[] = $n[$kind] . ' ' . ($n[$kind] === 1 ? $one : $many);
            }
        }

        return 'Tracked changes: ' . $total . ' (' . implode(', ', $parts) . ')';
    }

    // ─── Reading ────────────────────────────────────────────────────────

    /**
     * What the reader built, brought inside the rules: run once over the whole
     * document after it is read, so ids and move names can be judged across
     * paragraphs. Nothing here drops text a revision carries.
     *
     *  - A move whose source or destination is missing, broken up, or in a
     *    footnote becomes what it is on its own: moved-away text a deletion,
     *    moved-here text an insertion. A move on a footnote mark likewise.
     *  - Past {@see MAX_REVISIONS}, revisions are accepted, as the default read
     *    accepts every one.
     *  - Ids become r1, r2 ... in reading order (an owner's before its runs';
     *    a run's in {@see RUN_KINDS} order), split where one element's runs
     *    stopped being one revision. A move keeps its file name when that is a
     *    valid one used by one move, else becomes move1, move2 ...
     *  - Keys come out in a fixed order, so a document read twice is identical.
     *
     * `$unkept` is incremented once per revision changed or accepted here.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    public static function fromRead(array $doc, int &$unkept): array
    {
        // 1. Moves: which names have a sound source and destination.
        $moves = [];
        $ordinal = 0;
        self::map($doc, null, static function (array $run, string $path, string $where) use (&$moves, &$ordinal): array {
            if ($where === 'body') {
                $ordinal++;
            }
            foreach (['moveFrom' => 'from', 'moveTo' => 'to'] as $kind => $dir) {
                $entry = $run['revision'][$kind] ?? null;
                if (!is_array($entry)) {
                    continue;
                }
                $name = (string) ($entry['name'] ?? '');
                $moves[$name] ??= ['from' => null, 'to' => null, 'ok' => true, 'text' => ['from' => hash_init('sha256'), 'to' => hash_init('sha256')]];
                hash_update($moves[$name]['text'][$dir], is_string($run['text'] ?? null) ? $run['text'] : '');
                $last = $moves[$name][$dir];
                if ($where !== 'body' || array_key_exists('footnote', $run) || ($last !== null && $last !== $ordinal - 1)) {
                    $moves[$name]['ok'] = false;
                }
                $moves[$name][$dir] = $ordinal;
            }

            return $run;
        });
        $paired = [];
        foreach ($moves as $name => $move) {
            if ($name !== '' && $move['ok'] && $move['from'] !== null && $move['to'] !== null
                && hash_final($move['text']['from']) === hash_final($move['text']['to'])) {
                $paired[$name] = true;
            }
        }
        $degraded = [];
        $doc = self::map($doc, null, static function (array $run) use ($paired, &$degraded): array {
            foreach (['moveFrom' => 'delete', 'moveTo' => 'insert'] as $kind => $alone) {
                $entry = $run['revision'][$kind] ?? null;
                if (!is_array($entry) || isset($paired[(string) ($entry['name'] ?? '')])) {
                    continue;
                }
                unset($run['revision'][$kind], $entry['name']);
                $degraded[$kind . '|' . $entry['id']] = true;
                if (!isset($run['revision'][$alone])) {
                    $run['revision'][$alone] = $entry;
                }
            }

            return $run;
        });
        $unkept += count($degraded);

        // 2. Canonical ids, names and key order; the cap.
        $canon = [];      // temp id => [canonical id, owner, index, link, arrival temp]
        $nameMap = [];    // file name => model name
        $names = [];      // model names in use
        $total = 0;
        $over = [];
        $assign = static function (string $temp, string $owner, int $index, ?string $link, ?string $arrival) use (&$canon, &$total): ?string {
            $seen = $canon[$temp] ?? null;
            if ($seen !== null && $index >= 0 && $seen[1] === $owner && $seen[2] === $index - 1 && $seen[3] === $link && $seen[4] === $arrival) {
                $canon[$temp][2] = $index;

                return $seen[0];
            }
            if ($total >= self::MAX_REVISIONS) {
                return null;
            }
            $id = 'r' . ++$total;
            $canon[$temp] = [$id, $owner, $index, $link, $arrival];

            return $id;
        };
        $order = static function (array $entry, string $kind): array {
            $out = ['id' => $entry['id'], 'author' => $entry['author']];
            if (isset($entry['date'])) {
                $out['date'] = $entry['date'];
            }
            if ($kind === 'moveFrom' || $kind === 'moveTo') {
                $out['name'] = $entry['name'];
            }
            if ($kind === 'format') {
                $out['from'] = $entry['from'];
            }

            return $out;
        };

        $doc = self::map(
            $doc,
            static function (array $owner, string $path, string $where, string $kind) use ($assign, $order, &$over): array {
                if (!is_array($owner['revision'] ?? null)) {
                    return $owner;
                }
                $rev = [];
                foreach (self::PARAGRAPH_KINDS as $k) {
                    $entry = $owner['revision'][$k] ?? null;
                    if (!is_array($entry)) {
                        continue;
                    }
                    $id = $assign($path . '|' . $k . '|' . $entry['id'], $path, -1, null, null);
                    if ($id === null) {
                        $over[$k . '|' . $path . '|' . $entry['id']] = true;

                        continue;
                    }
                    $rev[$k] = $order(['id' => $id] + $entry, $k);
                }
                unset($owner['revision']);
                if ($rev !== []) {
                    $owner['revision'] = $rev;
                }

                return $owner;
            },
            static function (array $run, string $path, string $where, string $ownerPath, int $index) use ($assign, $order, &$over, &$nameMap, &$names): array {
                if (!is_array($run['revision'] ?? null)) {
                    return $run;
                }
                $link = is_string($run['link'] ?? null) ? $run['link'] : null;
                $rev = [];
                $arrivalTemp = $run['revision']['insert']['id'] ?? $run['revision']['moveTo']['id'] ?? null;
                foreach (self::RUN_KINDS as $k) {
                    $entry = $run['revision'][$k] ?? null;
                    if (!is_array($entry)) {
                        continue;
                    }
                    $departure = $k === 'delete' || $k === 'moveFrom';
                    $temp = $k . '|' . $entry['id'];
                    $id = $k === 'format'
                        ? $assign($temp . '|' . $path, $ownerPath, -1, null, null)
                        : $assign($temp, $ownerPath, $index, $link, $departure ? $arrivalTemp : null);
                    if ($id === null) {
                        $over[$temp] = true;

                        continue;
                    }
                    $entry['id'] = $id;
                    if (isset($entry['name'])) {
                        $file = $entry['name'];
                        if (!isset($nameMap[$file])) {
                            $model = self::isId($file) && !isset($names[$file]) ? $file : null;
                            for ($n = 1; $model === null; $n++) {
                                $model = isset($names['move' . $n]) ? null : 'move' . $n;
                            }
                            $nameMap[$file] = $model;
                            $names[$model] = true;
                        }
                        $entry['name'] = $nameMap[$file];
                    }
                    $rev[$k] = $order($entry, $k);
                }
                if ($rev === []) {
                    // Every revision on it was past the cap: accepted, as the default read does.
                    $wasGone = array_key_exists('delete', $run['revision']) || array_key_exists('moveFrom', $run['revision']);
                    unset($run['revision']);

                    return $wasGone ? ['text' => '', self::GONE => true] : $run;
                }
                if (count($rev) < count($run['revision']) && (isset($run['revision']['delete']) && !isset($rev['delete']) || isset($run['revision']['moveFrom']) && !isset($rev['moveFrom']))) {
                    // A deletion past the cap on a run whose insertion was kept: accepted, so the run goes.
                    unset($run['revision']);

                    return ['text' => '', self::GONE => true];
                }
                $run['revision'] = $rev;

                return $run;
            },
        );
        $unkept += count($over);
        if ($over !== []) {
            $doc = self::map($doc, static function (array $owner): array {
                if (is_array($owner['runs'] ?? null) && array_is_list($owner['runs'])) {
                    $owner['runs'] = array_values(array_filter($owner['runs'], static fn (mixed $r): bool => !is_array($r) || !isset($r[self::GONE])));
                }

                return $owner;
            }, null);
        }

        return $doc;
    }

    /** Marks a run fromRead() accepted away, for the sweep that removes it. */
    private const GONE = "\0lastword:revision-gone";

    /** @return array{path: string, message: string, code: string} */
    private static function error(string $path, string $message, string $code): array
    {
        return ['path' => $path, 'message' => $message, 'code' => $code];
    }
}
