<?php

declare(strict_types=1);

use LastWord\Agent;
use LastWord\Exceptions\SchemaException;

/*
 * Spec vector 4: validate flags missing blocks + out-of-range heading
 * levels; repair clamps levels to 6, coerces "text" block strings, drops
 * unknown block types with the error retained, defaults missing blocks
 * to [].
 */

it('validates the canonical document without errors', function () {
    expect(Agent::validate(lwCanonical()))->toBe([]);
});

it('reports a missing blocks key', function () {
    $errors = Agent::validate([]);

    expect($errors)->not->toBeEmpty()
        ->and($errors[0]['path'])->toBe('blocks')
        ->and($errors[0]['message'])->toContain('blocks');
});

it('rejects heading level 9', function () {
    $errors = Agent::validate([
        'blocks' => [
            ['type' => 'heading', 'level' => 9, 'runs' => [['text' => 'Too deep']]],
        ],
    ]);

    expect($errors)->toHaveCount(1)
        ->and($errors[0]['path'])->toBe('blocks[0].level')
        ->and($errors[0]['message'])->toContain('9');
});

it('repairs heading level 9 by clamping to 6', function () {
    $result = Agent::validateAndRepair([
        'blocks' => [
            ['type' => 'heading', 'level' => 9, 'runs' => [['text' => 'Too deep']]],
        ],
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['schema']['blocks'][0]['level'])->toBe(6)
        ->and($result['errors'])->not->toBeEmpty();
});

it('repairs the "text" string shorthand into runs', function () {
    $result = Agent::validateAndRepair([
        'blocks' => [
            ['type' => 'paragraph', 'text' => 'hello world'],
            ['type' => 'heading', 'level' => 2, 'text' => 'a heading'],
        ],
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['schema']['blocks'][0]['runs'])->toBe([['text' => 'hello world']])
        ->and($result['schema']['blocks'][1]['runs'])->toBe([['text' => 'a heading']]);
});

it('coerces bare string runs and bare string blocks', function () {
    $result = Agent::validateAndRepair([
        'blocks' => [
            'just a string block',
            ['type' => 'paragraph', 'runs' => ['a plain string run']],
        ],
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['schema']['blocks'][0])->toBe(['type' => 'paragraph', 'runs' => [['text' => 'just a string block']]])
        ->and($result['schema']['blocks'][1]['runs'])->toBe([['text' => 'a plain string run']]);
});

it('drops unknown block types but retains the error', function () {
    $result = Agent::validateAndRepair([
        'blocks' => [
            ['type' => 'paragraph', 'runs' => [['text' => 'keep me']]],
            ['type' => 'hologram', 'runs' => [['text' => 'drop me']]],
        ],
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['schema']['blocks'])->toHaveCount(1)
        ->and($result['schema']['blocks'][0]['type'])->toBe('paragraph');

    $messages = implode(' | ', array_column($result['errors'], 'message'));
    expect($messages)->toContain('hologram');
});

it('defaults missing blocks to an empty list', function () {
    $result = Agent::validateAndRepair(['title' => 'No body yet']);

    expect($result['ok'])->toBeTrue()
        ->and($result['schema']['blocks'])->toBe([])
        ->and($result['errors'])->not->toBeEmpty();
});

it('flags bad run colors and image sources', function () {
    $errors = Agent::validate([
        'blocks' => [
            ['type' => 'paragraph', 'runs' => [['text' => 'x', 'color' => 'red']]],
            ['type' => 'image', 'src' => 'https://example.com/pic.png'],
        ],
    ]);

    $paths = array_column($errors, 'path');
    expect($paths)->toContain('blocks[0].runs[0].color')
        ->and($paths)->toContain('blocks[1].src');
});

it('throws SchemaException with structured errors from toBytes on invalid input', function () {
    try {
        Agent::toBytes(['blocks' => [['type' => 'heading', 'level' => 9, 'runs' => [['text' => 'x']]]]]);
        $this->fail('Expected SchemaException');
    } catch (SchemaException $e) {
        expect($e->errors)->not->toBeEmpty()
            ->and($e->errors[0])->toHaveKeys(['path', 'message']);
    }
});
