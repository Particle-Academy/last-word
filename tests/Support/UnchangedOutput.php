<?php

declare(strict_types=1);

namespace LastWord\Tests\Support;

use LastWord\Agent;

/**
 * Documents whose written output must not move when first-page bands, field
 * tokens and `meta` arrive (0.16.0), and a digest of what 0.15.0 wrote for each.
 *
 * The digest covers every part's NAME, its POSITION in the archive and the
 * SHA-256 of its CONTENT -- not the zip bytes, which also carry libzip's own
 * framing and would make the pin a claim about the platform rather than the
 * writer. Each golden was produced by running released 0.15.0 (commit 4172ed5),
 * never by running the code it now checks.
 */
final class UnchangedOutput
{
    /** @return array<string, array{doc: array<string, mixed>, options: array<string, mixed>}> */
    public static function cases(): array
    {
        $body = [['type' => 'paragraph', 'runs' => [['text' => 'Body text, with {date} and {author} left literal in the body.']]]];

        return [
            'slot bands with page fields' => ['doc' => [
                'title' => 'Board memo',
                'page' => [
                    'header' => ['left' => 'Q3 | Confidential', 'right' => '{Title} {titles} {date:} {foo}'],
                    'footer' => ['left' => 'Acme', 'right' => 'Page {page} of {pages}'],
                ],
                'blocks' => $body,
            ], 'options' => []],
            'blocks band with page fields' => ['doc' => [
                'page' => ['footer' => ['blocks' => [['type' => 'paragraph', 'align' => 'right', 'runs' => [['text' => 'Page {page} of {pages}', 'bold' => true]]]]]],
                'blocks' => $body,
            ], 'options' => []],
            'title only' => ['doc' => ['title' => 'Only a title', 'blocks' => $body], 'options' => []],
            'letterhead, every page' => ['doc' => [
                'page' => ['footer' => ['center' => '{page}']],
                'blocks' => $body,
            ], 'options' => ['template' => GeneratedLetterhead::bytes(), 'templateParts' => ['styles', 'headers', 'footers', 'page']]],
            'letterhead with its own first page' => ['doc' => [
                'page' => ['footer' => ['center' => '{page}']],
                'blocks' => $body,
            ], 'options' => ['template' => GeneratedLetterhead::bytes(['firstPage' => true]), 'templateParts' => ['headers', 'footers']]],
            'letterhead with an even page' => ['doc' => ['blocks' => $body], 'options' => ['template' => GeneratedLetterhead::bytes(['evenPage' => true]), 'templateParts' => ['headers', 'footers']]],
        ];
    }

    /** Part names in archive order, each with the SHA-256 of its content, hashed together. */
    public static function digest(string $bytes): string
    {
        $lines = [];
        foreach (Package::parts($bytes) as $name => $content) {
            $lines[] = $name . ' ' . hash('sha256', $content);
        }

        return hash('sha256', implode("\n", $lines));
    }

    /** @param  array{doc: array<string, mixed>, options: array<string, mixed>}  $case */
    public static function write(array $case): string
    {
        return Agent::toBytes($case['doc'], $case['options']);
    }
}
