<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Roots\WPConfig\Config;

class HtmlHeader
{
    const CACHE_KEY = 'html_header';

    public function getContent(): string
    {
        return Cache::get(self::CACHE_KEY) ?? $this->loadAndCache();
    }

    /**
     * When the old web cannot be read, the last good header stays in the cache, so the page never loses it.
     */
    public function loadAndCache(): string
    {
        $header = $this->load();

        if (null === $header) {
            return Cache::get(self::CACHE_KEY, '');
        }

        Cache::forever(self::CACHE_KEY, $header);

        return $header;
    }

    private function load(): ?string
    {
        $url = rtrim(Config::get('HTML_HEADER_URL'), '/') . '/kontakt/?hide-daj-na-to=T&cb=' . rand(1, 100000000);

        $content = file_get_contents($url, false, stream_context_create([
            'http' => [
                'timeout' => 10,
            ],
            'ssl'  => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]));

        if (false === $content) {
            return null;
        }

        if (!preg_match('/<!-- START HEADER_WIDGET -->(.*)<!-- END HEADER_WIDGET -->/ms', $content, $matches)) {
            error_log(sprintf('HtmlHeader: %s has no HEADER_WIDGET, keeping the last header', $url));

            return null;
        }

        return $matches[1];
    }
}
