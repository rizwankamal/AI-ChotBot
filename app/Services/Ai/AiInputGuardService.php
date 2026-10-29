<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Prompt-injection / jailbreak defenses before expensive LLM calls.
 */
class AiInputGuardService
{
    /**
     * @var list<string>
     */
    private const JAILBREAK_SIGNATURES = [
        'ignore previous instructions',
        'ignore all previous',
        'developer mode',
        'system override',
        'dan mode',
        'jailbreak',
        'reveal your system prompt',
        'disregard the above',
        'you are now',
        'act as if you have no restrictions',
    ];

    /**
     * @return array{allowed: bool, reason: ?string, moderated: bool}
     */
    public function inspect(string $userMessage): array
    {
        $normalized = Str::lower(trim($userMessage));

        if ($normalized === '') {
            return ['allowed' => false, 'reason' => 'empty_input', 'moderated' => false];
        }

        foreach (self::JAILBREAK_SIGNATURES as $signature) {
            if (str_contains($normalized, $signature)) {
                return ['allowed' => false, 'reason' => 'jailbreak_signature', 'moderated' => false];
            }
        }

        if ($this->shouldUseOpenAiModeration() && $this->isFlaggedByOpenAi($userMessage)) {
            return ['allowed' => false, 'reason' => 'openai_moderation', 'moderated' => true];
        }

        return ['allowed' => true, 'reason' => null, 'moderated' => false];
    }

    /**
     * Wrap untrusted user text in XML delimiters for prompt containment.
     */
    public function wrapUserInput(string $userMessage): string
    {
        $safe = str_replace(['</user_input>', '<user_input>'], ['', ''], $userMessage);

        return "<user_input>\n{$safe}\n</user_input>";
    }

    private function shouldUseOpenAiModeration(): bool
    {
        if (! (bool) config('ai.moderation.openai_enabled', true)) {
            return false;
        }

        $key = config('services.openai.api_key');

        return is_string($key) && $key !== '';
    }

    private function isFlaggedByOpenAi(string $userMessage): bool
    {
        $key = (string) config('services.openai.api_key');

        $response = Http::withToken($key)
            ->timeout(8)
            ->acceptJson()
            ->post('https://api.openai.com/v1/moderations', [
                'input' => $userMessage,
            ]);

        if (! $response->successful()) {
            return false;
        }

        return (bool) data_get($response->json(), 'results.0.flagged', false);
    }
}
