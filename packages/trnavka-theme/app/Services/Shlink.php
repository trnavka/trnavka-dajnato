<?php

namespace App\Services;

/**
 * Thin client for a self-hosted Shlink instance (https://shlink.io) used to
 * shorten the links inside parish announcements and render a QR code for each.
 *
 * Configured through two env vars (see .env.example):
 *   SHLINK_URL      base URL of the Shlink server, e.g. https://s.trnavka.sk
 *   SHLINK_API_KEY  an API key with permission to create short URLs
 *
 * Every public method degrades gracefully: when Shlink is not configured or a
 * request fails, shorten() returns null and the caller simply renders the
 * original link without a QR code. Results are cached so repeated saves/renders
 * of the same bulletin don't hit the API again.
 */
class Shlink
{
    private const API_PATH = '/rest/v3/short-urls';
    private const SHORTEN_CACHE_TTL = 2592000; // 30 days

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->apiKey() !== '';
    }

    /**
     * Create (or reuse, via findIfExists) a short URL for $longUrl.
     *
     * @return array{shortUrl:string,shortCode:string}|null
     */
    public function shorten(string $longUrl): ?array
    {
        if (!$this->isConfigured() || !preg_match('#^https?://#i', $longUrl)) {
            return null;
        }

        $key = 'shlink_url_' . md5($longUrl);
        $cached = get_transient($key);

        if (is_array($cached)) {
            return $cached;
        }

        $response = wp_remote_post($this->baseUrl() . self::API_PATH, [
            'timeout' => 6,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Api-Key' => $this->apiKey(),
            ],
            'body' => wp_json_encode([
                'longUrl' => $longUrl,
                'findIfExists' => true,
                'tags' => ['farske-oznamy'],
            ]),
        ]);

        if (is_wp_error($response) || !in_array(wp_remote_retrieve_response_code($response), [200, 201], true)) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!is_array($body) || empty($body['shortUrl'])) {
            return null;
        }

        $result = [
            'shortUrl' => (string)$body['shortUrl'],
            'shortCode' => (string)($body['shortCode'] ?? ''),
        ];

        set_transient($key, $result, self::SHORTEN_CACHE_TTL);

        return $result;
    }

    private function baseUrl(): string
    {
        return rtrim((string)env('SHLINK_URL'), '/');
    }

    private function apiKey(): string
    {
        return (string)env('SHLINK_API_KEY');
    }
}
