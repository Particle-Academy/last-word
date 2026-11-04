<?php

declare(strict_types=1);

namespace LastWord\Schema;

/**
 * The LastWord document model — shared constants + the JSON Schema export.
 *
 * The model is deliberately JSON-first (associative arrays, camelCase keys)
 * so agents can emit documents as plain tool-call arguments and the same
 * shape round-trips through the Node mirror (@particle-academy/last-word).
 */
final class Schema
{
    /** Package version reported by Agent::version(). */
    public const VERSION = '0.2.0';

    /** @var list<string> */
    public const BLOCK_TYPES = [
        'heading',
        'paragraph',
        'list',
        'table',
        'code',
        'quote',
        'image',
        'chart',
        'pageBreak',
        'hr',
    ];

    /**
     * Block types a `{blocks}` header or footer refuses (last-word#6), at any
     * depth: a page break means nothing in a running header, and a chart's parts
     * are wired to the document body. `portable` is refused too -- it is an
     * input the body expands, never part of a band.
     *
     * @var list<string>
     */
    public const BAND_REFUSED_TYPES = ['chart', 'pageBreak', 'portable'];

    /**
     * Block types a footnote's `blocks` refuse, at any depth: a page break means
     * nothing at the foot of a page, and a chart's parts are wired to the body.
     * A footnote inside a footnote is refused too (Word has no such thing), and
     * so is a footnote in a header or footer, which Word cannot hold.
     *
     * @var list<string>
     */
    public const FOOTNOTE_REFUSED_TYPES = ['chart', 'pageBreak', 'portable'];

    /**
     * What a page margin is when the document does not say, in points: one inch
     * each side, and half an inch from the page edge to the header and to the
     * footer. The reader OMITS a value equal to these (a page read back carries
     * only what differs), so a viewer resolves the rest with
     * {@see pageMargins()}.
     */
    public const PAGE_MARGIN_DEFAULT = 72.0;

    public const PAGE_BAND_DISTANCE_DEFAULT = 36.0;

    /**
     * The page's running bands, in the order the schema lists them and read()
     * returns them (0.16.0). `header` / `footer` are every page; with a
     * `firstHeader` they are every page but the first, and with an `evenHeader`
     * the odd pages. Each variant is the same shape as `header`. Word's own
     * reference types: `default`, `first`, `even`.
     *
     * @var list<string>
     */
    public const PAGE_BANDS = ['header', 'footer', 'firstHeader', 'firstFooter', 'evenHeader', 'evenFooter'];

    /**
     * The page object's own keys, which an agent writes straight on a
     * `pageBreak` when it means `pageBreak.page`. Each is refused by name rather
     * than ignored as a decoration: ignored, the document comes out all portrait
     * and looks fine. In validation order, shared with the Node and Python ports.
     *
     * @var list<string>
     */
    public const PAGE_BREAK_NEAR_MISS_KEYS = ['size', 'orientation', 'margins', 'header', 'footer', 'firstHeader', 'firstFooter', 'evenHeader', 'evenFooter', 'background', 'border'];

    /**
     * A band key => [kind, Word reference type].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PAGE_BAND_SLOTS = [
        'header' => ['header', 'default'],
        'footer' => ['footer', 'default'],
        'firstHeader' => ['header', 'first'],
        'firstFooter' => ['footer', 'first'],
        'evenHeader' => ['header', 'even'],
        'evenFooter' => ['footer', 'even'],
    ];

    /**
     * The field tokens live in header and footer text (0.16.0), as one regex:
     * `{page}` `{pages}` `{section}` `{sectionpages}` `{author}` `{title}`
     * `{filename}`, and `{date}` / `{time}` with an optional `:FORMAT` -- a Word
     * date-time picture, one or more characters, none of `{`, `}`, `"` or a
     * backslash. Names are lower-case and exact. Anything else in braces is
     * literal text, and so is `{page:x}`: only date and time take a format.
     * Group 1 is a plain name; group 2 a dated name and group 3 its format.
     */
    public const FIELD_TOKEN = '/\{(?:(page|pages|section|sectionpages|author|title|filename)|(date|time)(?::([^{}"\\\\]+))?)\}/';

    /** Field token name => Word field instruction. */
    public const FIELD_INSTRUCTIONS = [
        'page' => 'PAGE',
        'pages' => 'NUMPAGES',
        'section' => 'SECTION',
        'sectionpages' => 'SECTIONPAGES',
        'author' => 'AUTHOR',
        'title' => 'TITLE',
        'filename' => 'FILENAME',
        'date' => 'DATE',
        'time' => 'TIME',
    ];

