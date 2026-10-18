<?php

namespace App\Rest;

/**
 * Public OpenAPI document for the site's REST API (trnavka/v1), served at
 *   GET /wp-json/trnavka/v1/openapi
 * so it can be handed to external consumers (e.g. the church-TV agent). It
 * describes the whole public API — currently just bulletins/latest — and is
 * generated from PHP so the server URL matches the live site.
 */
class OpenApiController
{
    public function __construct()
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        register_rest_route('trnavka/v1', '/openapi', [
            'methods' => 'GET',
            'callback' => [$this, 'spec'],
            'permission_callback' => '__return_true',
        ]);

        // Human-readable, rendered docs (Swagger UI) for the same spec.
        register_rest_route('trnavka/v1', '/openapi/docs', [
            'methods' => 'GET',
            'callback' => [$this, 'docs'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function spec(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo wp_json_encode($this->document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /** Renders the spec with Swagger UI (loaded from a CDN). */
    public function docs(): void
    {
        $specUrl = esc_url(rest_url('trnavka/v1/openapi'));

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!doctype html>
<html lang="sk">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>trnavka.sk — API</title>
<link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
<style>body { margin: 0; }</style>
</head>
<body>
<div id="swagger-ui"></div>
<script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js" crossorigin></script>
<script>
  window.ui = SwaggerUIBundle({ url: "{$specUrl}", dom_id: "#swagger-ui" });
</script>
</body>
</html>
HTML;
        exit;
    }

    private function document(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'trnavka.sk — verejné API',
                'version' => '1.0.0',
                'description' => 'Verejné REST API webu trnavka.sk (namespace trnavka/v1).',
            ],
            'servers' => [
                ['url' => rtrim(rest_url('trnavka/v1'), '/')],
            ],
            'paths' => [
                '/bulletins/latest' => [
                    'get' => [
                        'operationId' => 'getLatestBulletin',
                        'summary' => 'Farské oznamy + liturgický týždeň pre aktuálnu nedeľu',
                        'description' => 'Vráti bulletin poslednej dostupnej nedele (date ≤ dnes). Drží sa celý '
                            . 'týždeň a v sobotu o polnoci (najbližšia nedeľa 00:00) sa prepne na ďalšiu nedeľu. '
                            . 'Vracia iba publikované bulletiny. Určené aj pre neinteraktívne zariadenia '
                            . '(TV, digital signage) — viď pole non_interactive_text.',
                        'security' => [['ApiKeyQuery' => []]],
                        'parameters' => [
                            [
                                'name' => 'api_key',
                                'in' => 'query',
                                'required' => true,
                                'description' => 'Autorizačný kľúč.',
                                'schema' => ['type' => 'string'],
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Bulletin aktuálnej nedele, alebo {"found": false} ak žiadny nie je dostupný.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['$ref' => '#/components/schemas/Bulletin'],
                                    ],
                                ],
                            ],
                            '401' => ['description' => 'Chýbajúci alebo neplatný api_key.'],
                        ],
                    ],
                ],
            ],
            'components' => [
                'securitySchemes' => [
                    'ApiKeyQuery' => [
                        'type' => 'apiKey',
                        'in' => 'query',
                        'name' => 'api_key',
                    ],
                ],
                'schemas' => [
                    'Bulletin' => [
                        'type' => 'object',
                        'required' => ['found'],
                        'properties' => [
                            'found' => ['type' => 'boolean', 'description' => 'false = žiadny dostupný bulletin (ostatné polia chýbajú).'],
                            'id' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'date' => ['type' => 'string', 'format' => 'date', 'example' => '2026-06-21', 'description' => 'Nedeľa, ktorej sa bulletin týka (ISO 8601).'],
                            'dateFormatted' => ['type' => 'string', 'example' => '21. júna 2026'],
                            'url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Odkaz na bulletin na webe.'],
                            'liturgicalDays' => [
                                'type' => 'array',
                                'description' => 'Liturgické dni týždňa, zoradené podľa dátumu.',
                                'items' => ['$ref' => '#/components/schemas/LiturgicalDay'],
                            ],
                            'announcements' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Announcement'],
                            ],
                        ],
                    ],
                    'LiturgicalDay' => [
                        'type' => 'object',
                        'properties' => [
                            'weekday' => ['type' => 'string', 'example' => 'Pondelok'],
                            'date' => ['type' => 'string', 'example' => '22. 6.'],
                            'text' => ['type' => 'string', 'example' => 'Spomienka sv. …'],
                        ],
                    ],
                    'Announcement' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string', 'description' => 'Krátky nadpis oznamu (môže byť prázdny).'],
                            'text' => ['type' => 'string', 'description' => 'HTML oznamu — môže obsahovať <a href>, <strong>, <br>.'],
                            'non_interactive_text' => [
                                'type' => 'string',
                                'description' => 'Rovnaký obsah ako pole „text", no odkazy sú nahradené samotnou skrátenou '
                                    . 'adresou v podobe čistého textu, bez predpony https:// (napr. sdb.sk/abc). Určené pre '
                                    . 'zariadenia bez interakcie (TV, informačné obrazovky), kde sa na odkaz nedá kliknúť. '
                                    . 'Na takýchto zariadeniach odporúčame pri každom odkaze zobraziť aj jeho QR kód '
                                    . '(pole links[].qr).',
                            ],
                            'links' => [
                                'type' => 'array',
                                'items' => ['$ref' => '#/components/schemas/Link'],
                            ],
                        ],
                    ],
                    'Link' => [
                        'type' => 'object',
                        'properties' => [
                            'url' => ['type' => 'string', 'format' => 'uri', 'description' => 'Pôvodná URL v texte oznamu.'],
                            'shortUrl' => ['type' => 'string', 'format' => 'uri', 'description' => 'Skrátená URL.'],
                            'qr' => ['type' => 'string', 'format' => 'uri', 'description' => 'URL, ktorá vráti SVG QR kód pre skrátenú URL.'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
