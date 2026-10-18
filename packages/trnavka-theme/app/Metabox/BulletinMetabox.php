<?php

namespace App\Metabox;

use App\Services\QrCode;
use App\Services\Shlink;
use App\Services\TitleSuggester;

/**
 * "Farské oznamy" custom post type ('bulletin') + React editor mount.
 * All fields are stored as one serialized meta array by AbstractMetabox; the
 * Sunday date is additionally mirrored to the `bulletin_date` meta key so it
 * can be queried and ordered by.
 */
class BulletinMetabox extends AbstractMetabox
{
    public const ALLOWED_HTML = [
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'br' => [],
        'a' => ['href' => [], 'target' => [], 'rel' => []],
    ];

    /** Singular/plural base for the CPT's own capability set (see BulletinRole). */
    public const CAPABILITY_TYPE = ['bulletin', 'bulletins'];

    public function __construct()
    {
        parent::__construct('bulletin', 'Obsah oznamov', ['context' => 'normal'], [
            'labels' => [
                'name' => 'Farské oznamy',
                'singular_name' => 'Oznam',
                'menu_name' => 'Farské oznamy',
                'add_new' => 'Pridať oznam',
                'add_new_item' => 'Nový oznam',
                'edit_item' => 'Upraviť oznam',
                'all_items' => 'Všetky oznamy',
                'not_found' => 'Žiadne oznamy',
            ],
            'public' => true,
            'show_in_rest' => false,
            'has_archive' => 'farske-oznamy',
            'menu_icon' => 'dashicons-megaphone',
            'menu_position' => 5,
            'supports' => ['title'],
            'rewrite' => ['slug' => 'farske-oznamy', 'with_front' => false],
            // Own capability set ('edit_bulletins' …) so a dedicated role can
            // manage only oznamy; admins/editors get these caps via BulletinRole.
            'capability_type' => self::CAPABILITY_TYPE,
            'map_meta_cap' => true,
        ]);
    }

    public function saveMetaBox($postId): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        parent::saveMetaBox($postId); // saves main meta + verifies nonce/permissions

        $data = get_post_meta($postId, $this->metaboxId, true);

