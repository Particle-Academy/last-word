<?php

declare(strict_types=1);

namespace LastWord\Reader;

use DOMDocument;
use DOMElement;
use DOMNode;
use LastWord\Archive\OfficeTemplate;
use LastWord\Archive\PartXml;
use LastWord\Archive\SourceArchive;
use LastWord\Exceptions\TrackedChangesRefused;
use LastWord\Schema\Revisions;
use LastWord\Schema\Schema;
use LastWord\Schema\Shapes;
use LastWord\Writer\DocxWriter;
use LastWord\Writer\TemplateSection;
use RuntimeException;

/**
 * DOCX reader — parses .docx bytes back into the Doc model.
 *
 * Handles this package's own writer output (lossless round-trip of the
 * semantic model), the Node mirror's output (@particle-academy/last-word —
 * same metadata slots since 0.2.0) AND tolerates Word-authored files:
 *
 *   - title from docProps/core.xml (dc:title, the cross-language slot);
 *     falls back to the pre-0.2.0 Title-styled paragraph
 *   - code blocks via `lastword:code[:{lang}]` w:sdt content controls
 *     (canonical), pre-0.2.0 `LastWordCode_{lang}` bookmarks, or bare
 *     CodeBlock-styled paragraphs; quotes via `lastword:quote` sdt or bare
 *     Quote-styled paragraphs
 *   - headings via pStyle Heading1-9 (clamped to 6) OR outlineLvl
 *   - run formatting: b / i / u / strike / color / highlight (named colors
 *     mapped to hex) / run shading fills / InlineCode char style
 *   - hyperlinks resolved through document.xml.rels
 *   - numPr lists with ilvl nesting; decimal numFmt → ordered, unknown
 *     numIds bucketed as unordered
 *   - tables (nested blocks in cells), header rows via w:tblHeader
 *   - images via a:blip r:embed → data URLs, extents → widthPx/heightPx
 *   - page breaks, bottom-border-only paragraphs → hr
 *   - unknown constructs degrade to plain paragraphs / get skipped —
 *     the reader never throws on strange XML
 *
 * Elements are matched by localName only, so files with unusual namespace
 * prefixes still parse.
 */
final class DocxReader
{
    /**
     * Faces that mean `code` rather than a font choice. Word-authored files
     * mark inline code by picking a monospace family and nothing else, so the
     * reader has to recognise the family; the writer's own output is
     * unambiguous (it also carries the InlineCode character style).
     */
    private const MONO_FONTS = ['consolas', 'courier new', 'courier', 'menlo', 'monaco', 'source code pro'];

    /** The header-cell grey, shared with the writer and both sibling engines. */
    private const HEADER_FILL = 'E7E7E7';

    /** Box edges, in the order every CT_ border and margin type declares them. */
    private const BOX_EDGES = ['top', 'left', 'bottom', 'right'];

    private const TABLE_EDGES = ['top', 'left', 'bottom', 'right', 'insideH', 'insideV'];

    /** Page sizes in twips, portrait — mirrors the writer's table. */
    private const PAGE_SIZES = [
        'letter' => [12240, 15840],
        'legal' => [12240, 20160],
        'a4' => [11906, 16838],
    ];

    /** w:highlight named colors → hex. */
    private const HIGHLIGHT_COLORS = [
        'yellow' => '#FFFF00', 'green' => '#00FF00', 'cyan' => '#00FFFF',
        'magenta' => '#FF00FF', 'blue' => '#0000FF', 'red' => '#FF0000',
        'darkBlue' => '#00008B', 'darkCyan' => '#008B8B', 'darkGreen' => '#006400',
        'darkMagenta' => '#8B008B', 'darkRed' => '#8B0000', 'darkYellow' => '#808000',
        'darkGray' => '#A9A9A9', 'lightGray' => '#D3D3D3',
        'black' => '#000000', 'white' => '#FFFFFF',
    ];

    /** rId → ['target' => string, 'external' => bool] */
    private array $rels = [];

    /** numId → ordered? (level-0 numFmt === decimal-ish) */
    private array $numbering = [];

    /** zip entry name (without leading word/) → bytes, for media resolution. */
    private array $media = [];

    /** header/footer part name (without leading word/) → XML. */
    private array $bandParts = [];

    /**
     * header/footer part name (without leading word/) → that part's OWN
     * relationships, which resolve its images and links (last-word#6).
     *
     * @var array<string, array<string, array{target: string, external: bool}>>
     */
    private array $bandRels = [];

    /**
     * True while a header or footer is read as blocks: images are held to the
     * template letterhead's media checks, PAGE / NUMPAGES fields read as
     * `{page}` / `{pages}`, and a picture before a paragraph's text goes first.
     */
    private bool $inBand = false;

    /** Image bytes taken into header/footer blocks so far, against the media total cap. */
    private int $bandMediaBytes = 0;

    /**
     * The document's footnotes, by `w:id`, from the part its `footnotes`
     * relationship names: the `w:footnote` elements of its own (separators and
     * continuation notices are not footnotes).
     *
     * @var array<string, DOMElement>
     */
    private array $footnoteNodes = [];

    /** The footnotes part's OWN relationships, which resolve its links and images. */
    private array $footnoteRels = [];

    /** A footnote's blocks once read, by id: a footnote referenced twice is read once. */
    private array $footnoteCache = [];

    /** True while a footnote's blocks are read: a reference inside one is ignored. */
    private bool $inFootnote = false;

    /** chart part name (without leading word/) → XML. */
    private array $chartParts = [];

    /**
     * The section's text column in twips, read before any block so a chart
     * that spans it -- the writer's default -- reads back without a size.
     */
    private int $contentWidth = 9360;

    /**
     * What ending each section does to the blocks, in order: the second section's
     * first. Worked out before the body is read, because a band part is read with
     * the same machinery as the body.
     *
     * @var list<array{break: ?array<string, mixed>, width: int, dropped: int, notes: list<array{where: string, kind: string, count: int}>}>
     */
    private array $sectionPlan = [];

    private int $sectionCursor = 0;

    /**
     * The key a run carries when it is a column break: its text is the "\n" the
     * break reads as wherever it cannot be a block, and the paragraph splits there
     * where it can. Only this reader makes runs, so no document can forge it, and
     * it never leaves {@see parseParagraphNode()}.
     */
    private const COLUMN_MARK = "\0columnBreak";

    /** How many columns the section being read has, as the model holds it: where a column break can be a block. */
    private int $sectionColumns = 1;

    /** The band part a section's reference types name, inherited from the section before: kind => type => part. */
    private array $sectionTargets = [];

    /** A band part once read, by part and model key: a part several sections share is read, and reported, once. */
    private array $bandCache = [];

    /** docProps/core.xml contents, when the archive has one. */
    private ?string $coreXml = null;

    private ?string $stylesXml = null;

    private ?string $title = null;

    private string $sourceKind = OfficeTemplate::DOCUMENT;

    /**
     * What a header or footer holds that the model's band cannot (last-word#5):
     * `{where, kind, count}`, `kind` from the exchange report's list (`image`,
     * `nested-block`). Reported, so a letterhead's logo is not lost in silence.
     *
     * @var list<array{where: string, kind: string, count: int}>
     */
    private array $unrepresented = [];

    /**
     * The body bookmarks read so far, lower-cased: Word compares names ignoring
     * case, so the second of two that differ only by case is not read.
     *
     * @var array<string, true>
     */
    private array $bookmarksSeen = [];

    /** How deeply body fields nest before the reader stops tracking them. */
    private const MAX_FIELD_DEPTH = 32;

    /**
     * How many times one read looks ahead for a table of contents' end. Each look
     * can run to the end of its container, so a file of unclosed TOCs is bounded;
     * past it a TOC reads as its text, as an unclosed one does.
     */
    private const MAX_TOC_SCANS = 20;

    /** The `toc` blocks read so far, capped at {@see Schema::MAX_TOCS}. */
    private int $tocCount = 0;

    private int $tocScans = 0;

    /**
     * The ids of the body bookmarks read, so only their ends become markers: a
     * hidden or refused bookmark's end must not break a user bookmark's range.
     *
     * @var array<string, true>
     */
    private array $bookmarkIds = [];

    /**
     * Revision markup found anywhere in the package's word parts, by kind:
     * `{insertions, deletions, moves, formatChanges}`. The reader accepts every
     * revision as it reads, so this is the only trace of them in the result.
     *
     * @var array{insertions: int, deletions: int, moves: int, formatChanges: int}
     */
    private array $trackedChanges = self::NO_TRACKED_CHANGES;

    /** The document's review comments, or null without a comments part. */
    private ?DocxComments $comments = null;

    /** The w:r whose runs are being read: what a body run's comments are looked up by. */
    private ?DOMElement $commentRun = null;
    /**
     * With `preserveTrackedChanges`: the revision a run is being read inside, an
     * arrival (`insert`, `moveTo`) and a departure (`delete`, `moveFrom`), each
     * `[kind, entry]`; and the formatting change of the w:r being read. Null
     * outside one, and always null in a header or footer.
     *
     * @var array{0: string, 1: array<string, mixed>}|null
     */
    private ?array $revArrival = null;

    /** @var array{0: string, 1: array<string, mixed>}|null */
    private ?array $revDeparture = null;

    /** @var array<string, mixed>|null */
    private ?array $runFormat = null;

    /** Temporary revision ids, one per element: {@see Revisions::fromRead()} renumbers them. */
    private int $revSeq = 0;

    /** Revision markup in the body and footnotes not kept as it was: accepted, or changed to fit. */
    private int $revUnkept = 0;

    /** Inside a text box's content: no bookmark, comment, footnote or tracked change is read into the model there. */
    private bool $inTextBox = false;

    /** Shapes read into the model so far: past {@see Shapes::MAX_SHAPES} a shape is read as its text. */
    private int $shapeCount = 0;

    /** How deep the `mc:AlternateContent` being read is nested, in paragraphs and runs. */
    private int $mcDepth = 0;

    /** Where a shape report goes: `/blocks`, or the header or footer being read. */
    private string $shapeWhere = '/blocks';

    /** Blocks read inside a text box that the box cannot hold (a footnote's): placed after the box. */
    private array $boxSpill = [];

    /**
     * The deepest `mc:AlternateContent` read as markup inside a paragraph or a
     * run; any deeper is read as its text, paragraph by paragraph, and reported.
     * Between blocks there is no cap: the part's nesting limit bounds that walk.
     */
    private const MAX_MC_DEPTH = 8;

    /** Open move ranges, by direction and range id => name. @var array{from: array<string, string>, to: array<string, string>} */
    private array $openMoves = ['from' => [], 'to' => []];

    public function __construct(
        private ?string $tempDir = null,
        private bool $refuseTrackedChanges = false,
        private bool $preserveTrackedChanges = false,
    ) {
    }

    /**
     * Parse DOCX bytes into the Doc model.
     *
     * @return array<string, mixed>
     */
    public function read(string $bytes): array
    {
        $documentXml = $this->openArchive($bytes);
        if ($this->refuseTrackedChanges && $this->trackedChanges !== self::NO_TRACKED_CHANGES) {
            throw new TrackedChangesRefused(...$this->trackedChanges);
        }
        $this->unrepresented = [];
        $this->bookmarksSeen = [];
        $this->bookmarkIds = [];
        $this->tocCount = 0;
        $this->tocScans = 0;
        $this->bandMediaBytes = 0;
        $this->inBand = false;
        $this->inFootnote = false;
        $this->footnoteCache = [];
        $this->sectionPlan = [];
        $this->sectionCursor = 0;
        $this->sectionColumns = 1;
        $this->sectionTargets = [];
        $this->bandCache = [];
        $this->revArrival = null;
        $this->revDeparture = null;
        $this->runFormat = null;
        $this->revSeq = 0;
        $this->revUnkept = 0;
        $this->openMoves = ['from' => [], 'to' => []];
        $this->inTextBox = false;
        $this->shapeCount = 0;
        $this->mcDepth = 0;
        $this->boxSpill = [];

        $dom = PartXml::dom($documentXml);
        if ($dom === null) {
            throw new RuntimeException('Could not parse word/document.xml.');
        }

        $body = $this->firstChildByName($dom->documentElement, 'body');
        if ($body !== null) {
            $this->comments?->index($body); // every range, before any block is read
        }

        // Canonical title slot: docProps/core.xml dc:title (shared with the
        // Node mirror). When absent, the pre-0.2.0 legacy slot — the first
        // top-level Title-styled paragraph — is consumed instead (see
        // parseBlockContainer()).
        $this->title = $this->parseCoreTitle();
        // Top-level `page` is the first section, and every later one a break holding
        // what differs from the page before it.
        [$sections, $dropped] = $body !== null ? $this->sections($body) : [[null], 0];
        $page = $this->parsePage($sections[0], $dom->documentElement);
        $this->contentWidth = Schema::columnTextTwips($page['columns'] ?? null, $this->contentWidthOf($sections[0]));
        $this->sectionColumns = Schema::columnCount($page['columns'] ?? null);
        $this->planSections($sections, $page ?? []);
        $dropped += $this->droppedByFirstSection($sections[0]);
        $blocks = $body !== null ? $this->parseBlockContainer($body, true) : [];

        $doc = [];
        if ($this->title !== null && $this->title !== '') {
            $doc['title'] = $this->title;
        }
        // meta.author (0.16.0): dc:creator, beside the title it shares a part with.
        $author = $this->parseCoreElement('creator');
        if ($author !== null) {
            $doc['meta'] = ['author' => $author];
        }

        // Section geometry and document defaults are surfaced ONLY when they
        // differ from what the writer produces unasked, for the same reason
        // table options are: a Letter portrait page at one-inch margins is
        // what every document written before `page` existed contains.
        if ($page !== null) {
            $doc['page'] = $page;
        }
        if ($dropped > 0) {
            $this->unrepresented[] = ['where' => '/page', 'kind' => 'layout-not-preserved', 'count' => $dropped];
        }
        foreach ($this->parseDocDefaults() as $key => $value) {
            $doc[$key] = $value;
        }

        if (!$this->preserveTrackedChanges && $this->trackedChanges['deletions'] > 0) {
            // Accepting a deleted paragraph mark joins it to the next paragraph, as Word does.
            $blocks = array_values(array_filter(Revisions::acceptBlocks($blocks)[0], static fn (mixed $b): bool => $b !== null));
        }
        if ($this->preserveTrackedChanges && $this->trackedChanges !== self::NO_TRACKED_CHANGES) {
            // What the model has no slot for, then the whole document brought inside the rules.
            $this->revUnkept += $this->unkeptRevisions($dom) + ($this->footnotesDom !== null ? $this->unkeptRevisions($this->footnotesDom) : 0);
            $blocks = Revisions::fromRead(['blocks' => $blocks], $this->revUnkept)['blocks'];
            if ($this->revUnkept > 0) {
                $this->unrepresented[] = ['where' => '/blocks', 'kind' => 'tracked-change', 'count' => $this->revUnkept];
            }
            $inBands = 0;
            foreach ($this->bandParts as $xml) {
                $inBands += $this->revisionElements((string) $xml);
            }
            if ($inBands > 0) {
                // A header or footer is read as the default read reads it: accepted.
                $this->unrepresented[] = ['where' => '/page', 'kind' => 'tracked-change', 'count' => $inBands];
            }
        }

        $doc['blocks'] = $blocks;
        $review = $this->comments?->finish() ?? ['comments' => [], 'unrepresented' => []];
        if ($review['comments'] !== []) {
            $doc['comments'] = $review['comments'];
        }
        array_push($this->unrepresented, ...$review['unrepresented']);
        // Never document text: locations and counts only, like the exchange report.
        if ($this->unrepresented !== []) {
            $doc['unrepresented'] = $this->unrepresented;
        }
        // Counts only, and only when there are any: a document with no revisions
        // reads exactly as it did before the signal existed.
        if ($this->trackedChanges !== self::NO_TRACKED_CHANGES) {
            $doc['trackedChanges'] = ['present' => true] + $this->trackedChanges;
        }
        // A .dotx reads exactly like its .docx twin, and says so.
        if ($this->sourceKind === OfficeTemplate::TEMPLATE) {
            $doc['sourceKind'] = OfficeTemplate::TEMPLATE;
        }

        return $doc;
    }

    /**
     * The body's sections in order, each by the `w:sectPr` that describes it: the
     * ones carried by a paragraph, at the level the reader takes blocks from, then
     * the body's own, which is the last section's (null when the file has none).
     * Also how many sit inside a code or quote block: those are not read, because
     * nothing in the model can break there.
     *
     * @return array{0: list<?DOMElement>, 1: int}
     */
    private function sections(DOMElement $body): array
    {
        $sections = [];
        $nested = 0;
        foreach ($this->blockChildren($body) as $node) {
            if ($node->localName === 'p') {
                $sectPr = $this->firstChildByName($this->firstChildByName($node, 'pPr'), 'sectPr');
                if ($sectPr !== null) {
                    $sections[] = $sectPr;
                }
            } elseif ($node->localName === 'sdt') {
                $content = $this->firstChildByName($node, 'sdtContent');
                $nested += $content !== null ? $this->sectionsIn($content) : 0;
            }
        }
        $sections[] = $this->firstChildByName($body, 'sectPr');

        return [$sections, $nested];
    }

