# Changelog

## [Unreleased]

### Added

- **Headers and footers that hold blocks, so a read letterhead keeps its logo**
  (last-word#6). `page.header` / `page.footer` may now be `{blocks: [...]}` in the body's
  own block vocabulary (less `chart` and `pageBreak`, at any depth, and `portable`)
  instead of the one-line `{left, center, right, size, color}` band. The two forms never
  mix: a band with both is a validation error. In a blocks band, `{page}` and `{pages}` in
  run text are live page fields, as in a band.
  - **`read()` output changes only for headers and footers it could not hold before.**
    A header or footer that is one paragraph of text and nothing else reads exactly as it
    did. One that held a picture, several paragraphs, a table or a content control (Word's
    own "Page X of Y" footer is one) now comes back as `{blocks}`: the logo as an image
    block with a `data:` URL like a body image, PAGE / NUMPAGES as `{page}` / `{pages}`.
    Those documents were the lossy ones: their logo or extra lines were reported under
    `unrepresented` and dropped. `unrepresented` now lists only what blocks cannot hold
    either (VML pictures, embedded objects, GIF / EMF and other non-PNG/JPEG images,
    linked or oversized images, text boxes as `image`; charts as `chart`).
    **Consumer action:** if you read `page.header.left` (or `center` / `right`), also
    accept `page.header.blocks`; a viewer draws it like any other blocks. If you write a
    read document back onto its own original with `templateParts`, keep dropping
    `page.header` / `page.footer` first, as before. Without a template, leave them in:
    the logo and address now write back on their own.
  - `toBytes` writes a blocks band through the body's block renderer into `header1.xml` /
    `footer1.xml`, wrapped in a `lastword:band` content control (so a one-paragraph blocks
    band reads back as blocks), with the part's own relationships file. Media names, drawing
    ids and relationship ids cannot collide with the body's or with a `templateParts`
    letterhead's, and the document's own band still replaces the template's.
  - Hardening equal to 0.13.0's letterhead media, on both paths: a header or footer image
    must be PNG or JPEG by its leading bytes and its declared type, at most 16 MiB each and
    32 MiB across the header and footer. On read, a linked picture is never read (nothing
    is fetched) and a header part over 2 MiB or declaring a DOCTYPE is not read as blocks.
    A non-`data:` image `src` is refused by validation and, unvalidated, written as its alt
    text, never fetched.
  - `validateAndRepair` drops a `chart`, `pageBreak` or `portable` block from a band with a
    note. `describe()` prints a blocks band (`Page header: 3 blocks (1 image, 2
    paragraph)`). `diff` / `reduce` carry a band change as one `doc.set` of `page`, and
    `diff($d, read(toBytes($d)))` stays `[]`. `catalog` / `extract` are unchanged: page
    furniture is not content.
  - The JSON Schema gains `$defs.blocksBand`; `page.header` / `page.footer` are
    `oneOf [band, blocksBand]`. `$defs.band` is unchanged.

## [0.13.0] - 2026-10-28

### Added

- **Keep a customer's letterhead: `templateParts`** (last-word#5). `toBytes` / `write` take
  `['template' => $docxOrDotx, 'templateParts' => ['styles', 'headers', 'footers', 'page']]`:
  the template's header and footer parts (default, first-page and even-page) and their
  images are copied byte for byte, on every page, and its page size, orientation, margins
  and header/footer distances are used. The template's BODY is never taken, so a letterhead
  that arrives as a `.docx` with a sample letter in it is fine. **Nothing to do to keep
  today's behaviour:** without `templateParts` (or with `['styles']`) the output is
  byte-identical to 0.12.0.
  - Precedence: the document wins field by field. `page.header` / `page.footer` replace
    the template's of that kind, all variants; `page.size`, `page.orientation` and each
    margin override that one value. Write-back onto the original: drop the `page.header` /
    `page.footer` that `read()` filled in, or they win.
  - Hostile input is refused with `TemplateException`, never degraded: DOCTYPEs, oversized
    parts or images, external links other than http/https/mailto hyperlinks (an externally
    linked image is refused, never fetched), embedded objects, images that are not
    PNG/JPEG/GIF/BMP/TIFF/EMF/WMF by extension AND leading bytes (no SVG), fields that pull
    content in (INCLUDEPICTURE, INCLUDETEXT, LINK, DDE, DDEAUTO, IMPORT), VML URL
    attributes, undeclared relationship ids. An unknown `templateParts` name throws.
- **`read()` reports header and footer content a band cannot hold**, as
  `unrepresented: [{where, kind, count}]` (`image`, `nested-block`), and `describe()`
  prints it. A letterhead logo was dropped silently before. Present only when there is
  something to report.

### Security

- **The `template` option now refuses a DOCTYPE in the template's `styles.xml` or
  theme.** These parts are copied into the output unchanged, and until now they
  were only scanned for style ids. A Word package never legitimately carries a
  DOCTYPE, and an internal subset is the entry point for entity expansion, so
  it is refused like every other template part. **Consumer action: none**,
  unless you pass hand-made templates that declare a DOCTYPE, which now throw
  `TemplateException`.

## [0.12.0] - 2026-10-27

Document exchange, step 4 of the approved plan (`.ai/plans/document-exchange-and-export.md`),
to fancy-conformance 0.37.0's `suites/exchange/SPEC.md`.

### Added

- **Portable content out: `Agent::catalog($input)` and `Agent::extract($input, $selector, $options)`.**
  `catalog` lists headings (`{index, level, text, selector}`, the selector grabbing the
  heading's section) and tables (`{table, index, rows, columns, headerRows, heading,
  selector}`). `extract` returns `{ok: true, content, report}`: selectors `{table: n}`,
  `{underHeading: "Results", nth: 0}` and `{blocks: [a, b]}` (0-based, inclusive), or null
  for the whole document. Reads .docx, .doc, .odt and .rtf (bytes or a path), or a Doc you
  hold; unreadable input is `{ok: false, refused: {reason}}` (`not_a_document`, `corrupt`,
  or an archive/template refusal code), never an exception. Not gated: it is a read.
- **Guarded extraction.** Every string taken from the document is stripped of invisible,
  bidi-control and Unicode tag characters (fine-print's `AgentReadGuard::INVISIBLE`), one
  `invisible-chars` finding per run removed. Links outside http, https and mailto are
  removed and reported `link`, their text kept. **Reports never carry document text**:
  every `where` is a JSON Pointer built from indexes.
- **Portable content in: `Agent::fromPortable($content)`** and an inline
  `{"type": "portable", "content": ...}` block wherever a block may go (the document, a
  quote, a table cell). `validate` checks it closed and against the exchange limits;
  `validateAndRepair`, `toBytes` and `write` expand it deterministically. Tables keep header
  rows, spans and widths, numeric columns are right-aligned, and formulas are never
  carried (`formula-as-value`). Invalid content is refused, never repaired.
  `Agent::toPortable($doc, $selector)` and `Agent::validateContent($content)` are the pure
  halves. `LastWord\Exchange\*` holds them; the classes shared with fine-print 0.5.0
  (`Content`, `Report`, `FromPortable`, `Portable`, `ExportPolicy`) are kept in step with it.
- **`Agent::toMarkdownWithReport($doc)`**: the markdown bridge with a report. What
  `ToMarkdown` used to drop silently (underline, colour, size, font, alignment and spacing,
  cell styling, spans, nested blocks in cells, extra header rows, line breaks, image size)
  is now reported by kind and located; a chart is `chart-as-table`. The markdown is the
  same string `toMarkdown()` returns, so the `fromMarkdown` fixpoint is untouched.
- **Exports, off by default: `Agent::export($input, $target, $options, $policy)`** with a
  host-built `LastWord\Exchange\ExportPolicy`. Targets `markdown` (the bridge, guarded,
  unsafe links removed), `text`, `html` (new: every string escaped, no scripts or event
  handlers, links only http/https/mailto with `rel="noopener noreferrer"`, images as
  PNG/JPEG data URIs) and `docx` (from .doc, .odt, .rtf). A null policy or an empty `allow`
  enables nothing; an `allow` among the agent's options throws `OptionsInvalid`. Refusals
  in `exchange/policy` order: `export_unsupported`, `export_disabled`, `export_unavailable`,
  `export_refused` (with the reader's `cause`), then a limit code. **`pdf` answers
  `export_unavailable` (`missing: fine-print`)** until the PDF bridge (plan step 6), and is
  never listed by `Agent::exportToolDefinition($policy)`, whose `target` enum shows only
  enabled targets.
- **`page.background` and `page.border`** (owner decision 11), with fine-print 0.3.0's
  meaning: a page colour, and a frame `inset` points from the page edge (24 by default),
  `width` across (0.5; a double is two rules of a third each), `color` (black), `style`
  single|double|dashed|dotted|none. Written as `w:background` (plus
  `w:displayBackgroundShape` in a new `word/settings.xml`, without which Word and
  LibreOffice ignore it) and `w:pgBorders`, measured from the page up to Word's 31pt
  limit and from the text beyond it. Read back by `read()`. Validated like fine-print's
  (including a frame that leaves no room inside it); `validateAndRepair` removes an invalid
  one with a note. Checked with LibreOffice 26.2 as an oracle: it opens the file with the
  page colour, the frame at the inset, and the text where the margins put it.
- `doc-model/shared` rows 0002 and 0003 now pass for last-word: its page field set is
  fine-print's.

### Changed

- **`jsonSchema()`: `blocks` (and quote and cell `blocks`) now reference
  `$defs.blockOrPortable`**, which is `$defs.block` or `$defs.portable`. `$defs.block` is
  unchanged and is still the document model; nothing to do unless you resolved the
  `blocks.items` reference by hand.
- `validateAndRepair()` returns a `report` key when it expanded a portable block, and an
  `errors` note per expansion. Without portable blocks its output is unchanged.
- **A document whose `page.background` or `page.border` is malformed now fails
  `validate()`**; both keys used to be ignored. `validateAndRepair()` removes them with a
  note, so a repair loop needs no change.
- **`read()` now returns `page.background` / `page.border`** for a .docx that has a page
  colour or a uniform page border (a Word document authored with them included). Documents
  without them read exactly as before.
- Documents without the new page keys write byte-identical files: `settings.xml` is added
  only for a page colour.
- `particle-academy/fancy-conformance` (require-dev) is pinned EXACTLY at `0.37.0`, and a
  test asserts the pin. The new `tests/Unit/Exchange/ConformanceTest.php` runs every
  exchange suite whose manifest names last-word PHP (186 rows) plus last-word's half of
  `doc-model/shared` (20 rows), and fails if the manifests name a suite it does not run.

### PARITY

- The Node (`@particle-academy/last-word`) and Python ports have none of this yet; they are
  plan step 8. Until then both are PHP-only: the ports' validators refuse a `portable`
  block as an unknown block type, and ignore `page.background` / `page.border`, so the
  same document writes there without the decoration.

## [0.11.0] - 2026-10-27

### Added

- **Word `.dotx` and LibreOffice `.ott` templates.** `Agent::read()` reads them
  exactly as their `.docx` / `.odt` twins and adds `"sourceKind": "template"`.
  Document reads are unchanged and carry no `sourceKind` key, so nothing to do
  unless you want to tell the two apart.
- **`Agent::convertTemplate($bytesOrPath, $to = null)`** and the
  `vendor/bin/last-word template:convert <in> <out> [--to=document|template] [--force]`
  CLI turn a template into its document twin and back. Only the declaration is
  rewritten: the main part's content-type value (OOXML), or the `mimetype`
  entry plus the manifest root's `media-type` (ODF 1.3 Part 2 section 3.3
  requires them to agree). Every other zip member is copied as its original
  record, byte for byte; `mimetype` stays first and stored.
- `LastWord\Exceptions\OfficeTemplateRefused` with a constant `reason`.

### Changed

- **A macro-enabled template (`.dotm`) is now refused** with
  `OfficeTemplateRefused('macro_enabled')`; it used to be read as a document
  with its VBA silently ignored. So is a `.dotx` carrying a `vbaProject.bin`.
  A `.docm` still reads as before but is never converted. Re-save a `.dotm` as
  `.dotx` if you need it read.
- A package that declares a template of another family (a PowerPoint or Excel
  template type, an ODF `presentation-template`, a `text-template` whose body is
  a spreadsheet) is refused with `family_mismatch` instead of being read as
  whatever its parts looked like.

## [0.10.1] - 2026-10-26

### Security

- Bound document and template file reads before allocation. DOCX, ODT and
  template archive reads now reject excessive expansion, duplicate or unsafe
  names, corrupt sizes/CRC, inconsistent ZIP metadata and truncation with
  `LastWord\Exceptions\ArchiveRefused`. Templates keep `TemplateException`, with
  the typed refusal available through `getPrevious()`. Valid fixture payloads
  and reader projections remain unchanged; no new dependency.

## [0.10.0] - 2026-10-20

A minor release. Pre-1.0, breaking changes may land in a minor; this one has none.

### Added

- **A native `chart` block** (last-word#4). Bar (vertical columns or horizontal
  bars), line and pie charts are written as real Word charts: a
  `word/charts/chartN.xml` part inline in the text, with an embedded workbook so
  Word's "Edit Data" opens the numbers. Before this a report that needed a chart
  could only offer a table. The block takes an Apache ECharts `option`, the
  same field dark-slide's chart element takes, so one prompt teaches charts in
  both formats and a chart moves from a deck into a report unchanged:
  `{"type": "chart", "option": {"xAxis": {"type": "category", "data": ["Q1", "Q2"]}, "yAxis": {"type": "value", "name": "USD (k)"}, "series": [{"type": "bar", "name": "Revenue", "data": [120, 135]}]}, "numberFormat": "#,##0"}`.
  A category `yAxis` gives horizontal bars, as it does in ECharts; a series
  `stack` stacks bars; `label.show` draws data labels; `itemStyle.color` and
  `option.color` colour a series, a slice or one highlighted point; `title`,
  `legend` and each axis `name` are honoured. Sized by `widthPx` / `heightPx`,
  or `width` as a percentage of the text column, or the full column at 0.6 of
  its width as tall. `numberFormat` is an Excel number format for the value
  axis, the data labels and the workbook. `alt` is the alternative text.
- **The reader reads charts back** into the same block, in a canonical form:
  colours come back on each series (`itemStyle.color`, omitted when it is the
  default palette's), stacking as `stack: "total"`, the legend as
  `{"show": true}`, a percentage width as the `widthPx` / `heightPx` it resolved
  to. A chart it cannot map (radar, scatter, 3-D, a damaged part) is skipped,
  never a failed read.
- **Agents learn it from the package**: `Agent::validate()` checks a chart and
  says how to fix it; `Agent::validateAndRepair()` converts the
  `{chartType, categories, series: [{name, values, color}]}` shorthand and
  `series[].values` into the ECharts form; `Agent::jsonSchema()` publishes the
  block; `Agent::guide()` teaches it (the guide's hash moves in all three
  engines). `Agent::toMarkdown()` renders a chart as its data table.
- Not supported, and a validation error rather than a silent placeholder:
  scatter (dark-slide draws it), area and combination charts.

**Nothing to do**: a document without a chart writes byte-identical output.
Lands with `@particle-academy/last-word` 0.10.0 and `fancy-last-word` 0.6.0;
every chart part, the embedded workbook's included, is byte-identical across
the three engines.

## [0.9.0] - 2026-10-19

### Added

- **An agent guide ships with the package: `agent-guide/professional-documents.md`, and
  `LastWord\Agent::guide()` returns it.** What makes a document professional and the schema
  idioms that produce it, distilled from rubric-judged agent builds in the
  Fancy document lab: every rule in it moved a measured score. Give it to a
  model together with the JSON schema; it works with any agent loop. The same
  bytes ship in the PHP, Node and Python packages (a test in each pins the
  hash). **Nothing to do**; nothing else changed.

## [0.8.0] - 2026-10-19

### Added

- **Running headers and footers with live page numbers**: `page.header` and
  `page.footer`, each one line with `left`, `center` and `right` slots (plus
  optional `size` and `color`). `{page}` and `{pages}` become PAGE and NUMPAGES
  fields, so "Page {page} of {pages}" stays true when the document is edited:
  `{"page": {"footer": {"left": "Q3 Board Memo | Confidential", "right": "Page {page} of {pages}"}}}`.
  9pt grey by default. The reader reads them back, including Word's own
  simple and complex page fields. Every multi-page document agents built for
  review was marked down for having no page numbers, and there was no way to
  ask for one. **Nothing to do**: a document without them is byte-identical.
  Lands with `@particle-academy/last-word` 0.8.0 and `fancy-last-word` 0.4.0;
  parity holds.

## [0.7.0] - 2026-10-07

### Added

- **`['template' => $dotxBytesOrPath]` on `Agent::toBytes()` and
  `Agent::write()` — render onto a house template** (last-word#3). Reported by a
  consumer generating customer-facing sales collateral: the writer produced a
  structurally correct `.docx` and a human still had to re-apply the house style,
  which they described as most of its value gone.

  **Binds by style name, with nothing to configure.** The document model already
  uses Word's own style ids, so a template defining `Normal`, `Title`,
  `Heading1..n`, `Quote`, `ListParagraph` and `Hyperlink` binds on its own. The
  template's `word/styles.xml` and `word/theme/theme1.xml` are carried verbatim;
  definitions the template lacks — `CodeBlock`, `InlineCode`, heading levels it
  omits — are supplied from the built-in set, because a `w:pStyle` naming an
  undefined style renders UNSTYLED in Word rather than erroring.

  The theme travels with the styles deliberately: a style naming a theme colour or
  font resolves against whatever theme is in the package, so styles-without-theme
  would give the template's structure in the default's colours.

  **Not taken from the template**, and each for a reason rather than an oversight:
  `w:sectPr` (page size, margins, headers, footers) lives in `document.xml`, which
  the writer owns; `word/numbering.xml` would repoint every list, since
  `document.xml` references numbering ids this package defines; `word/settings.xml`
  is mostly `w:rsid` revision history and would make output depend on a template's
  editing past. So list markers and page setup are still ours and the typography,
  colours and theme are the template's. **A cover page is not in this release.**

  **Nothing changes for callers who do not pass it** — the built-in look is
  byte-for-byte what it was, which the determinism and parity suites check.

- **`TemplateException`**, thrown when a template is not a readable package or has
  no `word/styles.xml`. It REFUSES rather than falling back, because a document
  that silently comes out in the built-in style is the exact failure the option
  exists to end. It also gives a host a way to validate a customer-supplied
  template at upload rather than at render.

### Changed

- **The tag workflow is now `.github/workflows/publish.yml`, named `Publish`**
  (it was `release-gate.yml`, "Release gate"). Every Particle-Academy package
  publishes from that file under that name. What it does is unchanged: on a
  `v*` tag it checks that `CHANGELOG.md` has an entry for the version and that
  the entry is the newest one. Packagist syncs from the tag and never reads the
  workflow file, so nothing about how a release reaches Composer changes.

  **What you must do:** nothing. Only a script that looks runs up by the old
  file (`gh run list --workflow=release-gate.yml`) needs `publish.yml` instead.

## 0.6.3 — 2026-09-14

### Fixed

Found by porting the ops to Node (last-word-js 0.6.0), each with a test that
fails against 0.6.2:

- **A document with a numeric top-level key lost its small diff (a 0.6.2
  regression).** PHP stores the key `"5"` as the int 5, `diff()` emitted
  `doc.set` with `key: 5`, and 0.6.2's reducer skips non-string keys, so the
  replay check failed and the whole diff became `doc.replace`. The key is now
  emitted as a string. 0.6.2's changelog said diff keys were always strings;
  they were not until now.
- **An insert whose path named a list by a key turned the list into a map.**
  `/blocks/blocks` set a key `blocks` on the top-level block list. A list is
  reached by position only; such a path is skipped.
- **`opSchema()` accepted an empty `doc.set` key**, which the reducer refuses.

  **What you must do:** nothing.

## 0.6.2 — 2026-09-14

### Fixed

- **An op with a position that is not a number edited the first item.**
  `blocks.remove` with `index: "abc"` removed block 0, because `(int) "abc"` is 0;
  so did `replace`, `move` and `insert` with a non-numeric `index`, `from` or
  `to`. Such ops are now skipped. Ints and digit strings still work.
- **`doc.set` with a non-string `key` set a key `"1"`** (`(string) true`). It is
  skipped. So is an op whose `op` or `path` is not a string, which previously
  cast an array to `"Array"` with a warning.

  **What you must do:** nothing. Ops from `diff()` always carry int positions
  and string keys.

## 0.6.1 — 2026-09-14

### Fixed

- **`diff()` could call two different values the same.** Its equality check
  encoded values to JSON and returned `""` for anything JSON cannot hold (invalid
  UTF-8, NAN, INF), so two different such values compared as equal and a change
  could go unrecorded. It now throws `JsonException`. Found in holy-sheet's
  identical helper by its Python port.

  **What you must do:** nothing.

## 0.6.0 — 2026-09-14

### Added

- **`Agent::diff()`, `Agent::reduce()`, `Agent::opSchema()` and `Agent::equivalent()`:
  a document's versions stored as ops** (last-word#2). A version history cannot
  keep a .docx per edit, hashing the bytes cannot keep a one-word edit small
  (it is a zip), and diffing `toMarkdown()` output would lose run formatting,
  tables and page breaks on restore. The diff is over Last Word's own model.
  - `reduce($a, diff($a, $b))` equals `$b`, key order aside. The ops are verified
    by replaying them; ops that do not reproduce `$b` become one `doc.replace`.
  - Every list is aligned by content: the top-level blocks, a quote's blocks, a
    list's items and their children, a table's rows, a row's cells and a cell's
    blocks. Rewording one paragraph is one `blocks.replace` at its own path, even
    inside a table cell; moving one is one `blocks.move`. A container whose own
    properties changed is replaced whole.
  - Documents that write the same file diff to `[]`, so a save without a change
    records nothing, even where the reader normalises (merged runs, a header
    row's bold, a dropped empty paragraph).
  - Blocks have no ids, so ops address a list by JSON Pointer and an item by
    index: `blocks.*`, `items.*`, `rows.*` and `cells.*`, each with
    `insert`/`remove`/`move`/`replace`, plus `doc.set` and `doc.replace`.

  **What you must do:** nothing. This only adds methods.

## 0.5.0 — 2026-09-13

### Added

- **`Agent::read()` reads legacy Word `.doc` (Word 97-2003)** (last-word#1).
  0.4 refused it by name, which was honest and left a host with nothing to give
  a model. The compound-file container (MS-CFB) and the Word binary format
  (MS-DOC) are read by code in this package; there is no new dependency.

  What comes through: paragraph text from every piece of the piece table
  (fast-saved files included, 8-bit and UTF-16 pieces), headings by the style's
  built-in identifier (so "Überschrift 1" is still a heading), directly applied
  bold / italic / underline / strike, hyperlinks from `HYPERLINK` fields (other
  fields keep their displayed result), bulleted and numbered lists with nesting,
  tables with header rows, and page breaks.

  What does not: formatting inherited from a style, fonts, sizes and colours,
  images and embedded objects, text boxes, headers, footers, footnotes and
  comments, merged cells, and the document title.

  Word 6/95 files and encrypted files are refused by name
  (`UnsupportedFormatException`, `format()` `doc`). A compound file that is not
  a Word document is named too: `xls`, `ppt`, `msg`, or `cfb`.

- **The same document now reads the same in all four formats.** A document
  written by this package and converted by LibreOffice to `.doc`, `.odt` and
  `.rtf` reads back **identically** to the `.docx` from the `.doc` and the
  `.odt`, and identically from the `.rtf` except for a header-row flag that
  file does not carry. `LegacyFormatsTest` asserts exactly that on committed
  fixtures (`tests/fixtures/formats/`, with a README saying how they were made).

- **Office files that are not word-processing documents are named.** An
  `.xlsx`, `.pptx`, `.ods` or `.odp` raises `UnsupportedFormatException` with
  that format, rather than "not a document".

### Changed

- **The ODT reader keeps far more of the document.** Bold, italic, underline
  and strike from automatic styles (where ODF keeps direct formatting),
  hyperlinks, lists with nesting and numbered/bulleted from their list style,
  tables with header rows and merged cells, `text:s` / `text:tab` /
  `text:line-break`, page breaks, and the title from `meta.xml`. 0.4 returned
  headings and paragraphs as plain text.

  The shape changes with it: a list item that 0.4 returned as a top-level
  paragraph is now an item of a `list` block, and a table cell's paragraph is
  inside a `table` block. A caller that walks blocks by `type`, as it already
  must for a `.docx`, needs no change; one that only read top-level paragraphs
  of an `.odt` will now find that text inside lists and tables.

- **The RTF reader is a real tokenizer with group-scoped state.** It reads
  headings by style name or outline level, direct bold / italic / underline /
  strike, `HYPERLINK` fields, lists from the list table, tables (header rows
  where `\trhdr` marks them), `\uN` with `\ucN` fallback skipping and surrogate
  pairs, `\'hh` in the document's code page (`\ansicpg`: 874 and 1250-1258
  decoded; 932/936/949/950 double-byte characters become one U+FFFD each), line
  breaks, tabs, page breaks and the title.

  **BREAKING, for RTF only:** 0.4 turned any bold-led paragraph into a level-1
  heading. That was a guess, and it made emphasis into structure. A bold
  paragraph is now a paragraph with a bold run; a heading is a paragraph whose
  style or outline level says so. If you relied on the guess, look for a
  paragraph whose runs are all bold.

- **Bytes that are no document raise `UnsupportedFormatException`** (format
  `unknown`) instead of a bare `InvalidArgumentException`. It extends
  `InvalidArgumentException`, so a `catch` for that still catches it; do
  nothing unless you compared the class exactly.

- **A damaged file raises `RuntimeException`, and an unsupported format raises
  `UnsupportedFormatException`.** "This file is broken" and "save it as .docx"
  send a person to do different things. A `.doc` signature with nothing valid
  behind it was an unsupported format in 0.4 and is a damaged file now.

### Security

- **Legacy readers treat an upload as hostile.** Every compound-file offset is
  bounds-checked; sector chains, the DIFAT chain and the directory tree are
  followed at most once per node, so a loop fails instead of spinning; a stream
  over 256 MB, or an allocation table naming more sectors than the file holds,
  is refused; the Word piece table must run forwards, so no byte range is read
  twice; an ODT part carrying a DOCTYPE is refused before parsing, parts over
  64 MB are refused, element nesting past 257 is refused (libxml's own limit,
  now pinned by a test so the Node and Python parsers refuse at the same depth),
  and repeated rows and columns are capped (1,000 per repeat, 100,000 cells in
  total); RTF group nesting is capped at 10,000 and `\bin` data
  is skipped by its length. Each guard has a test on a hand-built file
  (`tests/Support/LegacyFiles.php`).

## 0.4.1 — 2026-09-10

### Fixed

- **`version()` reports the version this package actually ships as.** It
  returned `0.2.0 (the SCHEMA version)` from a 0.4.x release. The constant had drifted because
  nothing compared it to the packaging metadata — the same shape as every other
  two-copies-of-one-number failure in this estate.

  `VersionIsSingleSourcedTest` / `version.test.ts` now pins it, so the class is
  closed rather than the instance fixed. `dark-slide-py` already had that
  assertion and was the only engine in the family to catch itself.


### Added

- **A premium-document composition suite.** `PremiumDocumentTest` /
  `premium-document.test.ts` writes one document using every formatting feature
  at once — all eight run properties in a single paragraph, styled headings, a
  repeating table header, resolved list numbering, A4 landscape geometry, a
  document default font — and asserts the unzipped OOXML rather than that a file
  appeared.

  The suites beside it prove each feature works ON ITS OWN. That is a different
  question from whether they compose, and a dropped feature is invisible: Word
  shows no error and the document is merely plain. Nobody files a bug against a
  report that looks boring; they conclude the library is boring.

  It found no defects here, which is the result worth recording. It includes a
  control that fails if the assertions could pass on an unformatted document,
  and it pins two decisions that read like gaps: `highlight` renders as `<w:shd>`
  rather than `<w:highlight>` (which takes sixteen named colours and could not
  carry a `#RRGGBB` schema), and `page.margins` are in POINTS.

## 0.4.0 — 2026-09-10

### Added

- **`Agent::read()` now reads ODT and RTF, and NAMES a legacy `.doc` instead of
  failing obscurely** (last-word#1).

  A consumer removed `phpoffice/phpword` and made this package the only docx
  path in their app. PhpWord's `IOFactory` sniffed `.doc`, `.odt` and `.rtf`
  alongside `.docx`; dropping it dropped those three.

  **The failure was silent, which is the part that mattered.** Those arrive as
  user uploads that an agent then analyses: the file stored fine and contributed
  no text. Nothing raised, nothing logged, and an agent answered questions about
  a document nobody had read.

- **`UnsupportedFormatException`**, distinct from `InvalidArgumentException`.
  "This file is damaged" and "this is a format we do not read, save it as
  .docx" lead a person to different actions, so they cannot share a class. It
  carries `format()` so a host can branch without grepping a message — messages
  are for people, and a host that parses one breaks when the wording improves.

  A legacy `.doc` is detected and refused by name. Binary Word 97-2003 parsing
  is a separate and much larger job; refusing it clearly is the honest answer
  today, and it is what lets a host say "re-save it as .docx".

### Fixed

- **A path is now sniffed, not assumed to be docx.** `read()` used to treat any
  existing path as docx bytes — reading the file and handing it to `DocxReader`
  without checking the signature — so a `.doc` on disk failed *inside the zip
  reader* and the error named a broken archive rather than the real problem.

### Reading, honestly scoped

  ODT keeps structure: `text:h` becomes a heading with its level, not a
  paragraph. The consumer left another library precisely because it flattened
  nested lists and stripped emphasis, and text that survives with its shape lost
  is a worse input for a model than text that fails loudly.

  RTF extraction takes paragraphs and bold-led headings. RTF carries much more —
  tables, embedded objects, styles — and this does not claim them. Stated here
  rather than discovered.

  Both return **the same document shape as `DocxReader`**, so a caller that
  already handles our documents needs no second code path per input format. The
  moment it does, the two paths drift and only one gets the next fix.

### Added

- **A rich-layout surface, so a business one-pager is expressible.** The model
  was far narrower than the XML this engine already emitted: font size, font
  family, small caps, letter spacing, per-cell shading, borders, padding,
  vertical alignment and both merge directions were produced from hardcoded
  blocks or from `styles.xml` and were **unreachable from the model**. An agent
  could emit `size`, `colSpan` or `shading`, the validator returned no errors,
  and every one of them was silently dropped.

  | where | new keys |
  |---|---|
  | run | `size` (points, half-points exact) · `font` · `smallCaps` · `letterSpacing` (points, may be negative) |
  | paragraph, **heading** and list item | `spaceBefore` · `spaceAfter` · `lineHeight` · `indentLeft` · `indentRight` · `keepNext` · `shading` · `borders` · `align` (on headings too) |
  | table | `widths` (relative column weights) · `width` (% of the text column) · `align` · `borders` (incl. `insideH` / `insideV`) · `cellPadding` |
  | cell | `shading` · `borders` · `padding` · `valign` · `colSpan` · `rowSpan` |
  | document | `page` (`size`, `orientation`, `margins`) · `defaultFont` · `defaultSize` |

  Every key is validated, every key round-trips through the reader, and every
  key appears in `jsonSchema()` so an agent registering the tool is told it
  exists.

- **A heading is now a paragraph.** It takes the same properties, so a section
  label that needs spacing or alignment no longer has to be a bold paragraph
  impersonating a heading — and therefore appearing in no navigation pane and
  no table of contents.

- **Both merge directions**, written HTML-style: a `rowSpan` cell appears ONCE
  and the rows it covers list only their own remaining cells. The writer
  synthesises the `w:vMerge` continuations OOXML requires and the reader folds
  them back, so the model that comes out is the model that went in.

- **The table grid is computed from the section.** All three engines carried
  `9360` twips as a literal, so a document that narrowed its margins got a
  table that no longer matched its own page — too narrow, and silently so.

- **`last-word/docx-constructs` in `fancy-conformance`** — 44 shared rows
  pinning which construct emits which XML, in which order, and what the reader
  gives back. The rows are not transcribed into this repo: all three engines
  assert the same file, so a mapping that drifts in one fails there rather than
  quietly becoming that engine's behaviour.

### Fixed

- **Adjacent tables no longer merge into one in Word.** OOXML merges two
  `<w:tbl>` elements that touch, imposing the first table's column grid on the
  second. A stat band followed by a callout became a single two-row table.
- **A run's properties can no longer be lost to run-merging.** Adjacent runs
  are merged when their formatting matches, and the comparison listed only the
  properties that existed when it was written — so two differently-sized runs
  merged into one and took the first one's size.

- **`fancy-conformance ^0.7.0` is a new dev dependency**, and it is not on
  Packagist yet — the fixture package has to be released before this one can
  install. `composer.lock` is deliberately NOT updated here for that reason;
  regenerate it once the fixture release lands.

### Changed

- **Table properties are now written inline, not taken from a named style.**
  A named table style cannot vary per table instance, so per-table borders
  forced this. Also reconciled with it: header cells are one grey in all three
  engines, and header bold is one mechanism.

- **Document defaults name an East Asian font.** Without it Word picks its own
  face for CJK runs, which is exactly the text a mixed-script document
  contains.

  **What you must do: nothing.** No existing key changed meaning, nothing was
  removed and nothing was renamed. A document written before this release
  produces the same page. The visible differences are confined to tables, are
  small, and are listed above so a pixel comparison against an old build is not
  a surprise.

### Notes

- **A `header: true` row is not a round-trip fixpoint, and now says so.** The
  writer bolds the row's runs and the reader honestly reports the bold it
  finds, so the model that comes out is not the model that went in. The
  alternatives were to stop bolding header rows (changing every existing
  consumer's output) or to have the reader strip bold from header rows
  (discarding bold an author really asked for). Pinned as case `0042` rather
  than left as a surprise.

- **Release order: `fancy-conformance` first.** The shared table is version
  `0.7.0`, which is not on a registry yet, so this package's dev dependency
  cannot resolve and its lockfile cannot be regenerated until that release
  lands. Nothing about the runtime surface depends on it — only the test that
  asserts the shared rows.

- **What DOCX cannot do, so nobody chases it:** table corners are always
  square (there is no border radius in WordprocessingML), a background cannot
  bleed past the page margin without an anchored drawing, and naming a font is
  not shipping one — a reader without it substitutes. None of the three is
  worked around here; a layout that needs them needs a different format.

## 0.3.0 — 2026-08-07

### Changed

- **BREAKING — PHP 8.2 is no longer supported.** `require.php` moves from `^8.2` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.2, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- CI now tests PHP 8.4 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.

## 0.2.0

Cross-language metadata parity with the Node mirror
(@particle-academy/last-word) — last-word-js#1.

- **Title** now lives in `docProps/core.xml` (`dc:title`), byte-identical
  to the Node writer's part (+ its content-type override and package rel),
  instead of a Title-styled body paragraph. The reader prefers core.xml
  and still consumes the legacy Title paragraph from 0.1.x files.
- **Code blocks** are wrapped in a `w:sdt` content control tagged
  `lastword:code[:{lang}]` (the canonical cross-language language slot;
  survives Word edits) instead of an invisible `LastWordCode_{lang}`
  bookmark; **quotes** get the matching `lastword:quote` sdt wrapper. The
  reader parses the sdt tags, keeps the legacy bookmark read, and still
  handles bare pStyle-only files.
- Tables no longer emit a trailing pad paragraph (only between adjacent
  tables), matching the Node writer's structure.
- New frozen cross-read vector: `tests/fixtures/node-canonical.docx`
  (written by the Node engine) + its JSON, asserted semantically
  deep-equal on read.

## 0.1.0

Initial release — the docx sibling of holy-sheet (xlsx) and dark-slide
(pptx), mirrored 1:1 with `@particle-academy/last-word` (Node).

- **JSON document model** (camelCase, associative arrays): title +
  heading / paragraph / list / table / code / quote / image / pageBreak /
  hr blocks with styled runs (bold, italic, underline, strike, inline
  code, link, color, highlight).
- **Agent façade**: `validate`, `validateAndRepair` (heuristic repair with
  retained errors), `toBytes`, `write`, `read` / `fromBytes`, `describe`,
  `toMarkdown` / `fromMarkdown`, `jsonSchema`, `version`.
- **DOCX writer**: minimal valid WordprocessingML package — styles part
  (Title, Heading1-6, Quote, CodeBlock, InlineCode, Hyperlink), bullet +
  decimal numbering with 6 indent levels and per-list ordered restarts,
  real tables with header shading, inline images from data URLs with
  PNG/JPEG dimension sniffing and a 6.5in width cap, hyperlink rels.
  Deterministic output: fixed entry order, no timestamps, pinned zip
  mtimes.
- **DOCX reader**: round-trips the writer's output and tolerates
  Word-authored files (pStyle/outlineLvl headings, named highlights,
  numPr nesting, unknown constructs degrade — never throws).
- **Markdown bridge**: hand-rolled GFM subset both directions, no
  external markdown dependency; `<!-- pagebreak -->` comment convention.
