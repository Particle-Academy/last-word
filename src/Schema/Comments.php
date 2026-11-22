<?php

declare(strict_types=1);

namespace LastWord\Schema;

/**
 * Word review comments: the rules, in one place, for the validator, the
 * repairer, the writer and the reader.
 *
 * A comment lives in the document's top-level `comments` list:
 * `{id, author, initials?, date?, text, replyTo?, done?}`. The text it is
 * about is the BODY runs whose `comments` list names its id, consecutive in
 * body order ({@see RunWalk}), so a range may cross paragraphs, list items and
 * table cells, and comments may overlap. A run with `text: ""` that names an id
 * is a point. A reply names the first comment of its thread in `replyTo` and
 * is anchored by nothing of its own: Word puts it on the thread's range.
 *
 * Body only. Word cannot put a comment in a header or footer, and one in a
 * footnote would anchor across two parts, so both are refused.
 *
 * `author` and `initials` are display text a person or a file chose, never an
 * identity: nothing in this package trusts them. They are still held to a
 * strict alphabet, because a name is read by a person deciding whose comment
 * this is: no control characters, no bidi controls (an override can make one
 * name display as another), no zero-width characters and no leading or
 * trailing space of any kind. The sets are explicit codepoint classes, not a
 * runtime's own `trim()`, so every port can spell the same rule.
 */
final class Comments
{
    /** Comments in one document. */
    public const MAX_COMMENTS = 10000;

    /** Comments one run may carry: how deep ranges may overlap. */
    public const MAX_PER_RUN = 32;

    /** Characters (code points) in one comment's text. */
    public const MAX_TEXT = 32768;

    /** Characters in an author. */
    public const MAX_AUTHOR = 255;

    /** Characters in initials. */
    public const MAX_INITIALS = 16;

    /** A comment id: a short ASCII name. */
    public const ID_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D';

    /** An ISO 8601 date-time, as `w:date` (xsd:dateTime) holds it. */
    private const DATE_PATTERN = '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d{1,7})?(?:Z|[+-](\d{2}):(\d{2}))?$/D';

    /**
     * Refused anywhere in an author or initials: C0 and C1 controls, bidi marks,
     * embeddings, overrides and isolates, zero-width characters and joiners, the
     * byte-order mark, line and paragraph separators, and the two noncharacters.
     */
    private const NAME_REFUSED = '/[\x{0000}-\x{001F}\x{007F}-\x{009F}\x{061C}\x{180E}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{FFFE}\x{FFFF}]/u';

    /** Space characters refused at either end of an author or initials (inside, they are fine). */
    private const EDGE_SPACE = '\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}';

