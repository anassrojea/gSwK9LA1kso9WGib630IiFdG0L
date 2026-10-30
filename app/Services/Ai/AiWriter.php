<?php

namespace App\Services\Ai;

/**
 * Writes for the host with a language model (plan section 14.3). kind is
 * "story", "poem" or "clean"; $input is what AiPrompts::user() turns into the
 * request. Implementations: ClaudeAiWriter (the Anthropic API) and
 * FakeAiWriter (tests, local demos with AI_FAKE=true).
 *
 * @throws AiUnavailable when the service cannot be reached or refuses the request
 */
interface AiWriter
{
    public function write(string $kind, array $input): AiResult;
}
