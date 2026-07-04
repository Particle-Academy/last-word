# LastWord

[![Fancy UI suite](art/fancy-ui.svg)](https://particle.academy)

PHP package for reading and writing word-processing documents (`.docx`)
from a JSON-friendly document model, with markdown bridges. Framework-
agnostic, zero runtime dependencies (just `ext-zip` + `ext-dom`). Designed
so WYSIWYG editors — react-fancy's `Editor` in particular — round-trip
real Word files without a converter sandwich (mammoth → turndown → docx):
one model, one engine, both directions.

Mirror package: [`@particle-academy/last-word`](https://github.com/Particle-Academy/last-word-js)
(Node/TS) implements the exact same JSON model and Agent API, so a doc
emitted by an agent works verbatim on either backend.

## Why

Sister project to [`holy-sheet`](https://github.com/Particle-Academy/holy-sheet)
(XLSX) and [`dark-slide`](https://github.com/Particle-Academy/dark-slide)
(PPTX). The three share an "agent emits JSON, PHP writes a real Office
document" pattern:

| Document type | Node mirror | PHP package |
|---|---|---|
| Spreadsheets | holy-sheet-js | holy-sheet |
| Presentations | dark-slide-js | dark-slide |
| Documents | last-word-js | **last-word** |

## Quickstart

```php
use LastWord\Agent;

$doc = [
    'title' => 'Quarterly Notes',
    'blocks' => [
        ['type' => 'heading', 'level' => 1, 'runs' => [['text' => 'Summary']]],
        ['type' => 'paragraph', 'runs' => [
            ['text' => 'Revenue was '],
            ['text' => 'up 12%', 'bold' => true],
            ['text' => ' — details in the '],
            ['text' => 'appendix', 'link' => 'https://example.com/appendix'],
            ['text' => '.'],
        ]],
        ['type' => 'list', 'items' => [
            ['runs' => [['text' => 'Ship the docx engine']], 'children' => [
                ['runs' => [['text' => 'Reader + writer']]],
            ]],
            ['runs' => [['text' => 'Wire the Editor bridge']]],
        ]],
    ],
];

// Validate before writing — catches malformed agent output
$errors = Agent::validate($doc);   // [] when valid: [{path, message}, …] otherwise

// Write to disk (synchronous)
$result = Agent::write($doc, storage_path('app/notes.docx'));
// ['path' => …, 'bytes' => 4151, 'blocks' => 3]

// Or keep it in memory
$bytes = Agent::toBytes($doc);

// And read any .docx back into the same model — including Word-authored files
$model = Agent::read($bytes);      // or Agent::read('/path/to/file.docx')
```

## The Editor round-trip

The whole point: an agent (or a human in a WYSIWYG editor) works in
markdown or the JSON model, and `.docx` is just a serialization at the
edges.

```php
use LastWord\Agent;

// Inbound: a Word file arrives → markdown for the Editor
$doc = Agent::read($uploadedBytes);
$markdown = Agent::toMarkdown($doc);

// … the Editor (or an agent) edits the markdown …

// Outbound: markdown → model → a real .docx
$doc = Agent::fromMarkdown($markdown);
$bytes = Agent::toBytes($doc);
```

The bridge is hand-rolled GFM (no external markdown dependency): headings,
`**bold**` / `*italic*` / `~~strike~~` / `` `code` ``, links, ordered +
unordered nested lists, tables, fenced code blocks, blockquotes,
`![alt](src)` images, `---` rules — plus an `<!-- pagebreak -->` comment
convention so page breaks survive the trip. Underline / colors / alignment
have no markdown slot and drop on that path (they round-trip fine through
`.docx` itself).

## Document model

A `Doc` is a title plus a flat list of blocks; camelCase keys, plain
associative arrays, identical in the Node mirror:

```jsonc
{ "title": "Optional title", "blocks": [ /* Block[] */ ] }
```

Runs (inline text spans) carry the formatting:

```jsonc
{ "text": "Hello", "bold": true, "italic": true, "underline": true,
  "strike": true, "code": true, "link": "https://…",
  "color": "#RRGGBB", "highlight": "#RRGGBB" }
```

Blocks, discriminated by `type`:

| Type | Shape |
|---|---|
| `heading` | `{ level: 1-6, runs }` |
| `paragraph` | `{ runs, align?: "left"\|"center"\|"right"\|"justify" }` |
| `list` | `{ ordered?, items: [{ runs, children? }] }` — nesting to 6 levels |
| `table` | `{ rows: [{ header?, cells: [{ blocks }] }] }` |
| `code` | `{ language?, text }` — multiline, monospace, shaded |
| `quote` | `{ blocks }` |
| `image` | `{ src: "data:image/png;base64,…", widthPx?, heightPx?, alt? }` |
| `pageBreak` | `{ }` |
| `hr` | `{ }` |

Image dimensions are optional — the writer sniffs intrinsic size straight
from the PNG IHDR / JPEG SOF bytes and caps at 6.5in page width keeping
aspect.

## Agent API

Static façade, mirrored exactly in the Node package:

| Method | Purpose |
|---|---|
| `Agent::validate($doc)` | structured `{path, message}[]`, empty = valid |
| `Agent::validateAndRepair($doc)` | `{ok, schema, errors}` — heuristic repair of near-miss agent output |
| `Agent::toBytes($doc)` | DOCX bytes; throws `SchemaException` when invalid |
| `Agent::write($doc, $path)` | write to disk → `{path, bytes, blocks}` |
| `Agent::read($bytesOrPath)` / `Agent::fromBytes($bytes)` | parse a real .docx back into the model |
| `Agent::toMarkdown($doc)` / `Agent::fromMarkdown($md)` | the Editor bridge |
| `Agent::describe($doc)` | plain-text summary (title, block counts, word count) |
| `Agent::jsonSchema()` | JSON Schema for LLM tool registration |
| `Agent::version()` | package version |

`validateAndRepair()` is built for agentic feedback loops: bare strings
become runs, `"text"` shorthand becomes runs, heading levels clamp to 1-6,
unknown block types drop with the error retained, missing `blocks`
defaults to `[]` — hand the errors back to the model if `ok` is false.

## Reading Word-authored files

`Agent::read()` handles more than its own writer output: headings via
`Heading1-9` styles or `outlineLvl`, run formatting including named
highlight colors, hyperlinks through the rels part, `numPr` lists with
`ilvl` nesting (unknown numbering buckets as unordered), tables, inline
images (returned as data URLs), page breaks and border-only paragraphs.
Unknown constructs degrade to plain paragraphs — the reader never throws
on strange XML.

## Determinism

`toBytes()` is reproducible: no timestamps in any XML part, fixed zip
entry order, and every entry's mtime pinned. The same document yields the
same bytes on every call — diff-able artifacts, cache-friendly outputs.

## Cross-language parity

As of 0.2.0 the metadata slots match the Node mirror exactly: the title is
carried in `docProps/core.xml` (`dc:title`) and the code block `language`
in a `lastword:code:{lang}` content-control tag (quotes use
`lastword:quote`), so the **same file opens in either engine** — title and
code language round-trip PHP ↔ Node in both directions. Files written by
0.1.x (Title-styled paragraph, `LastWordCode_{lang}` bookmark) still read
fine; the sibling repo's canonical fixture is frozen into each test suite
as a cross-read vector.

## Testing

```bash
composer install
composer test
```

## License

MIT