    /** Section breaks at any block level under a node. A walk: this nesting is attacker-supplied. */
    private function sectionsIn(DOMElement $root): int
    {
        $count = 0;
        $stack = [$root];
        while ($stack !== []) {
            foreach (array_pop($stack)->childNodes as $node) {
                if (!($node instanceof DOMElement)) {
                    continue;
                }
                if ($node->localName === 'p') {
                    if ($this->firstChildByName($this->firstChildByName($node, 'pPr'), 'sectPr') !== null) {
                        $count++;
                    }
                } elseif ($node->localName === 'customXml') {
                    $stack[] = $node;
                } elseif ($node->localName === 'sdt') {
                    $content = $this->firstChildByName($node, 'sdtContent');
                    if ($content !== null) {
                        $stack[] = $content;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Word's `w:type` says how a section STARTS, relative to the one before it, so
     * the break between sections k-1 and k is decided by section k's type. Absent is
     * nextPage. The first section's type therefore means nothing here.
     *
     * | Section k                                      | Result                                  |
     * |---|---|
     * | nextPage                                       | break, `page` holding what differs      |
     * | oddPage, evenPage                              | the same, plus a report                 |
     * | continuous, same size and orientation          | break `{start: "continuous"}` with any  |
     * |                                                | columns change; anything else reported  |
     * | continuous, other size or orientation          | break (Word has to start a page)        |
     * | w:cols the model cannot hold as written        | the nearest it can, plus a report       |
     * | a page-number restart                          | dropped, plus a report                  |
     * | a paper size the model has no name for         | dropped, plus a report                  |
     *
     * A continuous section changes only the model's columns, so the next section is
     * compared with the page the model actually has.
     *
     * @param  list<?DOMElement>  $sections
     * @param  array<string, mixed>  $page  the first section's page, as read
     */
    private function planSections(array $sections, array $page): void
    {
        $current = $page;
        foreach ($sections as $k => $sectPr) {
            if ($k === 0) {
                continue;
            }
            $mark = count($this->unrepresented);
            $next = $this->parsePage($sectPr) ?? [];
            $notes = array_splice($this->unrepresented, $mark);
            $knownPaper = $this->paperKnown($sectPr);

            $change = self::pageChange($current, $next, $knownPaper);
            $reshaped = isset($change['orientation']) || isset($change['size']);
            $typeEl = $sectPr !== null ? $this->firstChildByName($sectPr, 'type') : null;
            $type = $typeEl !== null ? $this->wAttr($typeEl, 'val') : null;

            $dropped = $this->parseColumns($sectPr)['dropped'];
            if ($type === 'continuous' && !$reshaped) {
                // On the same page: the columns change, and nothing else can.
                $break = ['start' => 'continuous'] + array_intersect_key($change, ['columns' => true]);
                $dropped += array_diff_key($change, ['columns' => true]) !== [] ? 1 : 0;
                unset($current['columns']);
                if (isset($next['columns'])) {
                    $current['columns'] = $next['columns'];
                }
            } else {
                $break = $change;
                $dropped += $type === null || in_array($type, ['nextPage', 'continuous'], true) ? 0 : 1;
                $next = $knownPaper ? $next : self::withSize($next, $current);
                $current = $next;
            }
            $dropped += $this->restarts($sectPr, true) ? 1 : 0;
            $dropped += $knownPaper ? 0 : 1;

            $this->sectionPlan[] = [
                'break' => $break,
                'width' => Schema::columnTextTwips($current['columns'] ?? null, $this->contentWidthOf($sectPr)),
                'columns' => Schema::columnCount($current['columns'] ?? null),
                'dropped' => $dropped,
                'notes' => $notes,
            ];
        }
    }

    /**
     * What a section changes from the page before it, in the schema's order. A side
     * is compared with defaults filled in, so a margin returning to one inch says so.
     * A band a section no longer shows is `{"blocks": []}`, and a frame `{"style":
     * "none"}`: without them the model's own inheritance would bring the old one back.
     * The first-page bands are never inherited, so they are never compared.
     *
     * @param  array<string, mixed>  $from
     * @param  array<string, mixed>  $to
     * @return array<string, mixed>
     */
    private static function pageChange(array $from, array $to, bool $knownPaper): array
    {
        $out = [];
        $orientation = static fn (array $p): string => $p['orientation'] ?? 'portrait';
        if ($orientation($from) !== $orientation($to)) {
            $out['orientation'] = $orientation($to);
        }
        $size = static fn (array $p): string => $p['size'] ?? 'letter';
        if ($knownPaper && $size($from) !== $size($to)) {
            $out['size'] = $size($to);
        }
        $margins = [];
        foreach ([...self::BOX_EDGES, 'header', 'footer'] as $side) {
            $default = $side === 'header' || $side === 'footer' ? 36 : 72;
            $value = $to['margins'][$side] ?? $default;
            if ((float) ($from['margins'][$side] ?? $default) !== (float) $value) {
                $margins[$side] = $value;
            }
        }
        if ($margins !== []) {
            $out['margins'] = $margins;
        }
        foreach (Schema::PAGE_BANDS as $key) {
            $first = $key === 'firstHeader' || $key === 'firstFooter';
            $was = $first ? null : ($from[$key] ?? null);
            $now = $to[$key] ?? null;
            if ($now !== null && $now !== $was) {
                $out[$key] = $now;
            } elseif ($now === null && $was !== null && self::bandShows($was)) {
                $out[$key] = ['blocks' => []];
            }
        }
        $border = $to['border'] ?? null;
        $before = $from['border'] ?? null;
        if ($border !== $before) {
            $out['border'] = $border ?? ['style' => 'none'];
        }
        $columns = $to['columns'] ?? ['count' => 1];
        if ($columns !== ($from['columns'] ?? ['count' => 1])) {
            $out['columns'] = $columns;
        }

        return $out;
    }

    /** @param  array<string, mixed>  $band */
    private static function bandShows(array $band): bool
    {
        return ($band['blocks'] ?? null) !== [];
    }

    /**
     * A page keeping the paper of the page before it, when its own has no name here.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>
     */
    private static function withSize(array $page, array $before): array
    {
        unset($page['size']);
        if (isset($before['size'])) {
            $page['size'] = $before['size'];
        }

        return $page;
    }

    /** What the first section asks for that the model cannot hold. */
    private function droppedByFirstSection(?DOMElement $sectPr): int
    {
        return $this->parseColumns($sectPr)['dropped']
            + ($this->restarts($sectPr, false) ? 1 : 0)
            + ($this->paperKnown($sectPr) ? 0 : 1);
    }

    /**
     * A section's `w:cols` (ISO/IEC 29500-1 §17.6.4) as the model's `columns`, or
     * null for one column, and how many settings had to change to get there (0 or 1).
     *
     * Unequal columns are the `w:col` children (§17.6.3) unless `w:equalWidth` is
     * on; Word writes them with no `w:num`. Equal ones are `w:num` and `w:space`
     * (720 twips when absent). What the model cannot hold degrades, never by
     * dropping text: a count past {@see Schema::MAX_COLUMNS} is clamped; unusable
     * widths make the columns equal; a bad gap is the default; and columns that do
     * not fit the page become the nearest that do ({@see Schema::fitColumns()}),
     * as the repairer makes them. At most MAX_COLUMNS + 1 `w:col` are looked at:
     * the children are attacker-supplied.
     *
     * @return array{columns: ?array<string, mixed>, dropped: int}
     */
    private function parseColumns(?DOMElement $sectPr): array
    {
        $cols = $sectPr !== null ? $this->firstChildByName($sectPr, 'cols') : null;
        if ($cols === null) {
            return ['columns' => null, 'dropped' => 0];
        }
        $twips = static fn (?string $v): ?int => $v !== null && preg_match('/^\s*-?\d{1,9}\s*$/', $v) === 1 ? (int) $v : null;
        $degraded = false;

        $children = [];
        $tooMany = false;
        foreach ($cols->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'col') {
                if (count($children) === Schema::MAX_COLUMNS) {
                    $tooMany = true;

                    break;
                }
                $children[] = $node;
            }
        }
        $equalAttr = $this->wAttr($cols, 'equalWidth');
        $equal = $children === [] || ($equalAttr !== null && $this->toggleOn($equalAttr));

        $numAttr = $this->wAttr($cols, 'num');
        $num = $numAttr === null ? null : (preg_match('/^\s*-?\d+\s*$/', $numAttr) === 1 ? $numAttr : false);
        if ($num === false) {
            $degraded = true;
            $count = 1;
        } elseif ($num === null) {
            $count = $equal ? 1 : count($children);
        } else {
            $count = strlen(ltrim(trim($num), '-0')) > 3 ? (str_starts_with(trim($num), '-') ? 0 : PHP_INT_MAX) : (int) $num;
            if ($count < 1) {
                $degraded = true;
                $count = 1;
            }
        }
        if ($tooMany && !$equal) {
            $degraded = true;
            $equal = true;
            if ($num === null) {
                $count = 1;
            }
        }
        if ($count > Schema::MAX_COLUMNS) {
            $degraded = true;
            $count = Schema::MAX_COLUMNS;
        }
        $separator = ($sep = $this->wAttr($cols, 'sep')) !== null && $this->toggleOn($sep);

        $columns = ['count' => $count];
        if (!$equal && count($children) !== $count) {
            $degraded = true; // fewer w:col than w:num: equal columns at w:num
            $equal = true;
        }
        if (!$equal && $count > 1) {
            $widths = [];
            $gaps = [];
            foreach ($children as $i => $col) {
                $w = $twips($this->wAttr($col, 'w'));
                $gap = $this->wAttr($col, 'space');
                $g = $gap === null ? 0 : $twips($gap);
                if ($w === null || $w < 1 || ($i < $count - 1 && ($g === null || $g < 0))) {
                    $widths = null;

                    break;
                }
                $widths[] = Schema::tidyPoints($w / 20);
                if ($i < $count - 1) {
                    $gaps[] = Schema::tidyPoints($g / 20);
                }
            }
            if ($widths === null || min($widths) < Schema::MIN_COLUMN_WIDTH) {
                $degraded = true;
                $equal = true;
            } else {
                $columns['widths'] = $widths;
                if (count(array_unique(array_map('strval', $gaps))) === 1) {
                    if ((float) $gaps[0] !== Schema::COLUMN_SPACE_DEFAULT) {
                        $columns['space'] = $gaps[0];
                    }
                } else {
                    $columns['spaces'] = $gaps;
                }
            }
        }
        if ($equal && $count > 1) {
            $spaceAttr = $this->wAttr($cols, 'space');
            $space = $spaceAttr === null ? 720 : $twips($spaceAttr);
            if ($space === null || $space < 0) {
                $degraded = true;
                $space = 720;
            }
            if ($space !== 720) {
                $columns['space'] = Schema::tidyPoints($space / 20);
            }
        }
        if ($count > 1 && $separator) {
            $columns['separator'] = true;
        }
        if ($count > 1) {
            $fitted = Schema::fitColumns($columns, $this->contentWidthOf($sectPr));
            if ($fitted !== $columns) {
                $degraded = true;
                $columns = $fitted;
            }
        }

        return ['columns' => Schema::columnCount($columns) > 1 ? $columns : null, 'dropped' => $degraded ? 1 : 0];
    }

    /**
     * `w:pgNumType w:start`: the model's page number runs through the whole document.
     * A first section starting at 1 changes nothing; any later start is a restart.
     */
    private function restarts(?DOMElement $sectPr, bool $later): bool
    {
        $type = $sectPr !== null ? $this->firstChildByName($sectPr, 'pgNumType') : null;
        $start = $type !== null ? $this->wAttr($type, 'start') : null;

        return is_numeric($start) && ($later || (int) $start !== 1);
    }

    /** Whether the section's paper is one the model names. No `w:pgSz` is the default, so it is. */
    private function paperKnown(?DOMElement $sectPr): bool
    {
        $pgSz = $sectPr !== null ? $this->firstChildByName($sectPr, 'pgSz') : null;
        if ($pgSz === null) {
            return true;
        }
        $w = is_numeric($this->wAttr($pgSz, 'w')) ? (int) $this->wAttr($pgSz, 'w') : 12240;
        $h = is_numeric($this->wAttr($pgSz, 'h')) ? (int) $this->wAttr($pgSz, 'h') : 15840;
        if ($this->wAttr($pgSz, 'orient') === 'landscape') {
            [$w, $h] = [$h, $w];
        }
        foreach (self::PAGE_SIZES as [$pw, $ph]) {
            if ($pw === $w && $ph === $h) {
                return true;
            }
        }

        return false;
    }

    /**
     * The section the top-level walk has just passed the end of: the width the next
     * blocks measure against, what was dropped, and the break, if it is one.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    private function endSection(array &$blocks): void
    {
        $plan = $this->sectionPlan[$this->sectionCursor++] ?? null;
        if ($plan === null) {
            return;
        }
        $this->contentWidth = $plan['width'];
        $this->sectionColumns = $plan['columns'];
        $at = '/blocks/' . count($blocks);
        foreach ($plan['notes'] as $note) {
            $this->unrepresented[] = ['where' => $at . $note['where'], 'kind' => $note['kind'], 'count' => $note['count']];
        }
        if ($plan['dropped'] > 0) {
            $this->unrepresented[] = ['where' => $at, 'kind' => 'layout-not-preserved', 'count' => $plan['dropped']];
        }
        if ($plan['break'] !== null) {
            $blocks[] = ['type' => 'pageBreak', 'page' => $plan['break']];
        }
    }

    /** Twips between the margins: page width (landscape-aware) less left and right. */
    private function contentWidthOf(?DOMElement $sectPr): int
    {
        if ($sectPr === null) {
            return 9360;
        }
        $pgSz = $this->firstChildByName($sectPr, 'pgSz');
        $w = $pgSz !== null && is_numeric($this->wAttr($pgSz, 'w')) ? (int) $this->wAttr($pgSz, 'w') : 12240;
        $pgMar = $this->firstChildByName($sectPr, 'pgMar');
        $left = $pgMar !== null && is_numeric($this->wAttr($pgMar, 'left')) ? (int) $this->wAttr($pgMar, 'left') : 1440;
        $right = $pgMar !== null && is_numeric($this->wAttr($pgMar, 'right')) ? (int) $this->wAttr($pgMar, 'right') : 1440;

        return max(1, $w - $left - $right);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parsePage(?DOMElement $sectPr, ?DOMElement $document = null): ?array
    {
        if ($sectPr === null) {
            return null;
        }
        $out = [];
        $out += $this->parsePageDecoration($sectPr, $document);

        $pgSz = $this->firstChildByName($sectPr, 'pgSz');
        $w = $pgSz !== null && is_numeric($this->wAttr($pgSz, 'w')) ? (int) $this->wAttr($pgSz, 'w') : 12240;
        $h = $pgSz !== null && is_numeric($this->wAttr($pgSz, 'h')) ? (int) $this->wAttr($pgSz, 'h') : 15840;
        if ($pgSz !== null && $this->wAttr($pgSz, 'orient') === 'landscape') {
            $out['orientation'] = 'landscape';
            [$w, $h] = [$h, $w];
        }
        foreach (self::PAGE_SIZES as $name => [$pw, $ph]) {
            if ($pw === $w && $ph === $h && $name !== 'letter') {
                $out['size'] = $name;
            }
        }

        $pgMar = $this->firstChildByName($sectPr, 'pgMar');
        $margins = [];
        if ($pgMar !== null) {
            // Each value only when it differs from what the writer puts there
            // unasked -- 1440 twips a side, 720 to the header and the footer --
            // so a document read before the distances were read reads the same.
            // Schema::pageMargins() fills the defaults back in for a viewer.
            foreach ([...self::BOX_EDGES, 'header', 'footer'] as $side) {
                $value = $this->wAttr($pgMar, $side);
                $default = $side === 'header' || $side === 'footer' ? 720 : 1440;
                if (is_numeric($value) && (int) $value !== $default) {
                    $margins[$side] = self::points((float) $value);
                }
            }
        }
        if ($margins !== []) {
            $out['margins'] = $margins;
        }

        foreach ($this->parseBands($sectPr) as $key => $band) {
            $out[$key] = $band;
        }

        // Decoration last, background before border, then columns, so a page reads
        // back in the order the schema lists it.
        foreach (['background', 'border'] as $key) {
            if (isset($out[$key])) {
                $value = $out[$key];
                unset($out[$key]);
                $out[$key] = $value;
            }
        }
        $columns = $this->parseColumns($sectPr)['columns'];
        if ($columns !== null) {
            $out['columns'] = $columns;
        }

        return $out === [] ? null : $out;
    }

    /**
     * `w:background` (the page colour) and `w:pgBorders` back into
     * `page.background` / `page.border`, with fine-print's meaning: inset from
     * the page edge to the outside of the frame, width across the whole frame.
     * Defaults (single, 0.5pt, black, 24pt) are not surfaced, as for table
     * borders. A frame whose four edges differ is not one this model can hold,
     * so it is not read (it still renders in Word; it just is not in the model).
     *
     * @return array<string, mixed>
     */
    private function parsePageDecoration(DOMElement $sectPr, ?DOMElement $document): array
    {
        $out = [];
        $bg = $document !== null ? $this->firstChildByName($document, 'background') : null;
        $color = $bg !== null ? $this->wAttr($bg, 'color') : null;
        if (is_string($color) && preg_match('/^[0-9A-Fa-f]{6}$/', $color) === 1) {
            $out['background'] = '#' . strtoupper($color);
        }

        $borders = $this->firstChildByName($sectPr, 'pgBorders');
        if ($borders === null) {
            return $out;
        }
        $edges = [];
        foreach (self::BOX_EDGES as $edge) {
            $el = $this->firstChildByName($borders, $edge);
            if ($el === null) {
                return $out;
            }
            $edges[$edge] = [
                'val' => (string) $this->wAttr($el, 'val'),
                'sz' => (int) $this->wAttr($el, 'sz'),
                'space' => (int) $this->wAttr($el, 'space'),
                'color' => strtoupper((string) $this->wAttr($el, 'color')),
            ];
        }
        $style = $edges['top']['val'];
        $first = $edges['top'];
        foreach ($edges as $e) {
            if ($e['val'] !== $first['val'] || $e['sz'] !== $first['sz'] || $e['color'] !== $first['color']) {
                return $out;
            }
        }
        if (! in_array($style, ['single', 'double', 'dashed', 'dotted'], true) || $first['sz'] < 1) {
            return $out;
        }

        $width = ($style === 'double' ? 3 : 1) * $first['sz'] / 8;
        if ($this->wAttr($borders, 'offsetFrom') === 'text') {
            $pgMar = $this->firstChildByName($sectPr, 'pgMar');
            $top = $pgMar !== null && is_numeric($this->wAttr($pgMar, 'top')) ? (float) $this->wAttr($pgMar, 'top') : 1440;
            $inset = $top / 20 - $first['space'] - $width;
        } else {
            $inset = (float) $first['space'];
        }
        $num = static fn (float $v): float|int => round($v, 2) == (int) round($v, 2) ? (int) round($v, 2) : round($v, 2);

        $border = [];
        if ($style !== 'single') {
            $border['style'] = $style;
        }
        if (abs($width - 0.5) > 1e-9) {
            $border['width'] = $num($width);
        }
        if (preg_match('/^[0-9A-F]{6}$/', $first['color']) === 1 && $first['color'] !== '000000') {
            $border['color'] = '#' . $first['color'];
        }
        if (abs($inset - Schema::PAGE_FRAME_INSET) > 1e-9 && $inset >= 0) {
            $border['inset'] = $num($inset);
        }
        $out['border'] = $border === [] ? ['style' => 'single'] : $border;

        return $out;
    }

    /**
     * The section's headers and footers, by model key, in
     * {@see Schema::PAGE_BANDS} order: `header` / `footer` from the DEFAULT
     * references, and (0.16.0) `firstHeader` / `firstFooter` from the FIRST ones
     * while `w:titlePg` is on, `evenHeader` / `evenFooter` from the EVEN ones
     * while settings turn on `w:evenAndOddHeaders`. A switch that is off makes
     * its references nothing, as in Word.
     *
     * A variant that names the same part as the default is not repeated (our
     * writer references the default for page one when only one kind has a
     * first-page band). One with no part, or an empty part, while the default
     * has content, is `{"blocks": []}`: that page shows no header in Word, and
     * the model says so rather than letting a write-back put the default there.
     *
     * @return array<string, array<string, mixed>>
     */
    private function parseBands(DOMElement $sectPr): array
    {
        $titlePg = false;
        $el = $this->firstChildByName($sectPr, 'titlePg');
        if ($el !== null) {
            $titlePg = $this->toggleOn($this->wAttr($el, 'val'));
        }
        // A reference a section leaves out is inherited from the section before.
        foreach (['header', 'footer'] as $kind) {
            foreach (['default', 'first', 'even'] as $type) {
                $target = $this->bandTarget($sectPr, $kind, $type);
                if ($target !== null) {
                    $this->sectionTargets[$kind][$type] = $target;
                }
            }
        }
        $found = [];
        foreach (['header', 'footer'] as $kind) {
            $default = $this->sectionTargets[$kind]['default'] ?? null;
            $band = $default !== null ? $this->bandPartOnce($default, $kind) : null;
            if ($band !== null) {
                $found[$kind] = $band;
            }
            foreach (['first' => $titlePg, 'even' => $this->evenAndOddHeaders] as $type => $on) {
                if (!$on) {
                    continue;
                }
                $key = $type . ucfirst($kind);
                $target = $this->sectionTargets[$kind][$type] ?? null;
                if ($target !== null && $target === $default) {
                    continue;
                }
                $variant = $target !== null ? $this->bandPartOnce($target, $key) : null;
                if ($variant === null && $band !== null) {
                    $variant = ['blocks' => []];
                }
                if ($variant !== null) {
                    $found[$key] = $variant;
                }
            }
        }

        $out = [];
        foreach (Schema::PAGE_BANDS as $key) {
            if (isset($found[$key])) {
                $out[$key] = $found[$key];
            }
        }

        return $out;
    }

    /**
     * {@see parseBandPart()}, once per part and key.
     *
     * @return array<string, mixed>|null
     */
    private function bandPartOnce(string $name, string $key): ?array
    {
        $at = $name . '|' . $key;
        if (!array_key_exists($at, $this->bandCache)) {
            $this->bandCache[$at] = $this->parseBandPart($name, $key);
        }

        return $this->bandCache[$at];
    }

    /**
     * The part name (`header1.xml`) a section's header or footer reference of
     * this type names, or null. The last reference of a type wins, as it always
     * has for the default; a reference with no type is the default.
     */
    private function bandTarget(DOMElement $sectPr, string $kind, string $type): ?string
    {
        $name = null;
        foreach ($sectPr->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $kind . 'Reference' && ($this->wAttr($node, 'type') ?? 'default') === $type) {
                $rid = (string) $this->rAttr($node, 'id');
                $target = $this->rels[$rid]['target'] ?? null;
                $name = is_string($target) ? ltrim(str_replace('\\', '/', $target), '/') : null;
            }
        }

        return $name;
    }

    /**
     * One header or footer part as a band: left / center / right text with its
     * field tokens read back (`{page}`, `{date:yyyy-MM-dd}`, ...), or the
     * `{blocks}` form when one line cannot hold it. `$key` is the model key the
     * band is read for, which is where anything it cannot hold is reported.
     *
     * Our writer puts the slots in one paragraph separated by two tabs. A band
     * from another producer is read the same way: three tab-separated segments
     * are left/center/right, two are left/right, one goes to the slot its
     * paragraph alignment names. Only the first paragraph is read.
     *
     * @return array<string, mixed>|null
     */
    private function parseBandPart(string $name, string $key): ?array
    {
        $part = $this->bandParts[$name] ?? null;
        if ($part === null) {
            return null;
        }
        $kind = $key;

        $dom = PartXml::dom($part);
        if ($dom === null || $dom->documentElement === null) {
            return null;
        }

        // last-word#6: a header the one-line band can hold -- a single paragraph
        // of text, nothing else -- reads exactly as it always has. Anything
        // richer, which the band could only report as lost, is read as blocks,
        // unless the part fails the checks a template letterhead's must pass.
        [$images, $extra] = $this->bandOverflow($dom->documentElement);
        if (($images > 0 || $extra > 0) && strlen($part) <= TemplateSection::PART_LIMIT) {
            return $this->parseBlocksBand($dom->documentElement, $name, $kind, $images);
        }
        $this->noteUnrepresented($kind, $images, $extra);
        $p = $this->firstChildByName($dom->documentElement, 'p');
        if ($p === null) {
            return null;
        }

        $segments = [''];
        $firstRun = null;
        $field = null; // inside a complex field: its instruction so far
        $walk = function (DOMElement $node) use (&$walk, &$segments, &$firstRun, &$field, $kind): void {
            foreach ($node->childNodes as $child) {
                if (!$child instanceof DOMElement) {
                    continue;
                }
                $name = $child->localName;
                if ($name === 'fldSimple') {
                    $token = self::fieldToken((string) $this->wAttr($child, 'instr'));
                    if ($token !== '') {
                        $segments[count($segments) - 1] .= $token;
                    } else {
                        $walk($child); // not a token: its cached result is the text
                    }
                } elseif ($name === 'r') {
                    $firstRun ??= $child;
                    $walk($child);
                } elseif ($name === 'fldChar') {
                    $type = $this->wAttr($child, 'fldCharType');
                    if ($type === 'begin') {
                        $field = '';
                    } elseif ($type === 'separate' && is_string($field)) {
                        $token = self::fieldToken($field);
                        $segments[count($segments) - 1] .= $token;
                        // A token's cached result ("1", the author) is skipped;
                        // any other field's is its text, as in a blocks band.
                        $field = $token !== '' ? false : null;
                    } elseif ($type === 'end') {
                        $field = null;
                    }
                } elseif ($name === 'instrText' && is_string($field)) {
                    $field .= $child->textContent;
                } elseif ($field === null && $name === 't') {
                    $segments[count($segments) - 1] .= $child->textContent;
                } elseif ($field === null && $name === 'tab') {
                    $segments[] = '';
                } elseif ($name === 'hyperlink' || $name === 'smartTag') {
                    $walk($child);
                } elseif ($name === 'AlternateContent') {
                    // One branch, never both; one the reader cannot choose is read
                    // as its first choice, and reported; an empty one loses nothing.
                    $branch = ShapeReader::branch($child);
                    if ($branch === null) {
                        foreach ($child->childNodes as $c) {
                            if ($c instanceof DOMElement && $c->localName === 'Choice') {
                                $branch = $c;
                                $this->noteShapeAt('/page/' . $kind, 'shape');

                                break;
                            }
                        }
                    }
                    if ($branch !== null) {
                        $walk($branch);
                    }
                }
            }
        };
        $walk($p);

        $slots = match (count($segments)) {
            1 => [match ($this->paragraphAlign($p)) {
                'center' => 'center',
                'right' => 'right',
                default => 'left',
            } => $segments[0]],
            2 => ['left' => $segments[0], 'right' => $segments[1]],
            default => ['left' => $segments[0], 'center' => $segments[1], 'right' => implode(' ', array_slice($segments, 2))],
        };

        $band = [];
        foreach (['left', 'center', 'right'] as $slot) {
            if (($slots[$slot] ?? '') !== '') {
                $band[$slot] = $slots[$slot];
            }
        }
        if ($band === []) {
            return null;
        }

        // Size and colour only when they differ from what the writer puts there
        // unasked, as every other optional field in a read document is.
        $rPr = $firstRun !== null ? $this->firstChildByName($firstRun, 'rPr') : null;
        $sz = $rPr !== null ? $this->firstChildByName($rPr, 'sz') : null;
        if ($sz !== null && is_numeric($this->wAttr($sz, 'val')) && (int) $this->wAttr($sz, 'val') !== 18) {
            $band['size'] = ((int) $this->wAttr($sz, 'val')) / 2;
        }
        $color = $rPr !== null ? $this->firstChildByName($rPr, 'color') : null;
        $hex = $color !== null ? strtoupper((string) $this->wAttr($color, 'val')) : null;
        if ($hex !== null && preg_match('/^[0-9A-F]{6}$/', $hex) === 1 && $hex !== '6B7280') {
            $band['color'] = '#' . $hex;
        }

        return $band;
    }

    /**
     * A header or footer as `{blocks: [...]}` (last-word#6), read by the body's
     * own block reader against the part's OWN relationships, so its images and
     * links resolve. What even blocks cannot hold is still reported: pictures
     * that did not become image blocks (VML, embedded objects, formats outside
     * PNG/JPEG, linked or oversized images, text boxes) as `image`, and charts,
     * whose parts are wired to the body, as `chart`. A page break is dropped:
     * it means nothing in a running header.
     *
     * @return array{blocks: list<array<string, mixed>>}|null
     */
    private function parseBlocksBand(DOMElement $root, string $name, string $kind, int $pictures): ?array
    {
        [$rels, $this->rels] = [$this->rels, $this->bandRels[$name] ?? []];
        $this->inBand = true;
        $this->shapeWhere = '/page/' . $kind;
        try {
            $blocks = $this->parseBlockContainer($root);
        } finally {
            $this->rels = $rels;
            $this->inBand = false;
            $this->shapeWhere = '/blocks';
        }

        $kept = $charts = 0;
        $blocks = $this->bandBlocksOnly($blocks, $kept, $charts);
        $this->noteUnrepresented($kind, max(0, $pictures - $kept - $charts), 0, $charts);

        return $blocks === [] ? null : ['blocks' => $blocks];
    }

    /**
     * Blocks without what a band refuses ({@see Schema::BAND_REFUSED_TYPES}),
     * at any depth, counting the images kept and the charts dropped.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private function bandBlocksOnly(array $blocks, int &$images, int &$charts): array
    {
        $out = [];
        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;
            if ($type === 'chart') {
                $charts++;

                continue;
            }
            if (in_array($type, Schema::BAND_REFUSED_TYPES, true)) {
                continue;
            }
            if ($type === 'image') {
                $images++;
            }
            if ($type === 'quote' && is_array($block['blocks'] ?? null)) {
                $block['blocks'] = $this->bandBlocksOnly($block['blocks'], $images, $charts);
            }
            if ($type === 'table') {
                foreach ($block['rows'] ?? [] as $r => $row) {
                    foreach ($row['cells'] ?? [] as $c => $cell) {
                        $block['rows'][$r]['cells'][$c]['blocks'] = $this->bandBlocksOnly($cell['blocks'] ?? [], $images, $charts);
                    }
                }
            }
            $out[] = $block;
        }

        return $out;
    }

    /** Record what a header or footer held that the model could not (last-word#5). */
    private function noteUnrepresented(string $kind, int $images, int $blocks, int $charts = 0): void
    {
        foreach (['image' => $images, 'nested-block' => $blocks, 'chart' => $charts] as $what => $count) {
            if ($count > 0) {
                $this->unrepresented[] = ['where' => '/page/' . $kind, 'kind' => $what, 'count' => $count];
            }
        }
    }

    /**
     * What the one-line band model cannot hold from this header/footer part: its
     * pictures (`w:drawing`, VML `w:pict`, `w:object`) and every block after
     * the first paragraph that carries text or is a table. Only the first
     * paragraph's text is read into a band; without this, a letterhead's logo
     * and address lines vanish with nothing said. Both zero: it is a band.
     *
     * @return array{0: int, 1: int} [pictures, blocks]
     */
    private function bandOverflow(DOMElement $root): array
    {
        $images = 0;
        foreach ($root->getElementsByTagName('*') as $el) {
            if (in_array($el->localName, ['drawing', 'pict', 'object'], true)) {
                // mc:AlternateContent's fallback is the same picture again.
                for ($up = $el->parentNode; $up instanceof DOMElement; $up = $up->parentNode) {
                    if ($up->localName === 'Fallback') {
                        continue 2;
                    }
                }
                $images++;
            }
        }
        $blocks = 0;
        $first = true;
        foreach ($root->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            if ($node->localName === 'p') {
                if (! $first && trim($node->textContent) !== '') {
                    $blocks++;
                }
                $first = false;
            } elseif (in_array($node->localName, ['tbl', 'sdt', 'AlternateContent'], true)) { // what may hold blocks
                $blocks++;
            }
        }

        return [$images, $blocks];
    }

    /**
     * A field instruction as a band token ({@see Schema::fieldTokenOf()}):
     * PAGE {page}, NUMPAGES {pages}, SECTIONPAGES {sectionpages},
     * `DATE \@ "d MMMM yyyy"` {date:d MMMM yyyy} ... and '' for any other field.
     */
    private static function fieldToken(string $instr): string
    {
        return Schema::fieldTokenOf($instr) ?? '';
    }

    /** A paragraph's w:jc, normalised to left / center / right. */
    private function paragraphAlign(DOMElement $p): string
    {
        $pPr = $this->firstChildByName($p, 'pPr');
        $jc = $pPr !== null ? $this->firstChildByName($pPr, 'jc') : null;
        $val = $jc !== null ? (string) $this->wAttr($jc, 'val') : '';

        return match ($val) {
            'center' => 'center',
            'right', 'end' => 'right',
            default => 'left',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function parseDocDefaults(): array
    {
        if ($this->stylesXml === null) {
            return [];
        }
        $dom = PartXml::dom($this->stylesXml);
        if ($dom === null || $dom->documentElement === null) {
            return [];
        }

        $rPr = $this->firstDescendantByName($dom->documentElement, 'rPrDefault');
        $rPr = $rPr !== null ? $this->firstChildByName($rPr, 'rPr') : null;
        if ($rPr === null) {
            return [];
        }

        $out = [];
        $rFonts = $this->firstChildByName($rPr, 'rFonts');
        $ascii = $rFonts !== null ? $this->wAttr($rFonts, 'ascii') : null;
        if (is_string($ascii) && $ascii !== '' && $ascii !== 'Calibri') {
            $out['defaultFont'] = $ascii;
        }
        $sz = $this->firstChildByName($rPr, 'sz');
        $val = $sz !== null ? $this->wAttr($sz, 'val') : null;
        if (is_numeric($val) && (int) $val !== 22) {
            $size = ((float) $val) / 2;
            $out['defaultSize'] = $size == (int) $size ? (int) $size : $size;
        }

        return $out;
    }

    /** Read only verified payloads; archive validation precedes XML parsing. */
    private function openArchive(string $bytes): string
    {
        $zip = SourceArchive::open($bytes, tempDir: $this->tempDir);
        $this->sourceKind = OfficeTemplate::kind($zip);
        $documentXml = $zip->read('word/document.xml');
        if ($documentXml === null) {
            throw new RuntimeException('No word/document.xml part — not a DOCX file.');
        }
        $relsXml = $zip->read('word/_rels/document.xml.rels');
        $this->rels = $relsXml !== null ? $this->parseRels($relsXml) : [];
        $this->loadFootnotes($zip, $relsXml);
        $this->comments = DocxComments::load($zip, $relsXml);
        $numberingXml = $zip->read('word/numbering.xml');
        $this->numbering = $numberingXml !== null ? $this->parseNumbering($numberingXml) : [];
        $this->coreXml = $zip->read('docProps/core.xml');
        $settingsTarget = $relsXml !== null ? $this->relTargetOfType($relsXml, 'settings') : null;
        $settingsXml = $settingsTarget !== null ? $zip->read('word/' . ltrim(str_replace('\\', '/', $settingsTarget), '/')) : null;
        $this->evenAndOddHeaders = $settingsXml !== null && $this->settingsEvenAndOdd($settingsXml);
        $this->stylesXml = $zip->read('word/styles.xml');
        $this->media = [];
        $this->bandParts = [];
        $this->bandRels = [];
        $this->chartParts = [];
        $this->trackedChanges = self::NO_TRACKED_CHANGES;
        foreach ($zip->names() as $name) {
            if (preg_match('#^word/[^/]+[.]xml$#', $name) === 1) {
                $this->countTrackedChanges((string) $zip->read($name));
            }
            if (preg_match('#^word/_rels/((?:header|footer)\d*\.xml)\.rels$#', $name, $m) === 1) {
                $xml = (string) $zip->read($name);
                // A DOCTYPE leaves the part with no relationships, so nothing resolves.
                $this->bandRels[$m[1]] = $this->parseRels($xml);
            }
            if (preg_match('#^word/charts/[^/]+\.xml$#', $name) === 1) {
                $this->chartParts[substr($name, strlen('word/'))] = $zip->read($name);
            }
            if (preg_match('#^word/(header|footer)\d*\.xml$#', $name) === 1) {
                $this->bandParts[substr($name, strlen('word/'))] = $zip->read($name);
            }
            if (str_starts_with($name, 'word/media/')) {
                $this->media[substr($name, strlen('word/'))] = $zip->read($name);
            }
        }

        return $documentXml;
    }

    private const NO_TRACKED_CHANGES = ['insertions' => 0, 'deletions' => 0, 'moves' => 0, 'formatChanges' => 0];

    /** WordprocessingML elements that are revisions, by the kind they count as. Moves are counted by passage instead. */
    private const REVISION_KINDS = [
        'ins' => 'insertions',
        'cellIns' => 'insertions',
        'del' => 'deletions',
        'cellDel' => 'deletions',
        'rPrChange' => 'formatChanges',
        'pPrChange' => 'formatChanges',
        'sectPrChange' => 'formatChanges',
        'tblPrChange' => 'formatChanges',
        'tblPrExChange' => 'formatChanges',
        'tblGridChange' => 'formatChanges',
        'trPrChange' => 'formatChanges',
        'tcPrChange' => 'formatChanges',
        'numberingChange' => 'formatChanges',
        'cellMerge' => 'formatChanges',
    ];

    /**
     * Add one part's revision markup to {@see $trackedChanges}. A move is one
     * passage however many runs it spans: counted by the names that tie its
     * source and destination ranges together, or, in a file without range
     * markers, by the larger of its source and destination element counts.
     */
    private function countTrackedChanges(string $xml): void
    {
        // Most parts hold none: skip the parse when no revision element can be there.
        if (preg_match('#<(?:[A-Za-z0-9_]+:)?(?:ins|del|cell(?:Ins|Del|Merge)|move(?:From|To)|[A-Za-z]+Change)[ \t\r\n/>]#', $xml) !== 1) {
            return;
        }
        $dom = PartXml::dom($xml);
        if ($dom === null) {
            return;
        }
        $names = [];
        $from = 0;
        $to = 0;
        foreach ($dom->getElementsByTagName('*') as $el) {
            if (!str_contains((string) $el->namespaceURI, 'wordprocessingml')) {
                continue;
            }
            $local = $el->localName;
            if (isset(self::REVISION_KINDS[$local])) {
                $this->trackedChanges[self::REVISION_KINDS[$local]]++;
            } elseif ($local === 'moveFromRangeStart' || $local === 'moveToRangeStart') {
                $names[(string) $this->wAttr($el, 'name')] = true;
            } elseif ($local === 'moveFrom') {
                $from++;
            } elseif ($local === 'moveTo') {
                $to++;
            }
        }
        $this->trackedChanges['moves'] += $names !== [] ? count($names) : max($from, $to);
    }

    /** Revision markup the model has no slot for: a table's, a row's, a cell's, a section's, numbering, a paragraph mark's formatting. */
    private const MOVE_RANGES = ['moveFromRangeStart', 'moveFromRangeEnd', 'moveToRangeStart', 'moveToRangeEnd'];

    private const UNKEPT_REVISIONS = ['cellIns', 'cellDel', 'cellMerge', 'tblPrChange', 'tblPrExChange', 'tblGridChange', 'trPrChange', 'tcPrChange', 'sectPrChange', 'numberingChange'];

    /** How many revision elements in a part the model cannot carry (they are accepted). */
    private function unkeptRevisions(DOMDocument $dom): int
    {
        $n = 0;
        foreach ($dom->getElementsByTagName('*') as $el) {
            if (!str_contains((string) $el->namespaceURI, 'wordprocessingml')) {
                continue;
            }
            $parent = $el->parentNode instanceof DOMElement ? $el->parentNode->localName : '';
            $grand = $el->parentNode?->parentNode instanceof DOMElement ? $el->parentNode->parentNode->localName : '';
            if (in_array($el->localName, self::UNKEPT_REVISIONS, true)
                || ($parent === 'trPr' && in_array($el->localName, ['ins', 'del'], true))
                || ($el->localName === 'rPrChange' && $parent === 'rPr' && $grand === 'pPr')) {
                $n++;
            }
        }

        return $n;
    }

    /** How many revision elements a part holds, every kind, ranges not counted. */
    private function revisionElements(string $xml): int
    {
        if (preg_match('#<(?:[A-Za-z0-9_]+:)?(?:ins|del|cell(?:Ins|Del|Merge)|move(?:From|To)|[A-Za-z]+Change)[ \t\r\n/>]#', $xml) !== 1) {
            return 0;
        }
        $dom = PartXml::dom($xml);
        $n = 0;
        foreach ($dom !== null ? $dom->getElementsByTagName('*') : [] as $el) {
            if (str_contains((string) $el->namespaceURI, 'wordprocessingml')
                && (isset(self::REVISION_KINDS[$el->localName]) || $el->localName === 'moveFrom' || $el->localName === 'moveTo')) {
                $n++;
            }
        }

        return $n;
    }

    // ─── Tracked changes (preserveTrackedChanges) ───────────────────────

    /** Whether revisions are read into the model here: asked for, and not in a header or footer. */
    private function preserving(): bool
    {
        return $this->preserveTrackedChanges && !$this->inBand && !$this->inTextBox;
    }

    /**
     * A revision entry from an element's attributes: a temporary id, the author
     * cleaned to the alphabet the model allows ("Unknown" when nothing is left),
     * and the date when it is a valid one. Anything changed is counted.
     *
     * @return array<string, mixed>
     */
    private function revisionEntry(DOMElement $el): array
    {
        $raw = (string) $this->wAttr($el, 'author');
        $author = Revisions::cleanAuthor($raw);
        if ($author !== $raw || $author === '') {
            $this->revUnkept++;
        }
        $entry = ['id' => 't' . ++$this->revSeq, 'author' => $author !== '' ? $author : Revisions::UNKNOWN_AUTHOR];
        $date = $this->wAttr($el, 'date');
        if ($date !== null && Revisions::isDate($date)) {
            $entry['date'] = $date;
        } elseif ($date !== null) {
            $this->revUnkept++;
        }

        return $entry;
    }

    /**
     * The runs inside a w:ins, w:del, w:moveFrom or w:moveTo, each carrying it.
     * A second insertion inside an insertion (or deletion inside a deletion)
     * has no slot: the outer one is kept, the inner counted, its text read.
     *
     * @return list<array<string, mixed>>
     */
    private function parseRevision(DOMElement $node, ?string $link, array &$state): array
    {
        $kind = ['ins' => 'insert', 'del' => 'delete', 'moveFrom' => 'moveFrom', 'moveTo' => 'moveTo'][$node->localName];
        $arrival = $kind === 'insert' || $kind === 'moveTo';
        if (($arrival ? $this->revArrival : $this->revDeparture) !== null) {
            $this->revUnkept++;

            return $this->parseInlineContainer($node, $link, $state);
        }
        $entry = $this->revisionEntry($node);
        if ($kind === 'moveFrom' || $kind === 'moveTo') {
            $open = $this->openMoves[$kind === 'moveFrom' ? 'from' : 'to'];
            if ($open !== []) {
                $entry['name'] = (string) end($open); // the innermost range; none, and it stands alone
            }
        }
        $slot = [$kind, $entry];
        $arrival ? $this->revArrival = $slot : $this->revDeparture = $slot;
        try {
            return $this->parseInlineContainer($node, $link, $state);
        } finally {
            $arrival ? $this->revArrival = null : $this->revDeparture = null;
        }
    }

    /** Whether a paragraph's mark is deleted (or moved away): `w:del` or `w:moveFrom` in its mark's rPr. */
    private function markDeleted(?DOMElement $pPr): bool
    {
        $markPr = $this->firstChildByName($pPr, 'rPr');

        return $this->firstChildByName($markPr, 'del') !== null || $this->firstChildByName($markPr, 'moveFrom') !== null;
    }

    /** A move range's start or end: which name a moveFrom or moveTo inside it belongs to. */
    private function moveRange(DOMElement $node): void
    {
        if (!$this->preserving()) {
            return;
        }
        $dir = str_starts_with($node->localName, 'moveFrom') ? 'from' : 'to';
        $id = (string) $this->wAttr($node, 'id');
        if (str_ends_with($node->localName, 'Start')) {
            unset($this->openMoves[$dir][$id]); // a reused id moves to the top
            $this->openMoves[$dir][$id] = (string) $this->wAttr($node, 'name');
        } else {
            unset($this->openMoves[$dir][$id]);
        }
    }

    /**
     * The revision a run read now carries: the elements it sits in and, unless
     * it is a footnote mark, its w:r's formatting change.
     *
     * @return array<string, mixed>
     */
    private function currentRevision(bool $withFormat): array
    {
        $rev = [];
        foreach ([$this->revArrival, $this->revDeparture] as $slot) {
            if ($slot !== null) {
                $rev[$slot[0]] = $slot[1];
            }
        }
        if ($withFormat && $this->runFormat !== null) {
            $rev['format'] = $this->runFormat;
        }

        return $rev;
    }

    /**
     * A w:rPr's w:rPrChange as a format entry, `from` holding the old properties
     * as model run keys; null without one.
     *
     * @return array<string, mixed>|null
     */
    private function formatEntry(?DOMElement $rPr): ?array
    {
        $change = $this->firstChildByName($rPr, 'rPrChange');
        if ($change === null) {
            return null;
        }
        $old = $this->firstChildByName($change, 'rPr');
        $props = $old !== null ? $this->parseRunProperties($old) : [];
        $from = [];
        foreach (Revisions::RUN_FORMAT_KEYS as $key) {
            if (in_array($key, ['bold', 'italic', 'underline', 'strike', 'code', 'smallCaps'], true) ? !empty($props[$key]) : isset($props[$key])) {
                $from[$key] = in_array($key, ['bold', 'italic', 'underline', 'strike', 'code', 'smallCaps'], true) ? true : $props[$key];
            }
        }

        return $this->revisionEntry($change) + ['from' => $from];
    }

    /**
     * A paragraph's own revision: its mark's insertion or deletion and its
     * w:pPrChange. A moved mark has no slot and reads as an insertion or a
     * deletion; a style or numbering change has none either and is counted.
     *
     * @return array<string, mixed>|null
     */
    private function paragraphRevision(?DOMElement $pPr, ?string $style): ?array
    {
        if ($pPr === null || !$this->preserving()) {
            return null;
        }
        $rev = [];
        $markPr = $this->firstChildByName($pPr, 'rPr');
        foreach (['ins' => 'insert', 'moveTo' => 'insert', 'del' => 'delete', 'moveFrom' => 'delete'] as $local => $kind) {
            $el = $this->firstChildByName($markPr, $local);
            if ($el === null) {
                continue;
            }
            if (isset($rev[$kind]) || $local === 'moveTo' || $local === 'moveFrom') {
                $this->revUnkept++;
            }
            $rev[$kind] ??= $this->revisionEntry($el);
        }
        $change = $this->firstChildByName($pPr, 'pPrChange');
        if ($change !== null) {
            $old = $this->firstChildByName($change, 'pPr');
            $oldStyle = $this->firstChildByName($old, 'pStyle');
            if (($oldStyle !== null ? $this->wAttr($oldStyle, 'val') : null) !== $style
                || ($this->firstChildByName($old, 'numPr') === null) !== ($this->firstChildByName($pPr, 'numPr') === null)) {
                $this->revUnkept++; // a style or list change: the model keeps the paragraph as it is now
            }
            $from = [];
            $jc = $this->firstChildByName($old, 'jc');
            $align = $jc !== null ? match ($this->wAttr($jc, 'val')) {
                'center' => 'center',
                'right', 'end' => 'right',
                'both', 'distribute' => 'justify',
                default => null,
            } : null;
            if ($align !== null) {
                $from['align'] = $align;
            }
            $rev['format'] = $this->revisionEntry($change) + ['from' => $from + $this->parseParagraphProperties($old)];
        }

        return $rev === [] ? null : $rev;
    }

    /**
     * Runs for a place the model holds as plain text (a code block, the title):
     * every revision accepted there, and counted.
     *
     * @param  array{runs: list<array<string, mixed>>, revision?: ?array<string, mixed>}  $p
     * @return list<array<string, mixed>>
     */
    private function flattenRevisions(array $p): array
    {
        if (!$this->preserveTrackedChanges) {
            return $p['runs'];
        }
        [, $n] = Revisions::acceptBlock(['type' => 'paragraph', 'runs' => $p['runs']] + (isset($p['revision']) ? ['revision' => $p['revision']] : []));
        $this->revUnkept += $n;

        return Revisions::acceptRuns($p['runs']);
    }

    /** `w:evenAndOddHeaders` in word/settings.xml: even pages have their own header and footer. */
    private bool $evenAndOddHeaders = false;

    /** Whether a settings part turns on even/odd headers. A DOCTYPE leaves it off. */
    private function settingsEvenAndOdd(string $xml): bool
    {
        $dom = PartXml::dom($xml);
        if ($dom === null || $dom->documentElement === null) {
            return false;
        }
        $el = $this->firstChildByName($dom->documentElement, 'evenAndOddHeaders');

        return $el !== null && $this->toggleOn($this->wAttr($el, 'val'));
    }

    /** Keeps the footnotes part's DOM alive while its elements are referenced. */
    private ?DOMDocument $footnotesDom = null;

    /**
     * The footnotes part the document's `footnotes` relationship names, and its
     * own relationships. Sized by the archive's own limits, as document.xml is
     * (a long legal document's footnotes outgrow a header's 2 MiB cap); a part
     * declaring a DOCTYPE is not read, and its references then read as nothing.
     */
    private function loadFootnotes(SourceArchive $zip, ?string $relsXml): void
    {
        $this->footnoteNodes = [];
        $this->footnoteRels = [];
        $this->footnotesDom = null;
        $target = $relsXml !== null ? $this->relTargetOfType($relsXml, 'footnotes') : null;
        if ($target === null) {
            return;
        }
        $name = 'word/' . ltrim(str_replace('\\', '/', $target), '/');
        if (str_starts_with($target, '/')) {
            $name = ltrim($target, '/');
        }
        $xml = $zip->read($name);
        if ($xml === null) {
            return;
        }
        $dom = PartXml::dom($xml);
        if ($dom === null || $dom->documentElement === null) {
            return;
        }
        foreach ($dom->documentElement->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'footnote'
                && in_array($this->wAttr($node, 'type'), [null, 'normal'], true)) {
                $id = $this->wAttr($node, 'id');
                if ($id !== null && !isset($this->footnoteNodes[$id])) {
                    $this->footnoteNodes[$id] = $node;
                }
            }
        }
        $this->footnotesDom = $dom;

        $relsName = 'word/_rels/' . basename($name) . '.rels';
        $partRels = $zip->read($relsName);
        $this->footnoteRels = $partRels !== null ? $this->parseRels($partRels) : [];
    }

    /** The target of the first relationship whose type ends `/{$type}`, or null. */
    private function relTargetOfType(string $relsXml, string $type): ?string
    {
        $dom = PartXml::dom($relsXml);
        if ($dom === null || $dom->documentElement === null) {
            return null;
        }
        foreach ($dom->documentElement->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'Relationship'
                && str_ends_with($node->getAttribute('Type'), '/' . $type)
                && strcasecmp($node->getAttribute('TargetMode'), 'External') !== 0
                && $node->getAttribute('Target') !== '') {
                return $node->getAttribute('Target');
            }
        }

        return null;
    }

    /**
     * The model run for a `w:footnoteReference`: `{text: "", footnote: {blocks}}`,
     * its blocks read by the body's own block reader against the footnotes
     * part's relationships. Null -- nothing in the model -- for a reference in a
     * header or footer or inside another footnote (Word allows neither), for an
     * id with no footnote, and for a footnote with no content.
     *
     * @return array{text: string, footnote: array{blocks: list<array<string, mixed>>}}|null
     */
    private function footnoteRun(string $id): ?array
    {
        if ($this->inBand || $this->inFootnote || !isset($this->footnoteNodes[$id])) {
            return null;
        }
        if (!array_key_exists($id, $this->footnoteCache)) {
            [$rels, $this->rels] = [$this->rels, $this->footnoteRels];
            // A footnote is its own story: the revision its mark sits in, and the
            // body's open move ranges, are not its own.
            $saved = [$this->revArrival, $this->revDeparture, $this->runFormat, $this->openMoves];
            [$this->revArrival, $this->revDeparture, $this->runFormat, $this->openMoves] = [null, null, null, ['from' => [], 'to' => []]];
            $this->inFootnote = true;
            try {
                $this->footnoteCache[$id] = $this->parseBlockContainer($this->footnoteNodes[$id]);
            } finally {
                $this->rels = $rels;
                $this->inFootnote = false;
                [$this->revArrival, $this->revDeparture, $this->runFormat, $this->openMoves] = $saved;
            }
        }
        $blocks = $this->footnoteCache[$id];

        return $blocks === [] ? null : ['text' => '', 'footnote' => ['blocks' => $blocks]];
    }

    /**
     * dc:title from docProps/core.xml — null when the part is missing,
     * unparsable, or the title element is absent/empty.
     */
    private function parseCoreTitle(): ?string
    {
        return $this->parseCoreElement('title');
    }

    /**
     * One Dublin Core element's text from docProps/core.xml (`title`,
     * `creator`) -- null when the part is missing, unparsable, declares a
     * DOCTYPE, or the element is absent or empty.
     */
    private function parseCoreElement(string $localName): ?string
    {
        if ($this->coreXml === null) {
            return null;
        }

        $dom = PartXml::dom($this->coreXml);
        if ($dom === null || $dom->documentElement === null) {
            return null;
        }

        $element = $this->firstChildByName($dom->documentElement, $localName);
        if ($element === null || $element->textContent === '') {
            return null;
        }

        return $element->textContent;
    }

    /**
     * @return array<string, array{target: string, external: bool}>
     */
    private function parseRels(string $xml): array
    {
        $dom = PartXml::dom($xml);
        if ($dom === null || $dom->documentElement === null) {
            return [];
        }

        $rels = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'Relationship') {
                $id = $node->getAttribute('Id');
                if ($id !== '') {
                    $rels[$id] = [
                        'target' => $node->getAttribute('Target'),
                        'external' => strcasecmp($node->getAttribute('TargetMode'), 'External') === 0,
                    ];
                }
            }
        }

        return $rels;
    }

    /**
     * numbering.xml → numId => ordered bool (from the level-0 numFmt of the
     * referenced abstract numbering). Unknown numIds read as unordered.
     *
     * @return array<int, bool>
     */
    private function parseNumbering(string $xml): array
    {
        $dom = PartXml::dom($xml);
        if ($dom === null || $dom->documentElement === null) {
            return [];
        }

        $orderedFormats = ['decimal', 'decimalZero', 'lowerLetter', 'upperLetter', 'lowerRoman', 'upperRoman', 'ordinal', 'cardinalText', 'ordinalText'];

        $abstractOrdered = []; // abstractNumId → bool
        foreach ($dom->documentElement->childNodes as $node) {
            if (!($node instanceof DOMElement) || $node->localName !== 'abstractNum') {
                continue;
            }
            $abstractId = $this->wAttr($node, 'abstractNumId');
            $isOrdered = false;
            foreach ($node->childNodes as $lvl) {
                if ($lvl instanceof DOMElement && $lvl->localName === 'lvl' && $this->wAttr($lvl, 'ilvl') === '0') {
                    $fmt = $this->firstChildByName($lvl, 'numFmt');
                    $isOrdered = $fmt !== null && in_array($this->wAttr($fmt, 'val'), $orderedFormats, true);

                    break;
                }
            }
            if ($abstractId !== null) {
                $abstractOrdered[$abstractId] = $isOrdered;
            }
        }

        $map = [];
        foreach ($dom->documentElement->childNodes as $node) {
            if (!($node instanceof DOMElement) || $node->localName !== 'num') {
                continue;
            }
            $numId = $this->wAttr($node, 'numId');
            $ref = $this->firstChildByName($node, 'abstractNumId');
            $abstractId = $ref !== null ? $this->wAttr($ref, 'val') : null;
            if ($numId !== null) {
                $map[(int) $numId] = $abstractOrdered[$abstractId ?? ''] ?? false;
            }
        }

        return $map;
    }

    // ─── Block-level parsing ─────────────────────────────────────────────

    /**
     * Walk the children of a block container (w:body, w:tc, w:sdtContent, …)
     * and assemble model blocks, grouping consecutive list / code / quote
     * paragraphs. `$insideQuote` marks content already wrapped by a
     * `lastword:quote` sdt so its Quote-styled paragraphs read as plain
     * paragraphs instead of nesting another quote.
     *
     * @return list<array<string, mixed>>
     */
    private function parseBlockContainer(DOMElement $container, bool $topLevel = false, bool $insideQuote = false): array
    {
        $blocks = [];
        /** @var list<array{ilvl: int, ordered: bool, runs: list<array<string, mixed>>}> $pendingList */
        $pendingList = [];
        /** @var list<string> $pendingCode */
        $pendingCode = [];
        $pendingCodeLanguage = null;
        /** @var list<array<string, mixed>> $pendingQuote */
        $pendingQuote = [];

        $flushList = function () use (&$blocks, &$pendingList): void {
            if ($pendingList !== []) {
                $blocks[] = $this->assembleList($pendingList);
                $pendingList = [];
            }
        };
        $flushCode = function () use (&$blocks, &$pendingCode, &$pendingCodeLanguage): void {
            if ($pendingCode !== []) {
                $block = ['type' => 'code'];
                if ($pendingCodeLanguage !== null) {
                    $block['language'] = $pendingCodeLanguage;
                }
                $block['text'] = implode("\n", $pendingCode);
                $blocks[] = $block;
                $pendingCode = [];
                $pendingCodeLanguage = null;
            }
        };
        $flushQuote = function () use (&$blocks, &$pendingQuote): void {
            if ($pendingQuote !== []) {
                $blocks[] = ['type' => 'quote', 'blocks' => $pendingQuote];
                $pendingQuote = [];
            }
        };
        $flushAll = function () use ($flushList, $flushCode, $flushQuote): void {
            $flushList();
            $flushCode();
            $flushQuote();
        };

        $sectionEnded = false;
        // Inside a table of contents: the child index of the paragraph that ends it
        // and the end itself. Its paragraphs are Word's cached entries, which the
        // toc block regenerates, so they are skipped.
        $tocUntil = null;
        $children = $this->blockChildren($container);
        foreach ($children as $idx => $node) {
            if ($sectionEnded) {
                $flushAll();
                $this->endSection($blocks);
                $sectionEnded = false;
            }
            if ($topLevel && $node->localName === 'p' && $this->firstChildByName($this->firstChildByName($node, 'pPr'), 'sectPr') !== null) {
                $sectionEnded = true; // after this paragraph, whatever it turns out to hold
            }
            if ($tocUntil !== null) {
                if ($idx < $tocUntil['index']) {
                    continue;
                }
                // The paragraph the TOC ends in: what follows the end is the document's.
                $tail = $this->paragraphPart($node, $tocUntil['end'], false);
                $tocUntil = null;
                array_push($blocks, ...$tail);

                continue;
            }
            if ($node->localName === 'p' && !$this->inBand && !$this->inFootnote) {
                $toc = $this->tocStart($node);
                if ($toc !== null) {
                    // A TOC becomes a toc block only where the model can hold one --
                    // top level, within the cap -- and only when its end is found.
                    // Anything else reads as its text, as before toc existed.
                    $close = $topLevel && $this->tocCount < Schema::MAX_TOCS && $this->tocScans++ < self::MAX_TOC_SCANS
                        ? $this->tocClose($children, $idx, $toc['begin'])
                        : null;
                    if ($close === null) {
                        $this->noteStructure();
                    } else {
                        $flushAll();
                        array_push($blocks, ...$this->paragraphPart($node, $toc['begin'], true));
                        $blocks[] = ['type' => 'toc'] + ($toc['levels'] !== Schema::TOC_LEVELS_DEFAULT ? ['levels' => $toc['levels']] : []);
                        $this->tocCount++;
                        if ($close['index'] === $idx) {
                            array_push($blocks, ...$this->paragraphPart($node, $close['end'], false));
                        } else {
                            $tocUntil = $close;
                        }

                        continue;
                    }
                }
            }
            if (in_array($node->localName, self::MOVE_RANGES, true)) {
                $this->moveRange($node); // a range marker between paragraphs

                continue;
            }
            if ($node->localName === 'tbl') {
                $flushAll();
                $blocks[] = $this->parseTable($node, $insideQuote);

                continue;
            }
            if ($node->localName === 'sdt') {
                // Only lastword-tagged sdts surface here (blockChildren
                // flattens the rest) — the canonical code / quote carriers.
                $flushAll();
                array_push($blocks, ...$this->parseTaggedSdt($node));

                continue;
            }
            if ($node->localName === 'AlternateContent') {
                // A choice the reader does not understand, with no fallback: its text.
                $flushAll();
                array_push($blocks, ...$this->textOfMarkup($node));

                continue;
            }
            if ($node->localName !== 'p') {
                continue; // unknown body-level construct — skip
            }

            $p = $this->parseParagraphNode($node);
            // A column break is a block only where the model can hold one: a body
            // paragraph or heading at the top level, in a section it reads with
            // columns. Anywhere else (a list item, code, a quote, a table cell, a
            // header, a footnote, one column) it stays the newline it reads as, so
            // the words either side stay apart, and each break is reported.
            $split = false;
            if ($p['columnBreaks'] > 0) {
                $split = $topLevel && !$this->inBand && !$this->inFootnote && $this->sectionColumns > 1
                    && $p['numPr'] === null && $p['style'] !== 'CodeBlock' && !($p['style'] === 'Quote' && !$insideQuote)
                    && !($p['style'] === 'Title' && $this->title === null);
                if (!$split) {
                    for ($n = 0; $n < $p['columnBreaks']; $n++) {
                        $this->noteColumnBreak();
                    }
                }
            }

            // Header/footer only (`leading` is empty elsewhere): a picture before
            // the paragraph's text -- a logo beside an address -- goes first.
            if ($p['leading'] !== []) {
                $flushAll();
                array_push($blocks, ...$p['leading']);
            }

            // Lists group before style handling — numPr wins.
            if ($p['numPr'] !== null) {
                $flushCode();
                $flushQuote();
                [$ilvl, $numId] = $p['numPr'];
                $ordered = $this->numbering[$numId] ?? false;
                // A change of orderedness at the top level starts a new list.
                if ($pendingList !== [] && $ilvl === 0 && $pendingList[0]['ordered'] !== $ordered) {
                    $flushList();
                }
                $pendingList[] = ['ilvl' => $ilvl, 'ordered' => $ordered, 'runs' => $p['runs']]
                    + ($p['revision'] !== null ? ['revision' => array_intersect_key($p['revision'], array_flip(Revisions::ITEM_KINDS))] : []);
                if (isset($p['revision']['format'])) {
                    $this->revUnkept++; // a list item has no paragraph formatting to change
                }

                continue;
            }

            if ($p['style'] === 'CodeBlock') {
                $flushList();
                $flushQuote();
                if ($pendingCode === [] && $p['codeLanguage'] !== null) {
                    $pendingCodeLanguage = $p['codeLanguage'];
                }
                // Soft line breaks inside a single code paragraph are lines too.
                $text = implode('', array_map(static fn (array $r): string => $r['text'], $this->flattenRevisions($p)));
                foreach (explode("\n", $text) as $line) {
                    $pendingCode[] = $line;
                }

                continue;
            }

            if ($p['style'] === 'Quote' && !$insideQuote) {
                $flushList();
                $flushCode();
                $para = ['type' => 'paragraph', 'runs' => $p['runs']];
                if ($p['align'] !== null) {
                    $para['align'] = $p['align'];
                }
                if ($p['revision'] !== null) {
                    $para['revision'] = $p['revision'];
                }
                $pendingQuote[] = $para;

                continue;
            }

            $flushAll();

            if ($topLevel && $p['style'] === 'Title' && $this->title === null) {
                $this->title = $this->plainText($this->flattenRevisions($p));

                continue;
            }

            // Images render as their own blocks; text in the same paragraph
            // (unusual, but legal) still becomes a paragraph first.
            $hasText = $this->plainText($p['runs']) !== '';

            $kept = $p['revision'] !== null && ($hasText || ($p['runs'] !== [] && $p['images'] === []));
            if ($p['revision'] !== null && !$kept) {
                $this->revUnkept += count($p['revision']); // a paragraph the model does not keep: no text
            }
            $revision = $kept ? ['revision' => $p['revision']] : [];

            $level = null;
            if ($p['style'] !== null && preg_match('/^Heading([1-9])$/', $p['style'], $m) === 1) {
                $level = min((int) $m[1], 6);
            } elseif ($p['outlineLvl'] !== null) {
                $level = min($p['outlineLvl'] + 1, 6);
            }

            if ($split) {
                // The paragraph between breaks, each break a block. The paragraph
                // mark ends the last piece, so its revision goes there; its pictures
                // and any page break follow, as for a paragraph that does not split.
                $last = count($p['segments']) - 1;
                $markKept = false;
                foreach ($p['segments'] as $k => $seg) {
                    if ($k > 0) {
                        $blocks[] = ['type' => 'columnBreak'];
                    }
                    if ($seg === []) {
                        continue;
                    }
                    $mark = $k === $last && $p['revision'] !== null ? ['revision' => $p['revision']] : [];
                    $markKept = $markKept || $mark !== [];
                    $piece = $level !== null && $this->plainText($seg) !== ''
                        ? ['type' => 'heading', 'level' => $level, 'runs' => $seg]
                        : ['type' => 'paragraph', 'runs' => $seg];
                    if ($p['align'] !== null) {
                        $piece['align'] = $p['align'];
                    }
                    $blocks[] = $piece + $p['props'] + $mark;
                }
                if ($p['revision'] !== null && !$markKept) {
                    $this->revUnkept += count($p['revision']);
                }
                foreach ($p['images'] as $image) {
                    $blocks[] = $image;
                }
                if ($p['pageBreak']) {
                    $blocks[] = ['type' => 'pageBreak'];
                }

                continue;
            }

            if ($p['pageBreak'] && !$hasText && $p['images'] === []) {
                $blocks[] = ['type' => 'pageBreak'];

                continue;
            }

            if ($level !== null && $hasText) {
                $heading = ['type' => 'heading', 'level' => $level, 'runs' => $p['runs']];
                if ($p['align'] !== null) {
                    $heading['align'] = $p['align'];
                }
                $blocks[] = $heading + $p['props'] + $revision;
            } elseif ($hasText || ($p['runs'] !== [] && $p['images'] === [])) {
                $para = ['type' => 'paragraph', 'runs' => $p['runs']];
                if ($p['align'] !== null) {
                    $para['align'] = $p['align'];
                }
                $blocks[] = $para + $p['props'] + $revision;
            } elseif (!$hasText && $p['images'] === [] && $p['bottomBorder']) {
                $blocks[] = ['type' => 'hr'];
            }

            foreach ($p['images'] as $image) {
                $blocks[] = $image;
            }

            if ($p['pageBreak'] && ($hasText || $p['images'] !== [])) {
                $blocks[] = ['type' => 'pageBreak'];
            }
        }

        $flushAll();
        if ($sectionEnded) {
            $this->endSection($blocks);
        }

        return $blocks;
    }

    /**
     * Children of a block container, descending into w:customXml and
     * unknown w:sdt wrappers so wrapped content degrades gracefully instead
     * of vanishing. Sdts carrying a `lastword:` tag (the canonical code /
     * quote metadata slots, shared with the Node mirror) are returned as-is
     * for {@see parseTaggedSdt()}.
     *
     * @return list<DOMElement>
     */
    private function blockChildren(DOMElement $container): array
    {
        $out = [];
        foreach ($container->childNodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            switch ($node->localName) {
                case 'p':
                case 'tbl':
                    $out[] = $node;

                    break;
                case 'sdt':
                    if ($this->lastWordSdtTag($node) !== null) {
                        $out[] = $node;

                        break;
                    }
                    $content = $this->firstChildByName($node, 'sdtContent');
                    if ($content !== null) {
                        array_push($out, ...$this->blockChildren($content));
                    }

                    break;
                case 'customXml':
                    array_push($out, ...$this->blockChildren($node));

                    break;
                case 'AlternateContent':
                    // No depth cap between blocks: the part's nesting limit already
                    // bounds this walk, and a cap here would drop the paragraphs
                    // under it. A branch the reader cannot choose is read as its
                    // text by parseBlockContainer().
                    $branch = ShapeReader::branch($node);
                    if ($branch !== null) {
                        array_push($out, ...$this->blockChildren($branch));
                    } else {
                        $out[] = $node;
                    }

                    break;
                case 'moveFromRangeStart':
                case 'moveFromRangeEnd':
                case 'moveToRangeStart':
                case 'moveToRangeEnd':
                    if ($this->preserving()) {
                        $out[] = $node; // a move range may open in one paragraph and close between two others
                    }

                    break;
                default:
                    break; // sectPr, bookmarkStart, proofErr, altChunk, …
            }
        }

        return $out;
    }

    /**
     * Where a paragraph starts a table of contents: its levels and the field's
     * begin (null for a `w:fldSimple`, which is whole in itself), or null when it
     * holds none. A TOC field is a `TOC` instruction; `\o "1-n"` gives the
     * levels, clamped to 1-6, and without one they are the default. No other
     * switch is read: the toc block writes its own instruction.
     *
     * @return array{levels: int, begin: ?DOMElement}|null
     */
    private function tocStart(DOMElement $p): ?array
    {
        $open = null;
        $instr = '';
        foreach ($p->getElementsByTagName('*') as $el) {
            $name = $el->localName;
            if ($name === 'fldSimple' && self::isTocInstruction((string) $this->wAttr($el, 'instr'))) {
                return ['levels' => self::tocLevels((string) $this->wAttr($el, 'instr')), 'begin' => $el];
            }
            if ($name === 'fldChar') {
                $type = $this->wAttr($el, 'fldCharType');
                if ($type === 'begin') {
                    [$open, $instr] = [$el, ''];
                } elseif ($open !== null && self::isTocInstruction($instr)) {
                    return ['levels' => self::tocLevels($instr), 'begin' => $open];
                } else {
                    $open = null;
                }
            } elseif ($name === 'instrText' && $open !== null) {
                $instr .= $el->textContent;
            }
        }

        return null;
    }

    /**
     * Where the TOC that `$begin` opens ends: the index of the container child
     * holding its end, and the end, or null when the container ends first. A
     * `w:fldSimple` ends where it is.
     *
     * @param  list<DOMElement>  $children
     * @return array{index: int, end: DOMElement}|null
     */
    private function tocClose(array $children, int $from, DOMElement $begin): ?array
    {
        if ($begin->localName === 'fldSimple') {
            return ['index' => $from, 'end' => $begin];
        }
        $depth = 0;
        $counting = false;
        $count = count($children);
        for ($i = $from; $i < $count; $i++) {
            foreach ($children[$i]->getElementsByTagName('*') as $el) {
                if ($el->localName !== 'fldChar') {
                    continue;
                }
                if (!$counting) {
                    if (!$el->isSameNode($begin)) {
                        continue;
                    }
                    $counting = true;
                }
                $type = $this->wAttr($el, 'fldCharType');
                if ($type === 'begin') {
                    $depth++;
                } elseif ($type === 'end' && --$depth === 0) {
                    return ['index' => $i, 'end' => $el];
                }
            }
        }

        return null;
    }

    /**
     * What a paragraph holds before (or after) the element `$at` -- the text
     * around a TOC -- as the blocks it reads as: a paragraph when there is text,
     * nothing otherwise. Read from a copy of the paragraph cut at the child that
     * holds `$at`, so the rest reads exactly as a paragraph would.
     *
     * @return list<array<string, mixed>>
     */
    private function paragraphPart(DOMElement $p, DOMElement $at, bool $before): array
    {
        $child = $at;
        while ($child->parentNode !== null && !$child->parentNode->isSameNode($p)) {
            $child = $child->parentNode;
        }
        if ($child->parentNode === null) {
            return [];
        }
        $position = 0;
        foreach ($p->childNodes as $k => $node) {
            if ($node->isSameNode($child)) {
                $position = $k;
            }
        }
        $copy = $p->cloneNode(true);
        $remove = [];
        foreach ($copy->childNodes as $k => $node) {
            $isPPr = $node instanceof DOMElement && $node->localName === 'pPr';
            if ($before ? $k >= $position : ($k <= $position && !$isPPr)) {
                $remove[] = $node;
            }
        }
        foreach ($remove as $node) {
            $copy->removeChild($node);
        }
        $read = $this->parseParagraphNode($copy);
        if ($this->plainText($read['runs']) === '') {
            return [];
        }
        $para = ['type' => 'paragraph', 'runs' => $read['runs']];
        if ($read['align'] !== null) {
            $para['align'] = $read['align'];
        }

        return [$para + $read['props']];
    }

    /**
     * One more piece of field markup the reader could not structure -- an
     * unclosed or misplaced TOC, a field that begins or ends in another
     * paragraph -- read as its text instead, counted in `unrepresented`.
     */
    private function noteStructure(): void
    {
        foreach ($this->unrepresented as $k => $note) {
            if ($note['where'] === '/blocks' && $note['kind'] === 'structure-not-recovered') {
                $this->unrepresented[$k]['count']++;

                return;
            }
        }
        $this->unrepresented[] = ['where' => '/blocks', 'kind' => 'structure-not-recovered', 'count' => 1];
    }

    /** One more column break the model cannot hold where it is: its paragraph is kept, the break counted. */
    private function noteColumnBreak(): void
    {
        foreach ($this->unrepresented as $k => $note) {
            if ($note['where'] === '/blocks' && $note['kind'] === 'layout-not-preserved') {
                $this->unrepresented[$k]['count']++;

                return;
            }
        }
        $this->unrepresented[] = ['where' => '/blocks', 'kind' => 'layout-not-preserved', 'count' => 1];
    }

    /** One more of a shape report kind at `/blocks`, or the header or footer being read: counts only, never text. */
    private function noteShape(string $kind): void
    {
        $this->noteShapeAt($this->shapeWhere, $kind);
    }

    private function noteShapeAt(string $where, string $kind): void
    {
        foreach ($this->unrepresented as $k => $note) {
            if ($note['where'] === $where && $note['kind'] === $kind) {
                $this->unrepresented[$k]['count']++;

                return;
            }
        }
        $this->unrepresented[] = ['where' => $where, 'kind' => $kind, 'count' => 1];
    }

    /**
     * A run's children as the reader takes them: each `mc:AlternateContent`
     * replaced by the children of the one branch it reads
     * ({@see ShapeReader::branch()}), nested ones too. Past
     * {@see MAX_MC_DEPTH} the markup is read as its text, into `images`.
     *
     * @param  array<string, mixed>  $state
     * @return list<DOMElement>
     */
    private function runChildren(DOMElement $r, array &$state, int $depth = 0): array
    {
        $out = [];
        foreach ($r->childNodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            if ($node->localName !== 'AlternateContent') {
                $out[] = $node;

                continue;
            }
            // In a header or footer too: a drawing in the branch is still read
            // the band's way (a picture it cannot hold, reported), and text is kept.
            $branch = $this->mcDepth + $depth < self::MAX_MC_DEPTH ? ShapeReader::branch($node) : null;
            if ($branch !== null) {
                array_push($out, ...$this->runChildren($branch, $state, $depth + 1));
            } else {
                array_push($state['images'], ...$this->textOfMarkup($node)); // too deep, or no branch it can read
            }
        }

        return $out;
    }

    /**
     * Markup the reader will not interpret, as the text in it: one paragraph per
     * `w:p` that holds text, in document order, from one branch only (the
     * fallback when there is one). Reported as a `shape` the model did not keep,
     * unless it holds nothing at all: an empty `mc:AlternateContent` loses nothing.
     *
     * @return list<array<string, mixed>>
     */
    private function textOfMarkup(DOMElement $alternate): array
    {
        foreach ($alternate->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $this->noteShape('shape');

                break;
            }
        }
        $texts = [];
        $this->collectText($alternate, null, $texts);
        $out = [];
        foreach ($texts as $text) {
            if ($text !== '') {
                $out[] = ['type' => 'paragraph', 'runs' => [['text' => $text]]];
            }
        }

        return $out;
    }

    /**
     * The `w:t` text under a node, by the `w:p` it is in, taking ONE branch of
     * every `mc:AlternateContent` (its fallback, else its first choice), so no
     * text is read twice. Linear in the nodes visited; the part's nesting is
     * already capped when it is parsed.
     *
     * @param  array<int, string>  $texts  paragraph id => text
     */
    private function collectText(DOMElement $node, ?int $paragraph, array &$texts): void
    {
        if ($node->localName === 'AlternateContent') {
            $branch = null;
            foreach ($node->childNodes as $child) {
                if ($child instanceof DOMElement && ($child->localName === 'Fallback' || $branch === null)) {
                    $branch = $child;
                }
            }
            if ($branch !== null) {
                $this->collectText($branch, $paragraph, $texts);
            }

            return;
        }
        if ($node->localName === 'p' && $node->namespaceURI === ShapeReader::NS_W) {
            $paragraph = spl_object_id($node);
        }
        if ($node->localName === 't' && $node->namespaceURI === ShapeReader::NS_W) {
            $texts[$paragraph ?? 0] = ($texts[$paragraph ?? 0] ?? '') . $node->textContent;

            return;
        }
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $this->collectText($child, $paragraph, $texts);
            }
        }
    }

    /**
     * A shape the reader met, as the blocks it becomes where it is: the shape
     * (its text box read as blocks), then whatever its box could not hold. Where
     * the model holds no shape -- inside another text box, in a footnote, past
     * {@see Shapes::MAX_SHAPES} -- it is its text alone, and reported.
     *
     * @param  array<string, mixed>  $shape
     * @param  list<string>  $notes
     * @return list<array<string, mixed>>
     */
    private function readShape(array $shape, ?DOMElement $content, array $notes): array
    {
        $holds = !$this->inTextBox && !$this->inFootnote && $this->shapeCount < Shapes::MAX_SHAPES;
        $blocks = $content !== null ? $this->readTextBox($content, $shape) : [];
        if (!$holds) {
            $this->noteShape('shape');

            return $blocks; // the outer box's own read flattens anything it spilled
        }
        $this->shapeCount++;
        foreach (array_unique($notes) as $kind) {
            $this->noteShape($kind);
        }
        [$spill, $this->boxSpill] = [$this->boxSpill, []];
        if ($content !== null) {
            if (in_array($shape['geometry'] ?? 'rect', Shapes::LINES, true)) {
                unset($shape['geometry']); // a line holds no text: a box that does
                $this->noteShape('format-approximated');
            }
            [$kept, $over] = Shapes::fit($blocks);
            if ($over !== []) {
                $this->noteShape('layout-not-preserved'); // what the box could not hold follows it
            }
            $shape['blocks'] = $kept;
            $spill = [...$over, ...$spill];
        }

        return [$shape, ...$spill];
    }

    /**
     * A text box's `w:txbxContent` as blocks: read as a story of its own, its
     * tables against the box's inner width, with no bookmark, comment, footnote
     * or tracked change kept in it (a tracked change is accepted). A shape inside
     * it is read as its text.
     *
     * @param  array<string, mixed>  $shape
     * @return list<array<string, mixed>>
     */
    private function readTextBox(DOMElement $content, array $shape): array
    {
        $saved = [$this->inTextBox, $this->contentWidth, $this->commentRun, $this->revArrival, $this->revDeparture, $this->runFormat];
        if ($this->preserveTrackedChanges && !$this->inTextBox) {
            $this->revUnkept += $this->revisionsIn($content); // accepted: a text box carries none
        }
        $this->inTextBox = true;
        $this->contentWidth = \LastWord\Writer\ShapeXml::innerTwips($shape);
        [$this->revArrival, $this->revDeparture, $this->runFormat] = [null, null, null];
        try {
            $blocks = $this->parseBlockContainer($content);
        } finally {
            [$this->inTextBox, $this->contentWidth, $this->commentRun, $this->revArrival, $this->revDeparture, $this->runFormat] = $saved;
        }

        return Shapes::flattenList($blocks);
    }

    /** Revision elements under a node, of every kind the reader counts. */
    private function revisionsIn(DOMElement $node): int
    {
        $n = 0;
        foreach ([...array_keys(self::REVISION_KINDS), 'moveFrom', 'moveTo'] as $name) {
            $n += $node->getElementsByTagNameNS(ShapeReader::NS_W, $name)->length;
        }

        return $n;
    }

    private static function isTocInstruction(string $instr): bool
    {
        return preg_match('/^\s*TOC(?:\s|$)/i', $instr) === 1;
    }

    private static function tocLevels(string $instr): int
    {
        if (preg_match('/\\\\o\s*"?\s*\d\s*-\s*(\d)/', $instr, $m) === 1) {
            return max(1, min(Schema::MAX_HEADING_LEVEL, (int) $m[1]));
        }

        return Schema::TOC_LEVELS_DEFAULT;
    }

    /**
     * Bookmark markers back into runs: a bookmark whose start and end hold exactly
     * one run of text (after merging) is that run's `bookmark`; any other -- one
     * spanning differently formatted runs, or ending in a later paragraph -- is a
     * point, `{"text": "", "bookmark": name}`, where it starts. Markers whose
     * other half is not here are dropped.
     *
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function resolveBookmarks(array $runs): array
    {
        $ends = [];
        $markers = [];
        foreach ($runs as $i => $run) {
            if (isset($run['__bookmark'])) {
                $markers[] = $i;
            }
            if (($run['__bookmark'] ?? null) === 'end') {
                $ends[$run['id']] ??= $i;
            }
        }
        // The marker after each one: a bookmark attaches only when the next marker
        // is its own end, so the runs between are looked at once (linear in a
        // hostile paragraph of thousands of bookmarks).
        $nextMarker = [];
        foreach ($markers as $k => $i) {
            $nextMarker[$i] = $markers[$k + 1] ?? null;
        }
        $out = [];
        $count = count($runs);
        for ($i = 0; $i < $count; $i++) {
            $run = $runs[$i];
            $marker = $run['__bookmark'] ?? null;
            if ($marker === 'end') {
                continue;
            }
            if ($marker === null) {
                $out[] = $run;

                continue;
            }
            $end = $ends[$run['id']] ?? null;
            if ($end !== null && $end > $i && $nextMarker[$i] === $end) {
                $merged = $this->mergeRuns(array_slice($runs, $i + 1, $end - $i - 1));
                if (count($merged) === 1 && $merged[0]['text'] !== '' && array_intersect_key($merged[0], ['footnote' => 1, 'field' => 1, 'bookmark' => 1]) === []) {
                    $out[] = $merged[0] + ['bookmark' => $run['name']];
                    $i = $end;

                    continue;
                }
            }
            $out[] = ['text' => '', 'bookmark' => $run['name']];
        }

        return $out;
    }

    /**
     * Whether a body bookmark of this name is read: a name the model takes
     * (Word's hidden `_` ones are not), not the reserved code-language prefix, not
     * a second of the same name ignoring case, and within the document's cap.
     */
    private function acceptBookmark(string $name): bool
    {
        if (!Schema::isBookmarkName($name) || str_starts_with($name, Schema::BOOKMARK_RESERVED_PREFIX)
            || isset($this->bookmarksSeen[strtolower($name)]) || count($this->bookmarksSeen) >= Schema::MAX_BOOKMARKS) {
            return false;
        }
        $this->bookmarksSeen[strtolower($name)] = true;

        return true;
    }

    /**
     * The sdt's w:tag value when it is one of ours (`lastword:code[:{lang}]`
     * or `lastword:quote`); null for foreign / untagged sdts.
     */
    private function lastWordSdtTag(DOMElement $sdt): ?string
    {
        $sdtPr = $this->firstChildByName($sdt, 'sdtPr');
        $tagNode = $sdtPr !== null ? $this->firstChildByName($sdtPr, 'tag') : null;
        $tag = $tagNode !== null ? $this->wAttr($tagNode, 'val') : null;
        if ($tag === null) {
            return null;
        }

        $isCode = $tag === DocxWriter::SDT_TAG_CODE || str_starts_with($tag, DocxWriter::SDT_TAG_CODE . ':');
        if ($isCode || $tag === DocxWriter::SDT_TAG_QUOTE) {
            return $tag;
        }

        return null;
    }

    /**
     * A `lastword:`-tagged sdt → the code or quote block it carries. The
     * sdt tag is the canonical cross-language slot for the code block's
     * `language` (the pre-0.2.0 bookmark is still honoured for old files
     * via {@see parseParagraphNode()}).
     *
     * @return list<array<string, mixed>>
     */
    private function parseTaggedSdt(DOMElement $sdt): array
    {
        $tag = $this->lastWordSdtTag($sdt);
        $content = $this->firstChildByName($sdt, 'sdtContent');
        if ($tag === null || $content === null) {
            return [];
        }

        if ($tag === DocxWriter::SDT_TAG_QUOTE) {
            return [[
                'type' => 'quote',
                'blocks' => $this->parseBlockContainer($content, false, true),
            ]];
        }

        // Code: one line per direct w:p child; language from the tag suffix.
        $lines = [];
        foreach ($content->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === 'p') {
                $lines[] = $this->plainText($this->flattenRevisions($this->parseParagraphNode($node)));
            }
        }

        $block = ['type' => 'code'];
        $prefix = DocxWriter::SDT_TAG_CODE . ':';
        if (str_starts_with($tag, $prefix) && strlen($tag) > strlen($prefix)) {
            $block['language'] = substr($tag, strlen($prefix));
        }
        $block['text'] = implode("\n", $lines);

        return [$block];
    }

    /**
     * @return array{
     *   style: ?string, align: ?string, outlineLvl: ?int,
     *   numPr: ?array{0: int, 1: int}, bottomBorder: bool, pageBreak: bool,
     *   codeLanguage: ?string,
     *   runs: list<array<string, mixed>>, images: list<array<string, mixed>>
     * }
     */
    private function parseParagraphNode(DOMElement $p): array
    {
        $style = null;
        $align = null;
        $outlineLvl = null;
        $numPr = null;
        $bottomBorder = false;
        $codeLanguage = null;

        $pPr = $this->firstChildByName($p, 'pPr');
        if ($pPr !== null) {
            $styleNode = $this->firstChildByName($pPr, 'pStyle');
            if ($styleNode !== null) {
                $style = $this->wAttr($styleNode, 'val');
            }
            $jc = $this->firstChildByName($pPr, 'jc');
            if ($jc !== null) {
                $align = match ($this->wAttr($jc, 'val')) {
                    'center' => 'center',
                    'right', 'end' => 'right',
                    'both', 'distribute' => 'justify',
                    default => null,
                };
            }
            $outline = $this->firstChildByName($pPr, 'outlineLvl');
            if ($outline !== null && is_numeric($this->wAttr($outline, 'val'))) {
                $outlineLvl = (int) $this->wAttr($outline, 'val');
            }
            $numPrNode = $this->firstChildByName($pPr, 'numPr');
            if ($numPrNode !== null) {
                $ilvlNode = $this->firstChildByName($numPrNode, 'ilvl');
                $numIdNode = $this->firstChildByName($numPrNode, 'numId');
                $numId = $numIdNode !== null ? (int) ($this->wAttr($numIdNode, 'val') ?? 0) : 0;
                if ($numId > 0) {
                    $ilvl = $ilvlNode !== null ? (int) ($this->wAttr($ilvlNode, 'val') ?? 0) : 0;
                    $numPr = [max(0, min(5, $ilvl)), $numId];
                }
            }
            $pBdr = $this->firstChildByName($pPr, 'pBdr');
            if ($pBdr !== null && $this->firstChildByName($pBdr, 'bottom') !== null) {
                $bottomBorder = true;
            }
        }

        $props = $this->parseParagraphProperties($pPr);

        // Pre-0.2.0 code-language bookmark convention (`LastWordCode_{lang}`)
        // — kept for back-compat; the canonical slot is the sdt tag.
        foreach ($p->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'bookmarkStart') {
                $name = $this->wAttr($child, 'name') ?? '';
                if (str_starts_with($name, 'LastWordCode_')) {
                    $codeLanguage = substr($name, strlen('LastWordCode_'));
                }
            }
        }

        // `leading`: pictures met before any of the paragraph's text, which a
        // header or footer puts first (a logo beside an address). `field`: a
        // complex field in a header or footer -- null outside one, its
        // instruction so far, or false while its cached result is skipped.
        // Field markup that does not balance inside the paragraph (a begin with no
        // end, an end with no begin) is not tracked at all: the paragraph reads as
        // its text, as before body fields were read, and says so.
        $fieldsOff = !$this->inBand && !$this->fieldsBalance($p);
        if ($fieldsOff) {
            $this->noteStructure();
        }
        $state = ['pageBreak' => false, 'images' => [], 'leading' => [], 'seenText' => false, 'field' => null, 'fields' => [], 'fieldsOff' => $fieldsOff];
        $revision = $this->paragraphRevision($pPr, $style); // before its runs: the order ids are read in
        if ($revision === null && !$this->preserveTrackedChanges && !$this->inBand && !$this->inTextBox && $this->markDeleted($pPr)) {
            // The default read accepts a deleted paragraph mark too: carried this far,
            // then joined to the next paragraph once the document is read.
            $revision = ['delete' => ['id' => 'accepted', 'author' => Revisions::UNKNOWN_AUTHOR]];
        }
        $runs = $this->parseInlineContainer($p, null, $state);
        // A field still capturing at the paragraph's end: its text, as text.
        foreach ($state['fields'] as $open) {
            if ($open['token'] !== null && $open['text'] !== '') {
                $runs[] = ['text' => $open['text']];
            }
        }
        $runs = $this->resolveBookmarks($runs);

        // The runs between column breaks, for a paragraph that splits there; and
        // the runs with each break a newline, for one that cannot.
        $joined = [];
        $segments = [[]];
        foreach ($runs as $run) {
            if (isset($run[self::COLUMN_MARK])) {
                unset($run[self::COLUMN_MARK]);
                $joined[] = $run;
                $segments[] = [];

                continue;
            }
            $joined[] = $run;
            $segments[count($segments) - 1][] = $run;
        }

        return [
            'revision' => $revision,
            'style' => $style,
            'align' => $align,
            'props' => $props,
            'outlineLvl' => $outlineLvl,
            'numPr' => $numPr,
            'bottomBorder' => $bottomBorder,
            'pageBreak' => $state['pageBreak'],
            'columnBreaks' => count($segments) - 1,
            'segments' => count($segments) > 1 ? array_map(fn (array $seg): array => $this->mergeRuns($seg), $segments) : null,
            'codeLanguage' => $codeLanguage,
            'runs' => $this->mergeRuns($joined),
            'images' => $state['images'],
            'leading' => $state['leading'],
        ];
    }

    /**
     * Paragraph properties back into the model — the same set `paragraph`,
     * `heading` and a list item all accept. `align` is left to the caller,
     * which already computed it.
     *
     * @return array<string, mixed>
     */
    private function parseParagraphProperties(?DOMElement $pPr): array
    {
        if ($pPr === null) {
            return [];
        }
        $out = [];

        $spacing = $this->firstChildByName($pPr, 'spacing');
        if ($spacing !== null) {
            foreach (['before' => 'spaceBefore', 'after' => 'spaceAfter'] as $attr => $key) {
                $value = $this->wAttr($spacing, $attr);
                if (is_numeric($value)) {
                    $out[$key] = self::points((float) $value);
                }
            }
            $line = $this->wAttr($spacing, 'line');
            if (is_numeric($line) && $this->wAttr($spacing, 'lineRule') === 'auto') {
                $out['lineHeight'] = round(((float) $line) / 240, 3);
            }
        }

        $ind = $this->firstChildByName($pPr, 'ind');
        if ($ind !== null) {
            foreach (['left' => 'indentLeft', 'right' => 'indentRight'] as $attr => $key) {
                $value = $this->wAttr($ind, $attr);
                if (is_numeric($value)) {
                    $out[$key] = self::points((float) $value);
                }
            }
        }

        $keepNext = $this->firstChildByName($pPr, 'keepNext');
        if ($keepNext !== null && $this->toggleOn($this->wAttr($keepNext, 'val'))) {
            $out['keepNext'] = true;
        }

        $shd = $this->firstChildByName($pPr, 'shd');
        if ($shd !== null) {
            $fill = $this->wAttr($shd, 'fill');
            if (is_string($fill) && preg_match('/^[0-9A-Fa-f]{6}$/', $fill) === 1) {
                $out['shading'] = '#' . strtoupper($fill);
            }
        }

        $borders = $this->parseBorders($this->firstChildByName($pPr, 'pBdr'), self::BOX_EDGES);
        if ($borders !== null) {
            $out['borders'] = $borders;
        }

        return $out;
    }

    /** Twips → points, kept exact for the halves the writer can emit. */
    private static function points(float $twips): float|int
    {
        $pt = round($twips / 20, 2);

        return $pt == (int) $pt ? (int) $pt : $pt;
    }

    /**
     * One border edge back into the model.
     *
     * Defaults are NOT surfaced: `style` only when it is not `single`,
     * `color` only when it is not `auto`. Otherwise reading a document written
     * from a model returns a bigger model than went in, and writing that model
     * back produces a different file.
     *
     * @return array<string, mixed>|null
     */
    private function parseBorderEdge(?DOMElement $edge): ?array
    {
        if ($edge === null) {
            return null;
        }
        $val = $this->wAttr($edge, 'val') ?? 'single';
        if ($val === 'nil' || $val === 'none') {
            return ['style' => 'none'];
        }
        $out = [];
        if ($val !== 'single') {
            $out['style'] = $val;
        }
        $sz = $this->wAttr($edge, 'sz');
        if (is_numeric($sz)) {
            $width = round(((float) $sz) / 8, 3);
            $out['width'] = $width == (int) $width ? (int) $width : $width;
        }
        $color = $this->wAttr($edge, 'color');
        if (is_string($color) && preg_match('/^[0-9A-Fa-f]{6}$/', $color) === 1) {
            $out['color'] = '#' . strtoupper($color);
        }

        return $out;
    }

    /**
     * @param  list<string>  $edges
     * @return array<string, mixed>|null
     */
    private function parseBorders(?DOMElement $container, array $edges): ?array
    {
        if ($container === null) {
            return null;
        }
        $out = [];
        foreach ($edges as $edge) {
            $border = $this->parseBorderEdge($this->firstChildByName($container, $edge));
            if ($border !== null) {
                $out[$edge] = $border;
            }
        }

        return $out === [] ? null : $out;
    }

    /**
     * A margin container (`w:tblCellMar`, `w:tcMar`) back into points.
     *
     * @return array<string, float|int>|null
     */
    private function parseSides(?DOMElement $container): ?array
    {
        if ($container === null) {
            return null;
        }
        $out = [];
        foreach (self::BOX_EDGES as $side) {
            $node = $this->firstChildByName($container, $side);
            if ($node === null) {
                continue;
            }
            $w = $this->wAttr($node, 'w');
            if (is_numeric($w)) {
                $out[$side] = self::points((float) $w);
            }
        }

        return $out === [] ? null : $out;
    }

    // ─── Inline parsing ──────────────────────────────────────────────────

    /**
     * Parse the inline content of a paragraph-like element into runs.
     * Recurses through hyperlinks, ins, fldSimple, smartTag and any other
     * unknown inline wrappers so their text degrades instead of dropping.
     *
     * @param  array{pageBreak: bool, images: list<array<string, mixed>>}  $state
     * @return list<array<string, mixed>>
     */
    private function parseInlineContainer(DOMElement $container, ?string $link, array &$state): array
    {
        $runs = [];
        foreach ($container->childNodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            switch ($node->localName) {
                case 'r':
                    // A list: one w:r holding text AND a footnote reference
                    // (another producer's spelling) is two runs in the model.
                    array_push($runs, ...$this->parseRun($node, $link, $state));

                    break;
                case 'hyperlink':
                    $rId = $this->rAttr($node, 'id');
                    $target = $rId !== null ? ($this->rels[$rId]['target'] ?? null) : null;
                    $anchor = $this->wAttr($node, 'anchor');
                    if ($target === null && $anchor !== null && $anchor !== '') {
                        $target = '#' . $anchor;
                    }
                    array_push($runs, ...$this->parseInlineContainer($node, $target ?? $link, $state));

                    break;
                case 'fldSimple':
                    $token = self::fieldToken((string) $this->wAttr($node, 'instr'));
                    if ($token === '') {
                        array_push($runs, ...$this->parseInlineContainer($node, $link, $state));

                        break;
                    }
                    if (!$this->inBand) {
                        // In body text an allow-listed field is a field run, its
                        // cached result the text; any other keeps its text.
                        $inner = array_values(array_filter(
                            $this->parseInlineContainer($node, $link, $state),
                            static fn (array $r): bool => !isset($r['__bookmark']) && is_string($r['text'] ?? null),
                        ));
                        $run = $inner[0] ?? ['text' => ''];
                        unset($run[self::COLUMN_MARK]);
                        $run['text'] = implode('', array_column($inner, 'text'));
                        $runs[] = $run + ['field' => $token];
                        $state['seenText'] = true;

                        break;
                    }
                    // The cached result's formatting, the field's token as text.
                    $inner = $this->parseInlineContainer($node, $link, $state);
                    $runs[] = ['text' => $token] + array_diff_key($inner[0] ?? [], [self::COLUMN_MARK => true]);
                    $state['seenText'] = true;

                    break;
                case 'ins':
                case 'moveTo':
                case 'del':
                case 'moveFrom':
                    if ($this->preserving()) {
                        array_push($runs, ...$this->parseRevision($node, $link, $state));
                    } elseif ($node->localName === 'ins' || $node->localName === 'moveTo') {
                        array_push($runs, ...$this->parseInlineContainer($node, $link, $state));
                    }
                    // Accepted: a deletion's text is dropped, a move's source too (its text is at its destination).

                    break;
                case 'moveFromRangeStart':
                case 'moveFromRangeEnd':
                case 'moveToRangeStart':
                case 'moveToRangeEnd':
                    $this->moveRange($node);

                    break;
                case 'bookmarkStart':
                case 'bookmarkEnd':
                    // Body bookmarks only, as markers {@see resolveBookmarks()} turns
                    // back into runs.
                    if ($this->inBand || $this->inFootnote || $this->inTextBox) {
                        break;
                    }
                    $id = (string) $this->wAttr($node, 'id');
                    if ($node->localName === 'bookmarkEnd') {
                        // Only the end of a bookmark that was read: a hidden one's
                        // (_GoBack) must not cut a user bookmark's range.
                        if (isset($this->bookmarkIds[$id])) {
                            $runs[] = ['__bookmark' => 'end', 'id' => $id];
                        }
                    } elseif (!isset($this->bookmarkIds[$id]) && $this->acceptBookmark((string) $this->wAttr($node, 'name'))) {
                        $this->bookmarkIds[$id] = true;
                        $runs[] = ['__bookmark' => 'start', 'id' => $id, 'name' => (string) $this->wAttr($node, 'name')];
                    }

                    break;
                case 'pPr':
                case 'proofErr':
                case 'commentRangeStart':
                case 'commentRangeEnd':
                    break;
                case 'AlternateContent':
                    // One branch, never both: the fallback is the same content again.
                    $branch = $this->mcDepth < self::MAX_MC_DEPTH ? ShapeReader::branch($node) : null;
                    if ($branch !== null) {
                        $this->mcDepth++;
                        try {
                            array_push($runs, ...$this->parseInlineContainer($branch, $link, $state));
                        } finally {
                            $this->mcDepth--;
                        }
                    } else {
                        array_push($state['images'], ...$this->textOfMarkup($node));
                    }

                    break;
                default:
                    // ins, fldSimple, smartTag, sdt(run-level), … — descend.
                    array_push($runs, ...$this->parseInlineContainer($node, $link, $state));

                    break;
            }
        }

        return $runs;
    }

    /**
     * One w:r as model runs: usually one, none when it carries no text, and a
     * footnote run where it holds a `w:footnoteReference` (with the text either
     * side of it as runs of their own).
     *
     * @param  array{pageBreak: bool, images: list<array<string, mixed>>}  $state
     * @return list<array<string, mixed>>
     */
    private function parseRun(DOMElement $r, ?string $link, array &$state): array
    {
        $this->commentRun = $r;
        $props = [];
        $rPr = $this->firstChildByName($r, 'rPr');
        if ($rPr !== null) {
            $props = $this->parseRunProperties($rPr);
        }
        $this->runFormat = $this->preserving() ? $this->formatEntry($rPr) : null;
        $deleted = $this->revDeparture !== null;

        $out = [];
        $text = '';
        foreach ($this->runChildren($r, $state) as $node) {
            if ($this->inBand && in_array($node->localName, ['fldChar', 'instrText'], true)) {
                $text .= $this->bandField($node, $state);

                continue;
            }
            if (!$this->inBand && in_array($node->localName, ['fldChar', 'instrText'], true)) {
                if ($state['fieldsOff'] ?? false) {
                    continue;
                }
                $done = $this->bodyField($node, $state);
                if ($done !== null) {
                    if ($text !== '') {
                        $out[] = $this->runFrom($text, $props, $link, $state);
                        $text = '';
                    }
                    if ($done['field'] !== null) {
                        $out[] = $this->runFrom($done['text'], $done['props'] ?? $props, $link, $state) + ['field' => $done['field']];
                    }
                }

                continue;
            }
            switch ($node->localName) {
                case 'delText':
                    if (!$deleted) {
                        break; // outside a deletion, not text
                    }
                    // A deletion's text: read as w:t is.
                case 't':
                    $capture = $this->bodyFieldCapture($state);
                    if ($capture === false) {
                        break; // an instruction's own text, not the document's
                    }
                    if (is_int($capture)) {
                        $state['fields'][$capture]['text'] .= $node->textContent;
                        $state['fields'][$capture]['props'] ??= $props;

                        break;
                    }
                    if ($state['field'] === null) {
                        $piece = $node->textContent;
                        // The space Word puts between a footnote's own mark and
                        // its text is the mark's, not the text's.
                        if (($state['afterMark'] ?? false) && $piece !== '') {
                            if ($piece[0] === ' ') {
                                $piece = substr($piece, 1);
                            }
                            $state['afterMark'] = false;
                        }
                        $text .= $piece;
                    }

                    break;
                case 'footnoteRef':
                    // A footnote's own number, inside footnotes.xml: Word draws it.
                    $state['afterMark'] = true;

                    break;
                case 'footnoteReference':
                    if ($this->inTextBox) {
                        // A text box holds no footnote: its text goes after the box.
                        $this->inTextBox = false;
                        try {
                            $note = $this->footnoteRun((string) $this->wAttr($node, 'id'));
                        } finally {
                            $this->inTextBox = true;
                        }
                        $this->commentRun = $r;
                        if ($note !== null) {
                            array_push($this->boxSpill, ...$note['footnote']['blocks']);
                            $this->noteShape('nested-block');
                        }

                        break;
                    }
                    $note = $this->footnoteRun((string) $this->wAttr($node, 'id'));
                    $this->commentRun = $r; // reading the footnote moved it
                    if ($note !== null) {
                        if ($text !== '') {
                            $out[] = $this->runFrom($text, $props, $link, $state);
                            $text = '';
                        }
                        $ids = $this->inBand || $this->inFootnote || $this->inTextBox ? null : $this->comments?->idsFor($r);
                        $marked = $ids !== null ? $note + ['comments' => $ids] : $note;
                        $rev = $this->currentRevision(false);
                        $out[] = $rev !== [] ? $marked + ['revision' => $rev] : $marked;
                    }

                    break;
                case 'commentReference':
                    // A point comment: one whose range kept no text, or had none.
                    $ids = $this->inTextBox ? null : $this->comments?->pointAt($node);
                    if ($ids !== null) {
                        if ($text !== '') {
                            $out[] = $this->runFrom($text, $props, $link, $state);
                            $text = '';
                        }
                        $out[] = ['text' => '', 'comments' => $ids];
                    }

                    break;
                case 'br':
                    if ($deleted && in_array($this->wAttr($node, 'type'), ['page', 'column'], true)) {
                        $this->revUnkept++; // a deleted page or column break: not made live

                        break;
                    }
                    if ($this->wAttr($node, 'type') === 'page' && $this->inTextBox) {
                        $this->noteShape('layout-not-preserved'); // a text box has no pages to break
                    } elseif ($this->wAttr($node, 'type') === 'page') {
                        $state['pageBreak'] = true;
                    } elseif ($this->wAttr($node, 'type') === 'column') {
                        // A run of its own, so the words either side never fuse: a
                        // block where the paragraph can split, a newline where not.
                        if ($text !== '') {
                            $out[] = $this->runFrom($text, $props, $link, $state);
                            $text = '';
                        }
                        $out[] = $this->runFrom("\n", $props, $link, $state) + [self::COLUMN_MARK => true];
                    } else {
                        $text .= "\n";
                    }

                    break;
                case 'tab':
                    if ($state['afterMark'] ?? false) {
                        $state['afterMark'] = false; // a tab after the mark is the mark's too

                        break;
                    }
                    $text .= "\t";

                    break;
                case 'pict':
                    $vml = $this->inBand ? null : ShapeReader::vml($node);
                    if ($vml !== null && $deleted) {
                        $this->revUnkept++;
                    } elseif ($vml !== null) {
                        array_push($state['images'], ...$this->readShape(...ShapeReader::fromVml($vml)));
                    }

                    break;
                case 'drawing':
                    if ($deleted) {
                        $this->revUnkept++; // a deleted picture: not made live

                        break;
                    }
                    $wsp = $this->inBand ? null : ShapeReader::wsp($node);
                    if ($wsp !== null) {
                        array_push($state['images'], ...$this->readShape(...ShapeReader::fromWsp($wsp)));

                        break;
                    }
                    if ($this->inTextBox && $this->parseChartDrawing($node) !== null) {
                        $this->noteShape('chart'); // a text box holds no chart

                        break;
                    }
                    $image = $this->parseDrawing($node);
                    if ($image !== null && $this->inBand && !$state['seenText'] && $text === '') {
                        $state['leading'][] = $image;
                    } elseif ($image !== null) {
                        $state['images'][] = $image;
                    }

                    break;
                default:
                    break;
            }
        }

        if ($text !== '') {
            $out[] = $this->runFrom($text, $props, $link, $state);
        }

        return $out;
    }

    /**
     * A model run from text and its run properties.
     *
     * @param  array<string, mixed>  $props
     * @param  array{seenText: bool}  $state
     * @return array<string, mixed>
     */
    private function runFrom(string $text, array $props, ?string $link, array &$state): array
    {
        $state['seenText'] = true;

        $run = ['text' => $text];
        foreach (['bold', 'italic', 'underline', 'strike', 'code', 'smallCaps'] as $flag) {
            if (!empty($props[$flag])) {
                $run[$flag] = true;
            }
        }
        if ($link !== null && $link !== '') {
            $run['link'] = $link;
        }
        // Every non-boolean run property, listed once. A key missing from
        // here is read out of the XML and then dropped on the floor — which is
        // exactly how `size`, `font` and `letterSpacing` were invisible on the
        // way back before this list grew.
        foreach (['color', 'highlight', 'size', 'font', 'letterSpacing'] as $key) {
            if (isset($props[$key])) {
                $run[$key] = $props[$key];
            }
        }
        $ids = $this->inBand || $this->inFootnote || $this->inTextBox ? null : $this->comments?->idsFor($this->commentRun);
        if ($ids !== null) {
            $run['comments'] = $ids;
        }
        if ($this->preserving()) {
            $rev = $this->currentRevision(true);
            if ($rev !== []) {
                $run['revision'] = $rev;
            }
        }

        return $run;
    }

    /** Whether every complex field in the paragraph begins and ends in it. */
    private function fieldsBalance(DOMElement $p): bool
    {
        $depth = 0;
        foreach ($p->getElementsByTagName('*') as $el) {
            if ($el->localName !== 'fldChar') {
                continue;
            }
            $type = $this->wAttr($el, 'fldCharType');
            if ($type === 'begin') {
                $depth++;
            } elseif ($type === 'end' && --$depth < 0) {
                return false;
            }
        }

        return $depth === 0;
    }

    /**
     * One piece of a complex field in body text. Fields nest, so they are a stack.
     * An allow-listed one ({@see Schema::fieldTokenOf()}) not inside another is
     * captured: its cached result becomes a run with `field`, and is returned when
     * its end arrives (`field` null for anything else that ends, which signals
     * the text before it to be flushed). Any other field keeps its cached result
     * as text, as before, and a field inside another's instruction (an IF's
     * PAGE) is the instruction's, never text. Null while nothing ends.
     *
     * @param  array<string, mixed>  $state
     * @return array{field: ?string, text: string, props: ?array<string, mixed>}|null
     */
    private function bodyField(DOMElement $node, array &$state): ?array
    {
        $top = array_key_last($state['fields']);
        if ($node->localName === 'instrText') {
            if ($top !== null && !$state['fields'][$top]['separated']) {
                $state['fields'][$top]['instr'] .= $node->textContent;
            }

            return null;
        }
        $type = $this->wAttr($node, 'fldCharType');
        // Fields nested deeper than this are counted, not tracked: the stack is
        // walked for every piece of text, so a hostile file's depth is bounded.
        if ($type === 'begin') {
            if ($top !== null && $top + 1 >= self::MAX_FIELD_DEPTH) {
                $state['fieldOverflow'] = ($state['fieldOverflow'] ?? 0) + 1;

                return null;
            }
            $state['fields'][] = ['instr' => '', 'separated' => false, 'token' => null, 'text' => '', 'props' => null];

            return null;
        }
        if (($state['fieldOverflow'] ?? 0) > 0) {
            if ($type === 'end') {
                $state['fieldOverflow']--;
            }

            return null;
        }
        if ($top === null) {
            return null;
        }
        if ($type === 'separate') {
            $state['fields'][$top]['separated'] = true;
            $outer = array_slice($state['fields'], 0, $top);
            $free = array_filter($outer, static fn (array $f): bool => !$f['separated'] || $f['token'] !== null) === [];
            $token = self::fieldToken($state['fields'][$top]['instr']);
            if ($free && $token !== '') {
                $state['fields'][$top]['token'] = $token;
            }

            return null;
        }
        if ($type === 'end') {
            $field = array_pop($state['fields']);
            $parent = array_key_last($state['fields']);
            if ($field['token'] !== null) {
                return ['field' => $field['token'], 'text' => $field['text'], 'props' => $field['props']];
            }
            if ($parent !== null) {
                return null;
            }

            return ['field' => null, 'text' => '', 'props' => null];
        }

        return null;
    }

    /**
     * Where body text goes while fields are open: false when it is an instruction's
     * (some open field has not reached its result), the index of the capturing
     * field when one is capturing, and null when it is plain text.
     *
     * @param  array<string, mixed>  $state
     */
    private function bodyFieldCapture(array $state): int|false|null
    {
        if ($this->inBand || ($state['fieldsOff'] ?? false)) {
            return null;
        }
        $capture = null;
        foreach ($state['fields'] ?? [] as $i => $field) {
            if (!$field['separated']) {
                return false;
            }
            if ($field['token'] !== null && $capture === null) {
                $capture = $i;
            }
        }

        return $capture;
    }

    /**
     * One piece of a complex field in a header or footer (last-word#6): a field
     * that is a token comes back as it (`{page}`, `{author}`, `{date:yyyy}`),
     * the same tokens a band's slots use, and its cached result ("1", the
     * author's name) is skipped. Any other field keeps its cached result as
     * text, as the body does.
     *
     * @param  array{field: string|false|null}  $state
     */
    private function bandField(DOMElement $node, array &$state): string
    {
        if ($node->localName === 'instrText') {
            if (is_string($state['field'])) {
                $state['field'] .= $node->textContent;
            }

            return '';
        }
        $type = $this->wAttr($node, 'fldCharType');
        if ($type === 'begin') {
            $state['field'] = '';
        } elseif ($type === 'separate' && is_string($state['field'])) {
            $token = self::fieldToken($state['field']);
            $state['field'] = $token !== '' ? false : null;

            return $token;
        } elseif ($type === 'end') {
            $state['field'] = null;
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRunProperties(DOMElement $rPr): array
    {
        $props = [];
        foreach ($rPr->childNodes as $node) {
            if (!($node instanceof DOMElement)) {
                continue;
            }
            $val = $this->wAttr($node, 'val');
            switch ($node->localName) {
                case 'b':
                    $props['bold'] = $this->toggleOn($val);

                    break;
                case 'i':
                    $props['italic'] = $this->toggleOn($val);

                    break;
                case 'strike':
                    $props['strike'] = $this->toggleOn($val);

                    break;
                case 'u':
                    $props['underline'] = $val !== 'none' && $val !== '0';

                    break;
                case 'color':
                    if (is_string($val) && preg_match('/^[0-9A-Fa-f]{6}$/', $val) === 1) {
                        $props['color'] = '#' . strtoupper($val);
                    }

                    break;
                case 'highlight':
                    if (is_string($val) && isset(self::HIGHLIGHT_COLORS[$val])) {
                        $props['highlight'] = self::HIGHLIGHT_COLORS[$val];
                    }

                    break;
                case 'shd':
                    $fill = $this->wAttr($node, 'fill');
                    if (is_string($fill) && preg_match('/^[0-9A-Fa-f]{6}$/', $fill) === 1 && strcasecmp($fill, 'auto') !== 0) {
                        $props['highlight'] = '#' . strtoupper($fill);
                    }

                    break;
                case 'rStyle':
                    if ($val === 'InlineCode') {
                        $props['code'] = true;
                    }

                    break;
                case 'smallCaps':
                    if ($this->toggleOn($val)) {
                        $props['smallCaps'] = true;
                    }

                    break;
                case 'sz':
                    if (is_numeric($val)) {
                        $props['size'] = ((float) $val) / 2;
                    }

                    break;
                case 'spacing':
                    // Tracking of zero is the writer's "absent", so a zero
                    // here would be a property nobody asked for.
                    if (is_numeric($val) && (float) $val != 0.0) {
                        $props['letterSpacing'] = ((float) $val) / 20;
                    }

                    break;
                case 'rFonts':
                    $ascii = $this->wAttr($node, 'ascii');
                    if (is_string($ascii) && $ascii !== '') {
                        $props['font'] = $ascii;
                    }

                    break;
                default:
                    break;
            }
        }

        // A `code` run's Consolas came FROM `code`, so surfacing it as `font`
        // too would hand back a bigger model than went in — and the next write
        // would then differ from the one just read.
        if (isset($props['font'])) {
            if (in_array(strtolower($props['font']), self::MONO_FONTS, true)) {
                $props['code'] = true;
                unset($props['font']);
            }
        }

        // The InlineCode style's own shading is presentation, not a highlight.
        if (!empty($props['code']) && isset($props['highlight']) && strcasecmp($props['highlight'], '#F2F2F2') === 0) {
            unset($props['highlight']);
        }

        return $props;
    }

    /**
     * Merge adjacent runs with identical formatting and drop empties —
     * Word fragments runs freely (spell-check, edit history), the model
     * doesn't care.
     *
     * @param  list<array<string, mixed>>  $runs
     * @return list<array<string, mixed>>
     */
    private function mergeRuns(array $runs): array
    {
        $merged = [];
        foreach ($runs as $run) {
            if ($run['text'] === '' && isset($run['comments']) && !array_key_exists('footnote', $run)) {
                $merged[] = $run; // a point comment: no text, still there

                continue;
            }
            // A footnote run's text is always "", and it is never merged: two
            // identical footnotes in a row are two footnotes.
            // A field is never merged either (two {page} in a row are two
            // fields), and a bookmark or a field with no text is still there.
            $isNote = array_key_exists('footnote', $run) || array_key_exists('field', $run);
            if ($run['text'] === '' && !$isNote && !array_key_exists('bookmark', $run)) {
                continue;
            }
            $last = $merged !== [] ? count($merged) - 1 : null;
            if ($last !== null && !$isNote && !array_key_exists('footnote', $merged[$last]) && !array_key_exists('field', $merged[$last])) {
                $a = $merged[$last];
                $b = $run;
                $aProps = $a;
                $bProps = $b;
                unset($aProps['text'], $bProps['text']);
                if ($aProps == $bProps) {
                    $merged[$last]['text'] .= $run['text'];

                    continue;
                }
            }
            $merged[] = $run;
        }

        return $merged;
    }

    // ─── Tables ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    /**
     * A table back into the model.
     *
     * Two things make this the hardest read in the package. First, the writer
     * emits borders, cell margins and a `w:tcW` for every cell whether or not
     * the model asked — so anything it would have produced anyway is NOT
     * surfaced, or every document written before these options existed would
     * come back carrying options nobody set. Second, the file contains the
     * `w:vMerge` continuation cells the writer synthesised, and they have to
     * be dropped and turned back into a `rowSpan` on the cell above.
     *
     * @return array<string, mixed>
     */
    private function parseTable(DOMElement $tbl, bool $insideQuote = false): array
    {
        $tblPr = $this->firstChildByName($tbl, 'tblPr');
        $table = ['type' => 'table'];

        $grid = [];
        $tblGrid = $this->firstChildByName($tbl, 'tblGrid');
        if ($tblGrid !== null) {
            foreach ($tblGrid->childNodes as $col) {
                if ($col instanceof DOMElement && $col->localName === 'gridCol') {
                    $grid[] = (int) ($this->wAttr($col, 'w') ?? 0);
                }
            }
        }

        if ($tblPr !== null) {
            $tblW = $this->firstChildByName($tblPr, 'tblW');
            if ($tblW !== null && $this->wAttr($tblW, 'type') === 'pct') {
                $w = $this->wAttr($tblW, 'w');
                if (is_numeric($w)) {
                    $pct = round(((float) $w) / 50, 2);
                    $table['width'] = $pct == (int) $pct ? (int) $pct : $pct;
                }
            }
            $jc = $this->firstChildByName($tblPr, 'jc');
            if ($jc !== null && in_array($this->wAttr($jc, 'val'), ['center', 'right'], true)) {
                $table['align'] = $this->wAttr($jc, 'val');
            }
            $borders = $this->parseBorders($this->firstChildByName($tblPr, 'tblBorders'), self::TABLE_EDGES);
            if ($borders !== null && !$this->isDefaultTableBorders($borders)) {
                $table['borders'] = $borders;
            }
            $padding = $this->parseSides($this->firstChildByName($tblPr, 'tblCellMar'));
            if ($padding !== null && !$this->isDefaultCellMargins($padding)) {
                $table['cellPadding'] = $padding;
            }
        }

        // Weights are only surfaced when the grid is NOT what an equal split
        // would have produced — compared against the split the writer
        // computes, not tested for exact equality, so a three-column table
        // whose width does not divide by three is still recognised as equal.
        $total = array_sum($grid);
        if ($grid !== [] && $total > 0 && $grid !== DocxWriter::splitColumnsFor($total, count($grid))) {
            $table['widths'] = array_map(
                static function (int $w) use ($total): float|int {
                    $pct = round(($w / $total) * 100, 2);

                    return $pct == (int) $pct ? (int) $pct : $pct;
                },
                $grid,
            );
        }

        // Pass 1: read every emitted cell, keeping its grid column and merge
        // state.
        $laid = [];
        $headers = [];
        foreach ($tbl->childNodes as $node) {
            if (!($node instanceof DOMElement) || $node->localName !== 'tr') {
                continue;
            }
            $trPr = $this->firstChildByName($node, 'trPr');
            $header = $trPr !== null && $this->firstChildByName($trPr, 'tblHeader') !== null;
            $headers[] = $header;

            $slots = [];
            $col = 0;
            foreach ($node->childNodes as $tc) {
                if (!($tc instanceof DOMElement) || $tc->localName !== 'tc') {
                    continue;
                }
                $tcPr = $this->firstChildByName($tc, 'tcPr');
                $span = 1;
                $vMerge = null;
                if ($tcPr !== null) {
                    $gridSpan = $this->firstChildByName($tcPr, 'gridSpan');
                    if ($gridSpan !== null && is_numeric($this->wAttr($gridSpan, 'val'))) {
                        $span = max(1, min(Schema::MAX_COL_SPAN, (int) $this->wAttr($gridSpan, 'val')));
                    }
                    $vMergeNode = $this->firstChildByName($tcPr, 'vMerge');
                    if ($vMergeNode !== null) {
                        $vMerge = $this->wAttr($vMergeNode, 'val') ?? 'continue';
                    }
                }

                $cell = null;
                if ($vMerge !== 'continue') {
                    $cell = ['blocks' => $this->parseBlockContainer($tc, false, $insideQuote)];
                    if ($tcPr !== null) {
                        $shd = $this->firstChildByName($tcPr, 'shd');
                        $fill = $shd !== null ? $this->wAttr($shd, 'fill') : null;
                        // A header row's grey came FROM `header`, so it is
                        // attributable and not surfaced. Any other fill is the
                        // author's.
                        if (is_string($fill) && preg_match('/^[0-9A-Fa-f]{6}$/', $fill) === 1
                            && !($header && strcasecmp($fill, self::HEADER_FILL) === 0)) {
                            $cell['shading'] = '#' . strtoupper($fill);
                        }
                        $cellBorders = $this->parseBorders($this->firstChildByName($tcPr, 'tcBorders'), self::BOX_EDGES);
                        if ($cellBorders !== null) {
                            $cell['borders'] = $cellBorders;
                        }
                        $cellPadding = $this->parseSides($this->firstChildByName($tcPr, 'tcMar'));
                        if ($cellPadding !== null) {
                            $cell['padding'] = $cellPadding;
                        }
                        $vAlign = $this->firstChildByName($tcPr, 'vAlign');
                        if ($vAlign !== null && in_array($this->wAttr($vAlign, 'val'), ['top', 'center', 'bottom'], true)) {
                            $cell['valign'] = $this->wAttr($vAlign, 'val');
                        }
                    }
                    if ($span > 1) {
                        $cell['colSpan'] = $span;
                    }
                }

                $slots[] = ['col' => $col, 'span' => $span, 'vMerge' => $vMerge, 'cell' => $cell];
                $col += $span;
            }
            $laid[] = $slots;
        }

        // Pass 2: fold each run of continuations back into the cell that
        // started it.
        foreach ($laid as $r => $line) {
            foreach ($line as $i => $slot) {
                if ($slot['vMerge'] !== 'restart' || $slot['cell'] === null) {
                    continue;
                }
                $covered = 1;
                for ($below = $r + 1; $below < count($laid); $below++) {
                    $found = false;
                    foreach ($laid[$below] as $candidate) {
                        if ($candidate['col'] === $slot['col'] && $candidate['vMerge'] === 'continue') {
                            $found = true;

                            break;
                        }
                    }
                    if (!$found) {
                        break;
                    }
                    $covered++;
                }
                if ($covered > 1) {
                    $laid[$r][$i]['cell']['rowSpan'] = $covered;
                }
            }
        }

        $rows = [];
        foreach ($laid as $r => $line) {
            $cells = [];
            foreach ($line as $slot) {
                if ($slot['cell'] !== null) {
                    $cells[] = $slot['cell'];
                }
            }
            $row = [];
            if ($headers[$r]) {
                $row['header'] = true;
            }
            $row['cells'] = $cells;
            $rows[] = $row;
        }

        $table['rows'] = $rows;

        return $table;
    }

    /**
     * Is this exactly what the writer emits for a table that asked for
     * nothing? Not a tolerance and not a heuristic: the writer's defaults are
     * a fixed set, and anything differing by one edge or one twip is the
     * author's and must survive the read.
     *
     * @param  array<string, mixed>  $borders
     */
    private function isDefaultTableBorders(array $borders): bool
    {
        if (count($borders) !== count(self::TABLE_EDGES)) {
            return false;
        }
        foreach (self::TABLE_EDGES as $edge) {
            // The default edge is single / 0.5pt / auto, which reads back as
            // width alone — style and colour are both the omitted default.
            if (($borders[$edge] ?? null) !== ['width' => 0.5]) {
                return false;
            }
        }

        return true;
    }

    /** @param  array<string, float|int>  $sides */
    private function isDefaultCellMargins(array $sides): bool
    {
        return $sides == ['top' => 3, 'left' => 5.4, 'bottom' => 3, 'right' => 5.4];
    }

    // ─── Images ──────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function parseDrawing(DOMElement $drawing): ?array
    {
        $blip = $this->firstDescendantByName($drawing, 'blip');
        if ($blip === null) {
            return $this->parseChartDrawing($drawing);
        }
        // In a header or footer only an EMBEDDED picture is read: a linked one is
        // a URL, and nothing here ever fetches (last-word#6, as #5).
        $rId = $this->inBand ? $this->rAttr($blip, 'embed') : ($this->rAttr($blip, 'embed') ?? $this->rAttr($blip, 'link'));
        if ($this->inBand && $rId !== null && ($this->rels[$rId]['external'] ?? true)) {
            return null;
        }
        $target = $rId !== null ? ($this->rels[$rId]['target'] ?? null) : null;
        if ($target === null) {
            return null;
        }
        $target = ltrim($target, '/');
        if (str_starts_with($target, 'word/')) {
            $target = substr($target, strlen('word/'));
        }
        $bytes = $this->media[$target] ?? null;
        if ($bytes === null) {
            return null;
        }

        $ext = strtolower((string) pathinfo($target, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            default => null,
        };
        if ($mime === null) {
            return null; // gif/emf/… — outside the model, degrade by dropping
        }
        // A header or footer is where a customer's letterhead lives, so its
        // pictures face the template letterhead's media checks: the bytes must
        // start the way the extension says, under the per-image and total caps.
        if ($this->inBand) {
            if (!TemplateSection::looksLike($ext, $bytes) || strlen($bytes) > TemplateSection::MEDIA_LIMIT
                || $this->bandMediaBytes + strlen($bytes) > TemplateSection::MEDIA_TOTAL_LIMIT) {
                return null;
            }
            $this->bandMediaBytes += strlen($bytes);
        }

        $image = ['type' => 'image', 'src' => "data:{$mime};base64," . base64_encode($bytes)];

        $extent = $this->firstDescendantByName($drawing, 'extent');
        if ($extent !== null) {
            $cx = (int) $extent->getAttribute('cx');
            $cy = (int) $extent->getAttribute('cy');
            if ($cx > 0) {
                $image['widthPx'] = (int) round($cx / 9525);
            }
            if ($cy > 0) {
                $image['heightPx'] = (int) round($cy / 9525);
            }
        }

        $docPr = $this->firstDescendantByName($drawing, 'docPr');
        $descr = $docPr?->getAttribute('descr');
        if (is_string($descr) && $descr !== '') {
            $image['alt'] = $descr;
        }

        return $image;
    }

    /**
     * A drawing holding a chart (`a:graphicData` of the chart namespace), back
     * as a chart block; null for any drawing that is not a chart this package
     * can model -- the drawing is skipped, the read carries on.
     *
     * @return array<string, mixed>|null
     */
    private function parseChartDrawing(DOMElement $drawing): ?array
    {
        $chart = $this->firstDescendantByName($drawing, 'chart');
        $graphicData = $chart?->parentNode;
        if ($chart === null || !($graphicData instanceof DOMElement) || $graphicData->getAttribute('uri') !== ChartReader::NS_C) {
            return null;
        }
        $rId = $this->rAttr($chart, 'id');
        $target = $rId !== null ? ($this->rels[$rId]['target'] ?? null) : null;
        if ($target === null) {
            return null;
        }
        $target = ltrim($target, '/');
        if (str_starts_with($target, 'word/')) {
            $target = substr($target, strlen('word/'));
        }
        $xml = $this->chartParts[$target] ?? null;
        $read = is_string($xml) ? ChartReader::read($xml) : null;
        if ($read === null) {
            return null;
        }

        $block = ['type' => 'chart', 'option' => $read['option']];

        // The size, unless it is exactly what the writer gives a chart that
        // names none: the text column, 0.6 as tall.
        $extent = $this->firstDescendantByName($drawing, 'extent');
        $cx = $extent !== null ? (int) $extent->getAttribute('cx') : 0;
        $cy = $extent !== null ? (int) $extent->getAttribute('cy') : 0;
        $column = $this->contentWidth * 635;
        if ($cx > 0 && $cy > 0 && !($cx === $column && $cy === (int) round($column * \LastWord\Helpers\Chart::DEFAULT_ASPECT))) {
            $block['widthPx'] = (int) round($cx / 9525);
            $block['heightPx'] = (int) round($cy / 9525);
        }
        if ($read['numberFormat'] !== null) {
            $block['numberFormat'] = $read['numberFormat'];
        }
        $docPr = $this->firstDescendantByName($drawing, 'docPr');
        $descr = $docPr?->getAttribute('descr');
        if (is_string($descr) && $descr !== '') {
            $block['alt'] = $descr;
        }

        return $block;
    }

    // ─── Lists ───────────────────────────────────────────────────────────

    /**
     * Assemble a flat run of numbered paragraphs into the nested list model.
     *
     * @param  list<array{ilvl: int, ordered: bool, runs: list<array<string, mixed>>}>  $entries
     * @return array<string, mixed>
     */
    private function assembleList(array $entries): array
    {
        $block = ['type' => 'list'];
        if ($entries[0]['ordered']) {
            $block['ordered'] = true;
        }
        $block['items'] = [];

        // Stack of references into the growing tree, one per depth.
        $items = &$block['items'];
        $stack = [&$items];

        foreach ($entries as $entry) {
            $depth = min($entry['ilvl'], count($stack)); // clamp level jumps
            while (count($stack) - 1 > $depth) {
                array_pop($stack);
            }
            $parent = &$stack[count($stack) - 1];
            if ($depth > count($stack) - 1) {
                // Deeper than current: attach to the last item's children.
                if ($parent === []) {
                    $depth = count($stack) - 1; // no parent item yet — clamp
                } else {
                    $lastIndex = count($parent) - 1;
                    if (!isset($parent[$lastIndex]['children'])) {
                        $parent[$lastIndex]['children'] = [];
                    }
                    $stack[] = &$parent[$lastIndex]['children'];
                    $parent = &$stack[count($stack) - 1];
                }
            }
            $parent[] = ['runs' => $entry['runs']] + (isset($entry['revision']) && $entry['revision'] !== [] ? ['revision' => $entry['revision']] : []);
            unset($parent);
        }

        return $block;
    }

    // ─── DOM helpers ─────────────────────────────────────────────────────

    private function firstChildByName(?DOMElement $parent, string $localName): ?DOMElement
    {
        if ($parent === null) {
            return null;
        }
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $localName) {
                return $node;
            }
        }

        return null;
    }

    private function firstDescendantByName(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if (!($node instanceof DOMNode) || !($node instanceof DOMElement)) {
                continue;
            }
            if ($node->localName === $localName) {
                return $node;
            }
            $found = $this->firstDescendantByName($node, $localName);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /** Read a w:-namespaced (or namespace-less) attribute by local name. */
    private function wAttr(DOMElement $el, string $name): ?string
    {
        foreach ($el->attributes as $attr) {
            if ($attr->localName === $name) {
                return $attr->value;
            }
        }

        return null;
    }

    /** Read an r:-namespaced attribute (id/embed/link) by local name. */
    private function rAttr(DOMElement $el, string $name): ?string
    {
        return $this->wAttr($el, $name);
    }

    /** True unless the toggle attribute explicitly disables the property. */
    private function toggleOn(?string $val): bool
    {
        return $val === null || !in_array(strtolower($val), ['0', 'false', 'none', 'off'], true);
    }

    /**
     * @param  list<array<string, mixed>>  $runs
     */
    private function plainText(array $runs): string
    {
        return implode('', array_map(static fn (array $r): string => (string) ($r['text'] ?? ''), $runs));
    }
}