        if (is_array($data) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($data['date'] ?? ''))) {
            update_post_meta($postId, 'bulletin_date', $data['date']);
        }
    }

    protected function formToModelData(array $postData): array
    {
        // WordPress slashes $_POST (magic quotes); unslash before json_decode.
        $date = sanitize_text_field(wp_unslash($postData['date'] ?? ''));
        $liturgy = $this->sanitizeLiturgy(json_decode(wp_unslash($postData['liturgy'] ?? '[]'), true) ?: []);

        return [
            'date' => $date,
            'items' => $this->sanitizeItems(json_decode(wp_unslash($postData['items'] ?? '[]'), true) ?: []),
            'liturgy' => $this->sortLiturgyByDate($liturgy, $date),
            'types' => $this->sanitizeTypes(json_decode(wp_unslash($postData['types'] ?? '{}'), true) ?: []),
            'availableTypes' => $this->sanitizeStringList(json_decode(wp_unslash($postData['availableTypes'] ?? '[]'), true) ?: []),
        ];
    }

    private function sanitizeItems(array $items): array
    {
        $shlink = new Shlink();

        $clean = array_map(function ($i) use ($shlink) {
            $text = wp_kses($i['text'] ?? '', self::ALLOWED_HTML);

            return [
                'label' => sanitize_text_field($i['label'] ?? ''),
                'text' => $text,
                // short URL + QR for every link in the text, generated server-side
                // (the source of truth) so the PDF and TV/REST never call Shlink.
                'links' => $this->shortenLinks($shlink, $text),
            ];
        }, $items);

        // drop empty announcements (no label and no text)
        return array_values(array_filter($clean, fn($i) => $i['label'] !== '' || trim(wp_strip_all_tags($i['text'])) !== ''));
    }

    /**
     * Shorten every http(s) link found in the announcement text. Returns an
     * empty list when Shlink is not configured or unreachable, so saving the
     * bulletin never fails because of the shortener.
     *
     * @return array<int, array{url:string,shortUrl:string,shortCode:string}>
     */
    private function shortenLinks(Shlink $shlink, string $html): array
    {
        if (!$shlink->isConfigured()) {
            return [];
        }

        $links = [];

        foreach ($this->extractUrls($html) as $url) {
            $short = $shlink->shorten($url);

            if ($short) {
                $links[] = ['url' => $url, 'shortUrl' => $short['shortUrl'], 'shortCode' => $short['shortCode']];
            }
        }

        return $links;
    }

    /**
     * Unique http(s) URLs from the href attributes of <a> tags, in order.
     *
     * @return string[]
     */
    private function extractUrls(string $html): array
    {
        if (!preg_match_all('/<a\b[^>]*\bhref\s*=\s*(["\'])(.*?)\1/i', $html, $matches)) {
            return [];
        }

        $urls = [];

        foreach ($matches[2] as $href) {
            $href = html_entity_decode($href, ENT_QUOTES);

            if (preg_match('#^https?://#i', $href) && !in_array($href, $urls, true)) {
                $urls[] = $href;
            }
        }

        return $urls;
    }

    /**
     * Items prepared for the React editor: each stored link gets its QR endpoint
     * URL attached so the editor can show the code instantly on load (no REST
     * round-trip) and only call out for links that aren't shortened yet.
     */
    private function itemsForEditor(array $items): array
    {
        $qr = new QrCode();

        return array_map(function ($item) use ($qr) {
            $links = is_array($item['links'] ?? null) ? $item['links'] : [];

            return [
                'label' => $item['label'] ?? '',
                'text' => $item['text'] ?? '',
                'links' => array_map(fn($l) => [
                    'url' => $l['url'] ?? '',
                    'shortUrl' => $l['shortUrl'] ?? '',
                    'qr' => empty($l['shortUrl']) ? '' : $qr->url($l['shortUrl']),
                ], $links),
            ];
        }, $items);
    }

    private function sanitizeLiturgy(array $rows): array
    {
        $clean = array_map(fn($r) => [
            'day' => max(0, min(31, (int)($r['day'] ?? 0))),
            'month' => max(0, min(12, (int)($r['month'] ?? 0))),
            'text' => sanitize_text_field($r['text'] ?? ''),
            // editor-only provenance, so type toggles keep manual/edited rows on reload
            'type' => sanitize_text_field($r['type'] ?? 'manual'),
            'kbsKey' => isset($r['kbsKey']) && $r['kbsKey'] !== null ? sanitize_text_field((string)$r['kbsKey']) : null,
            'pristine' => (bool)($r['pristine'] ?? false),
        ], $rows);

        return array_values(array_filter($clean, fn($r) => $r['text'] !== '' || $r['day'] || $r['month']));
    }

    /** Order liturgy rows by their actual date within the bulletin's week. */
    private function sortLiturgyByDate(array $rows, string $sundayDate): array
    {
        $valid = (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $sundayDate);
        $baseYear = $valid ? (int)substr($sundayDate, 0, 4) : (int)date('Y');
        $baseMonth = $valid ? (int)substr($sundayDate, 5, 2) : 0;

        $key = function (array $row) use ($baseYear, $baseMonth): int {
            $day = (int)($row['day'] ?? 0);
            $month = (int)($row['month'] ?? 0);

            if (!$day || !$month) {
                return PHP_INT_MAX;
            }

            $year = $baseMonth && $month < $baseMonth ? $baseYear + 1 : $baseYear;

            return checkdate($month, $day, $year) ? (int)mktime(0, 0, 0, $month, $day, $year) : PHP_INT_MAX;
        };

        usort($rows, fn($a, $b) => $key($a) <=> $key($b));

        return $rows;
    }

    private function sanitizeTypes(array $types): array
    {
        $clean = [];

        foreach ($types as $key => $value) {
            $clean[sanitize_text_field((string)$key)] = (bool)$value;
        }

        return $clean;
    }

    private function sanitizeStringList(array $list): array
    {
        return array_values(array_map(fn($v) => sanitize_text_field((string)$v), $list));
    }

    protected function renderForm(array $meta): void
    {
        $post = get_post();
        $id = $post ? $post->ID : 0;

        $initial = wp_json_encode([
            'metaboxId' => $this->metaboxId,
            'restUrl' => esc_url_raw(rest_url('trnavka/v1/')),
            'nonce' => wp_create_nonce('wp_rest'),
            'date' => $meta['date'] ?? '',
            'items' => $this->itemsForEditor($meta['items'] ?? []),
            'liturgy' => $meta['liturgy'] ?? [],
            'types' => $meta['types'] ?? [],
            'availableTypes' => $meta['availableTypes'] ?? [],
            'viewUrl' => $id ? get_permalink($id) : '',
            'pdfUrl' => $id ? add_query_arg('pdf', '1', get_permalink($id)) : '',
            'published' => $post && $post->post_status === 'publish',
            'aiTitles' => (new TitleSuggester())->isConfigured(),
        ]);
        ?>
        <script type="application/json" id="bulletin-data"><?php echo $initial; ?></script>
        <div id="bulletin-app"><noscript>Pre úpravu oznamov je potrebný zapnutý JavaScript.</noscript></div>
        <?php
    }
}