    /**
     * Header/footer text split into literal text and field tokens, in order:
     * a string is literal, an array is a field --
     * `{token, name, instruction}`, where `instruction` is the Word field
     * instruction without its surrounding spaces (`PAGE`,
     * `DATE \@ "yyyy-MM-dd"`). Empty pieces are dropped.
     *
     * @return list<string|array{token: string, name: string, instruction: string}>
     */
    public static function splitFieldTokens(string $text): array
    {
        $out = [];
        $at = 0;
        if (preg_match_all(self::FIELD_TOKEN, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches as $m) {
                [$token, $offset] = $m[0];
                if ($offset > $at) {
                    $out[] = substr($text, $at, $offset - $at);
                }
                $plain = $m[1][0] ?? '';
                $name = $plain !== '' ? $plain : $m[2][0];
                $format = $plain === '' ? ($m[3][0] ?? '') : '';
                $out[] = [
                    'token' => $token,
                    'name' => $name,
                    'instruction' => self::FIELD_INSTRUCTIONS[$name] . ($format !== '' ? ' \@ "' . $format . '"' : ''),
                ];
                $at = $offset + strlen($token);
            }
        }
        if ($at < strlen($text)) {
            $out[] = substr($text, $at);
        }

        return $out;
    }

    /**
     * A Word field instruction back as its token, or null when it is not one:
     * the first word names the field (any case), `\@ "FORMAT"` (or an unquoted
     * `\@ FORMAT`) is DATE's and TIME's picture, and every other switch or
     * argument is dropped (`\* MERGEFORMAT`, FILENAME's `\p`, AUTHOR's new name).
     * A picture the grammar cannot hold reads as the bare `{date}` / `{time}`.
     */
    public static function fieldTokenOf(string $instruction): ?string
    {
        if (preg_match('/^\s*([A-Za-z]+)(.*)$/s', $instruction, $m) !== 1) {
            return null;
        }
        $name = array_search(strtoupper($m[1]), self::FIELD_INSTRUCTIONS, true);
        if (!is_string($name)) {
            return null;
        }
        if (in_array($name, ['date', 'time'], true)
            && preg_match('/\\\\@\s*(?:"([^"]*)"|(\S+))/', $m[2], $p) === 1) {
            $format = ($p[1] ?? '') !== '' ? $p[1] : ($p[2] ?? '');
            $token = '{' . $name . ':' . $format . '}';
            if ($format !== '' && preg_match(self::FIELD_TOKEN, $token, $check) === 1 && $check[0] === $token) {
                return $token;
            }
        }

        return '{' . $name . '}';
    }

    /** @var list<string> */
    public const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    /** @var list<string> */
    public const BORDER_STYLES = ['single', 'double', 'dashed', 'dotted', 'none'];

    /** @var list<string> */
    public const PAGE_SIZES = ['letter', 'legal', 'a4'];

    /** Page sizes in points, portrait (fine-print's numbers; the writer uses the twip equivalents). */
    public const PAGE_POINTS = [
        'letter' => [612.0, 792.0],
        'legal' => [612.0, 1008.0],
        'a4' => [595.28, 841.89],
    ];

    /** Points from the page edge to the outside of a page frame, by default (fine-print's). */
    public const PAGE_FRAME_INSET = 24.0;

    /** Boolean run flags (all optional). */
    public const RUN_FLAGS = ['bold', 'italic', 'underline', 'strike', 'code', 'smallCaps'];

    /** Max heading level in the model (Word tolerates 9; we clamp on read/repair). */
    public const MAX_HEADING_LEVEL = 6;

