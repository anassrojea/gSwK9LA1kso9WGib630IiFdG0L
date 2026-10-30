<?php

/*
| AI writing with Claude (plan section 14.3): the story, a short poem (paid
| add-on "ai_poem") and guest-list cleanup. Calls run in queued jobs through
| App\Services\Ai\AiWriter; AI_FAKE=true binds the canned FakeAiWriter instead
| (tests, local demos). Results are suggestions: nothing goes live until the
| host accepts it.
*/

return [

    // read by App\Services\Ai\ClaudeAiWriter; empty falls back to the ANTHROPIC_API_KEY environment variable
    'api_key' => env('ANTHROPIC_API_KEY'),

    // never call the API: answer with App\Services\Ai\FakeAiWriter
    'fake' => (bool) env('AI_FAKE', false),

    'models' => [
        'story' => env('AI_MODEL_STORY', 'claude-sonnet-5-5'),
        'poem' => env('AI_MODEL_POEM', 'claude-sonnet-5-5'),
        'clean' => env('AI_MODEL_CLEAN', 'claude-haiku-5-5'),
    ],

    // output_config.effort per kind
    'effort' => [
        'story' => 'medium',
        'poem' => 'medium',
        'clean' => 'low',
    ],

    'max_tokens' => [
        'story' => 8000,
        'poem' => 4000,
        'clean' => 16000,
    ],

    // USD cents per million tokens [input, output], for ai_generations.cost_cents
    'prices' => [
        'claude-sonnet-5-5' => [200, 1000],
        'claude-haiku-5-5' => [10, 50],
    ],

    // Seconds one API call may take inside the job
    'timeout' => (int) env('AI_TIMEOUT', 120),

    // Generations per event per calendar month, by plan (null: no monthly cap)
    'monthly' => [
        'free' => ['story' => 3, 'poem' => 3, 'clean' => 5],
        'premium' => ['story' => 10, 'poem' => 5, 'clean' => 20],
        'signature' => ['story' => 20, 'poem' => 10, 'clean' => 40],
        'venue_pro' => ['story' => 40, 'poem' => 20, 'clean' => 80],
    ],

    // Short-term throttles (requests per hour)
    'per_user_hourly' => (int) env('AI_PER_USER_HOURLY', 20),
    'per_event_hourly' => (int) env('AI_PER_EVENT_HOURLY', 15),

];