    /** Refused in a comment's text: what XML 1.0 cannot carry. Tab, LF and CR are fine. */
    private const TEXT_REFUSED = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{FFFE}\x{FFFF}]/u';

    private const KEYS = ['id', 'author', 'initials', 'date', 'text', 'replyTo', 'done'];

    /** @return array<string, mixed> */
    public static function jsonSchemaDef(): array
    {
        return [
            'type' => 'object',
            'required' => ['id', 'author', 'text'],
            'additionalProperties' => false,
            'description' => 'A Word review comment. It is ABOUT the body runs whose "comments" list names its id: {"text": "18%", "comments": ["c1"]}. A range is those runs, consecutive in reading order; it may cross paragraphs and table cells, and comments may overlap. A run with text "" that names an id is a point. Body text only: not a header, footer or footnote.',
            'properties' => [
                'id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$', 'description' => 'Unique in the document. read() names them c1, c2 ... in file order.'],
                'author' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_AUTHOR, 'description' => 'Display name. No control or bidi characters and no leading or trailing space. A label, never an identity.'],
                'initials' => ['type' => 'string', 'minLength' => 1, 'maxLength' => self::MAX_INITIALS, 'description' => 'What Word shows in the margin, e.g. "AR". Same character rules as author.'],
                'date' => ['type' => 'string', 'description' => 'ISO 8601 date-time, e.g. "2026-11-21T09:30:00Z". Omit it for none: the writer never adds a timestamp of its own.'],
                'text' => ['type' => 'string', 'maxLength' => self::MAX_TEXT, 'description' => 'The comment. "\n" starts a new paragraph, "\t" is a tab.'],
                'replyTo' => ['type' => 'string', 'description' => 'The id of the FIRST comment of the thread this replies to (Word threads are one level). A reply is anchored by its thread: no run names it.'],
                'done' => ['type' => 'boolean', 'description' => 'Resolved in Word ("Resolve thread").'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function runJsonSchema(): array
    {
        return [
            'type' => 'array',
            'items' => ['type' => 'string'],
            'uniqueItems' => true,
            'maxItems' => self::MAX_PER_RUN,
            'description' => 'Ids of the comments (top-level "comments") this run is part of the range of. Body text only.',
        ];
    }

    // ─── Character rules ────────────────────────────────────────────────

    public static function isId(mixed $id): bool
    {
        return is_string($id) && preg_match(self::ID_PATTERN, $id) === 1;
    }

    /** An author (cap {@see MAX_AUTHOR}) or initials (cap {@see MAX_INITIALS}). */
    public static function isName(mixed $name, int $cap): bool
    {
        if (!is_string($name) || $name === '' || preg_match('//u', $name) !== 1) {
            return false;
        }
        if (mb_strlen($name, 'UTF-8') > $cap || preg_match(self::NAME_REFUSED, $name) === 1) {
            return false;
        }

        return preg_match('/^[' . self::EDGE_SPACE . ']|[' . self::EDGE_SPACE . ']$/u', $name) !== 1;
    }

    /** A name cleaned into one {@see isName()} accepts, or '' when nothing is left. */
    public static function cleanName(string $name, int $cap): string
    {
        $name = mb_scrub($name, 'UTF-8');
        $name = (string) preg_replace(self::NAME_REFUSED, '', $name);
        $name = (string) preg_replace('/^[' . self::EDGE_SPACE . ']+|[' . self::EDGE_SPACE . ']+$/u', '', $name);
        if (mb_strlen($name, 'UTF-8') > $cap) {
            $name = (string) preg_replace('/[' . self::EDGE_SPACE . ']+$/u', '', mb_substr($name, 0, $cap, 'UTF-8'));
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

    /** null when the text is fine, else the error code. */
    public static function textProblem(mixed $text): ?string
    {
        if (!is_string($text) || preg_match('//u', $text) !== 1 || preg_match(self::TEXT_REFUSED, $text) === 1) {
            return 'comment_text_invalid';
        }

        return mb_strlen($text, 'UTF-8') > self::MAX_TEXT ? 'comment_text_too_long' : null;
    }

    /** Text cleaned for the model: what XML cannot carry removed, line ends as "\n". */
    public static function cleanText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", mb_scrub($text, 'UTF-8'));

        return (string) preg_replace(self::TEXT_REFUSED, '', $text);
    }

    // ─── The document ───────────────────────────────────────────────────

    /**
     * The document's comments, by id, for the ones well-formed enough to anchor
     * to: `id => {index, reply}`. First wins on a duplicate.
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, array{index: int, reply: bool}>
     */
    public static function index(array $doc): array
    {
        $out = [];
        $list = $doc['comments'] ?? null;
        if (!is_array($list) || !array_is_list($list)) {
            return [];
        }
        foreach ($list as $i => $comment) {
            if (is_array($comment) && self::isId($comment['id'] ?? null) && !isset($out[$comment['id']])) {
                $out[$comment['id']] = ['index' => $i, 'reply' => array_key_exists('replyTo', $comment)];
            }
        }

        return $out;
    }

    /**
     * Every error the comments make: the list, each comment, each anchor, and
     * what only the whole document can say (every thread anchored, every range
     * unbroken).
     *
     * @param  array<string, mixed>  $doc
     * @return list<array{path: string, message: string, code: string}>
     */
    public static function validate(array $doc): array
    {
        $errors = [];
        $has = array_key_exists('comments', $doc);
        $list = $doc['comments'] ?? [];
        if ($has && (!is_array($list) || !array_is_list($list))) {
            $errors[] = self::error('comments', 'comments must be a list of {id, author, text, ...} objects', 'comment_invalid');
            $list = [];
        }
        if (count($list) > self::MAX_COMMENTS) {
            $errors[] = self::error('comments', 'a document holds at most ' . self::MAX_COMMENTS . ' comments', 'comment_count_exceeded');
        }

        $ids = [];
        foreach ($list as $i => $comment) {
            $at = "comments[{$i}]";
            if (!is_array($comment) || array_is_list($comment)) {
                $errors[] = self::error($at, 'a comment must be an object: {"id": "c1", "author": "Ann", "text": "..."}', 'comment_invalid');

                continue;
            }
            foreach (array_keys($comment) as $key) {
                if (!in_array($key, self::KEYS, true)) {
                    $errors[] = self::error("{$at}.{$key}", 'a comment has no "' . $key . '"; it takes ' . implode(', ', self::KEYS), 'comment_invalid');
                }
            }
            $id = $comment['id'] ?? null;
            if (!self::isId($id)) {
                $errors[] = self::error("{$at}.id", 'comment id must be a letter or digit, then up to 63 letters, digits, "_", "." or "-" (ASCII)', 'comment_id_invalid');
            } elseif (isset($ids[$id])) {
                $errors[] = self::error("{$at}.id", "comment id \"{$id}\" is already used by comments[{$ids[$id]}]", 'comment_id_duplicate');
            } else {
                $ids[$id] = $i;
            }
            if (!self::isName($comment['author'] ?? null, self::MAX_AUTHOR)) {
                $errors[] = self::error("{$at}.author", 'comment author must be 1-' . self::MAX_AUTHOR . ' characters of text, with no control, bidi or zero-width characters and no space at either end', 'comment_author_invalid');
            }
            if (array_key_exists('initials', $comment) && !self::isName($comment['initials'], self::MAX_INITIALS)) {
                $errors[] = self::error("{$at}.initials", 'comment initials must be 1-' . self::MAX_INITIALS . ' characters, with the same rules as author (or omitted)', 'comment_initials_invalid');
            }
            if (array_key_exists('date', $comment) && !self::isDate($comment['date'])) {
                $errors[] = self::error("{$at}.date", 'comment date must be an ISO 8601 date-time such as "2026-11-21T09:30:00Z" (or omitted)', 'comment_date_invalid');
            }
            $problem = self::textProblem($comment['text'] ?? null);
            if ($problem !== null) {
                $errors[] = self::error("{$at}.text", $problem === 'comment_text_too_long'
                    ? 'comment text is at most ' . self::MAX_TEXT . ' characters'
                    : 'comment requires "text": UTF-8 text without control characters other than tab and newline', $problem);
            }
            if (array_key_exists('done', $comment) && !is_bool($comment['done'])) {
                $errors[] = self::error("{$at}.done", 'comment done must be a boolean', 'comment_invalid');
            }
        }

        // Threads: a reply names a comment that exists, is not itself, and is not a reply.
        $index = self::index($doc);
        foreach ($list as $i => $comment) {
            if (!is_array($comment) || !array_key_exists('replyTo', $comment)) {
                continue;
            }
            $to = $comment['replyTo'];
            if (!is_string($to) || !isset($index[$to]) || $to === ($comment['id'] ?? null) || $index[$to]['reply']) {
                $errors[] = self::error("comments[{$i}].replyTo", 'replyTo must name the first comment of a thread: an existing comment that is not itself a reply', 'comment_reply_invalid');
            }
        }

        // Anchors, in body order.
        $last = [];      // id => body ordinal of the last run that named it
        $anchored = [];  // id => true
        $ordinal = 0;
        foreach (RunWalk::all($doc) as ['run' => $run, 'path' => $path, 'where' => $where]) {
            $isBody = $where === 'body';
            $mine = $isBody ? $ordinal++ : -1;
            if (!array_key_exists('comments', $run)) {
                continue;
            }
            $at = "{$path}.comments";
            if (!$isBody) {
                $errors[] = self::error($at, $where === 'band'
                    ? 'a header or footer cannot hold a comment (Word allows comments in body text only)'
                    : 'a footnote cannot hold a comment here; comment on the footnote mark in the body instead', 'comment_outside_body');

                continue;
            }
            $names = $run['comments'];
            if (!is_array($names) || !array_is_list($names) || count(array_filter($names, 'is_string')) !== count($names)
                || count(array_unique($names)) !== count($names)) {
                $errors[] = self::error($at, 'run comments must be a list of distinct comment ids, e.g. ["c1"]', 'comment_anchor_invalid');

                continue;
            }
            if (count($names) > self::MAX_PER_RUN) {
                $errors[] = self::error($at, 'a run is part of at most ' . self::MAX_PER_RUN . ' comments', 'comment_overlap_exceeded');

                continue;
            }
            foreach ($names as $name) {
                if (!isset($index[$name])) {
                    $errors[] = self::error($at, self::isId($name) ? "no comment has the id \"{$name}\"" : 'an anchor names something that is not a comment id', 'comment_unknown'); // never echo an arbitrary string back

                    continue;
                }
                if ($index[$name]['reply']) {
                    $errors[] = self::error($at, "comment \"{$name}\" is a reply: it is anchored by its thread, so name the thread's first comment instead", 'comment_reply_anchored');

                    continue;
                }
                if (isset($last[$name]) && $last[$name] !== $mine - 1) {
                    $errors[] = self::error($at, "comment \"{$name}\" covers runs that are not consecutive: a range has no gaps; split it into two comments", 'comment_range_broken');
                }
                $last[$name] = $mine;
                $anchored[$name] = true;
            }
        }
        foreach ($index as $id => $entry) {
            if (!$entry['reply'] && !isset($anchored[$id])) {
                $errors[] = self::error("comments[{$entry['index']}]", "comment \"{$id}\" is anchored by no run: put \"{$id}\" in the comments of the runs it is about (or a run with text \"\" for a point)", 'comment_unanchored');
            }
        }

        return $errors;
    }

    /**
     * Repair what can be repaired without guessing: an author's or initials'
     * refused characters and edge spaces go (initials left empty go too); an
     * anchor in a header, footer or footnote goes; an id that names no comment,
     * a reply or a duplicate leaves the run; a range keeps its first unbroken
     * stretch; and a comment nothing anchors goes, with its replies. Each with a
     * note. An author with nothing left is NOT invented: that stays an error.
     *
     * @param  array<string, mixed>  $doc
     * @param  list<array{path: string, message: string}>  $notes
     * @return array<string, mixed>
     */
    public static function repair(array $doc, array &$notes): array
    {
        $list = $doc['comments'] ?? null;
        if (is_array($list) && array_is_list($list)) {
            foreach ($list as $i => $comment) {
                if (!is_array($comment)) {
                    continue;
                }
                if (is_string($comment['author'] ?? null) && !self::isName($comment['author'], self::MAX_AUTHOR)) {
                    $clean = self::cleanName($comment['author'], self::MAX_AUTHOR);
                    if ($clean !== '') {
                        $list[$i]['author'] = $clean;
                        $notes[] = ['path' => "comments[{$i}].author", 'message' => 'removed control, bidi and zero-width characters and edge spaces from the author'];
                    }
                }
                if (is_string($comment['initials'] ?? null) && !self::isName($comment['initials'], self::MAX_INITIALS)) {
                    $clean = self::cleanName($comment['initials'], self::MAX_INITIALS);
                    if ($clean === '') {
                        unset($list[$i]['initials']);
                        $notes[] = ['path' => "comments[{$i}].initials", 'message' => 'dropped initials with no usable characters'];
                    } else {
                        $list[$i]['initials'] = $clean;
                        $notes[] = ['path' => "comments[{$i}].initials", 'message' => 'removed control, bidi and zero-width characters and edge spaces from the initials'];
                    }
                }
            }
            $doc['comments'] = $list;
        }

        $index = self::index($doc);
        $last = [];
        $closed = [];
        $anchored = [];
        $ordinal = 0;
        $doc = RunWalk::map($doc, static function (array $run, string $path, string $where) use ($index, &$last, &$closed, &$anchored, &$ordinal, &$notes): array {
            $mine = $where === 'body' ? $ordinal++ : -1;
            if (!array_key_exists('comments', $run)) {
                return $run;
            }
            if ($where !== 'body' || !is_array($run['comments'])) {
                unset($run['comments']);
                $notes[] = ['path' => "{$path}.comments", 'message' => $where === 'body' ? 'dropped run comments that are not a list' : 'dropped comments outside body text'];

                return $run;
            }
            $kept = [];
            foreach ($run['comments'] as $name) {
                $ok = is_string($name) && isset($index[$name]) && !$index[$name]['reply'] && !in_array($name, $kept, true)
                    && !isset($closed[$name]) && count($kept) < self::MAX_PER_RUN;
                if ($ok && isset($last[$name]) && $last[$name] !== $mine - 1) {
                    $closed[$name] = true; // the range broke: keep its first stretch
                    $ok = false;
                }
                if (!$ok) {
                    $notes[] = ['path' => "{$path}.comments", 'message' => 'dropped ' . (self::isId($name) ?"\"{$name}\"" : 'a non-id') . ' from the run: no such comment, a reply, a repeat, past a broken range or over the cap'];

                    continue;
                }
                $kept[] = $name;
                $last[$name] = $mine;
                $anchored[$name] = true;
            }
            if ($kept === []) {
                unset($run['comments']);
            } else {
                $run['comments'] = $kept;
            }

            return $run;
        });

        if (is_array($doc['comments'] ?? null) && array_is_list($doc['comments'])) {
            $out = [];
            foreach ($doc['comments'] as $i => $comment) {
                $id = is_array($comment) ? ($comment['id'] ?? null) : null;
                $root = is_array($comment) && array_key_exists('replyTo', $comment) ? $comment['replyTo'] : $id;
                if (is_string($root) && isset($index[$root]) && !$index[$root]['reply'] && !isset($anchored[$root])) {
                    $notes[] = ['path' => "comments[{$i}]", 'message' => 'dropped a comment that no run anchors' . ($root !== $id ? ' (a reply to one)' : '')];

                    continue;
                }
                $out[] = $comment;
            }
            $doc['comments'] = $out;
        }

        return $doc;
    }

    /**
     * What describe() says: "Comments: 7 (1 reply, 1 done)", or null without any.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function describe(array $doc): ?string
    {
        $list = $doc['comments'] ?? null;
        if (!is_array($list) || $list === []) {
            return null;
        }
        $replies = count(array_filter($list, static fn (mixed $c): bool => is_array($c) && array_key_exists('replyTo', $c)));
        $done = count(array_filter($list, static fn (mixed $c): bool => is_array($c) && ($c['done'] ?? false) === true));
        $parts = [];
        if ($replies > 0) {
            $parts[] = $replies . ' repl' . ($replies === 1 ? 'y' : 'ies');
        }
        if ($done > 0) {
            $parts[] = $done . ' done';
        }

        return 'Comments: ' . count($list) . ($parts !== [] ? ' (' . implode(', ', $parts) . ')' : '');
    }

    /** @return array{path: string, message: string, code: string} */
    private static function error(string $path, string $message, string $code): array
    {
        return ['path' => $path, 'message' => $message, 'code' => $code];
    }
}
