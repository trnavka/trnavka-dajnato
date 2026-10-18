<?php

namespace App\Rest;

use App\Entity\Bulletin;
use App\Repositories\BulletinRepository;
use App\Services\LiturgyService;
use App\Services\QrCode;
use App\Services\Shlink;
use App\Services\TitleSuggester;
use WP_Error;
use WP_REST_Request;

/**
 * Public (read-only) REST endpoints — data source for the church TV.
 *   GET /wp-json/trnavka/v1/bulletins/current   – current week (anonymous)
 *   GET /wp-json/trnavka/v1/bulletins           – list (anonymous)
 *   GET /wp-json/trnavka/v1/bulletins/liturgy   – fetch from KBS (admin only)
 *   GET /wp-json/trnavka/v1/bulletins/shorten   – Shlink short URL + QR (admin only)
 *
 * The global rest_authentication_errors filter in setup.php whitelists the
 * trnavka/v1/bulletins namespace; per-route authorization is below.
 */
class BulletinRestController
{
    private BulletinRepository $bulletins;
    private LiturgyService $liturgy;
    private Shlink $shlink;
    private QrCode $qr;
    private TitleSuggester $titles;

    public function __construct()
    {
        $this->bulletins = new BulletinRepository();
        $this->liturgy = new LiturgyService();
        $this->shlink = new Shlink();
        $this->qr = new QrCode();
        $this->titles = new TitleSuggester();

        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('trnavka/v1', '/bulletins/current', [
            'methods' => 'GET',
            'callback' => [$this, 'current'],
            'permission_callback' => '__return_true',
        ]);

        // Announcements + liturgical week for the latest available Sunday, as JSON.
        // Authorized by an API key passed in the URL: ?api_key=... (see BULLETIN_API_KEY).
        register_rest_route('trnavka/v1', '/bulletins/latest', [
            'methods' => 'GET',
            'callback' => [$this, 'latest'],
            'permission_callback' => [$this, 'checkApiKey'],
            'args' => ['api_key' => ['required' => true, 'type' => 'string']],
        ]);

        register_rest_route('trnavka/v1', '/bulletins', [
            'methods' => 'GET',
            'callback' => [$this, 'list'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route('trnavka/v1', '/bulletins/liturgy', [
            'methods' => 'GET',
            'callback' => [$this, 'liturgy'],
            'permission_callback' => fn() => current_user_can('edit_bulletins'),
            'args' => ['sunday' => ['required' => true, 'type' => 'string']],
        ]);

        register_rest_route('trnavka/v1', '/bulletins/shorten', [
            'methods' => 'GET',
            'callback' => [$this, 'shorten'],
            'permission_callback' => fn() => current_user_can('edit_bulletins'),
            'args' => ['url' => ['required' => true, 'type' => 'string']],
        ]);

        register_rest_route('trnavka/v1', '/bulletins/suggest-title', [
            'methods' => 'POST',
            'callback' => [$this, 'suggestTitle'],
            'permission_callback' => fn() => current_user_can('edit_bulletins'),
            'args' => ['text' => ['required' => true, 'type' => 'string']],
        ]);

        // Public so the church TV (and the printed PDF's source) can load the
        // QR <img>; abuse is limited by the QR_ALLOWED_DOMAINS host allow-list.
        register_rest_route('trnavka/v1', '/bulletins/qr', [
            'methods' => 'GET',
            'callback' => [$this, 'qr'],
            'permission_callback' => '__return_true',
            'args' => [
                'url' => ['required' => true, 'type' => 'string'],
                'size' => ['type' => 'integer'],
            ],
        ]);
    }

    public function current(): array
    {
        $bulletin = $this->bulletins->current();

        return $bulletin ? $this->serialize($bulletin) : ['found' => false];
    }

    /** Announcements + liturgical week for the latest available Sunday. API-key protected. */
    public function latest(): void
    {
        $bulletin = $this->bulletins->latest();
        $data = $bulletin ? $this->serialize($bulletin) : ['found' => false];

        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Authorize via the api_key URL parameter against the BULLETIN_API_KEY env var. */
    public function checkApiKey(WP_REST_Request $request): bool
    {
        $expected = (string)env('BULLETIN_API_KEY');
        $given = (string)$request->get_param('api_key');

        return $expected !== '' && $given !== '' && hash_equals($expected, $given);
    }

    public function list(): array
    {
        return array_map(fn(Bulletin $b) => [
            'id' => $b->id,
            'title' => $b->title,
            'date' => $b->date,
            'url' => $b->url(),
        ], $this->bulletins->recent(50));
    }

    public function liturgy(WP_REST_Request $request): array
    {
        $sunday = sanitize_text_field((string)$request->get_param('sunday'));

        return [
            'sundayName' => $this->liturgy->sundayName($sunday),
            'days' => $this->liturgy->weekOptions($sunday),
        ];
    }

    /** Live short URL + QR for one link, used by the editor's announcement preview. */
    public function shorten(WP_REST_Request $request): array
    {
        $url = esc_url_raw((string)$request->get_param('url'));
        $short = $url ? $this->shlink->shorten($url) : null;

        return $short
            ? ['ok' => true, 'url' => $url, 'shortUrl' => $short['shortUrl'], 'qr' => $this->qr->url($short['shortUrl'])]
            : ['ok' => false];
    }

    /** Suggest a short heading for an announcement (AI), used by the editor's button. */
    public function suggestTitle(WP_REST_Request $request): array
    {
        $text = (string)$request->get_param('text');
        $title = $this->titles->suggest($text);

        return $title !== null ? ['ok' => true, 'title' => $title] : ['ok' => false];
    }

    /** Render a QR code (SVG) for an allow-listed URL. Streams the image directly. */
    public function qr(WP_REST_Request $request)
    {
        $url = esc_url_raw((string)$request->get_param('url'));
        $size = (int)($request->get_param('size') ?: 300);

        if ($url === '' || !$this->qr->isAllowed($url)) {
            return new WP_Error('qr_forbidden', 'URL not allowed for QR generation.', ['status' => 403]);
        }

        $svg = $this->qr->svg($url, $size);

        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: public, max-age=31536000, immutable');
        header('Content-Length: ' . strlen($svg));
        echo $svg;
        exit;
    }

    private function serialize(Bulletin $bulletin): array
    {
        return [
            'found' => true,
            'id' => $bulletin->id,
            'title' => $bulletin->title,
            'date' => $bulletin->date,
            'dateFormatted' => $bulletin->formattedDate(),
            'url' => $bulletin->url(),
            'liturgicalDays' => $bulletin->liturgyRows(),
            'announcements' => array_map(fn($i) => [
                'label' => $i['label'] ?? '',
                'text' => $i['text'] ?? '',
                'non_interactive_text' => $this->nonInteractiveText($i['text'] ?? '', $i['links'] ?? []),
                'links' => $this->serializeLinks($i['links'] ?? []),
            ], $bulletin->items),
        ];
    }

    /**
     * Same text as `text`, but each <a> is flattened to a plain-text link — for
     * non-interactive displays (TV, digital signage, projected presentations) where
     * links can't be clicked. The short URL is used when available (else the original
     * href), shown without the scheme (e.g. sdb.sk/abc). Other formatting (<strong>,
     * <br>) is kept; matching is on the decoded href so entity-encoded URLs work.
     *
     * @param array<int, array{url?:string,shortUrl?:string}> $links
     */
    private function nonInteractiveText(string $html, array $links): string
    {
        $map = [];

        foreach ($links as $link) {
            if (!empty($link['url']) && !empty($link['shortUrl'])) {
                $map[$link['url']] = (string)$link['shortUrl'];
            }
        }

        return preg_replace_callback(
            '/<a\b[^>]*?\bhref\s*=\s*(["\'])(.*?)\1[^>]*>.*?<\/a>/is',
            function ($m) use ($map) {
                $url = html_entity_decode($m[2], ENT_QUOTES);
                $target = $map[$url] ?? $url;

                return esc_html(preg_replace('#^https?://#i', '', $target));
            },
            $html
        );
    }

    /**
     * @param array<int, array{url?:string,shortUrl?:string}> $links
     * @return array<int, array{url:string,shortUrl:string,qr:string}>
     */
    private function serializeLinks(array $links): array
    {
        $serialized = array_map(function ($link) {
            if (empty($link['shortUrl'])) {
                return null;
            }

            return [
                'url' => (string)($link['url'] ?? ''),
                'shortUrl' => (string)$link['shortUrl'],
                'qr' => $this->qr->url((string)$link['shortUrl']),
            ];
        }, $links);

        return array_values(array_filter($serialized));
    }
}
