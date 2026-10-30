<?php

namespace App\Services\Ai;

use App\Services\Dashboard\PhoneNumber;
use App\Services\Wizard\GuestListParser;

/**
 * A canned AiWriter for tests and local demos (AI_FAKE=true): never calls the
 * API. Answers in the requested language; a test can set the stop reason
 * ("refusal", "max_tokens"), the data, or make it fail.
 */
class FakeAiWriter implements AiWriter
{
    public ?string $stopReason = 'end_turn';

    /** replaces the canned answer when set */
    public ?array $data = null;

    public ?string $fail = null;

    public int $inputTokens = 1200;

    public int $outputTokens = 600;

    /** @var list<array{kind: string, input: array}> */
    public array $calls = [];

    public function write(string $kind, array $input): AiResult
    {
        $this->calls[] = ['kind' => $kind, 'input' => $input];

        if ($this->fail) {
            throw new AiUnavailable($this->fail);
        }

        $data = $this->data ?? $this->canned($kind, $input);
        $finished = in_array($this->stopReason, ['end_turn', 'stop_sequence'], true);
        $text = $this->stopReason === 'max_tokens'
            ? mb_substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 40)
            : ($this->stopReason === 'refusal' ? '' : json_encode($data, JSON_UNESCAPED_UNICODE));

        return new AiResult(
            model: (string) config("ai.models.{$kind}"),
            stopReason: $this->stopReason,
            text: $text,
            data: $finished ? $data : null,
            inputTokens: $this->inputTokens,
            outputTokens: $this->outputTokens,
        );
    }

    private function canned(string $kind, array $input): array
    {
        $en = ($input['language'] ?? 'ar') === 'en';
        $first = $input['names']['first'] ?? ($en ? 'Sara' : 'ريم');
        $second = $input['names']['second'] ?? ($en ? 'Omar' : 'عمر');

        return match ($kind) {
            'story' => ['chapters' => $en ? [
                ['year' => '2019', 'title' => 'The first hello', 'text' => trim(($input['how_met'] ?? '') ?: "{$first} and {$second} met at a friend's dinner and talked until the tea went cold.")],
                ['year' => '', 'title' => 'A moment to keep', 'text' => trim(($input['moment'] ?? '') ?: 'A walk by the sea, and a promise made without words.')],
                ['year' => '2026', 'title' => 'Our day', 'text' => 'Today we begin our home together, and we would love you to be part of it.'],
            ] : [
                ['year' => '٢٠١٩', 'title' => 'البداية', 'text' => "التقى {$first} و{$second} في عشاء عند أصدقاء، وطال الحديث حتى برد الشاي."],
                ['year' => '', 'title' => 'لحظة لا تُنسى', 'text' => 'نزهة على البحر، ووعدٌ قيل بلا كلمات.'],
                ['year' => '٢٠٢٦', 'title' => 'يومنا', 'text' => 'اليوم نبدأ بيتنا معاً، ويسعدنا أن تكونوا معنا.'],
            ]],
            'poem' => $en
                ? ['poem' => "Two hearts that found their way, {$first} and {$second},\nBlessed by family, joined today,\nMay every year be kind and long,\nAnd every home be full of song.", 'caption' => 'With love']
                : ['poem' => "يا فرحةً جمعت {$first} و{$second}\nوزانها الأهلُ بالأفراحِ والطيبِ\nبارك الإلهُ لكم في كل خطوتكم\nودامَ بيتُكمُ بالحبِّ والطيبِ", 'caption' => 'مع خالص المحبة'],
            'clean' => ['families' => collect(preg_split('/\R/u', (string) ($input['text'] ?? '')) ?: [])
                ->map(function (string $line) {
                    preg_match('/(\+?[0-9٠-٩][0-9٠-٩\s\-]{6,}[0-9٠-٩])/u', $line, $m);
                    $phone = $m[1] ?? null;
                    $line = $phone ? str_replace($phone, '', $line) : $line;
                    // a rough stand-in for the model: numbering, emojis, "(4 people)", "and his wife"
                    $line = preg_replace(['/^\s*[0-9٠-٩]+\s*[-.)]\s*/u', '/[\x{2600}-\x{27BF}\x{1F300}-\x{1FAFF}\x{FE0F}]/u'], '', $line) ?? $line;
                    $seats = preg_match('/\(\s*([0-9٠-٩]+)[^)]*\)/u', $line, $s) ? (int) PhoneNumber::western($s[1]) : null;
                    $line = preg_replace('/\(\s*[0-9٠-٩]+[^)]*\)/u', '', $line) ?? $line;
                    $guest = GuestListParser::parseLine($line);
                    if ($guest && $seats === null && preg_match('/وزوجته|and his wife|and her husband|وزوجها/u', $guest['name'])) {
                        $seats = 2;
                    }

                    return $guest ? ['name' => $guest['name'], 'seats' => $seats ?? $guest['seats'], 'phone' => $phone ? PhoneNumber::western(trim($phone)) : null, 'notes' => null] : null;
                })
                ->filter()
                ->values()
                ->all()],
            default => [],
        };
    }
}
