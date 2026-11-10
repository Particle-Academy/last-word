<?php

declare(strict_types=1);

/**
 * The agent guide names every public Agent method, or the method is on the
 * allowlist below with the reason it is left out.
 *
 * A method the guide never names is a method no model reading the guide will
 * use. This fails the build when a release adds one and the guide is not
 * brought along with it.
 *
 * "Names" means written as code: `Agent::x`, `Agent.x` or a backticked `x`, so
 * the English word "read" in a sentence does not count as documenting read().
 * The Node and Python ports carry the same test over their own surfaces.
 */

/** @return array<string,string> method => why the guide does not name it */
function lastWordGuideAllowlist(): array
{
    return [
        'guide' => 'the guide itself: a model reading it already has it',
        'version' => 'package metadata; it has no bearing on building or reading a document',
    ];
}

/** @return list<string> */
function lastWordAgentPublicMethods(): array
{
    $methods = (new ReflectionClass(LastWord\Agent::class))->getMethods(ReflectionMethod::IS_PUBLIC);

    return array_values(array_map(fn (ReflectionMethod $m): string => $m->getName(), $methods));
}

function lastWordGuideNames(string $guide, string $method): bool
{
    $name = preg_quote($method, '/');

    return preg_match('/(Agent::|Agent\.|`)'.$name.'\b/', $guide) === 1;
}

it('names every public Agent method, or allowlists it with a reason', function () {
    $guide = LastWord\Agent::guide();
    $allow = lastWordGuideAllowlist();

    $missing = array_values(array_filter(
        lastWordAgentPublicMethods(),
        fn (string $m): bool => ! isset($allow[$m]) && ! lastWordGuideNames($guide, $m),
    ));

    expect($missing)->toBe([], 'agent-guide/professional-documents.md never names: '.implode(', ', $missing));
});

it('allowlists only methods that exist, each with a reason, none the guide already names', function () {
    $guide = LastWord\Agent::guide();
    $methods = lastWordAgentPublicMethods();

    foreach (lastWordGuideAllowlist() as $method => $reason) {
        expect($methods)->toContain($method)
            ->and(trim($reason))->not->toBe('')
            ->and(lastWordGuideNames($guide, $method))->toBeFalse("{$method} is named in the guide; drop it from the allowlist");
    }
});
