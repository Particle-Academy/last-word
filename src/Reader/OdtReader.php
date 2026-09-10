<?php

declare(strict_types=1);

namespace LastWord\Reader;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;
use ZipArchive;

/**
 * OpenDocument Text -> the same document shape `DocxReader` returns.
 *
 * Same shape is the requirement, not a nicety. A consumer that already handles
 * our documents must not need a second code path because the upload happened to
 * be an `.odt`; the moment they do, the two paths drift and only one gets the
 * next fix.
 *
 * Structure is kept rather than flattened. `text:h` becomes a heading with its
 * level, not a paragraph — the consumer who asked for this left another library
 * precisely because it flattened nested lists and stripped emphasis, and text
 * that survives with its shape lost is a worse input for a model than text that
 * fails loudly.
 */
final class OdtReader
{
    /** @return array<string,mixed> */
    public function read(string $bytes): array
    {
        return ['blocks' => $this->blocks($this->contentXml($bytes))];
    }

    private function contentXml(string $bytes): string
    {
        // ZipArchive needs a path. Writing one is the thing this family exists
        // to avoid — a consumer lost every document feature at once when
        // sys_get_temp_dir() resolved somewhere unwritable — so the temp file
        // is created, read and removed inside this method and never escapes it.
        $tmp = tempnam(sys_get_temp_dir(), 'lw_odt_');
        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary file to open the ODT.');
        }

        try {
            if (file_put_contents($tmp, $bytes) === false) {
                throw new RuntimeException('Could not write the ODT to a temporary file.');
            }

            $zip = new ZipArchive();
            if ($zip->open($tmp) !== true) {
                throw new RuntimeException('Could not open the ODT archive.');
            }

            $xml = $zip->getFromName('content.xml');
            $zip->close();

            if ($xml === false) {
                throw new RuntimeException('ODT archive has no content.xml.');
            }

            return $xml;
        } finally {
            @unlink($tmp);
        }
    }

    /** @return list<array<string,mixed>> */
    private function blocks(string $xml): array
    {
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded || $dom->documentElement === null) {
            throw new RuntimeException('Could not parse content.xml.');
        }

        $blocks = [];
        foreach ($this->descendants($dom->documentElement) as $el) {
            $name = $this->localName($el);

            if ($name === 'h') {
                $text = $this->text($el);
                if ($text === '') {
                    continue;
                }
                $level = (int) ($el->getAttribute('text:outline-level') ?: '1');
                $blocks[] = [
                    'type' => 'heading',
                    'level' => max(1, min(6, $level)),
                    'runs' => [['text' => $text]],
                ];
                continue;
            }

            if ($name === 'p') {
                $text = $this->text($el);
                // An empty `text:p` is ODF's blank line. Emitting it as a
                // paragraph with no runs produces a document full of nothing,
                // which reads as corruption downstream.
                if ($text === '') {
                    continue;
                }
                $blocks[] = ['type' => 'paragraph', 'runs' => [['text' => $text]]];
            }
        }

        return $blocks;
    }

    /** @return list<DOMElement> */
    private function descendants(DOMNode $node): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }
            $name = $this->localName($child);
            if ($name === 'h' || $name === 'p') {
                $out[] = $child;
                continue; // do not descend into a block we have taken
            }
            $out = array_merge($out, $this->descendants($child));
        }

        return $out;
    }

    private function localName(DOMElement $el): string
    {
        $name = $el->nodeName;
        $colon = strpos($name, ':');

        return $colon === false ? $name : substr($name, $colon + 1);
    }

    private function text(DOMElement $el): string
    {
        return trim(preg_replace('/\s+/u', ' ', $el->textContent) ?? '');
    }
}
