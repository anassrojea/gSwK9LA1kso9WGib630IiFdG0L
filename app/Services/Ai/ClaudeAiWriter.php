<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\Message;
use Illuminate\Support\Facades\Log;

/**
 * AiWriter on the Claude API through the official PHP SDK (anthropic-ai/sdk).
 * Story and poem: Claude Sonnet 5.5 (thinking is adaptive by default and is
 * never switched off; effort "medium"); guest-list cleanup: Claude Haiku 5.5
 * at effort "low". Every answer is JSON constrained by a schema
 * (output_config.format); the stable system prompt is cached.
 */
class ClaudeAiWriter implements AiWriter
{
    public function __construct(private ?Client $client = null) {}

    public function write(string $kind, array $input): AiResult
    {
        $model = (string) config("ai.models.{$kind}");

        try {
            $message = $this->client()->messages->create(
                model: $model,
                maxTokens: (int) config("ai.max_tokens.{$kind}", 8000),
                system: [[
                    'type' => 'text',
                    'text' => AiPrompts::system($kind),
                    'cacheControl' => ['type' => 'ephemeral'],
                ]],
                messages: [['role' => 'user', 'content' => AiPrompts::user($kind, $input)]],
                outputConfig: [
                    'effort' => config("ai.effort.{$kind}", 'medium'),
                    'format' => ['type' => 'json_schema', 'schema' => AiPrompts::schema($kind)],
                ],
                requestOptions: ['timeout' => (float) config('ai.timeout', 120)],
            );
        } catch (RateLimitException $e) {
            throw new AiUnavailable('busy', $e->getMessage(), $e);
        } catch (AuthenticationException|PermissionDeniedException $e) {
            Log::error('ai: the Anthropic API key was refused', ['kind' => $kind]);
            throw new AiUnavailable('unavailable', $e->getMessage(), $e);
        } catch (BadRequestException $e) {
            Log::error('ai: request rejected', ['kind' => $kind, 'error' => $e->getMessage()]);
            throw new AiUnavailable('unavailable', $e->getMessage(), $e);
        } catch (APIStatusException $e) {
            // overloaded, 5xx…
            throw new AiUnavailable('busy', $e->getMessage(), $e);
        } catch (APIConnectionException $e) {
            throw new AiUnavailable('unavailable', $e->getMessage(), $e);
        }

        return static::result($message, $model);
    }

    /**
     * The SDK's Message as an AiResult. The text is only decoded when the model
     * finished: a refusal or a max_tokens cut-off may not match the schema.
     */
    public static function result(Message $message, string $model): AiResult
    {
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }

        $finished = in_array($message->stopReason, ['end_turn', 'stop_sequence'], true);
        $data = $finished ? json_decode($text, true) : null;

        return new AiResult(
            model: $message->model ?: $model,
            stopReason: $message->stopReason,
            text: $text,
            data: is_array($data) ? $data : null,
            inputTokens: (int) $message->usage->inputTokens,
            outputTokens: (int) $message->usage->outputTokens,
            cacheReadTokens: (int) ($message->usage->cacheReadInputTokens ?? 0),
            cacheWriteTokens: (int) ($message->usage->cacheCreationInputTokens ?? 0),
        );
    }

    private function client(): Client
    {
        // new Client() reads ANTHROPIC_API_KEY from the environment; config wins when set (config:cache)
        return $this->client ??= ($key = config('ai.api_key')) ? new Client(apiKey: $key) : new Client;
    }
}
