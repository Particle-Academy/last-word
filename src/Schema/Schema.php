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

    /** @var list<string> */
    public const ALIGNMENTS = ['left', 'center', 'right', 'justify'];

    /** @var list<string> */
    public const BORDER_STYLES = ['single', 'double', 'dashed', 'dotted', 'none'];

    /** @var list<string> */
    public const PAGE_SIZES = ['letter', 'legal', 'a4'];

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
        $bandSlot = ['type' => 'string', 'description' => 'Text. `{page}` is the current page number and `{pages}` the page count, both live fields: "Page {page} of {pages}".'];
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
            ],
        ];

        $runs = ['type' => 'array', 'items' => ['$ref' => '#/$defs/run']];

        $listItem = [
            'type' => 'object',
            'required' => ['runs'],
            'additionalProperties' => false,
            'properties' => [
                'runs' => $runs,
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/listItem']],
            ] + $paragraphProps,
        ];

        $blockRef = ['$ref' => '#/$defs/block'];

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
                'title' => ['type' => 'string'],
                'blocks' => ['type' => 'array', 'items' => $blockRef],
                'page' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'description' => 'Section geometry. A one-page business document does not fit inside the default one-inch margins.',
                    'properties' => [
                        'size' => ['enum' => self::PAGE_SIZES],
                        'orientation' => ['enum' => ['portrait', 'landscape']],
                        'margins' => ['$ref' => '#/$defs/boxSides'],
                        'header' => ['$ref' => '#/$defs/band', 'description' => 'A running header on every page.'],
                        'footer' => ['$ref' => '#/$defs/band', 'description' => 'A running footer on every page -- where a multi-page document carries its page numbers.'],
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
                'band' => $band,
                'block' => ['oneOf' => array_values($blocks)],
            ],
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
