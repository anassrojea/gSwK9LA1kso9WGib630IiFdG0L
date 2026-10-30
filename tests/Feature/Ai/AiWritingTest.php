<?php

use Anthropic\Client;
use App\Models\Addon;
use App\Models\AiGeneration;
use App\Models\Household;
use App\Models\Price;
use App\Models\User;
use App\Services\Ai\AiOutput;
use App\Services\Ai\AiResult;
use App\Services\Ai\AiWriter;
use App\Services\Ai\ClaudeAiWriter;
use App\Services\Ai\FakeAiWriter;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tests\Support\GuestExtras;

function fakeAi(): FakeAiWriter
{
    $fake = new FakeAiWriter;
    app()->instance(AiWriter::class, $fake);

    return $fake;
}

it('writes the story as a suggestion and never saves it to the invitation', function () {
    $fake = fakeAi();
    [$event] = GuestExtras::event('premium');
    $before = $event->invitation->config;

    $response = $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/story", [
        'how_met' => 'At a friend\'s dinner in Amman',
        'moment' => 'A walk by the sea',
        'meaning' => 'Our families together',
        'tone' => 'warm',
        'language' => 'ar',
        'dialect' => 'levantine',
    ])->assertAccepted();

    // the queue is sync in tests: the job already ran
    $response->assertJsonPath('status', 'done')->assertJsonPath('kind', 'story');
    expect($response->json('result.chapters'))->toHaveCount(3)
        ->and($response->json('result.chapters.0'))->toHaveKeys(['title', 'text']);

    expect($fake->calls[0]['kind'])->toBe('story')
        ->and($fake->calls[0]['input'])->toMatchArray(['dialect' => 'levantine', 'tone' => 'warm', 'names' => ['first' => 'ريم', 'second' => 'عمر']]);
    expect($event->invitation->fresh()->config)->toEqual($before);

    $this->actingAs($event->owner)->getJson($response->json('poll'))->assertOk()->assertJsonPath('status', 'done');
});

it('needs at least one answer and a valid tone and dialect', function () {
    fakeAi();
    [$event] = GuestExtras::event('premium');

    $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/story", [])->assertUnprocessable()->assertJsonValidationErrors('how_met');
    $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/story", ['moment' => 'x', 'dialect' => 'klingon'])->assertJsonValidationErrors('dialect');
});

it('trims story chapters to the v1 schema', function () {
    $fake = fakeAi();
    [$event] = GuestExtras::event('premium');
    $fake->data = ['chapters' => [
        ...array_fill(0, 14, ['year' => str_repeat('9', 30), 'title' => str_repeat('عنوان ', 30), 'text' => str_repeat('كلمة ', 600)]),
        ['year' => '', 'title' => '', 'text' => 'no title'],
    ]];

    $result = $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/story", ['how_met' => 'x'])->json('result');

    expect($result['chapters'])->toHaveCount(12);
    foreach ($result['chapters'] as $chapter) {
        expect(mb_strlen($chapter['title']))->toBeLessThanOrEqual(80)
            ->and(mb_strlen($chapter['text']))->toBeLessThanOrEqual(2000)
            ->and(mb_strlen($chapter['year']))->toBeLessThanOrEqual(20);
    }

    // markup and empty chapters never reach the host
    expect(AiOutput::story(['chapters' => [['year' => '', 'title' => '<b>Hi</b>', 'text' => '<script>x</script>ok']]]))
        ->toBe(['chapters' => [['title' => 'Hi', 'text' => 'xok']]])
        ->and(AiOutput::story(['chapters' => [['title' => ' ', 'text' => '']]]))->toBeNull()
        ->and(AiOutput::story(null))->toBeNull();
});

it('records tokens and cost for every call', function () {
    $fake = fakeAi();
    $fake->inputTokens = 1500;
    $fake->outputTokens = 2500;
    [$event] = GuestExtras::event('premium');

    $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/story", ['how_met' => 'x'])->assertAccepted();

    $row = AiGeneration::query()->sole();
    // Sonnet 5.5: 1500 × $2 + 2500 × $10 per MTok = 2.8 cents, rounded up
    expect($row->model)->toBe('claude-sonnet-5-5')
        ->and($row->cost_cents)->toBe(3)
        ->and($row->user_id)->toBe($event->owner_id)
        ->and($row->input['usage'])->toMatchArray(['input_tokens' => 1500, 'output_tokens' => 2500])
        ->and($row->output)->toContain('chapters');
});