    /**
     * JSON Schema for the Doc model — pass to LLM tool registration so the
     * model gets typed field hints up front.
     *
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        $hex = ['type' => 'string', 'pattern' => '^#[0-9A-Fa-f]{6}$'];
        $points = ['type' => 'number', 'description' => 'Points.'];

        $border = [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'One border edge. {"style":"none"} REMOVES a border — a zero width is not it.',
            'properties' => [
                'style' => ['enum' => self::BORDER_STYLES],
                'width' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Points. Defaults to 0.5 (a hairline).'],
                'color' => $hex,
            ],
        ];
        $borderRef = ['$ref' => '#/$defs/border'];

        $boxBorders = [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'Box edges. Anything omitted is left alone rather than reset.',
            'properties' => array_fill_keys(['top', 'right', 'bottom', 'left'], $borderRef),
        ];
        $tableBorders = [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'Table edges — the box, plus the two inside directions.',
            'properties' => array_fill_keys(['top', 'right', 'bottom', 'left', 'insideH', 'insideV'], $borderRef),
        ];
        $boxSides = [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'Box spacing in points. Anything omitted is left alone.',
            'properties' => array_fill_keys(['top', 'right', 'bottom', 'left'], $points),
        ];
        $bandSlot = ['type' => 'string', 'description' => 'Text with live Word fields: {page} and {pages} ("Page {page} of {pages}"), {section}, {sectionpages}, {author} (meta.author), {title} (the document title), {filename}, {date} and {time} in the reader\'s locale, or {date:FORMAT} / {time:FORMAT} with a Word picture such as {date:d MMMM yyyy} or {time:HH:mm}. Anything else in braces is literal text.'];
        $band = [
            'type' => 'object',
            'additionalProperties' => false,
            'description' => 'A running header or footer: one line with a left, a centre and a right slot, e.g. {"left": "Q3 Board Memo | Confidential", "right": "Page {page} of {pages}"}. 9pt grey unless size/color say otherwise.',
            'properties' => [
                'left' => $bandSlot,
                'center' => $bandSlot,
                'right' => $bandSlot,
                'size' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Points; 9 by default.'],
                'color' => ['type' => 'string', 'pattern' => '^#[0-9A-Fa-f]{6}$', 'description' => 'Text colour; #6B7280 by default.'],
            ],
        ];

        /** Properties every paragraph-shaped block accepts — paragraph, heading, list item. */
        $paragraphProps = [
            'align' => ['enum' => self::ALIGNMENTS],
            'spaceBefore' => $points,
            'spaceAfter' => ['type' => 'number', 'description' => 'Points. Zero is meaningful — the document default puts 8pt under every paragraph.'],
            'lineHeight' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'A multiple of single spacing.'],
            'indentLeft' => $points,
            'indentRight' => $points,
            'keepNext' => ['type' => 'boolean', 'description' => 'Keep on the same page as the block after it.'],
            'shading' => $hex,
            'borders' => ['$ref' => '#/$defs/boxBorders'],
        ];

        $run = [
            'type' => 'object',
            'required' => ['text'],
            'additionalProperties' => false,
            'properties' => [
                'text' => ['type' => 'string'],
                'bold' => ['type' => 'boolean'],
                'italic' => ['type' => 'boolean'],
                'underline' => ['type' => 'boolean'],
                'strike' => ['type' => 'boolean'],
                'code' => ['type' => 'boolean'],
                'smallCaps' => ['type' => 'boolean'],
                'link' => ['type' => 'string', 'description' => 'Hyperlink target URL.'],
                'color' => $hex,
                'highlight' => $hex,
                'size' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Font size in points. Half-points are exactly representable.'],
                'font' => ['type' => 'string', 'description' => 'Font family name.'],
                'letterSpacing' => ['type' => 'number', 'description' => 'Tracking in points; may be negative.'],
                'footnote' => ['$ref' => '#/$defs/footnote'],
            ],
        ];

        $runs =['type' => 'array', 'items' => ['$ref' => '#/$defs/run']];

        $listItem = [
            'type' => 'object',
            'required' => ['runs'],
            'additionalProperties' => false,
            'properties' => [
                'runs' => $runs,
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/listItem']],
            ] + $paragraphProps,
        ];

        // Wherever a block may go, a portable node may go too. `$defs.block` is
        // the document model; `portable` is an input that validateAndRepair and
        // the writer expand into model blocks, so it is kept beside the model
        // (doc-model/shared reads `$defs.block`, which stays model-only).
        $blockRef = ['$ref' => '#/$defs/blockOrPortable'];

        $blocks = [
            'heading' => [
                'type' => 'object',
                'required' => ['type', 'level', 'runs'],
                'description' => 'A heading is a paragraph and takes the same properties, so a section label can be spaced and aligned without being demoted to a bold paragraph.',
                'properties' => [
                    'type' => ['const' => 'heading'],
                    'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_HEADING_LEVEL],
                    'runs' => $runs,
                ] + $paragraphProps,
            ],
            'paragraph' => [
                'type' => 'object',
                'required' => ['type', 'runs'],
                'properties' => [
                    'type' => ['const' => 'paragraph'],
                    'runs' => $runs,
                ] + $paragraphProps,
            ],
            'list' => [
                'type' => 'object',
                'required' => ['type', 'items'],
                'properties' => [
                    'type' => ['const' => 'list'],
                    'ordered' => ['type' => 'boolean'],
                    'items' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/listItem']],
                ],
            ],
            'table' => [
                'type' => 'object',
                'required' => ['type', 'rows'],
                'properties' => [
                    'type' => ['const' => 'table'],
                    'widths' => [
                        'type' => 'array',
                        'items' => ['type' => 'number', 'minimum' => 0],
                        'description' => 'Relative column weights — [30,40,30] and [3,4,3] are the same table. Also fixes the layout so Word honours them.',
                    ],
                    'width' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 100, 'description' => 'Table width as a percentage of the text column.'],
                    'align' => ['enum' => ['left', 'center', 'right']],
                    'borders' => ['$ref' => '#/$defs/tableBorders'],
                    'cellPadding' => ['$ref' => '#/$defs/boxSides'],
                    'rows' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['cells'],
                            'properties' => [
                                'header' => ['type' => 'boolean'],
                                'cells' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['blocks'],
                                        'properties' => [
                                            'blocks' => ['type' => 'array', 'items' => $blockRef],
                                            'shading' => $hex,
                                            'borders' => ['$ref' => '#/$defs/boxBorders'],
                                            'padding' => ['$ref' => '#/$defs/boxSides'],
                                            'valign' => ['enum' => ['top', 'center', 'bottom']],
                                            'colSpan' => ['type' => 'integer', 'minimum' => 1],
                                            'rowSpan' => [
                                                'type' => 'integer',
                                                'minimum' => 1,
                                                'description' => 'Written HTML-style: the cell appears ONCE, and the rows it covers list only their own remaining cells.',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'code' => [
                'type' => 'object',
                'required' => ['type', 'text'],
                'properties' => [
                    'type' => ['const' => 'code'],
                    'language' => ['type' => 'string'],
                    'text' => ['type' => 'string'],
                ],
            ],
            'quote' => [
                'type' => 'object',
                'required' => ['type', 'blocks'],
                'properties' => [
                    'type' => ['const' => 'quote'],
                    'blocks' => ['type' => 'array', 'items' => $blockRef],
                ],
            ],
            'image' => [
                'type' => 'object',
                'required' => ['type', 'src'],
                'properties' => [
                    'type' => ['const' => 'image'],
                    'src' => [
                        'type' => 'string',
                        'pattern' => '^data:image/(png|jpe?g);base64,',
                        'description' => 'PNG or JPEG data URL.',
                    ],
                    'widthPx' => ['type' => 'number', 'exclusiveMinimum' => 0],
                    'heightPx' => ['type' => 'number', 'exclusiveMinimum' => 0],
                    'alt' => ['type' => 'string'],
                ],
            ],
            'chart' => [
                'type' => 'object',
                'required' => ['type', 'option'],
                'description' => 'A native Word chart -- editable in Word, with its numbers in an embedded workbook ("Edit Data") -- drawn from an Apache ECharts option: the same `option` a dark-slide chart element takes, so a chart moves between a deck and a report unchanged. Kinds: column (bar series over a category xAxis), horizontal bar (bar series over a category yAxis), line, pie. Put a caption paragraph above it, as for a table.',
                'properties' => [
                    'type' => ['const' => 'chart'],
                    'option' => self::chartOptionJsonSchema(),
                    'widthPx' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Width in px at 96 dpi; capped at the text column.'],
                    'heightPx' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Height in px at 96 dpi. Absent, 0.6 of the width.'],
                    'width' => ['type' => 'number', 'exclusiveMinimum' => 0, 'maximum' => 100, 'description' => 'Width as a percentage of the text column, used when widthPx is absent. Absent both, the chart spans the column.'],
                    'numberFormat' => ['type' => 'string', 'description' => 'An Excel number format for the values, on the value axis, the data labels and the workbook: "#,##0", "0.0%", "$#,##0.00". Absent, General.'],
                    'alt' => ['type' => 'string', 'description' => 'Alternative text: what the chart shows, for a screen reader.'],
                ],
            ],
            'pageBreak' => [
                'type' => 'object',
                'required' => ['type'],
                'properties' => ['type' => ['const' => 'pageBreak']],
            ],
            'hr' => [
                'type' => 'object',
                'required' => ['type'],
                'properties' => ['type' => ['const' => 'hr']],
            ],
        ];

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => 'LastWord Document',
            'description' => 'A word-processing document: an optional title plus a flat list of blocks. Written to .docx by particle-academy/last-word.',
            'type' => 'object',
            'required' => ['blocks'],
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'The document title: docProps/core.xml dc:title, what Word shows in File > Info and what a {title} field in a header or footer shows. Not rendered in the body; write a heading for that.'],
                'meta' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'description' => 'Document properties beyond the title (which is the top-level `title`).',
                    'properties' => [
                        'author' => ['type' => 'string', 'description' => 'docProps/core.xml dc:creator: Word\'s Author property, and what an {author} field in a header or footer shows.'],
                    ],
                ],
                'blocks' => ['type' => 'array', 'items' => $blockRef],
                'page' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'description' => 'Section geometry. A one-page business document does not fit inside the default one-inch margins.',
                    'properties' => [
                        'size' => ['enum' => self::PAGE_SIZES],
                        'orientation' => ['enum' => ['portrait', 'landscape']],
                        'margins' => ['$ref' => '#/$defs/pageMargins'],
                        'header' => ['oneOf' => [['$ref' => '#/$defs/band'], ['$ref' => '#/$defs/blocksBand']], 'description' => 'A running header on every page: a one-line band, or blocks (a letterhead with a logo). With a firstHeader, every page but the first; with an evenHeader, the odd pages.'],
                        'footer' => ['oneOf' => [['$ref' => '#/$defs/band'], ['$ref' => '#/$defs/blocksBand']], 'description' => 'A running footer on every page -- where a multi-page document carries its page numbers. With a firstFooter, every page but the first; with an evenFooter, the odd pages.'],
                        'firstHeader' => ['oneOf' => [['$ref' => '#/$defs/band'], ['$ref' => '#/$defs/blocksBand']], 'description' => 'The header of the FIRST page only, in place of header there (Word\'s "Different first page"): a letterhead on page one, a plainer header after. {"blocks": []} leaves page one with no header when header is set.'],
                        'firstFooter' => ['oneOf' => [['$ref' => '#/$defs/band'], ['$ref' => '#/$defs/blocksBand']], 'description' => 'The footer of the first page only, in place of footer there. Without it the footer is on the first page too. {"blocks": []} leaves page one with no footer when footer is set.'],
                        'evenHeader' => ['oneOf' => [['$ref' => '#/$defs/band'], ['$ref' => '#/$defs/blocksBand']], 'description' => 'The header of even pages (Word\'s "Different odd and even pages"); header is then the odd pages\'. For a bound, two-sided document.'],
                        'evenFooter' => ['oneOf' => [['$ref' => '#/$defs/band'], ['$ref' => '#/$defs/blocksBand']], 'description' => 'The footer of even pages; footer is then the odd pages\'. Without it the footer is on even pages too.'],
                        'background' => $hex + ['description' => 'Page colour on every page, under everything else, e.g. "#FDFAF3". White (none) by default. Word shows it on screen and prints it only when "Print background colors" is on.'],
                        'border' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'description' => 'A frame around every page, measured from the page edge, so it is the same whatever the page holds (a certificate border). e.g. {"style": "double", "width": 6, "color": "#B08D57", "inset": 18}. Margins are separate: keep them wider than inset + width so text clears the frame.',
                            'properties' => [
                                'style' => ['enum' => self::BORDER_STYLES, 'description' => 'single by default; double draws two rules with a gap, each a third of the width; none draws nothing.'],
                                'width' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Points, the whole frame (both rules and the gap, for double). 0.5 by default. Word draws at most 12pt per rule.'],
                                'color' => $hex + ['description' => 'Black by default.'],
                                'inset' => ['type' => 'number', 'minimum' => 0, 'description' => 'Points from the page edge to the outside of the frame. '.self::PAGE_FRAME_INSET.' by default.'],
                            ],
                        ],
                    ],
                ],
                'defaultFont' => ['type' => 'string', 'description' => 'Font every run inherits unless it names its own.'],
                'defaultSize' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Size in points every run inherits unless it names its own.'],
            ],
            '$defs' => [
                'run' => $run,
                'listItem' => $listItem,
                'border' => $border,
                'boxBorders' => $boxBorders,
                'tableBorders' => $tableBorders,
                'boxSides' => $boxSides,
                'pageMargins' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'description' => 'Page margins in points, 72 (one inch) each side by default, and the header and footer DISTANCES: how far the header sits from the top edge of the page and the footer from the bottom edge, 36 by default. Word moves the body down (or up) when a header (or footer) is taller than the margin leaves room for. Anything omitted is the default.',
                    'properties' => [
                        'top' => $points,
                        'right' => $points,
                        'bottom' => $points,
                        'left' => $points,
                        'header' => ['type' => 'number', 'minimum' => 0, 'description' => 'Points from the top edge of the page to the top of the header. 36 by default.'],
                        'footer' => ['type' => 'number', 'minimum' => 0, 'description' => 'Points from the bottom edge of the page to the bottom of the footer. 36 by default.'],
                    ],
                ],
                'footnote' => [
                    'type' => 'object',
                    'required' => ['blocks'],
                    'additionalProperties' => false,
                    'description' => 'A footnote, carried by a run whose text is "" and which sits where the reference mark goes: {"text": "", "footnote": {"blocks": [{"type": "paragraph", "runs": [{"text": "Audited figures, FY2026."}]}]}}. Word numbers the marks 1, 2, 3 in document order and sets each footnote at the foot of the page that holds its mark. The blocks are the body vocabulary less chart and pageBreak, with no footnote inside a footnote. Not in a header or footer.',
                    'properties' => [
                        'blocks' => [
                            'type' => 'array',
                            'items' => [
                                'allOf' => [
                                    ['$ref' => '#/$defs/block'],
                                    ['not' => ['properties' => ['type' => ['enum' => ['chart', 'pageBreak']]], 'required' => ['type']]],
                                ],
                            ],
                        ],
                    ],
                ],
                'band' => $band,
                'blocksBand' => [
                    'type' => 'object',
                    'required' => ['blocks'],
                    'additionalProperties' => false,
                    'description' => 'A running header or footer that holds blocks -- the body\'s own vocabulary less chart and pageBreak (at any depth) -- for what one line cannot: a logo, an address over several lines, a table. e.g. {"blocks": [{"type": "image", "src": "data:image/png;base64,...", "widthPx": 140}, {"type": "paragraph", "align": "right", "runs": [{"text": "Acme Ltd, 1 Example Street", "size": 9}]}]}. In run text the field tokens are live fields, as in a band ({page}, {pages}, {date:yyyy-MM-dd} ...). Never mixed with left/center/right/size/color. Images: PNG or JPEG data URLs whose bytes match, each at most 16 MiB, at most 32 MiB across the header and footer.',
                    'properties' => [
                        'blocks' => [
                            'type' => 'array',
                            'items' => [
                                'allOf' => [
                                    ['$ref' => '#/$defs/block'],
                                    ['not' => ['properties' => ['type' => ['enum' => ['chart', 'pageBreak']]], 'required' => ['type']]],
                                ],
                            ],
                        ],
                    ],
                ],
                'block' =>['oneOf' => array_values($blocks)],
                'portable' => self::portableSchema(),
                'portableRun' => [
                    'type' => 'object',
                    'required' => ['text'],
                    'additionalProperties' => false,
                    'properties' => [
                        'text' => ['type' => 'string'],
                        'bold' => ['type' => 'boolean'],
                        'italic' => ['type' => 'boolean'],
                        'underline' => ['type' => 'boolean'],
                        'strike' => ['type' => 'boolean'],
                        'code' => ['type' => 'boolean'],
                        'link' => ['type' => 'string', 'pattern' => '^(https?://|mailto:)[!-~]+$', 'maxLength' => 2048],
                    ],
                ],
                'portableListItem' => [
                    'type' => 'object',
                    'required' => ['runs'],
                    'additionalProperties' => false,
                    'properties' => [
                        'runs' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/portableRun']],
                        'children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/portableListItem']],
                    ],
                ],
                'blockOrPortable' => ['oneOf' => [['$ref' => '#/$defs/block'], ['$ref' => '#/$defs/portable']]],
            ],
        ];
    }

    /**
     * The inline portable node and the `fancyContent: 1` format it carries
     * (fancy-conformance `suites/exchange/SPEC.md`), as fine-print 0.5.0
     * declares it. Every object is closed. The validator enforces what JSON
     * Schema cannot say: the grid is a rectangle, runs spell the cell's
     * display text, image bytes match their mime, and the exchange limits.
     *
     * @return array<string, mixed>
     */
    private static function portableSchema(): array
    {
        $runs = ['type' => 'array', 'items' => ['$ref' => '#/$defs/portableRun']];
        $closed = fn (array $props, array $required = []) => ['type' => 'object', 'additionalProperties' => false, 'properties' => $props] + ($required === [] ? [] : ['required' => $required]);
        $cell = $closed([
            't' => ['enum' => ['string', 'number', 'bool', 'date', 'datetime', 'empty', 'error']],
            'v' => ['description' => 'The typed value: a string, number or boolean; date YYYY-MM-DD; datetime YYYY-MM-DDTHH:MM:SSZ; error a string starting with #. Absent for empty.'],
            'text' => ['type' => 'string', 'description' => 'The display string the source showed, when it differs from the value.'],
            'format' => $closed([
                'kind' => ['enum' => ['number', 'currency', 'percent', 'date', 'datetime', 'text']],
                'decimals' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 10],
                'currency' => ['type' => 'string', 'pattern' => '^[A-Z]{3}$'],
            ], ['kind']),
            'formula' => ['type' => 'string', 'minLength' => 1, 'description' => 'Metadata only: never evaluated, never written. The value is written.'],
            'colSpan' => ['type' => 'integer', 'minimum' => 1],
            'rowSpan' => ['type' => 'integer', 'minimum' => 1],
            'align' => ['enum' => ['left', 'center', 'right']],
            'runs' => $runs,
        ], ['t']);

        $content = $closed([
            'fancyContent' => ['const' => 1],
            'source' => ['type' => 'object', 'description' => 'Where the content came from: {package, kind, version, sourceKind, selector}.'],
            'items' => ['type' => 'array', 'items' => ['oneOf' => [
                $closed([
                    'type' => ['const' => 'table'],
                    'caption' => ['type' => 'string'],
                    'headerRows' => ['type' => 'integer', 'minimum' => 0],
                    'columns' => ['type' => 'array', 'items' => $closed(['width' => ['type' => 'number', 'exclusiveMinimum' => 0], 'align' => ['enum' => ['left', 'center', 'right']]])],
                    'rows' => ['type' => 'array', 'minItems' => 1, 'items' => $closed(['cells' => ['type' => 'array', 'minItems' => 1, 'items' => $cell]], ['cells'])],
                ], ['type', 'rows']),
                $closed([
                    'type' => ['const' => 'text'],
                    'role' => ['enum' => ['heading', 'paragraph']],
                    'level' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 6, 'description' => 'Required for a heading, forbidden otherwise.'],
                    'runs' => $runs,
                ], ['type', 'role', 'runs']),
                $closed([
                    'type' => ['const' => 'list'],
                    'ordered' => ['type' => 'boolean'],
                    'items' => ['type' => 'array', 'minItems' => 1, 'items' => ['$ref' => '#/$defs/portableListItem']],
                ], ['type', 'items']),
                $closed([
                    'type' => ['const' => 'image'],
                    'ref' => ['type' => 'string', 'description' => 'A key of `images`. Never a URL or a path.'],
                    'alt' => ['type' => 'string'],
                    'widthPx' => ['type' => 'number', 'exclusiveMinimum' => 0],
                    'heightPx' => ['type' => 'number', 'exclusiveMinimum' => 0],
                ], ['type', 'ref']),
            ]]],
            'images' => [
                'type' => 'object',
                'propertyNames' => ['pattern' => '^[A-Za-z0-9_-]{1,64}$'],
                'additionalProperties' => $closed(['mime' => ['enum' => ['image/png', 'image/jpeg']], 'data' => ['type' => 'string', 'description' => 'Standard base64 of the PNG or JPEG bytes.']], ['mime', 'data']),
            ],
            'report' => ['type' => 'object', 'description' => 'What the reader lost or approximated: {dropped, approximated, guard}.'],
        ], ['fancyContent', 'items']);

        return [
            'type' => 'object',
            'required' => ['type', 'content'],
            'additionalProperties' => false,
            'description' => 'Portable content (fancyContent 1) from another document package, e.g. a spreadsheet range from holy-sheet extract(). Placed as is: validateAndRepair and the writer expand it into headings, paragraphs, lists, images and tables (numbers right-aligned, header rows marked). Pass it through unchanged rather than retyping it.',
            'properties' => [
                'type' => ['const' => 'portable'],
                'content' => $content,
            ],
        ];
    }

    /**
     * Page width and height in points, after orientation.
     *
     * @param  array<string, mixed>  $doc
     * @return array{0: float, 1: float}
     */
    public static function pageSize(array $doc): array
    {
        $page = is_array($doc['page'] ?? null) ? $doc['page'] : [];
        $size = is_string($page['size'] ?? null) ? strtolower($page['size']) : 'letter';
        [$w, $h] = self::PAGE_POINTS[$size] ?? self::PAGE_POINTS['letter'];
        if (($page['orientation'] ?? 'portrait') === 'landscape') {
            [$w, $h] = [$h, $w];
        }

        return [$w, $h];
    }

    /**
     * Every page margin and both header/footer distances, in points, defaults
     * filled in -- what a paged viewer lays a page out from. `read()` omits a
     * value equal to its default ({@see PAGE_MARGIN_DEFAULT},
     * {@see PAGE_BAND_DISTANCE_DEFAULT}), and omits `page` altogether when
     * nothing differs, so this is the one place the defaults are applied.
     *
     * @param  array<string, mixed>  $doc
     * @return array{top: float, right: float, bottom: float, left: float, header: float, footer: float}
     */
    public static function pageMargins(array $doc): array
    {
        $page = is_array($doc['page'] ?? null) ? $doc['page'] : [];
        $m = is_array($page['margins'] ?? null) ? $page['margins'] : [];
        $value = static fn (string $k, float $default): float => (is_int($m[$k] ?? null) || is_float($m[$k] ?? null)) && is_finite((float) $m[$k])
            ? (float) $m[$k]
            : $default;

        return [
            'top' => $value('top', self::PAGE_MARGIN_DEFAULT),
            'right' => $value('right', self::PAGE_MARGIN_DEFAULT),
            'bottom' => $value('bottom', self::PAGE_MARGIN_DEFAULT),
            'left' => $value('left', self::PAGE_MARGIN_DEFAULT),
            'header' => $value('header', self::PAGE_BAND_DISTANCE_DEFAULT),
            'footer' => $value('footer', self::PAGE_BAND_DISTANCE_DEFAULT),
        ];
    }

    /**
     * The page frame with fine-print's defaults filled in, or null when there
     * is none (absent, or style "none"). Values that would not validate fall
     * back to their defaults; the validator is what refuses them.
     *
     * @param  array<string, mixed>  $doc
     * @return array{style: string, width: float, color: string, inset: float}|null
     */
    public static function pageFrame(array $doc): ?array
    {
        $b = is_array($doc['page'] ?? null) ? ($doc['page']['border'] ?? null) : null;
        if (! is_array($b)) {
            return null;
        }
        $style = in_array($b['style'] ?? null, self::BORDER_STYLES, true) ? $b['style'] : 'single';
        if ($style === 'none') {
            return null;
        }
        $width = $b['width'] ?? null;
        $inset = $b['inset'] ?? null;
        $color = $b['color'] ?? null;

        return [
            'style' => $style,
            'width' => (is_int($width) || is_float($width)) && $width > 0 && is_finite((float) $width) ? (float) $width : 0.5,
            'color' => is_string($color) && preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : '#000000',
            'inset' => (is_int($inset) || is_float($inset)) && $inset >= 0 && is_finite((float) $inset) ? (float) $inset : self::PAGE_FRAME_INSET,
        ];
    }

    /**
     * A chart block's `option`: the subset of an ECharts option this writer
     * reads, described in dark-slide's words wherever the two agree.
     * `additionalProperties` stays open -- it IS an ECharts option, and a viewer
     * handing it to ECharts may use keys a Word chart has no slot for.
     *
     * @return array<string, mixed>
     */
    private static function chartOptionJsonSchema(): array
    {
        $hex = ['type' => 'string', 'pattern' => '^#[0-9A-Fa-f]{6}$'];
        $axis = [
            'type' => 'object',
            'properties' => [
                'type' => ['enum' => ['category', 'value']],
                'data' => ['type' => 'array', 'items' => ['type' => ['string', 'number']], 'description' => 'The category labels, on the category axis.'],
                'name' => ['type' => 'string', 'description' => 'The axis title.'],
            ],
        ];

        return [
            'type' => 'object',
            'required' => ['series'],
            'description' => 'An Apache ECharts option. Column chart: {"xAxis": {"type": "category", "data": ["Q1", "Q2"]}, "yAxis": {"type": "value"}, "series": [{"type": "bar", "name": "Revenue", "data": [120, 135]}]}. Swap the two axes for horizontal bars. Pie: {"series": [{"type": "pie", "data": [{"name": "Payroll", "value": 61}, {"name": "Cloud", "value": 22}]}]}.',
            'properties' => [
                'series' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'The data to plot, every series of one type; a pie takes exactly one.',
                    'items' => [
                        'type' => 'object',
                        'required' => ['data'],
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => \LastWord\Helpers\Chart::SUPPORTED_TYPES, 'description' => 'The chart kind, bar when absent. A bar series draws columns over a category xAxis and horizontal bars over a category yAxis. scatter, radar, gauge and other ECharts types are not supported in a document.'],
                            'name' => ['type' => 'string', 'description' => 'The series name, shown in the legend and the workbook header.'],
                            'data' => ['type' => 'array', 'description' => 'The points, one per category: a number, or {"value": number}; for a pie, {"name": string, "value": number}, whose names become the labels. Colour ONE point to make the finding stand out: {"value": 90.7, "itemStyle": {"color": "#DC2626"}}.'],
                            'itemStyle' => ['type' => 'object', 'properties' => ['color' => $hex], 'description' => 'The series\' own colour: {"color": "#1F3A5F"}. A muted series with one highlighted point reads faster than a rainbow.'],
                            'label' => ['type' => 'object', 'properties' => ['show' => ['type' => 'boolean']], 'description' => 'Data labels: {"show": true} draws each value on the chart, in numberFormat.'],
                            'smooth' => ['type' => 'boolean', 'description' => 'A smoothed line (line series).'],
                            'stack' => ['type' => 'string', 'description' => 'Any name stacks the bar series that carry one.'],
                        ],
                    ],
                ],
                'xAxis' => $axis,
                'yAxis' => $axis,
                'title' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'description' => 'The chart title, {"text": "..."}. A report usually captions the chart in a paragraph instead.'],
                'legend' => ['type' => 'object', 'properties' => ['show' => ['type' => 'boolean']], 'description' => 'A legend below the chart, drawn when present and show is not false. A single series rarely needs one.'],
                'color' => ['type' => 'array', 'items' => $hex, 'description' => 'The palette series (or pie slices) take in turn. Absent, Office\'s default accents.'],
            ],
        ];
    }
}
