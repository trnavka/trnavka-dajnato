<?php

namespace App\Services;

use Anthropic\Client;
use Throwable;

/**
 * Suggests a short announcement heading from its body text via Claude Sonnet 4.6
 * (a small one-shot task). Used by the editor's "navrhnúť nadpis" button.
 * Configured with the ANTHROPIC_API_KEY env var; returns null on any failure so
 * the editor degrades gracefully.
 */
class TitleSuggester
{
    private const MODEL = 'claude-sonnet-4-6';
    private const SYSTEM = 'Si redaktor farských oznamov. Z textu oznamu vytvor jeden výstižný a krátky nadpis '
        . 'v slovenčine, ideálne 2 až 4 slová. V nadpise preferuj slová, ktoré sú všeobecne známe. Vráť iba '
        . 'samotný nadpis — bez úvodzoviek, bez bodky na konci a bez akéhokoľvek vysvetlenia.';

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function suggest(string $html): ?string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string)wp_strip_all_tags($html)));

        if (!$this->isConfigured() || $text === '') {
            return null;
        }

        try {
            $message = (new Client(apiKey: $this->apiKey()))->messages->create(
                maxTokens: 32,
                messages: [['role' => 'user', 'content' => $text]],
                model: self::MODEL,
                system: self::SYSTEM,
            );
        } catch (Throwable $e) {
            return null;
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text' && trim($block->text) !== '') {
                return $this->clean($block->text);
            }
        }

        return null;
    }

    /** Strip wrapping quotes / trailing punctuation the model sometimes adds. */
    private function clean(string $title): string
    {
        $title = trim(preg_replace('/\s+/', ' ', $title));
        $title = trim($title, " \t\n\r\0\x0B\"'„“”»«");

        return rtrim($title, " \t.:;–—-");
    }

    private function apiKey(): string
    {
        return (string)env('ANTHROPIC_API_KEY');
    }
}
