# Daj na to a finančný prehľad

WordPress pre darcovské kampane **Daj na to** a **finančný prehľad** Saleziánskeho diela na Trnávke.

Kde sa používa, kde beží a kto ho spravuje, je v [IT mape](https://github.com/trnavka/it-mapa/blob/main/systemy/daj-na-to.md).

## Čo robí

- **Kampane Daj na to** – stránky kampaní s darcovským formulárom pre jednorazové aj pravidelné dary. Platbu spracuje [Darujme.sk](https://darujme.sk).
- **Finančný prehľad** – ročný prehľad príjmov a nákladov po častiach diela.
- Hlavičku a najnovšie články preberá zo starého webu trnavka.sk, aby obe časti vyzerali rovnako.

## Technológie

- WordPress na [Bedrocku](https://roots.io/bedrock/) (inštalácia cez Composer)
- téma `packages/trnavka-theme` postavená na [Acorn](https://roots.io/acorn/) (Laravel vo WordPresse), assety cez Webpack Encore
- Darujme.sk API

## Štruktúra

| Priečinok | Obsah |
|---|---|
| `config/` | konfigurácia WordPressu podľa prostredia |
| `web/` | verejný koreň webu – WordPress v `web/wp`, obsah v `web/app` |
| `packages/trnavka-theme/` | téma, Composer ju prepojí do `web/app/themes/theme` |
| `packages/awesome-flickr-gallery-plugin/` | plugin s fotogalériami z Flickru |

## Lokálny vývoj

Potrebné: PHP 8.4 (pozri `.php-version`), Composer, Node.js, MySQL.

1. `composer install`
2. `cp .env.example .env` a vyplniť:
   - `DB_*`, `WP_HOME`, `WP_SITEURL` – databáza a adresa webu
   - `AUTH_KEY` … `NONCE_SALT` – vygenerovať napr. na [roots.io/salts.html](https://roots.io/salts.html)
   - `DARUJME_*` – prístup k Darujme API
   - `DAJNATO_BASE_URL` – cesta, pod ktorou beží Daj na to (`/dajnato/`)
   - `HTML_HEADER_URL` – web, z ktorého sa preberá hlavička (`https://trnavka.sk/`)
   - `LEGACY_DATABASE_URL` – databáza starého webu trnavka.sk, z ktorej sa preberajú najnovšie články
3. Téma: `cd packages/trnavka-theme && npm install && npm run build` (počas vývoja `npm run watch`)
4. Spustiť napr. `symfony serve` z koreňa repozitára.

## Pravidelné úlohy

Spúšťajú sa cez WP-CLI:

| Príkaz | Čo robí |
|---|---|
| `wp acorn update:darujme-stats` | aktualizuje štatistiky kampaní z Darujme.sk |
| `wp acorn aggregate:payments` | agreguje platby |
| `wp acorn update:html-header` | prevezme hlavičku zo starého trnavka.sk |

## Nasadenie

Ručne cez FTP alebo SSH. Deploy skript ani CI nie je.
Zbuildované assety témy (`packages/trnavka-theme/public`) sú v repozitári, takže na serveri netreba spúšťať `npm`.
