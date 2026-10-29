<?php

declare(strict_types=1);

namespace LastWord\Tests\Support;

use ZipArchive;

/**
 * A customer letterhead `.docx`, hand-authored at test time (last-word#5).
 *
 * Built here rather than downloaded, for the reason `GeneratedTemplate` gives:
 * a real letterhead is somebody's property, and a binary fixture nobody can read
 * is a fixture nobody can reason about.
 *
 * It is deliberately a plain `.docx` WITH a body, the way a letterhead actually
 * arrives: a sample letter (paragraphs marked {@see BODY_TEXT}) and an image in
 * the body ({@see bodyPng()}). None of that may reach a document written onto it.
 *
 * The section it carries:
 *
 *   - a default header: a blue logo PNG ({@see logoPng()}) stored as
 *     `word/media/image1.png` -- the very name the writer gives its own first
 *     image, so a collision would show -- and the text {@see HEADER_TEXT};
 *   - a default footer: {@see FOOTER_TEXT}, with an external web link;
 *   - a custom page, {@see PAGE_W} x {@see PAGE_H} twips (neither Letter nor
 *     A4), margins top {@see MARGIN_TOP}, right 1000, bottom 1500, left 1100,
 *     header distance 400, footer distance 300.
 *
 * Variants, by option, for the first-page / even-page and hostile cases.
 */
final class GeneratedLetterhead
{
    public const HEADER_TEXT = 'ACME LETTERHEAD';

    public const FIRST_HEADER_TEXT = 'ACME FIRST PAGE';

    public const EVEN_HEADER_TEXT = 'ACME EVEN PAGE';

    public const FOOTER_TEXT = 'Acme Ltd, 1 Example Street';

    /** The address under the logo in the `addressBlock` variant, a paragraph each. */
    public const ADDRESS_LINES = ['Acme Ltd', '1 Example Street, Exampleton'];

    public const BODY_TEXT ='Dear customer, this sample letter is template body.';

    public const PAGE_W = 11000;

    public const PAGE_H = 15000;

    public const MARGIN_TOP = 2600;

    public const LOGO_DRAWING_ID = 7;

    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';

