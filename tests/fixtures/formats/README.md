# One document, four formats

`source.json` is a last-word document built to exercise what a reader has to get
right: three heading levels, bold / italic / underline / strike in one paragraph,
a hyperlink, accented Latin, Japanese and an emoji in one line, a bulleted list
nested three deep, a numbered list with a nested item, and a table with a header
row and non-ASCII cells.

| File | Made by |
|---|---|
| `report.docx` | `LastWord\Agent::write(source.json)` (last-word 0.4.1) |
| `report.odt` | LibreOffice 26.2.5.2, converting `report.docx` |
| `report.rtf` | LibreOffice 26.2.5.2, converting `report.docx` |
| `report.doc` | LibreOffice 26.2.5.2, converting `report.docx` (Word 97-2003 binary) |
| `report.read.json` | `Agent::read(report.docx)`: the answer every format and every runtime is held to |

No third-party document is committed; every byte here came from this package or
from converting its output.

## Regenerating

```bash
php -r 'require "vendor/autoload.php";
  LastWord\Agent::write(json_decode(file_get_contents("tests/fixtures/formats/source.json"), true),
  "tests/fixtures/formats/report.docx");'

cd tests/fixtures/formats
"C:/Program Files/LibreOffice/program/soffice.com" --headless --convert-to odt report.docx
"C:/Program Files/LibreOffice/program/soffice.com" --headless --convert-to rtf report.docx
"C:/Program Files/LibreOffice/program/soffice.com" --headless --convert-to doc report.docx
```

Rerun on 2026-09-13 with the same LibreOffice: `report.docx`, `report.doc` and
`report.rtf` came out byte-identical; `report.odt` differed in its bytes (ODF
stores save timestamps) and read identically.

The Node and Python ports carry byte-identical copies of these four files and
assert the same results on them.

## What the tests hold each format to

`LegacyFormatsTest` reads all four through `Agent::read()` and requires the
`.doc` and `.odt` reads to be **identical** to the `.docx` read (`toBe`, the
whole document). The `.rtf` read is identical except for one thing the file does
not contain: LibreOffice writes no `\trhdr`, so the table's header row cannot be
marked as one. The test asserts that absence rather than assuming it.

If a converter update changes these files, regenerate them, rerun the suites of
all three runtimes, and treat any new difference as a finding about a reader or
about the converter, never as an expectation to relax.
