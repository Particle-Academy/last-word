<?php

declare(strict_types=1);

namespace LastWord\Reader;

use DOMElement;
use LastWord\Schema\Anchor;
use LastWord\Schema\Shapes;

/**
 * A shape's own properties, read from its markup into the model's `shape`
 * block: a DrawingML `wps:wsp` in its `wp:inline` / `wp:anchor` frame, or a
 * VML shape (`v:rect`, `v:roundrect`, `v:oval`, `v:line`, `v:shape`) from a
 * `w:pict`. Its text box content is NOT read here: the caller gets the
 * `w:txbxContent` element back and reads it as blocks.
 *
 * Nothing in the markup is trusted to be in range. Every number is matched
 * digit by digit before it is cast (at most 15 digits), and every value outside
 * what the model holds becomes the nearest one it does, with a note. A note is a
 * report kind ({@see \LastWord\Exchange\Report::KINDS}), never document text.
 *
 * Also the branch choice for `mc:AlternateContent`: the first `mc:Choice` whose
 * `Requires` names only namespaces this reader understands, else `mc:Fallback`.
 */
final class ShapeReader
{
    public const NS_WPS = 'http://schemas.microsoft.com/office/word/2010/wordprocessingShape';

    public const NS_VML = 'urn:schemas-microsoft-com:vml';

    public const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** Namespaces whose `mc:Choice` this reader takes over the fallback. */
    private const UNDERSTOOD = [self::NS_WPS];

    /** VML shape elements read as shapes, and the geometry each is. */
    private const VML_SHAPES = ['rect' => 'rect', 'roundrect' => 'roundRect', 'oval' => 'ellipse', 'line' => 'line', 'shape' => 'rect'];

    private const EMU_PER_PX = 9525;

    /**
     * The branch of an `mc:AlternateContent` to read, or null for none.
     */
    public static function branch(DOMElement $alternate): ?DOMElement
    {
        $fallback = null;
        foreach ($alternate->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 'Choice') {
                $requires = preg_split('/\s+/', trim($child->getAttribute('Requires'))) ?: [];
                $ok = $requires !== [] && $requires !== [''];
                foreach ($requires as $prefix) {
                    if (!in_array($child->lookupNamespaceURI($prefix), self::UNDERSTOOD, true)) {
                        $ok = false;
                    }
                }
                if ($ok) {
                    return $child;
                }
            } elseif ($child->localName === 'Fallback') {
                $fallback ??= $child;
            }
        }

