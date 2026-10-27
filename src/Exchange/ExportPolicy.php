<?php

declare(strict_types=1);

namespace LastWord\Exchange;

/**
 * Which exports the HOST has turned on (exchange SPEC section 13, pinned by
 * `exchange/policy`). Exports are OFF by default, every target, every reader:
 * a null policy and an empty `allow` both allow nothing, so an upgrade never
 * hands an agent a new file-producing tool on its own.
 *
 * A policy is built by the host, never from agent input. Nothing an agent
 * passes as an export option can widen it: `allow` among the options is an
 * unknown option and throws.
 *
 * The check is the shared one, for every reader, so the whole conformance
 * table runs here; last-word itself supports `markdown`, `text`, `html`,
 * `docx` and (once the PDF bridge ships) `pdf`.
 */
final class ExportPolicy
{
    public const TARGETS = ['csv', 'markdown', 'text', 'html', 'outline', 'xlsx', 'docx', 'pptx', 'pdf', 'ods', 'odt'];

    /** Supported targets per reader, in the reader's order. */
    public const READERS = [
        'holy-sheet' => ['csv', 'markdown', 'xlsx', 'pdf', 'docx'],
        'last-word' => ['markdown', 'text', 'html', 'docx', 'pdf'],
        'dark-slide' => ['markdown', 'outline', 'text', 'pptx', 'pdf'],
        'fine-print' => ['text', 'markdown'],
    ];

    private const KEYS = ['allow', 'limits', 'pdf', 'csv', 'includeHidden', 'modules'];

    /** What an agent may pass. Nothing here can enable a target. */
    public const OPTION_KEYS = ['selector', 'headerRows', 'display'];

    /** @var list<string> */
    public readonly array $allow;

    /** @var array<string, int> the effective content limits */
    public readonly array $limits;

    /** @var array<string, bool> host-injected module presence */
    public readonly array $modules;

    /**
     * @param  array<string, mixed>|\stdClass  $policy  `{allow?, limits?, pdf?, csv?, includeHidden?, modules?}`
     *
     * @throws OptionsInvalid on an unknown key, an unknown target, or a malformed value
     */
    public function __construct(array|\stdClass $policy = [])
    {
        $p = Content::props($policy);
        foreach (array_keys($p) as $key) {
            if (! in_array($key, self::KEYS, true)) {
                throw new OptionsInvalid('unknown export policy key "'.$key.'"');
            }
        }
        $allow = $p['allow'] ?? [];
        if (! Content::isList($allow)) {
            throw new OptionsInvalid('allow must be a list of targets');
        }
        foreach ($allow as $target) {
            if (! in_array($target, self::TARGETS, true)) {
                throw new OptionsInvalid('unknown export target in allow');
            }
        }
        $this->allow = array_values($allow);
        $this->limits = Content::limits(array_key_exists('limits', $p) ? ['limits' => $p['limits']] : null);

        foreach (['pdf', 'csv'] as $key) {
            if (array_key_exists($key, $p) && ! Content::isObject($p[$key])) {
                throw new OptionsInvalid($key.' must be an object');
            }
        }
        if (array_key_exists('csv', $p)) {
            foreach (Content::props($p['csv']) as $k => $v) {
                if ($k !== 'escapeFormulas' || ! is_bool($v)) {
                    throw new OptionsInvalid('csv takes only escapeFormulas (boolean)');
                }
            }
        }
        if (array_key_exists('includeHidden', $p) && ! is_bool($p['includeHidden'])) {
            throw new OptionsInvalid('includeHidden must be a boolean');
        }
        $modules = [];
        if (array_key_exists('modules', $p)) {
            if (! Content::isObject($p['modules'])) {
                throw new OptionsInvalid('modules must be an object');
            }
            foreach (Content::props($p['modules']) as $name => $present) {
                if (! is_bool($present)) {
                    throw new OptionsInvalid('a module presence must be a boolean');
                }
                $modules[$name] = $present;
            }
        }
        $this->modules = $modules;
    }

