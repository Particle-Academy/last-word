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
                'block' => ['oneOf' => array_values($blocks)],
            ],
        ];
    }
}