    /**
     * @param  array{
     *     firstPage?: bool, evenPage?: bool, gifLogo?: bool,
     *     doctypeHeader?: bool, doctypeDocument?: bool, doctypeRels?: bool,
     *     doctypeStyles?: bool, doctypeTheme?: bool,
     *     externalImage?: bool, svgLogo?: bool, pngNamedSvg?: bool,
     *     includePicture?: bool, oleObject?: bool, vmlSrc?: bool, undeclaredRel?: bool,
     *     template?: bool, oversizedHeader?: bool,
     *     addressBlock?: bool, pageFooter?: bool, oversizedLogo?: bool,
     * }  $o
     *
     * `addressBlock` (last-word#6) gives the header the shape a real letterhead
     * has: the logo alone in the first paragraph, then {@see ADDRESS_LINES} as a
     * paragraph each. `pageFooter` makes the footer Word's own "Page X of Y"
     * gallery footer: a `w:sdt` around one paragraph of complex PAGE / NUMPAGES
     * fields. `oversizedLogo` makes the logo a PNG one byte over the media cap.
     */
    public static function bytes(array $o = []): string
    {
        $parts = [];
        $types = ['png' => 'image/png'];
        $overrides = [
            '/word/document.xml' => ($o['template'] ?? false)
                ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml'
                : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml',
            '/word/styles.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml',
            '/word/header1.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml',
            '/word/footer1.xml' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml',
        ];

        // ── Body: a sample letter with an image. Must never be carried. ──
        $parts['word/media/image2.png'] = self::bodyPng();
        $docRels = [
            ['rId1', 'styles', 'styles.xml', false],
            ['rId8', 'header', 'header1.xml', false],
            ['rId9', 'footer', 'footer1.xml', false],
            ['rId20', 'image', 'media/image2.png', false],
        ];

        $refs = '<w:headerReference w:type="default" r:id="rId8"/>';
        if ($o['firstPage'] ?? false) {
            $parts['word/header2.xml'] = self::header(self::FIRST_HEADER_TEXT, '');
            $docRels[] = ['rId10', 'header', 'header2.xml', false];
            $overrides['/word/header2.xml'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml';
            $refs .= '<w:headerReference w:type="first" r:id="rId10"/>';
        }
        if ($o['evenPage'] ?? false) {
            $parts['word/header3.xml'] = self::header(self::EVEN_HEADER_TEXT, '');
            $docRels[] = ['rId11', 'header', 'header3.xml', false];
            $overrides['/word/header3.xml'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml';
            $refs .= '<w:headerReference w:type="even" r:id="rId11"/>';
            $parts['word/settings.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:settings xmlns:w="' . self::W . '"><w:evenAndOddHeaders/></w:settings>';
            $overrides['/word/settings.xml'] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml';
            $docRels[] = ['rId12', 'settings', 'settings.xml', false];
        }
        $refs .= '<w:footerReference w:type="default" r:id="rId9"/>';

        $body = '';
        for ($i = 1; $i <= 3; $i++) {
            $body .= '<w:p><w:r><w:t>' . self::BODY_TEXT . ' ' . $i . '</w:t></w:r></w:p>';
        }
        $body .= '<w:p><w:r>' . self::drawing('rId20', 1, 'Body picture', 914400, 914400) . '</w:r></w:p>';
        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . (($o['doctypeDocument'] ?? false) ? '<!DOCTYPE w:document [<!ENTITY x "x">]>' : '')
            . '<w:document xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<w:body>' . $body
            . '<w:sectPr>' . $refs
            . '<w:pgSz w:w="' . self::PAGE_W . '" w:h="' . self::PAGE_H . '"/>'
            . '<w:pgMar w:top="' . self::MARGIN_TOP . '" w:right="1000" w:bottom="1500" w:left="1100" w:header="400" w:footer="300" w:gutter="0"/>'
            . (($o['firstPage'] ?? false) ? '<w:titlePg/>' : '')
            . '</w:sectPr></w:body></w:document>';
        $parts['word/document.xml'] = $document;

        // ── Header: logo + text. ──
        $logoTarget = 'media/image1.png';
        $logoBytes = self::logoPng();
        if ($o['gifLogo'] ?? false) {
            // An extension the writer declares no Default for on its own.
            $logoTarget = 'media/logo.gif';
            $logoBytes = self::gif();
            $types['gif'] = 'image/gif';
        } elseif ($o['svgLogo'] ?? false) {
            $logoTarget = 'media/image1.svg';
            $logoBytes = '<svg xmlns="http://www.w3.org/2000/svg"><image href="http://example.com/beacon.png"/></svg>';
            $types['svg'] = 'image/svg+xml';
        } elseif ($o['pngNamedSvg'] ?? false) {
            $logoBytes = '<svg xmlns="http://www.w3.org/2000/svg"/>';
        }
        $headerRels = [];
        if ($o['externalImage'] ?? false) {
            $headerRels[] = ['rId1', 'image', 'http://example.com/logo.png', true];
        } else {
            $headerRels[] = ['rId1', 'image', $logoTarget, false];
            $parts['word/' . $logoTarget] = $logoBytes;
        }
        $extra = '';
        if ($o['includePicture'] ?? false) {
            $extra .= '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
                . '<w:r><w:instrText xml:space="preserve"> INCLUDE</w:instrText></w:r>'
                . '<w:r><w:instrText xml:space="preserve">PICTURE "http://example.com/x.png" \d </w:instrText></w:r>'
                . '<w:r><w:fldChar w:fldCharType="end"/></w:r>';
        }
        if ($o['oleObject'] ?? false) {
            $headerRels[] = ['rId2', 'oleObject', 'embeddings/oleObject1.bin', false];
            $parts['word/embeddings/oleObject1.bin'] = 'not really ole';
            $types['bin'] = 'application/vnd.openxmlformats-officedocument.oleObject';
            $extra .= '<w:r><w:object><o:OLEObject xmlns:o="urn:schemas-microsoft-com:office:office" r:id="rId2"/></w:object></w:r>';
        }
        if ($o['vmlSrc'] ?? false) {
            $extra .= '<w:r><w:pict><v:shape xmlns:v="urn:schemas-microsoft-com:vml"><v:imagedata src="http://example.com/x.png"/></v:shape></w:pict></w:r>';
        }
        if ($o['undeclaredRel'] ?? false) {
            $extra .= '<w:hyperlink r:id="rId99"><w:r><w:t>x</w:t></w:r></w:hyperlink>';
        }
        $blip = ($o['externalImage'] ?? false) ? '<a:blip r:link="rId1"/>' : '<a:blip r:embed="rId1"/>';
        $logo = self::drawing('rId1', self::LOGO_DRAWING_ID, 'Logo', 1371600, 457200, $blip);
        $header = self::header(self::HEADER_TEXT, $logo . $extra);
        if ($o['addressBlock'] ?? false) {
            $lines = '';
            foreach (self::ADDRESS_LINES as $line) {
                $lines .= '<w:p><w:pPr><w:jc w:val="right"/></w:pPr><w:r><w:rPr><w:sz w:val="18"/></w:rPr><w:t>' . $line . '</w:t></w:r></w:p>';
            }
            $header = str_replace(
                '<w:p><w:r>' . $logo . $extra . '</w:r><w:r><w:t xml:space="preserve"> ' . self::HEADER_TEXT . '</w:t></w:r></w:p>',
                '<w:p><w:r>' . $logo . $extra . '</w:r></w:p>' . $lines,
                $header,
            );
        }
        if ($o['oversizedLogo'] ?? false) {
            $parts['word/' . $logoTarget] = "\x89PNG\r\n\x1a\n" . random_bytes(\LastWord\Writer\TemplateSection::MEDIA_LIMIT - 7);
        }
        if ($o['doctypeHeader'] ?? false) {
            $header = str_replace('?>', '?><!DOCTYPE w:hdr [<!ENTITY e SYSTEM "file:///etc/passwd">]>', $header);
        }
        if ($o['oversizedHeader'] ?? false) {
            $header = str_replace('</w:hdr>', '<!--' . str_repeat('x', 2200000) . '--></w:hdr>', $header);
        }
        $parts['word/header1.xml'] = $header;
        $parts['word/_rels/header1.xml.rels'] = self::rels($headerRels);
        if ($o['doctypeRels'] ?? false) {
            $parts['word/_rels/header1.xml.rels'] = str_replace('?>', '?><!DOCTYPE Relationships>', $parts['word/_rels/header1.xml.rels']);
        }

        // ── Footer: text and a web link. ──
        $parts['word/footer1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:ftr xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"><w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
            . '<w:r><w:t>' . self::FOOTER_TEXT . ' </w:t></w:r>'
            . '<w:hyperlink r:id="rId1"><w:r><w:t>example.com</w:t></w:r></w:hyperlink></w:p></w:ftr>';
        $parts['word/_rels/footer1.xml.rels'] = self::rels([['rId1', 'hyperlink', 'https://example.com/', true]]);
        if ($o['pageFooter'] ?? false) {
            $field = static fn (string $instr, string $cached): string => '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
                . '<w:r><w:instrText xml:space="preserve"> ' . $instr . ' \* MERGEFORMAT </w:instrText></w:r>'
                . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
                . '<w:r><w:t>' . $cached . '</w:t></w:r>'
                . '<w:r><w:fldChar w:fldCharType="end"/></w:r>';
            $parts['word/footer1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:ftr xmlns:w="' . self::W . '" xmlns:r="' . self::R . '">'
                . '<w:sdt><w:sdtPr><w:docPartObj><w:docPartGallery w:val="Page Numbers (Bottom of Page)"/><w:docPartUnique/></w:docPartObj></w:sdtPr>'
                . '<w:sdtContent><w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
                . '<w:r><w:t xml:space="preserve">Page </w:t></w:r>' . $field('PAGE', '2')
                . '<w:r><w:t xml:space="preserve"> of </w:t></w:r>' . $field('NUMPAGES', '9')
                . '</w:p></w:sdtContent></w:sdt></w:ftr>';
            $parts['word/_rels/footer1.xml.rels'] = self::rels([]);
        }

        $parts['word/styles.xml'] = GeneratedTemplate::styles();
        if ($o['doctypeStyles'] ?? false) {
            $parts['word/styles.xml'] = str_replace('?>', '?><!DOCTYPE w:styles [<!ENTITY e SYSTEM "file:///etc/passwd">]>', $parts['word/styles.xml']);
        }
        if ($o['doctypeTheme'] ?? false) {
            $parts['word/theme/theme1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<!DOCTYPE a:theme [<!ENTITY e "e">]>'
                . '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="T"/>';
        }
        $parts['word/_rels/document.xml.rels'] = self::rels($docRels);
        $parts['_rels/.rels'] = self::rels([['rId1', 'officeDocument', 'word/document.xml', false]]);

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>';
        foreach ($types as $ext => $type) {
            $ct .= '<Default Extension="' . $ext . '" ContentType="' . $type . '"/>';
        }
        foreach ($overrides as $name => $type) {
            $ct .= '<Override PartName="' . $name . '" ContentType="' . $type . '"/>';
        }
        $ct .= '</Types>';

        return self::zip(['[Content_Types].xml' => $ct] + $parts);
    }

    /** A 60x20 solid blue PNG: the logo. */
    public static function logoPng(): string
    {
        return self::png(60, 20, [0x1F, 0x38, 0xC4]);
    }

    /** A 1x1 GIF. */
    public static function gif(): string
    {
        return (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
    }

    /** A 10x10 solid green PNG: the template's BODY image, which must not travel. */
    public static function bodyPng(): string
    {
        return self::png(10, 10, [0x00, 0xA0, 0x40]);
    }

    /**
     * A solid-colour truecolour PNG, built by hand so the fixture needs no GD.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public static function png(int $w, int $h, array $rgb): string
    {
        $row = "\x00" . str_repeat(chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]), $w);
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress(str_repeat($row, $h)))
            . $chunk('IEND', '');
    }

    private static function header(string $text, string $runs): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:hdr xmlns:w="' . self::W . '" xmlns:r="' . self::R . '"'
            . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<w:p>' . ($runs === '' ? '' : '<w:r>' . $runs . '</w:r>')
            . '<w:r><w:t xml:space="preserve"> ' . $text . '</w:t></w:r></w:p></w:hdr>';
    }

    private static function drawing(string $rId, int $id, string $name, int $cx, int $cy, ?string $blip = null): string
    {
        $blip ??= '<a:blip r:embed="' . $rId . '"/>';

        return '<w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $cx . '" cy="' . $cy . '"/><wp:docPr id="' . $id . '" name="' . $name . '"/>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="' . $name . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill>' . $blip . '<a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $cx . '" cy="' . $cy . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'
            . '</a:graphicData></a:graphic></wp:inline></w:drawing>';
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: bool}> $rels */
    private static function rels(array $rels): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($rels as [$id, $type, $target, $external]) {
            $xml .= '<Relationship Id="' . $id . '" Type="' . self::REL . $type . '" Target="' . htmlspecialchars($target, ENT_XML1 | ENT_QUOTES) . '"'
                . ($external ? ' TargetMode="External"' : '') . '/>';
        }

        return $xml . '</Relationships>';
    }

    /** @param array<string, string> $parts */
    private static function zip(array $parts): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lw-lh') . '.docx';
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($parts as $name => $bytes) {
            $zip->addFromString($name, $bytes);
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }
}
