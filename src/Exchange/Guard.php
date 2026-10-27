<?php

declare(strict_types=1);

namespace LastWord\Exchange;

/**
 * Injection guard, v1 (exchange SPEC section 10): strip the characters
 * fine-print's `AgentReadGuard::INVISIBLE` matches from every string taken
 * from a document, and count one `invisible-chars` finding per maximal run
 * removed.
 *
 * The count is all that is kept. A report never carries the text.
 */
final class Guard
{
    /** Zero-width, bidi embeddings/overrides/isolates, word joiners, BOM, Mongolian vowel separator, Arabic letter mark, Unicode tags. */
    public const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{2069}\x{FEFF}\x{180E}\x{061C}\x{E0000}-\x{E007F}]+/u';

    public const RULE = 'invisible-chars';

    /** @var array<string, int> */
    private array $counts = [];

    public function strip(mixed $s): string
    {
        $s = is_string($s) ? $s : (is_scalar($s) ? (string) $s : '');
        if (! mb_check_encoding($s, 'UTF-8')) {
            $s = mb_scrub($s, 'UTF-8');
        }
        $n = 0;
        $out = preg_replace(self::INVISIBLE, '', $s, -1, $n);
        if ($n > 0) {
            $this->counts[self::RULE] = ($this->counts[self::RULE] ?? 0) + $n;
        }

        return $out ?? $s;
    }

    /** @return array<string, int> rule => count */
    public function counts(): array
    {
        return $this->counts;
    }

    /** Copy the findings into a report. */
    public function into(Report $report): void
    {
        foreach ($this->counts as $rule => $n) {
            $report->finding($rule, $n);
        }
    }
}
