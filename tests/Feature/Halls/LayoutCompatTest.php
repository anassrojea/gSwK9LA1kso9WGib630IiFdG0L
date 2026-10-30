<?php

use App\Services\Invite\GuestDayState;
use App\Services\Seating\AutoSeater;
use App\Services\Seating\HallLayoutData;

/*
| Backward compatibility of the hall layout (phase 8B): layouts saved in phase
| 7A, with only round tables, keep working unchanged. The fixtures were made
| with the phase 7A code (HallGenerator, HallLayoutData::normalize, AutoSeater,
| GuestDayState::map and the seating PDF's hall SVG) before the new shapes.
*/

function p8bFixture(string $name): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/{$name}.json")), true);
}

function p8bJson(mixed $value): string
{
    return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE);
}

it('normalizes a phase 7A layout exactly as before', function () {
    foreach (p8bFixture('hall-layouts-7a') as $name => $case) {
        expect(p8bJson(HallLayoutData::normalize($case['raw'])))->toBe(p8bJson($case['normalized']), $name)
            // saving it again changes nothing
            ->and(p8bJson(HallLayoutData::normalize($case['normalized'])))->toBe(p8bJson($case['normalized']), $name);
    }
});

it('finds phase 7A layouts valid against the JSON schema', function () {
    foreach (p8bFixture('hall-layouts-7a') as $name => $case) {
        expect(HallLayoutData::schemaErrors($case['normalized']))->toBe([], $name);
    }
});

it('auto-seats a round-only hall exactly as before', function () {
    $outputs = p8bFixture('hall-outputs-7a');
    foreach (p8bFixture('hall-layouts-7a') as $name => $case) {
        expect((new AutoSeater)->plan($case['normalized'], $outputs['families']))->toBe($outputs['plans'][$name], $name);
    }
});

it('draws the same guest mini-map for a round-only hall', function () {
    $outputs = p8bFixture('hall-outputs-7a');
    foreach (p8bFixture('hall-layouts-7a') as $name => $case) {
        $first = $outputs['plans'][$name]['assignments'][0]['table_id'] ?? $case['normalized']['tables'][0]['id'];
        expect(GuestDayState::map($case['normalized'], $first))->toBe($outputs['maps'][$name], $name);
    }
});

it('prints the same hall SVG for a round-only hall', function () {
    $outputs = p8bFixture('hall-outputs-7a');
    foreach (p8bFixture('hall-layouts-7a') as $name => $case) {
        $seated = [];
        foreach ($outputs['plans'][$name]['assignments'] as $a) {
            $seated[$a['table_id']][] = $a['seat_index'];
        }
        // compared without layout whitespace
        $svg = trim(preg_replace(['/\s+/', '/>\s+</'], [' ', '><'], view('seating.hall-svg', ['layout' => $case['normalized'], 'seated' => $seated])->render()));
        expect(strlen($outputs['svg'][$name]) === 40 ? sha1($svg) : $svg)->toBe($outputs['svg'][$name], $name);
    }
});