it('computes cost_cents from the configured prices, rounded up to whole cents', function () {
    expect(AiResult::costCents('claude-sonnet-5-5', 0, 0))->toBe(0)
        ->and(AiResult::costCents('claude-sonnet-5-5', 1, 0))->toBe(1)
        ->and(AiResult::costCents('claude-sonnet-5-5', 1_000_000, 0))->toBe(200)
        ->and(AiResult::costCents('claude-sonnet-5-5', 0, 1_000_000))->toBe(1000)
        ->and(AiResult::costCents('claude-sonnet-5-5', 500_000, 100_000))->toBe(200)
        ->and(AiResult::costCents('claude-haiku-5-5', 1_000_000, 0))->toBe(10)
        ->and(AiResult::costCents('claude-haiku-5-5', 1_000_001, 0))->toBe(11)
        ->and(AiResult::costCents('claude-haiku-5-5', 200_000, 200_000))->toBe(12)
        ->and(AiResult::costCents('unknown-model', 5_000_000, 5_000_000))->toBe(0);
});

it('handles refusals and cut-off answers without a suggestion', function (string $stop) {
    $fake = fakeAi();
    $fake->stopReason = $stop;
    [$event] = GuestExtras::event('premium');

    $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/story", ['how_met' => 'x'])
        ->assertAccepted()
        ->assertJsonPath('status', 'failed')
        ->assertJsonPath('error', $stop)
        ->assertJsonPath('result', null)
        ->assertJsonPath('message', __("ai.errors.{$stop}"));

    // tokens were spent, so the call is still recorded with its cost
    expect(AiGeneration::query()->sole()->cost_cents)->toBeGreaterThan(0);
})->with(['refusal', 'max_tokens']);

it('reports an unavailable service without counting against the month', function () {
    $fake = fakeAi();
    $fake->fail = 'busy';
    [$event] = GuestExtras::event('premium');
    config()->set('ai.monthly.premium.story', 1);

    $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/story", ['how_met' => 'x'])->assertJsonPath('error', 'busy');
    $fake->fail = null;
    $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/story", ['how_met' => 'x'])->assertJsonPath('status', 'done');
    $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/story", ['how_met' => 'x'])->assertStatus(429)->assertJsonPath('reason', 'monthly');
});

it('rate-limits AI calls per user per hour', function () {
    fakeAi();
    config()->set('ai.per_user_hourly', 2);
    [$event] = GuestExtras::event('premium');

    $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/story", ['how_met' => 'x'])->assertAccepted();
    $this->actingAs($event->owner)->postJson("/events/{$event->id}/guests/clean", ['text' => 'أم فيصل 3'])->assertAccepted();
    $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/story", ['how_met' => 'x'])->assertTooManyRequests();
});

it('keeps AI to signed-in hosts of the event', function () {
    fakeAi();
    [$event] = GuestExtras::event('premium');

    $this->postJson("/events/{$event->id}/ai/story", ['how_met' => 'x'])->assertUnauthorized();
    $this->actingAs(User::factory()->create())->postJson("/events/{$event->id}/ai/story", ['how_met' => 'x'])->assertForbidden();

    $generation = $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/story", ['how_met' => 'x'])->json('id');
    $this->actingAs(User::factory()->create())->getJson("/ai/generations/{$generation}")->assertNotFound();
});

it('writes the poem only with the ai_poem add-on', function () {
    $fake = fakeAi();
    [$event] = GuestExtras::event('signature');

    $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/poem", ['dialect' => 'gulf'])
        ->assertForbidden()->assertJsonPath('reason', 'addon');
    expect($fake->calls)->toBe([]);

    GuestExtras::grant($event, 'ai_poem');
    $result = $this->actingAs($event->owner)->postJson("/events/{$event->id}/ai/poem", ['dialect' => 'gulf'])->assertAccepted()->json('result');

    expect($result['text'])->toContain('ريم')->toContain("\n")
        ->and(mb_strlen($result['text']))->toBeLessThanOrEqual(2000)
        ->and($fake->calls[0]['input']['dialect'])->toBe('gulf')
        // still only a suggestion: no intro_line was changed
        ->and(collect($event->invitation->fresh()->config['sections'])->firstWhere('type', 'intro_line')['style'] ?? 'line')->not->toBe('verse');
});

it('sells the AI poem as an add-on', function () {
    GuestExtras::event();

    expect(config('payments.addons.ai_poem'))->toMatchArray(['feature' => 'ai_poem'])
        ->and(Addon::query()->find('ai_poem'))->not->toBeNull()
        ->and(Price::query()->where('priceable_id', 'ai_poem')->count())->toBe(3);
});

