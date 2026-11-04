<?php

declare(strict_types=1);

namespace LastWord\Tests\Support;

use DOMDocument;
use DOMElement;
use LastWord\Agent;
use RuntimeException;
use ZipArchive;

/**
 * The `last-word/docx-constructs` projections added in fancy-conformance 0.41.0
 * (first-page bands, field tokens, `meta`), and the row-input convention they
 * introduced, kept free of Pest so the script that generated the goldens and
 * the test that checks them run the SAME code.
 *
 * A row's input is `{doc, template?, options?}`. `template`, when present, is
 * itself a document model: it is written with toBytes() and those bytes are
 * the `template` write option. `options` are further write options
 * (`templateParts`, `templateFirstPageOnly`), passed as given. Every function
 * writes the document that way.
 */
final class ConformanceExtract
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @param  array<string, mixed>  $input */
    public static function write(array $input): string
    {
        $options = is_array($input['options'] ?? null) ? $input['options'] : [];
        if (is_array($input['template'] ?? null)) {
            $options['template'] = Agent::toBytes($input['template']);
        }

        return Agent::toBytes($input['doc'], $options);
    }

    /** @return array<string, string> entry name => bytes, in archive order */
    public static function parts(string $bytes): array
    {
        $tmp = \lwTemp('lw-conf-', '.docx');
        file_put_contents($tmp, $bytes);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('could not open the written document');
        }
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $parts[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        @unlink($tmp);

        return $parts;
    }

    /**
     * `bandParts`: {titlePg, evenAndOddHeaders, references, parts}.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function bandParts(array $input): array
    {
        $parts = self::parts(self::write($input));
        $sectPr = self::bodySectPr($parts);
        $targets = self::bandTargets($parts);

        $references = [];
        foreach ($sectPr?->childNodes ?? [] as $node) {
            if ($node instanceof DOMElement && in_array($node->localName, ['headerReference', 'footerReference'], true)) {
                $target = $targets[$node->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')] ?? null;
                $references[] = [
                    'kind' => substr($node->localName, 0, -strlen('Reference')),
                    'type' => $node->getAttributeNS(self::W, 'type'),
                    'target' => $target,
                    'text' => $target !== null ? self::text($parts['word/' . $target] ?? null) : null,
                ];
            }
        }
        $settings = self::root($parts['word/settings.xml'] ?? null);
        $names = array_values(array_filter(
            array_keys($parts),
            static fn (string $name): bool => preg_match('#^word/(header|footer)[^/]*\.xml$#', $name) === 1,
        ));
        sort($names);

        return [
            'titlePg' => $sectPr !== null && self::child($sectPr, 'titlePg') !== null,
            'evenAndOddHeaders' => $settings !== null && self::child($settings, 'evenAndOddHeaders') !== null,
            'references' => $references,
            'parts' => $names,
        ];
    }

    /**
     * `sections`: every w:sectPr of word/document.xml in document order, each as
     * {props, references}: its ordered properties without its header and footer
     * references, and those references as {kind, type, target, tabs}, `tabs` the
     * w:pos of each tab stop in the part's first w:tabs.
     *
     * @param  array<string, mixed>  $input
     * @return list<array{props: list<mixed>, references: list<array<string, mixed>>}>
     */
    public static function sections(array $input): array
    {
        $parts = self::parts(self::write($input));
        $targets = self::bandTargets($parts);
        $root = self::root($parts['word/document.xml'] ?? null);
        $found = [];
        foreach ($root?->getElementsByTagNameNS(self::W, 'sectPr') ?? [] as $sectPr) {
            $found[] = $sectPr;
        }
        $out = [];
        foreach ($found as $sectPr) {
            $references = [];
            $props = [];
            foreach ($sectPr->childNodes as $node) {
                if (!$node instanceof DOMElement) {
                    continue;
                }
                if (in_array($node->localName, ['headerReference', 'footerReference'], true)) {
                    $target = $targets[$node->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')] ?? null;
                    $part = self::root($parts['word/' . $target] ?? null);
                    $tabs = [];
                    $stops = $part?->getElementsByTagNameNS(self::W, 'tabs')->item(0);
                    foreach ($stops?->childNodes ?? [] as $tab) {
                        if ($tab instanceof DOMElement) {
                            $tabs[] = $tab->getAttributeNS(self::W, 'pos');
                        }
                    }
                    $references[] = [
                        'kind' => $node->localName === 'headerReference' ? 'header' : 'footer',
                        'type' => $node->getAttributeNS(self::W, 'type'),
                        'target' => $target,
                        'tabs' => $tabs,
                    ];

                    continue;
                }
                $attrs = [];
                foreach ($node->attributes as $attr) {
                    $attrs[$attr->localName] = $attr->value;
                }
                $props[] = [$node->localName, $attrs === [] ? true : $attrs];
            }
            $out[] = ['props' => $props, 'references' => $references];
        }

        return $out;
    }

    /**
     * `bandFields`: for each header/footer part the section references, in
     * reference order and once each, {target, fields}: every complex field in
     * the part as {instruction, result}.
     *
     * @param  array<string, mixed>  $input
     * @return list<array{target: string, fields: list<array{instruction: string, result: string}>}>
     */
    public static function bandFields(array $input): array
    {
        $parts = self::parts(self::write($input));
        $sectPr = self::bodySectPr($parts);
        $targets = self::bandTargets($parts);
        $seen = [];
        $out = [];
        foreach ($sectPr?->childNodes ?? [] as $node) {
            if (!$node instanceof DOMElement || !in_array($node->localName, ['headerReference', 'footerReference'], true)) {
                continue;
            }
            $target = $targets[$node->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')] ?? null;
            if ($target === null || isset($seen[$target])) {
                continue;
            }
            $seen[$target] = true;
            $out[] = ['target' => $target, 'fields' => self::fields(self::root($parts['word/' . $target] ?? null))];
        }

        return $out;
    }

    /**
     * `coreProps`: null without docProps/core.xml; otherwise {contentType,
     * relationship, elements}, elements every child of cp:coreProperties in
     * order as [prefixed name, text].
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>|null
     */
    public static function coreProps(array $input): ?array
    {
        $parts = self::parts(self::write($input));
        $core = self::root($parts['docProps/core.xml'] ?? null);
        if ($core === null) {
            return null;
        }
        $contentType = null;
        foreach (self::root($parts['[Content_Types].xml'])?->childNodes ?? [] as $o) {
            if ($o instanceof DOMElement && $o->localName === 'Override' && $o->getAttribute('PartName') === '/docProps/core.xml') {
                $contentType = $o->getAttribute('ContentType');
            }
        }
        $relationship = null;
        foreach (self::root($parts['_rels/.rels'])?->childNodes ?? [] as $rel) {
            if ($rel instanceof DOMElement && str_ends_with($rel->getAttribute('Type'), '/core-properties')) {
                $relationship = $rel->getAttribute('Target');
            }
        }
        $elements = [];
        foreach ($core->childNodes as $el) {
            if ($el instanceof DOMElement) {
                $elements[] = [$el->nodeName, $el->textContent];
            }
        }

        return ['contentType' => $contentType, 'relationship' => $relationship, 'elements' => $elements];
    }

    /** @return list<array{instruction: string, result: string}> */
    private static function fields(?DOMElement $root): array
    {
        $out = [];
        if ($root === null) {
            return $out;
        }
        $state = null;
        $instruction = $result = '';
        foreach ($root->getElementsByTagName('*') as $el) {
            if ($el->localName === 'fldChar') {
                $type = $el->getAttributeNS(self::W, 'fldCharType');
                if ($type === 'begin') {
                    [$state, $instruction, $result] = ['instruction', '', ''];
                } elseif ($type === 'separate') {
                    $state = 'result';
                } elseif ($type === 'end' && $state !== null) {
                    $out[] = ['instruction' => $instruction, 'result' => $result];
                    $state = null;
                }
            } elseif ($el->localName === 'instrText' && $state === 'instruction') {
                $instruction .= $el->textContent;
            } elseif ($el->localName === 't' && $state === 'result') {
                $result .= $el->textContent;
            }
        }

        return $out;
    }

    /** @param  array<string, string>  $parts */
    private static function bodySectPr(array $parts): ?DOMElement
    {
        $document = self::root($parts['word/document.xml'] ?? null);
        $body = $document !== null ? self::child($document, 'body') : null;

        return $body !== null ? self::child($body, 'sectPr') : null;
    }

    /**
     * Header/footer relationship id => target, from the document's rels.
     *
     * @param  array<string, string>  $parts
     * @return array<string, string>
     */
    private static function bandTargets(array $parts): array
    {
        $out = [];
        foreach (self::root($parts['word/_rels/document.xml.rels'] ?? null)?->childNodes ?? [] as $rel) {
            if ($rel instanceof DOMElement && preg_match('#/(header|footer)$#', $rel->getAttribute('Type')) === 1) {
                $out[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            }
        }

        return $out;
    }

    private static function text(?string $xml): ?string
    {
        $root = self::root($xml);
        if ($root === null) {
            return null;
        }
        $text = '';
        foreach ($root->getElementsByTagNameNS(self::W, 't') as $t) {
            $text .= $t->textContent;
        }

        return $text;
    }

    private static function root(?string $xml): ?DOMElement
    {
        if ($xml === null) {
            return null;
        }
        $dom = new DOMDocument();
        $dom->loadXML($xml);

        return $dom->documentElement;
    }

    private static function child(DOMElement $parent, string $name): ?DOMElement
    {
        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }
}