    /** A policy from what a host handed over: null stays null (nothing allowed). */
    public static function from(mixed $policy): ?self
    {
        return match (true) {
            $policy === null => null,
            $policy instanceof self => $policy,
            is_array($policy), $policy instanceof \stdClass => new self($policy),
            default => throw new OptionsInvalid('a policy must be an object or null'),
        };
    }

    /**
     * Agent-supplied export options: only `selector`, `headerRows`, `display`.
     *
     * @throws OptionsInvalid
     */
    public static function assertOptions(mixed $options): void
    {
        if ($options === null) {
            return;
        }
        if (! Content::isObject($options)) {
            throw new OptionsInvalid('options must be an object');
        }
        foreach (array_keys(Content::props($options)) as $key) {
            if (! in_array($key, self::OPTION_KEYS, true)) {
                throw new OptionsInvalid('unknown export option "'.$key.'"; an export option can never enable a target');
            }
        }
    }

    /** The optional module a target needs on a reader, if any. */
    public static function needs(string $reader, string $target): ?string
    {
        return match (true) {
            $target === 'pdf' => 'fine-print',
            $reader === 'holy-sheet' && $target === 'docx' => 'last-word',
            default => null,
        };
    }

    /**
     * The checks in order: unsupported, disabled, unavailable, refused, limit.
     *
     * @param  array<string, bool>  $environment  what this language's detection found
     * @return array{ok: true}|array{ok: false, refused: array<string, string>}
     */
    public static function check(string $reader, string $target, ?self $policy, array $environment, ?string $inputRefused = null, ?string $limitCode = null): array
    {
        $supported = self::READERS[$reader] ?? throw new OptionsInvalid('unknown reader');
        if (! in_array($target, $supported, true)) {
            return self::refuse('export_unsupported', $target);
        }
        if ($policy === null || ! in_array($target, $policy->allow, true)) {
            return self::refuse('export_disabled', $target);
        }
        $module = self::needs($reader, $target);
        if ($module !== null && ! ($policy->modules[$module] ?? $environment[$module] ?? false)) {
            return self::refuse('export_unavailable', $target, ['missing' => $module]);
        }
        if ($inputRefused !== null) {
            return self::refuse('export_refused', $target, ['cause' => $inputRefused]);
        }
        if ($limitCode !== null) {
            return self::refuse($limitCode, $target);
        }

        return ['ok' => true];
    }

    /**
     * Supported, allowed and available, in the reader's order: exactly the
     * `target` enum an agent is shown.
     *
     * @param  array<string, bool>  $environment
     * @return list<string>
     */
    public static function enabledTargets(string $reader, ?self $policy, array $environment): array
    {
        return array_values(array_filter(
            self::READERS[$reader] ?? [],
            fn (string $t) => self::check($reader, $t, $policy, $environment)['ok'],
        ));
    }

    /**
     * `checkExport(request)` as exchange/policy states it.
     *
     * @param  array<string, mixed>  $request  `{reader, target, policy, options?, environment, inputRefused?, limitCode?}`
     * @return array<string, mixed>
     */
    public static function checkExport(array $request): array
    {
        $policy = self::from($request['policy'] ?? null);
        self::assertOptions($request['options'] ?? null);

        return self::check(
            (string) $request['reader'],
            (string) $request['target'],
            $policy,
            self::environment($request['environment'] ?? []),
            isset($request['inputRefused']) ? (string) $request['inputRefused'] : null,
            isset($request['limitCode']) ? (string) $request['limitCode'] : null,
        );
    }

    /**
     * `enabledTargets(request)` as exchange/policy states it.
     *
     * @param  array<string, mixed>  $request  `{reader, policy, environment}`
     * @return list<string>
     */
    public static function enabledTargetsFor(array $request): array
    {
        return self::enabledTargets((string) $request['reader'], self::from($request['policy'] ?? null), self::environment($request['environment'] ?? []));
    }

    /** @return array<string, bool> */
    private static function environment(mixed $env): array
    {
        $out = [];
        foreach (Content::props($env) as $k => $v) {
            $out[$k] = $v === true;
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $extra
     * @return array{ok: false, refused: array<string, string>}
     */
    private static function refuse(string $reason, string $target, array $extra = []): array
    {
        return ['ok' => false, 'refused' => ['reason' => $reason, 'target' => $target] + $extra];
    }
}
