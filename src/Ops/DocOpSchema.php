<?php

declare(strict_types=1);

namespace LastWord\Ops;

/**
 * JSON Schema for one document op — validate ops on the wire, or register the op
 * vocabulary as an LLM tool.
 *
 * Named like dark-slide's DeckOps (`op`, dotted `noun.verb`), with one
 * difference forced by the model: a Last Word document has no ids, so an op
 * addresses a list by JSON Pointer and an item by index in it. See
 * {@see DocReducer} for what each path may point at.
 */
final class DocOpSchema
{
    public const TYPES = [
        'doc.replace', 'doc.set',
        'blocks.insert', 'blocks.remove', 'blocks.move', 'blocks.replace',
        'items.insert', 'items.remove', 'items.move', 'items.replace',
        'rows.insert', 'rows.remove', 'rows.move', 'rows.replace',
        'cells.insert', 'cells.remove', 'cells.move', 'cells.replace',
    ];

    /** @return array<string,mixed> */
    public static function jsonSchema(): array
    {
        $index = ['type' => 'integer', 'minimum' => 0];
        $variants = [
            self::variant('doc.replace', ['doc' => ['type' => 'object']], ['doc'], 'Replace the whole document.'),
            self::variant('doc.set', ['key' => ['type' => 'string', 'not' => ['const' => 'blocks']], 'value' => ['description' => 'Any JSON value; null removes the key.']], ['key', 'value'], 'Set a top-level property (title, page, defaultFont, defaultSize); null removes it.'),
        ];

        foreach (DocReducer::KINDS as $kind => [$valueKey, $ends]) {
            $path = ['type' => 'string', 'pattern' => '^(/[^/]+)*/('.implode('|', $ends).')$'];
            $value = ['type' => 'object'];

            $variants[] = self::variant("{$kind}.insert", ['path' => $path, 'index' => $index, $valueKey => $value], ['path', 'index', $valueKey], "Insert a {$valueKey} at a 0-based index in the list at path.");
            $variants[] = self::variant("{$kind}.remove", ['path' => $path, 'index' => $index], ['path', 'index'], "Remove the {$valueKey} at an index in the list at path.");
            $variants[] = self::variant("{$kind}.move", ['path' => $path, 'from' => $index, 'to' => $index], ['path', 'from', 'to'], "Move a {$valueKey} within the list at path: removed at from, inserted at to.");
            $variants[] = self::variant("{$kind}.replace", ['path' => $path, 'index' => $index, $valueKey => $value], ['path', 'index', $valueKey], "Replace the {$valueKey} at an index in the list at path.");
        }

        return [
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'title' => 'Last Word op',
            'description' => 'One op from Agent::diff, applied by Agent::reduce.',
            'oneOf' => $variants,
        ];
    }

    /**
     * @param  array<string,mixed>  $properties
     * @param  list<string>  $required
     * @return array<string,mixed>
     */
    private static function variant(string $op, array $properties, array $required, string $description): array
    {
        return [
            'type' => 'object',
            'description' => $description,
            'required' => ['op', ...$required],
            'additionalProperties' => false,
            'properties' => ['op' => ['const' => $op], ...$properties],
        ];
    }
}