it('cleans a messy list into families with normalized phones', function () {
    $fake = fakeAi();
    [$event] = GuestExtras::event('premium');
    $fake->data = ['families' => [
        ['name' => '  عائلة خالد العلي ', 'seats' => 4, 'phone' => '+962 79 123 4567', 'notes' => 'أقارب العريس'],
        ['name' => 'أم فيصل', 'seats' => 0, 'phone' => '٠٧٩١ ٢٣٤ ٥٦٧', 'notes' => null],
        ['name' => 'Sara & Omar', 'seats' => 500, 'phone' => 'call me', 'notes' => null],
        ['name' => '', 'seats' => 2, 'phone' => null, 'notes' => null],
    ]];

    $response = $this->actingAs($event->owner)->postJson("/events/{$event->id}/guests/clean", ['text' => "عائلة خالد العلي 4 +962791234567\nأم فيصل"])->assertAccepted();

    expect($response->json('result.families'))->toEqual([
        ['name' => 'عائلة خالد العلي', 'seats' => 4, 'phone' => '+962791234567', 'notes' => 'أقارب العريس', 'warnings' => []],
        ['name' => 'أم فيصل', 'seats' => 1, 'phone' => '0791234567', 'notes' => null, 'warnings' => []],
        ['name' => 'Sara & Omar', 'seats' => 100, 'phone' => null, 'notes' => null, 'warnings' => ['phone']],
    ]);
    expect(AiGeneration::query()->sole())->model->toBe('claude-haiku-5-5')->kind->toBe('clean');
    // nothing imported yet
    expect($event->households()->count())->toBe(1);
});

it('imports the reviewed families through the import endpoint, within the guest limit', function () {
    [$event] = GuestExtras::event('premium', ['guest_limit' => 10]);

    $this->actingAs($event->owner)->post("/events/{$event->id}/guests/import", ['guests' => [
        ['name' => 'عائلة الزين', 'seats' => 4, 'phone' => '٠٧٩١ ٢٣٤ ٥٦٧'],
        ['name' => 'Sara', 'seats' => 2, 'phone' => null],
    ]])->assertRedirect()->assertSessionHasNoErrors();

    $added = Household::query()->where('event_id', $event->id)->where('display_name', 'عائلة الزين')->sole();
    expect($added->phone)->toBe('0791234567')->and($event->households()->count())->toBe(3);

    // 3 + 4 + 2 = 9 seats used of 10
    $this->actingAs($event->owner)->post("/events/{$event->id}/guests/import", ['guests' => [['name' => 'Big family', 'seats' => 5]]])
        ->assertSessionHasErrors('text');
    $this->actingAs($event->owner)->post("/events/{$event->id}/guests/import", ['guests' => [['name' => 'X', 'seats' => 1, 'phone' => 'not a phone']]])
        ->assertSessionHasErrors('guests.0.phone');
    expect($event->households()->count())->toBe(3);
});

it('sends the Claude API request the plan asks for', function () {
    $transport = new class implements ClientInterface
    {
        public array $bodies = [];

        public string $stop = 'end_turn';

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->bodies[] = json_decode((string) $request->getBody(), true);

            return new Response(200, ['content-type' => 'application/json'], json_encode([
                'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => $this->bodies[0]['model'],
                'content' => $this->stop === 'refusal' ? [] : [['type' => 'text', 'text' => '{"families":[{"name":"أم فيصل","seats":3,"phone":null,"notes":null}]}']],
                'stop_reason' => $this->stop, 'stop_sequence' => null,
                'usage' => ['input_tokens' => 900, 'output_tokens' => 120, 'cache_read_input_tokens' => 0],
            ]));
        }
    };
    $writer = new ClaudeAiWriter(new Client(apiKey: 'sk-ant-test', requestOptions: ['transporter' => $transport, 'maxRetries' => 0]));

    $result = $writer->write('clean', ['text' => 'أم فيصل ٣']);
    $body = $transport->bodies[0];

    expect($body['model'])->toBe('claude-haiku-5-5')
        ->and($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['output_config']['effort'])->toBe('low')
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['output_config']['format']['schema']['properties'])->toHaveKey('families')
        ->and($body)->not->toHaveKeys(['temperature', 'top_p', 'top_k', 'thinking'])
        ->and($body['messages'][0]['content'])->toContain('أم فيصل ٣')
        ->and($result->complete())->toBeTrue()
        ->and($result->data['families'][0]['seats'])->toBe(3)
        ->and($result->inputTokens)->toBe(900);

    $writer->write('story', ['how_met' => 'x', 'language' => 'en']);
    expect($transport->bodies[1]['model'])->toBe('claude-sonnet-5-5')
        ->and($transport->bodies[1]['output_config']['effort'])->toBe('medium')
        ->and($transport->bodies[1])->not->toHaveKeys(['temperature', 'thinking']);

    $transport->stop = 'refusal';
    $refused = $writer->write('clean', ['text' => 'x']);
    expect($refused->complete())->toBeFalse()->and($refused->data)->toBeNull()->and($refused->stopReason)->toBe('refusal');
});

it('binds the fake writer only when AI_FAKE is on', function () {
    expect(app(AiWriter::class))->toBeInstanceOf(ClaudeAiWriter::class);

    config()->set('ai.fake', true);
    app()->forgetInstance(AiWriter::class);
    expect(app(AiWriter::class))->toBeInstanceOf(FakeAiWriter::class);
});
