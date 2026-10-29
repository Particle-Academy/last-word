<?php

declare(strict_types=1);

namespace LastWord\Tests\Support;

use DOMDocument;
use ZipArchive;

/**
 * A written `.docx` opened as parts, and the package-consistency check the
 * letterhead tests (last-word#5) and the blocks-band tests (last-word#6) share.
 */
final class Package
{
    /** @return array<string, string> entry name => bytes */
    public static function parts(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'lw-pkg') . '.docx';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive();
        expect($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS))->toBeTrue();
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $parts[$name] = (string) $zip->getFromName($name);
        }
        $zip->close();
        @unlink($path);

        return $parts;
    }

    /**
     * The package is self-consistent: every internal relationship target exists,
     * every part has a content type, no relationship id repeats in a part, every
     * r:* reference in a part names a relationship its own .rels declares, and
     * drawing ids are unique across the package.
     *
     * @param  array<string, string>  $parts
     */
    public static function assertConsistent(array $parts): void
    {
        $ct = new DOMDocument();
        $ct->loadXML($parts['[Content_Types].xml']);
        $defaults = $overrides = [];
        foreach ($ct->getElementsByTagName('Default') as $el) {
            $defaults[strtolower($el->getAttribute('Extension'))] = $el->getAttribute('ContentType');
        }
        foreach ($ct->getElementsByTagName('Override') as $el) {
            $overrides[$el->getAttribute('PartName')] = $el->getAttribute('ContentType');
        }
        foreach (array_keys($parts) as $name) {
            if ($name === '[Content_Types].xml') {
                continue;
            }
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            expect(isset($overrides['/' . $name]) || isset($defaults[$ext]))->toBeTrue("no content type for {$name}");
        }
        foreach ($overrides as $partName => $type) {
            expect($parts)->toHaveKey(ltrim($partName, '/'));
        }
        foreach ($parts as $name => $xml) {
            if (! str_ends_with($name, '.rels')) {
                continue;
            }
            $source = str_replace('_rels/', '', substr($name, 0, -5));
            $base = str_contains($source, '/') ? substr($source, 0, strrpos($source, '/') + 1) : '';
            $rels = new DOMDocument();
            $rels->loadXML($xml);
            $ids = [];
            foreach ($rels->getElementsByTagName('Relationship') as $rel) {
                $id = $rel->getAttribute('Id');
                expect($ids)->not->toHaveKey($id);
                $ids[$id] = true;
                if ($rel->getAttribute('TargetMode') === 'External') {
                    continue;
                }
                expect($parts)->toHaveKey($base . $rel->getAttribute('Target'));
            }
            // Every r:* reference in the source part names a declared relationship.
            if ($source !== '' && isset($parts[$source]) && str_ends_with($source, '.xml')) {
                preg_match_all('/\sr:(?:id|embed|link)="([^"]+)"/', $parts[$source], $used);
                foreach ($used[1] as $id) {
                    expect($ids)->toHaveKey($id);
                }
            }
        }
        // A part that uses an r:* reference has a .rels at all.
        foreach ($parts as $name => $xml) {
            if (preg_match('#^word/[^/]+\.xml$#', $name) === 1 && preg_match('/\sr:(?:id|embed|link)="/', $xml) === 1) {
                expect($parts)->toHaveKey('word/_rels/' . substr($name, strlen('word/')) . '.rels');
            }
        }
        // Drawing ids are unique across the package.
        preg_match_all('/<wp:docPr id="(\d+)"/', implode('', $parts), $drawingIds);
        expect(count($drawingIds[1]))->toBe(count(array_unique($drawingIds[1])));
    }
}
