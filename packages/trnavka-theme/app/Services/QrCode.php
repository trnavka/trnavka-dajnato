<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR codes generated locally (Shlink's own QR endpoint is deprecated). Output is
 * SVG — crisp, scalable, tiny, and works both as an <img> on the web/TV and
 * embedded into the PDF (dompdf renders the SVG path natively, no GD needed).
 *
 * The public QR endpoint (see BulletinRestController::qr) only renders codes for
 * URLs whose host is allow-listed via the QR_ALLOWED_DOMAINS env var, so it can't
 * be abused as an open QR generator. The allow-list defaults to the Shlink host.
 */
class QrCode
{
    private const DEFAULT_SIZE = 300;
    private const MIN_SIZE = 80;
    private const MAX_SIZE = 1000;
    private const MARGIN = 4; // quiet zone in modules (QR spec recommends 4)

    public function svg(string $data, int $size = self::DEFAULT_SIZE): string
    {
        $size = max(self::MIN_SIZE, min(self::MAX_SIZE, $size));
        $renderer = new ImageRenderer(new RendererStyle($size, self::MARGIN), new SvgImageBackEnd());

        return (new Writer($renderer))->writeString($data);
    }

    /** SVG QR as a data: URI — for embedding into the PDF export. */
    public function dataUri(string $data, int $size = self::DEFAULT_SIZE): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode($this->svg($data, $size));
    }

    /** URL of our own QR endpoint that renders the code for $data (used by the editor and TV). */
    public function url(string $data, int $size = self::DEFAULT_SIZE): string
    {
        // add_query_arg() does NOT url-encode values, so encode the URL ourselves.
        return add_query_arg(['url' => rawurlencode($data), 'size' => $size], rest_url('trnavka/v1/bulletins/qr'));
    }

    /** Whether $url may be turned into a QR — http(s) and an allow-listed host. */
    public function isAllowed(string $url): bool
    {
        if (!preg_match('#^https?://#i', $url)) {
            return false;
        }

        $host = strtolower((string)wp_parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach ($this->allowedDomains() as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Allow-listed hosts (lower-cased) from QR_ALLOWED_DOMAINS (comma-separated).
     * Falls back to the Shlink host so the common case (short URLs) works without
     * extra configuration.
     *
     * @return string[]
     */
    public function allowedDomains(): array
    {
        $raw = trim((string)env('QR_ALLOWED_DOMAINS'));

        if ($raw === '') {
            $shlinkHost = strtolower((string)wp_parse_url((string)env('SHLINK_URL'), PHP_URL_HOST));

            return $shlinkHost ? [$shlinkHost] : [];
        }

        return array_values(array_filter(array_map(
            fn($domain) => strtolower(trim($domain)),
            explode(',', $raw)
        )));
    }
}
