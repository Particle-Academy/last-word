<?php

declare(strict_types=1);

namespace LastWord\Tests\Support;

use ZipArchive;

/**
 * A minimal, valid `.dotx` built at test time.
 *
 * Generated rather than committed, for the same reason `GeneratedFont` is: a
 * real house template is someone's licensed property, and a binary fixture
 * nobody can read is a fixture nobody can reason about. Every value here is
 * deliberately unlike the writer's own defaults, so a test can tell "the
 * template was applied" from "the built-in look happens to match".
 *
 * The distinctive markers:
 *
 *   - `w:docDefaults` font `Garamond` (the writer defaults to Calibri)
 *   - `Normal`     → Garamond 24 half-points
 *   - `Heading1`   → navy `1F3864`, 44 half-points
 *   - `Heading2`   → navy, 32 half-points
 *   - `Title`      → 72 half-points
 *   - `HouseNote`  → a style the writer never emits, present so a test can show
 *                    the template's own definitions survive untouched
 *   - NO `CodeBlock` and NO `InlineCode`, so a test can show the writer supplies
 *     what the template lacks rather than emitting a document that references an
 *     undefined style
 *   - NO `Heading3`..`Heading6`, same reason, for paragraph styles
 */
final class GeneratedTemplate
{
    public const FONT = 'Garamond';

    public const HEADING_COLOR = '1F3864';

    public const ACCENT1 = 'C00000';

    public static function bytes(bool $withTheme = true): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lw-gen') . '.dotx';

        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', self::contentTypes($withTheme));
        $zip->addFromString('_rels/.rels', self::topRels());
        $zip->addFromString('word/document.xml', self::document());
        $zip->addFromString('word/styles.xml', self::styles());
        if ($withTheme) {
            $zip->addFromString('word/theme/theme1.xml', self::theme());
        }
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    /** A package that opens as a zip but defines no styles. */
    public static function withoutStyles(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lw-gen') . '.dotx';

        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', self::contentTypes(false));
        $zip->addFromString('word/document.xml', self::document());
        $zip->close();

        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    public static function styles(): string
    {
        $w = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $font = self::FONT;
        $color = self::HEADING_COLOR;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<w:styles xmlns:w="' . $w . '">';
        $xml .= '<w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '" w:eastAsia="' . $font . '" w:cs="' . $font . '"/>'
            . '<w:sz w:val="24"/><w:szCs w:val="24"/>'
            . '</w:rPr></w:rPrDefault></w:docDefaults>';
        $xml .= '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
            . '<w:name w:val="Normal"/><w:qFormat/>'
            . '<w:rPr><w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '"/><w:sz w:val="24"/></w:rPr>'
            . '</w:style>';
        $xml .= '<w:style w:type="paragraph" w:styleId="Title">'
            . '<w:name w:val="Title"/><w:basedOn w:val="Normal"/><w:qFormat/>'
            . '<w:rPr><w:b/><w:sz w:val="72"/></w:rPr>'
            . '</w:style>';
        $xml .= '<w:style w:type="paragraph" w:styleId="Heading1">'
            . '<w:name w:val="heading 1"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:keepNext/><w:outlineLvl w:val="0"/></w:pPr>'
            . '<w:rPr><w:b/><w:color w:val="' . $color . '"/><w:sz w:val="44"/></w:rPr>'
            . '</w:style>';
        $xml .= '<w:style w:type="paragraph" w:styleId="Heading2">'
            . '<w:name w:val="heading 2"/><w:basedOn w:val="Normal"/><w:next w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:keepNext/><w:outlineLvl w:val="1"/></w:pPr>'
            . '<w:rPr><w:b/><w:color w:val="' . $color . '"/><w:sz w:val="32"/></w:rPr>'
            . '</w:style>';
        $xml .= '<w:style w:type="paragraph" w:styleId="Quote">'
            . '<w:name w:val="Quote"/><w:basedOn w:val="Normal"/><w:qFormat/>'
            . '<w:pPr><w:ind w:left="1440"/></w:pPr>'
            . '</w:style>';
        $xml .= '<w:style w:type="paragraph" w:styleId="ListParagraph">'
            . '<w:name w:val="List Paragraph"/><w:basedOn w:val="Normal"/><w:qFormat/>'
            . '</w:style>';
        $xml .= '<w:style w:type="character" w:styleId="Hyperlink">'
            . '<w:name w:val="Hyperlink"/><w:rPr><w:color w:val="0563C1"/><w:u w:val="single"/></w:rPr>'
            . '</w:style>';
        // A style the writer never emits. Proves the template's own definitions
        // are carried through rather than filtered to the ones we recognise.
        $xml .= '<w:style w:type="paragraph" w:styleId="HouseNote">'
            . '<w:name w:val="House Note"/><w:basedOn w:val="Normal"/>'
            . '<w:rPr><w:i/><w:color w:val="' . self::ACCENT1 . '"/></w:rPr>'
            . '</w:style>';
        $xml .= '</w:styles>';

        return $xml;
    }

    public static function theme(): string
    {
        $a = 'http://schemas.openxmlformats.org/drawingml/2006/main';

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<a:theme xmlns:a="' . $a . '" name="House">';
        $xml .= '<a:themeElements>';
        $xml .= '<a:clrScheme name="House">'
            . '<a:dk1><a:srgbClr val="000000"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1>'
            . '<a:dk2><a:srgbClr val="' . self::HEADING_COLOR . '"/></a:dk2><a:lt2><a:srgbClr val="E7E6E6"/></a:lt2>'
            . '<a:accent1><a:srgbClr val="' . self::ACCENT1 . '"/></a:accent1>'
            . '<a:accent2><a:srgbClr val="ED7D31"/></a:accent2><a:accent3><a:srgbClr val="A5A5A5"/></a:accent3>'
            . '<a:accent4><a:srgbClr val="FFC000"/></a:accent4><a:accent5><a:srgbClr val="5B9BD5"/></a:accent5>'
            . '<a:accent6><a:srgbClr val="70AD47"/></a:accent6>'
            . '<a:hlink><a:srgbClr val="0563C1"/></a:hlink><a:folHlink><a:srgbClr val="954F72"/></a:folHlink>'
            . '</a:clrScheme>';
        $xml .= '<a:fontScheme name="House">'
            . '<a:majorFont><a:latin typeface="' . self::FONT . '"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont>'
            . '<a:minorFont><a:latin typeface="' . self::FONT . '"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont>'
            . '</a:fontScheme>';
        $xml .= '<a:fmtScheme name="House">'
            . '<a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>'
            . '<a:lnStyleLst><a:ln><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst>'
            . '</a:fmtScheme>';
        $xml .= '</a:themeElements></a:theme>';

        return $xml;
    }

    private static function contentTypes(bool $withTheme): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">';
        $xml .= '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $xml .= '<Default Extension="xml" ContentType="application/xml"/>';
        $xml .= '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml"/>';
        $xml .= '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>';
        if ($withTheme) {
            $xml .= '<Override PartName="/word/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>';
        }
        $xml .= '</Types>';

        return $xml;
    }

    private static function topRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>';
    }

    private static function document(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body><w:p/></w:body></w:document>';
    }
}
