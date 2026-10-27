<?php

declare(strict_types=1);

namespace LastWord\Exchange;

/**
 * Portable content to a standalone HTML document: the `html` export.
 *
 * Rendered from VALIDATED portable content, so it inherits the guard (no
 * invisible characters), the closed object set and the link rule. On top of
 * that, everything here is written defensively, because the input is hostile:
 *
 *   - every piece of text and every attribute value is escaped
 *     (htmlspecialchars, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5);
 *   - the only elements are fixed structural ones; no script, style, iframe,
 *     object, form or event handler is ever written, because none is in the
 *     vocabulary below;
 *   - a link is written only when it matches http, https or mailto (checked
 *     again here, not just trusted from validation), with rel="noopener
 *     noreferrer"; any other is reported `link` and its text kept;
 *   - images are inline PNG/JPEG data URIs from the validated `images` map,
 *     never a URL or a path;
 *   - the only style attribute is `text-align` from a closed enum.
 */
final class Html
{
    private const ALIGNS = ['left', 'center', 'right'];

    private Report $report;

    /** @var array<string, array{mime: string, data: string}> */
    private array $images;

    /**
     * @param  array<string, mixed>  $content  portable content that validated
     * @return array{text: string, report: array<string, mixed>}
     */
    public static function fromPortable(array $content, ?string $title = null): array
    {
        $x = new self;
        $x->report = new Report;
        $x->images = is_array($content['images'] ?? null) ? $content['images'] : [];

        $body = '';
        foreach ($content['items'] as $item) {
            $body .= $x->item($item)."\n";
        }
        $head = '<meta charset="utf-8">';
        if ($title !== null && $title !== '') {
            $head .= '<title>'.self::e($title).'</title>';
        }

        return [
            'text' => "<!DOCTYPE html>\n<html><head>".$head."</head>\n<body>\n".$body."</body></html>\n",
            'report' => $x->report->toArray(),
        ];
    }

    public static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** @param array<string, mixed> $item */
    private function item(array $item): string
    {
        switch ($item['type']) {
            case 'text':
                $tag = $item['role'] === 'heading' ? 'h'.max(1, min(6, (int) $item['level'])) : 'p';

                return '<'.$tag.'>'.$this->runs($item['runs']).'</'.$tag.'>';
            case 'list':
                return $this->listHtml($item['items'], ($item['ordered'] ?? false) === true);
            case 'image':
                $img = $this->images[$item['ref']] ?? null;
                if ($img === null || ! in_array($img['mime'], ['image/png', 'image/jpeg'], true)) {
                    $this->report->add('image');

                    return '';
                }
                $attrs = ' src="data:'.$img['mime'].';base64,'.self::e($img['data']).'"';
                $attrs .= ' alt="'.self::e((string) ($item['alt'] ?? '')).'"';
                foreach (['widthPx' => 'width', 'heightPx' => 'height'] as $key => $attr) {
                    if (isset($item[$key])) {
                        $attrs .= ' '.$attr.'="'.(int) round((float) $item[$key]).'"';
                    }
                }

                return '<img'.$attrs.'>';
            case 'table':
                return $this->table($item);
        }

        return '';
    }

    /** @param list<array<string, mixed>> $items */
    private function listHtml(array $items, bool $ordered): string
    {
        $tag = $ordered ? 'ol' : 'ul';
        $out = '<'.$tag.'>';
        foreach ($items as $entry) {
            $out .= '<li>'.$this->runs($entry['runs']);
            if (! empty($entry['children'])) {
                $out .= $this->listHtml($entry['children'], $ordered);
            }
            $out .= '</li>';
        }

        return $out.'</'.$tag.'>';
    }

    /** @param array<string, mixed> $table */
    private function table(array $table): string
    {
        $headerRows = (int) ($table['headerRows'] ?? 0);
        $out = '<table>';
        if (isset($table['caption'])) {
            $out .= '<caption>'.self::e($table['caption']).'</caption>';
        }
        $columns = $table['columns'] ?? [];
        if (array_filter($columns, fn ($c) => isset($c['width'])) !== []) {
            $this->report->add('layout-not-preserved'); // relative widths are not carried
        }
        foreach ($table['rows'] as $r => $row) {
            if ($r === 0 && $headerRows > 0) {
                $out .= '<thead>';
            }
            if ($r === $headerRows) {
                $out .= '<tbody>';
            }
            $cellTag = $r < $headerRows ? 'th' : 'td';
            $out .= '<tr>';
            foreach ($row['cells'] as $cell) {
                $attrs = '';
                foreach (['colSpan' => 'colspan', 'rowSpan' => 'rowspan'] as $key => $attr) {
                    if (isset($cell[$key]) && (int) $cell[$key] > 1) {
                        $attrs .= ' '.$attr.'="'.(int) $cell[$key].'"';
                    }
                }
                if (in_array($cell['align'] ?? null, self::ALIGNS, true)) {
                    $attrs .= ' style="text-align:'.$cell['align'].'"';
                }
                if ($cell['t'] !== 'empty' && $cell['t'] !== 'string') {
                    $this->report->add('typed-as-text');
                }
                $display = $cell['text'] ?? Content::canonical($cell['t'], $cell['v'] ?? null);
                $inner = isset($cell['runs']) ? $this->runs($cell['runs']) : self::lines($display);
                $out .= '<'.$cellTag.$attrs.'>'.$inner.'</'.$cellTag.'>';
            }
            $out .= '</tr>';
            if ($r === $headerRows - 1) {
                $out .= '</thead>';
            }
        }
        if (count($table['rows']) > $headerRows) {
            $out .= '</tbody>';
        }

        return $out.'</table>';
    }

    /** @param list<array<string, mixed>> $runs */
    private function runs(array $runs): string
    {
        $out = '';
        foreach ($runs as $run) {
            $s = self::lines($run['text']);
            if (($run['code'] ?? false) === true) {
                $s = '<code>'.$s.'</code>';
            }
            if (($run['strike'] ?? false) === true) {
                $s = '<s>'.$s.'</s>';
            }
            if (($run['underline'] ?? false) === true) {
                $s = '<u>'.$s.'</u>';
            }
            if (($run['italic'] ?? false) === true) {
                $s = '<em>'.$s.'</em>';
            }
            if (($run['bold'] ?? false) === true) {
                $s = '<strong>'.$s.'</strong>';
            }
            if (isset($run['link'])) {
                if (Content::linkAllowed($run['link'])) {
                    $s = '<a href="'.self::e($run['link']).'" rel="noopener noreferrer">'.$s.'</a>';
                } else {
                    $this->report->add('link');
                }
            }
            $out .= $s;
        }

        return $out;
    }

    /** Escaped text with its line breaks kept. */
    private static function lines(string $text): string
    {
        return str_replace("\n", '<br>', self::e(str_replace(["\r\n", "\r"], "\n", $text)));
    }
}