        return $fallback;
    }

    /** The `wps:wsp` a `w:drawing` holds, or null when it holds something else. */
    public static function wsp(DOMElement $drawing): ?DOMElement
    {
        foreach ($drawing->childNodes as $frame) {
            if (!$frame instanceof DOMElement || ($frame->localName !== 'inline' && $frame->localName !== 'anchor')) {
                continue;
            }
            $graphic = self::child($frame, 'graphic');
            $data = $graphic !== null ? self::child($graphic, 'graphicData') : null;
            if ($data === null || $data->getAttribute('uri') !== self::NS_WPS) {
                return null;
            }
            $wsp = self::child($data, 'wsp');

            return $wsp !== null && $wsp->namespaceURI === self::NS_WPS ? $wsp : null;
        }

        return null;
    }

    /** The first VML shape a `w:pict` holds, or null (a picture, a group, nothing). */
    public static function vml(DOMElement $pict): ?DOMElement
    {
        foreach ($pict->childNodes as $el) {
            if ($el instanceof DOMElement && $el->namespaceURI === self::NS_VML && isset(self::VML_SHAPES[$el->localName])) {
                // A v:shape holding v:imagedata and no text box is a picture, not a shape.
                if (self::child($el, 'imagedata') !== null && self::child($el, 'textbox') === null) {
                    return null;
                }

                return $el;
            }
        }

        return null;
    }

    /**
     * A DrawingML shape: [the shape block without `blocks`, its `w:txbxContent`
     * or null, notes].
     *
     * @return array{0: array<string, mixed>, 1: ?DOMElement, 2: list<string>}
     */
    public static function fromWsp(DOMElement $wsp): array
    {
        $notes = [];
        $frame = $wsp->parentNode?->parentNode?->parentNode; // graphicData > graphic > inline|anchor
        $frame = $frame instanceof DOMElement ? $frame : null;
        $spPr = self::child($wsp, 'spPr');
        $xfrm = $spPr !== null ? self::child($spPr, 'xfrm') : null;
        $style = self::child($wsp, 'style');

        // Geometry: a preset the model names, or a rectangle.
        $geometry = 'rect';
        $prst = $spPr !== null ? self::child($spPr, 'prstGeom') : null;
        if ($prst !== null) {
            $name = $prst->getAttribute('prst');
            if (in_array($name, Shapes::GEOMETRIES, true)) {
                $geometry = $name;
            } else {
                $notes[] = 'format-approximated';
            }
        } elseif ($spPr !== null && self::child($spPr, 'custGeom') !== null) {
            $notes[] = 'format-approximated';
        }

        // Size: the frame's extent, else the shape's own, else the default.
        $ext = $frame !== null ? self::child($frame, 'extent') : null;
        $size = $ext !== null ? [self::digits($ext->getAttribute('cx')), self::digits($ext->getAttribute('cy'))] : [null, null];
        if (($size[0] === null || $size[1] === null) && $xfrm !== null && ($inner = self::child($xfrm, 'ext')) !== null) {
            $size = [$size[0] ?? self::digits($inner->getAttribute('cx')), $size[1] ?? self::digits($inner->getAttribute('cy'))];
        }

        $shape = ['type' => 'shape'];
        if ($geometry !== 'rect') {
            $shape['geometry'] = $geometry;
        }
        [$shape['widthPx'], $shape['heightPx']] = self::extents($size[0], $size[1], $geometry, $notes);

        // Fill.
        $fill = $spPr !== null ? self::fillOf($spPr) : null;
        if ($fill === null && $style !== null && self::refIndex($style, 'fillRef') > 0) {
            $notes[] = 'format-approximated'; // a theme fill: not read
        } elseif (is_string($fill) && $fill !== '') {
            $shape['fill'] = $fill;
        } elseif ($fill === false) {
            $notes[] = 'format-approximated'; // a gradient, a pattern, a picture
        }

        // Outline.
        $ln = $spPr !== null ? self::child($spPr, 'ln') : null;
        if ($ln === null) {
            if ($style === null || self::refIndex($style, 'lnRef') <= 0) {
                $shape['outline'] = ['style' => 'none'];
            } else {
                $notes[] = 'format-approximated'; // a theme line: read as the default outline
            }
        } else {
            $outline = self::outlineOf($ln, $notes);
            if ($outline !== []) {
                $shape['outline'] = $outline;
            }
        }

        if ($xfrm !== null) {
            foreach (['flipH', 'flipV'] as $flag) {
                if (in_array($xfrm->getAttribute($flag), ['1', 'true'], true)) {
                    $shape[$flag] = true;
                }
            }
            $rot = $xfrm->getAttribute('rot');
            if ($rot !== '' && $rot !== '0') {
                $notes[] = 'format-approximated'; // rotation: not in the model
            }
        }

        if ($frame !== null && $frame->localName === 'anchor') {
            $shape['anchor'] = self::anchorOf($frame, $notes);
        }

        $content = null;
        $txbx = self::child($wsp, 'txbx');
        if ($txbx !== null) {
            $content = self::child($txbx, 'txbxContent');
        } elseif (self::child($wsp, 'linkedTxbx') !== null) {
            $notes[] = 'layout-not-preserved'; // a box in a chain: its text is read in the chain's first box
        }
        $bodyPr = self::child($wsp, 'bodyPr');
        if ($content !== null && $bodyPr !== null) {
            $align = match ($bodyPr->getAttribute('anchor')) {
                'ctr' => 'middle',
                'b' => 'bottom',
                default => null,
            };
            if ($align !== null) {
                $shape['verticalAlign'] = $align;
            }
        }

        $docPr = $frame !== null ? self::child($frame, 'docPr') : null;
        $descr = $docPr?->getAttribute('descr');
        if (is_string($descr) && $descr !== '') {
            $shape['alt'] = $descr;
        }

        return [$shape, $content, $notes];
    }

    /**
     * A VML shape: [the shape block without `blocks`, its `w:txbxContent` or
     * null, notes]. VML is read approximately and always says so.
     *
     * @return array{0: array<string, mixed>, 1: ?DOMElement, 2: list<string>}
     */
    public static function fromVml(DOMElement $el): array
    {
        $notes = ['format-approximated'];
        $geometry = self::VML_SHAPES[$el->localName] ?? 'rect';
        $css = self::css($el->getAttribute('style'));

        if ($geometry === 'line') {
            $from = array_map(self::length(...), explode(',', $el->getAttribute('from') ?: '0,0', 3) + [1 => '0']);
            $to = array_map(self::length(...), explode(',', $el->getAttribute('to') ?: '0,0', 3) + [1 => '0']);
            $w = $from[0] !== null && $to[0] !== null ? abs($to[0] - $from[0]) : null;
            $h = $from[1] !== null && $to[1] !== null ? abs($to[1] - $from[1]) : null;
        } else {
            $w = self::length($css['width'] ?? '');
            $h = self::length($css['height'] ?? '');
        }
        $toEmu = static fn (?float $pt): ?int => $pt === null ? null : (int) round(min($pt, 1e7) * 12700);
        $shape = ['type' => 'shape'];
        if ($geometry !== 'rect') {
            $shape['geometry'] = $geometry;
        }
        [$shape['widthPx'], $shape['heightPx']] = self::extents($toEmu($w), $toEmu($h), $geometry, $notes);

        // VML fills white and strokes black by default.
        $filled = strtolower($el->getAttribute('filled'));
        if (!in_array($filled, ['f', 'false'], true)) {
            $shape['fill'] = self::vmlColor($el->getAttribute('fillcolor')) ?? '#FFFFFF';
        }
        $stroked = strtolower($el->getAttribute('stroked'));
        if (in_array($stroked, ['f', 'false'], true)) {
            $shape['outline'] = ['style' => 'none'];
        } else {
            $outline = [];
            $color = self::vmlColor($el->getAttribute('strokecolor'));
            if ($color !== null && $color !== Shapes::DEFAULT_OUTLINE_COLOR) {
                $outline['color'] = $color;
            }
            $weight = self::length($el->getAttribute('strokeweight'));
            if ($weight !== null && $weight > 0) {
                $weight = self::round(min($weight, Shapes::MAX_OUTLINE_WIDTH));
                if ($weight != Shapes::DEFAULT_OUTLINE_WIDTH) {
                    $outline['width'] = $weight;
                }
            }
            if ($outline !== []) {
                $shape['outline'] = $outline;
            }
        }

        if (strtolower($css['position'] ?? '') === 'absolute') {
            $shape['anchor'] = self::vmlAnchor($el, $css, $notes);
        }

        $box = self::child($el, 'textbox');
        $content = $box !== null ? self::child($box, 'txbxContent') : null;
        if ($content !== null && in_array($geometry, Shapes::LINES, true)) {
            unset($shape['geometry']);
        }
        $alt = $el->getAttribute('alt');
        if ($alt !== '') {
            $shape['alt'] = $alt;
        }

        return [$shape, $content, $notes];
    }

    /**
     * @param  array<string, string>  $css
     * @param  list<string>  $notes
     * @return array{horizontal: array<string, mixed>, vertical: array<string, mixed>, wrap: string, allowOverlap: bool}
     */
    private static function vmlAnchor(DOMElement $el, array $css, array &$notes): array
    {
        $h = match (strtolower($css['mso-position-horizontal-relative'] ?? '')) {
            'page' => 'page',
            'margin', 'left-margin-area', 'right-margin-area' => 'margin',
            'char' => 'character',
            default => 'column',
        };
        $v = match (strtolower($css['mso-position-vertical-relative'] ?? '')) {
            'page' => 'page',
            'margin', 'top-margin-area', 'bottom-margin-area' => 'margin',
            'line' => 'line',
            default => 'paragraph',
        };
        $axis = static function (string $name, string $from, ?string $align, ?float $offset, array $allowed) use (&$notes): array {
            if ($align !== null && in_array($align, $allowed, true)) {
                if (!Anchor::alignFromParagraph($name, ['relativeTo' => $from])) {
                    return ['relativeTo' => $from, 'align' => $align];
                }
                $notes[] = 'layout-not-preserved'; // Word aligns nothing to a paragraph: its offset instead
            }
            $offset = $offset === null ? 0 : self::round(max(-Anchor::MAX_OFFSET, min(Anchor::MAX_OFFSET, $offset)));

            return ['relativeTo' => $from, 'offset' => $offset];
        };
        $wrapEl = null;
        foreach ($el->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'wrap') {
                $wrapEl = $child;
            }
        }
        $type = $wrapEl?->getAttribute('type') ?? '';
        $z = $css['z-index'] ?? '0';
        $wrap = match ($type) {
            'square' => 'square',
            'tight' => 'tight',
            'through' => 'through',
            'topAndBottom' => 'topAndBottom',
            default => preg_match('/^-\d/', $z) === 1 ? 'behind' : 'inFront',
        };

        return [
            'horizontal' => $axis('horizontal', $h, strtolower($css['mso-position-horizontal'] ?? '') ?: null, self::length($css['margin-left'] ?? ($css['left'] ?? '')), Anchor::HORIZONTAL_ALIGN),
            'vertical' => $axis('vertical', $v, strtolower($css['mso-position-vertical'] ?? '') ?: null, self::length($css['margin-top'] ?? ($css['top'] ?? '')), Anchor::VERTICAL_ALIGN),
            'wrap' => $wrap,
            'allowOverlap' => strtolower($el->getAttribute('o:allowoverlap')) !== 'f' && strtolower($el->getAttribute('o:allowoverlap')) !== 'false',
        ];
    }

    /**
     * An anchored frame's position and wrap, in full ({@see Anchor::full()}).
     *
     * @param  list<string>  $notes
     * @return array{horizontal: array<string, mixed>, vertical: array<string, mixed>, wrap: string}
     */
    private static function anchorOf(DOMElement $frame, array &$notes): array
    {
        $anchor = [];
        if (in_array($frame->getAttribute('simplePos'), ['1', 'true'], true)) {
            // Positioned by wp:simplePos, from the page's corner.
            $pos = self::child($frame, 'simplePos');
            $notes[] = 'layout-not-preserved';
            foreach (['horizontal' => 'x', 'vertical' => 'y'] as $axis => $attr) {
                $anchor[$axis] = ['relativeTo' => 'page', 'offset' => self::offset($pos?->getAttribute($attr) ?? '', $notes)];
            }
        } else {
            foreach (['horizontal' => ['positionH', Anchor::HORIZONTAL_FROM, Anchor::HORIZONTAL_ALIGN, 'column'], 'vertical' => ['positionV', Anchor::VERTICAL_FROM, Anchor::VERTICAL_ALIGN, 'paragraph']] as $axis => [$tag, $from, $aligns, $default]) {
                $el = self::child($frame, $tag);
                if ($el === null) {
                    $notes[] = 'layout-not-preserved';
                    $anchor[$axis] = ['relativeTo' => $default, 'offset' => 0];

                    continue;
                }
                $relative = $el->getAttribute('relativeFrom');
                if (!in_array($relative, $from, true)) {
                    $notes[] = 'layout-not-preserved'; // leftMargin, insideMargin ...: the page instead
                    $relative = 'page';
                }
                $holder = $el;
                $alternate = self::child($el, 'AlternateContent');
                if (self::child($el, 'align') === null && self::child($el, 'posOffset') === null && $alternate !== null) {
                    $holder = self::branch($alternate) ?? $el; // Word 2010's percentage offset, and its fallback
                }
                $align = self::child($holder, 'align');
                $offset = self::child($holder, 'posOffset');
                if ($align !== null && in_array(trim($align->textContent), $aligns, true) && Anchor::alignFromParagraph($axis, ['relativeTo' => $relative])) {
                    $notes[] = 'layout-not-preserved'; // the schema allows it, Word cannot make it: the model refuses it
                    $anchor[$axis] = ['relativeTo' => $relative, 'offset' => 0];
                } elseif ($align !== null && in_array(trim($align->textContent), $aligns, true)) {
                    $anchor[$axis] = ['relativeTo' => $relative, 'align' => trim($align->textContent)];
                } elseif ($offset !== null) {
                    $anchor[$axis] = ['relativeTo' => $relative, 'offset' => self::offset(trim($offset->textContent), $notes)];
                } else {
                    $notes[] = 'layout-not-preserved';
                    $anchor[$axis] = ['relativeTo' => $relative, 'offset' => 0];
                }
            }
        }

        $wrap = null;
        foreach ($frame->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $wrap = match ($child->localName) {
                'wrapSquare' => 'square',
                'wrapTight' => 'tight',
                'wrapThrough' => 'through',
                'wrapTopAndBottom' => 'topAndBottom',
                'wrapNone' => in_array($frame->getAttribute('behindDoc'), ['1', 'true'], true) ? 'behind' : 'inFront',
                default => $wrap,
            };
        }
        if ($wrap === null) {
            $notes[] = 'layout-not-preserved';
        }
        $anchor['wrap'] = $wrap ?? 'inFront';
        $anchor['allowOverlap'] = !in_array($frame->getAttribute('allowOverlap'), ['0', 'false'], true);

        return Anchor::full($anchor);
    }

    /** An EMU offset as points, clamped to the model's range. @param list<string> $notes */
    private static function offset(string $value, array &$notes): int|float
    {
        $emu = self::digits($value, true);
        if ($emu === null) {
            $notes[] = 'layout-not-preserved';

            return 0;
        }
        $pt = $emu / Anchor::EMU_PER_PT;
        if (abs($pt) > Anchor::MAX_OFFSET) {
            $notes[] = 'layout-not-preserved';
            $pt = $pt < 0 ? -Anchor::MAX_OFFSET : Anchor::MAX_OFFSET;
        }

        return self::round($pt);
    }

    /**
     * Two EMU extents as model px: in range, and never 0 on an area shape.
     *
     * @param  list<string>  $notes
     * @return array{0: int|float, 1: int|float}
     */
    private static function extents(?int $cx, ?int $cy, string $geometry, array &$notes): array
    {
        $isLine = in_array($geometry, Shapes::LINES, true);
        $out = [];
        foreach ([[$cx, Shapes::DEFAULT_WIDTH_PX], [$cy, Shapes::DEFAULT_HEIGHT_PX]] as [$emu, $default]) {
            if ($emu === null || $emu < 0) {
                $notes[] = 'layout-not-preserved';
                $out[] = $default;

                continue;
            }
            $px = $emu / self::EMU_PER_PX;
            if ($px > Shapes::MAX_EXTENT_PX) {
                $notes[] = 'layout-not-preserved';
                $px = Shapes::MAX_EXTENT_PX;
            }
            $out[] = self::round($px);
        }
        if (!$isLine && ($out[0] == 0 || $out[1] == 0)) {
            $notes[] = 'layout-not-preserved';
            $out = [$out[0] == 0 ? 1 : $out[0], $out[1] == 0 ? 1 : $out[1]];
        } elseif ($isLine && $out[0] == 0 && $out[1] == 0) {
            $notes[] = 'layout-not-preserved';
            $out[0] = 1;
        }

        return [$out[0], $out[1]];
    }

    /**
     * The fill a `wps:spPr` names: a hex colour, '' for `a:noFill`, null for none
     * named, or false for a fill the model cannot hold.
     */
    private static function fillOf(DOMElement $spPr): string|false|null
    {
        foreach ($spPr->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            switch ($child->localName) {
                case 'noFill':
                    return '';
                case 'solidFill':
                    return self::color($child) ?? false;
                case 'gradFill':
                case 'pattFill':
                case 'blipFill':
                case 'grpFill':
                    return false;
            }
        }

        return null;
    }

    /**
     * An `a:ln` as the model's outline, defaults left out.
     *
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    private static function outlineOf(DOMElement $ln, array &$notes): array
    {
        $outline = [];
        $color = null;
        foreach ($ln->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            switch ($child->localName) {
                case 'noFill':
                    return ['style' => 'none'];
                case 'solidFill':
                    $color = self::color($child);
                    if ($color === null) {
                        $notes[] = 'format-approximated';
                    }

                    break;
                case 'gradFill':
                case 'pattFill':
                    $notes[] = 'format-approximated';

                    break;
                case 'prstDash':
                    $dash = $child->getAttribute('val');
                    if (in_array($dash, ['dot', 'sysDot'], true)) {
                        $outline['style'] = 'dotted';
                    } elseif ($dash !== 'solid' && $dash !== '') {
                        $outline['style'] = 'dashed';
                    }

                    break;
                case 'headEnd':
                case 'tailEnd':
                    $type = $child->getAttribute('type');
                    if (in_array($type, Shapes::LINE_ENDS, true) && $type !== 'none') {
                        $outline[$child->localName] = $type;
                    }

                    break;
            }
        }
        if ($color !== null && $color !== Shapes::DEFAULT_OUTLINE_COLOR) {
            $outline = ['color' => $color] + $outline;
        }
        if ($ln->hasAttribute('w')) {
            $w = self::digits($ln->getAttribute('w'));
            if ($w === null || $w <= 0) {
                $notes[] = 'format-approximated';
            } else {
                $pt = self::round(min($w / Anchor::EMU_PER_PT, Shapes::MAX_OUTLINE_WIDTH));
                if ($w / Anchor::EMU_PER_PT > Shapes::MAX_OUTLINE_WIDTH) {
                    $notes[] = 'format-approximated';
                }
                if ($pt != Shapes::DEFAULT_OUTLINE_WIDTH && $pt > 0) {
                    $outline['width'] = $pt;
                }
            }
        }
        // The model's key order: color, width, style, headEnd, tailEnd.
        $ordered = [];
        foreach (['color', 'width', 'style', 'headEnd', 'tailEnd'] as $key) {
            if (array_key_exists($key, $outline)) {
                $ordered[$key] = $outline[$key];
            }
        }

        return $ordered;
    }

    /** `a:srgbClr` (six hex digits) or the preset black and white, as #RRGGBB; null for any other colour. */
    private static function color(DOMElement $fill): ?string
    {
        foreach ($fill->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 'srgbClr' && preg_match('/^[0-9A-Fa-f]{6}$/D', $child->getAttribute('val')) === 1) {
                return '#' . strtoupper($child->getAttribute('val'));
            }
            if ($child->localName === 'prstClr') {
                return match ($child->getAttribute('val')) {
                    'black' => '#000000',
                    'white' => '#FFFFFF',
                    default => null,
                };
            }

            return null;
        }

        return null;
    }

    private static function refIndex(DOMElement $style, string $name): int
    {
        $ref = self::child($style, $name);

        return $ref === null ? 0 : (self::digits($ref->getAttribute('idx')) ?? 0);
    }

    private static function vmlColor(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^#([0-9A-Fa-f]{6})$/D', $value, $m) === 1) {
            return '#' . strtoupper($m[1]);
        }
        if (preg_match('/^#([0-9A-Fa-f])([0-9A-Fa-f])([0-9A-Fa-f])$/D', $value, $m) === 1) {
            return strtoupper('#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3]);
        }

        return null;
    }

    /**
     * A VML `style` attribute as lower-cased property => value. At most 64
     * declarations are looked at.
     *
     * @return array<string, string>
     */
    private static function css(string $style): array
    {
        $out = [];
        foreach (array_slice(explode(';', $style, 65), 0, 64) as $decl) {
            $pair = explode(':', $decl, 2);
            if (count($pair) === 2) {
                $out[strtolower(trim($pair[0]))] = trim($pair[1]);
            }
        }

        return $out;
    }

    /** A CSS or VML length in points; a bare number is px, as VML takes it. Null when it is not one. */
    private static function length(string $value): ?float
    {
        if (preg_match('/^\s*(-?\d{1,9}(?:\.\d{1,6})?)\s*(pt|in|px|cm|mm|pc|emu)?\s*$/iD', $value, $m) !== 1) {
            return null;
        }
        $n = (float) $m[1];

        return match (strtolower($m[2] ?? '')) {
            'pt' => $n,
            'in' => $n * 72,
            'cm' => $n * 72 / 2.54,
            'mm' => $n * 72 / 25.4,
            'pc' => $n * 12,
            'emu' => $n / 12700,
            default => $n * 0.75,
        };
    }

    /** An integer of at most 15 digits, or null. Never casts anything longer. */
    private static function digits(string $value, bool $signed = false): ?int
    {
        $value = trim($value);

        return preg_match($signed ? '/^-?\d{1,15}$/D' : '/^\d{1,15}$/D', $value) === 1 ? (int) $value : null;
    }

    /** Two decimals at most, and an integer when it is one. */
    private static function round(float $value): int|float
    {
        $r = round($value, 2);

        return $r == (int) $r ? (int) $r : $r;
    }

    private static function child(DOMElement $parent, string $localName): ?DOMElement
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === $localName) {
                return $child;
            }
        }

        return null;
    }
}
