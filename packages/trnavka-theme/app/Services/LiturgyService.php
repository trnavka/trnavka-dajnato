<?php

namespace App\Services;

use DateTimeImmutable;
use SimpleXMLElement;

/**
 * Liturgical calendar from the KBS source (lc.kbs.sk) — the same source the
 * bratia.sk/farnost project uses. Returns the celebrations for the days from
 * Monday to the following Sunday, grouped by day, with the type (rank) and a flag
 * whether it is a "common" celebration (checked by default in the editor).
 *
 * @see https://lc.kbs.sk/?format=xml&mesiac=YYYY-MM
 */
class LiturgyService
{
    private const API = 'https://lc.kbs.sk/?format=xml&mesiac=';
    private const CACHE_TTL = 21600; // 6 hours

    /** Sunday celebration name, e.g. "Najsvätejšej Trojice" — used to prefill the title. */
    public function sundayName(string $sundayYmd): string
    {
        $xml = $this->fetchMonth(substr($sundayYmd, 0, 7));

        foreach ($xml ? $xml->xpath('//day') : [] as $day) {
            if ((string)$day['date'] !== $sundayYmd) {
                continue;
            }

            foreach ($day->xpath('.//head/title') ?: [] as $title) {
                if ($name = $this->cleanTitle((string)$title)) {
                    return $name;
                }
            }
        }

        return '';
    }

    /**
     * Celebrations for each day from Monday to the following Sunday, with a
     * normalized `type` so the editor can offer per-type checkboxes. The closing
     * Sunday is listed only when it carries a named celebration.
     *
     * @return array<int, array{day:int,month:int,options:array<int,array{type:string,text:string}>}>
     */
    public function weekOptions(string $sundayYmd): array
    {
        $sunday = DateTimeImmutable::createFromFormat('Y-m-d', $sundayYmd);

        if (!$sunday) {
            return [];
        }

        $targets = [];
        $months = [];

        // Monday..the following Sunday. The next Sunday is included only when it
        // carries a named celebration — an ordinary Sunday yields no options (its
        // title has an empty `solemnity`) and is dropped by the `if ($options)` gate.
        for ($i = 1; $i <= 7; $i++) {
            $d = $sunday->modify("+$i day");
            $targets[$d->format('Y-m-d')] = $d;
            $months[$d->format('Y-m')] = true;
            $months[$d->modify('+1 day')->format('Y-m')] = true; // cover a next-day solemnity over a month boundary
        }

        // Index every fetched day so a celebration can be matched against the
        // *next* day's principal celebration (vigil detection below).
        $byDate = [];

        foreach (array_keys($months) as $ym) {
            $xml = $this->fetchMonth($ym);

            foreach ($xml ? $xml->xpath('//day') : [] as $day) {
                $byDate[(string)$day['date']] = $day;
            }
        }

        // Name of each day's principal (first) celebration, recorded only when it is
        // a solemnity/feast — the eve of such a day may carry a vigil of the same name.
        $principalName = [];

        foreach ($byDate as $date => $day) {
            $first = ($day->xpath('./celebration/head/title') ?: [])[0] ?? null;

            if ($first !== null && $this->isHighRank(trim((string)$first['solemnity']))) {
                $principalName[$date] = $this->cleanTitle((string)$first);
            }
        }

        $days = [];

        foreach ($targets as $date => $target) {
            $day = $byDate[$date] ?? null;

            if (!$day) {
                continue;
            }

            // a vigil on this day shares its name with the next day's solemnity
            $nextSolemnity = $principalName[$target->modify('+1 day')->format('Y-m-d')] ?? null;

            $options = [];

            foreach (($day->xpath('./celebration') ?: []) as $idx => $celebration) {
                // only <head><title> = celebration names (not titles inside readings/gospel)
                foreach ($celebration->xpath('.//head/title') ?: [] as $title) {
                    $solemnity = trim((string)$title['solemnity']);

                    // skip feria ('') and internal codes (#sl, #sv, __NEDELA__ …)
                    if ($solemnity === '' || $solemnity[0] === '#' || str_starts_with($solemnity, '__')) {
                        continue;
                    }

                    $name = $this->cleanTitle((string)$title);

                    if ($name === '') {
                        continue;
                    }

                    // skip the vigil: a secondary celebration whose name matches the
                    // next day's solemnity, so it is offered only on the day itself.
                    if ($idx > 0 && $nextSolemnity !== null && $name === $nextSolemnity) {
                        continue;
                    }

                    [$type, $prefix] = $this->classify($solemnity, $name);
                    $options[] = ['type' => $type, 'text' => trim("$prefix $name")];
                }
            }

            if ($options) {
                $days[$date] = [
                    'day' => (int)$target->format('j'),
                    'month' => (int)$target->format('n'),
                    'options' => $options,
                ];
            }
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * @return array{0:string,1:string} [type, text prefix]
     */
    private function classify(string $solemnity, string $title): array
    {
        $lower = static fn($v) => function_exists('mb_strtolower') ? mb_strtolower($v) : strtolower($v);
        $s = $lower($solemnity);

        if (str_contains($s, 'pôst') || str_contains($s, 'pokán') || str_contains($s, 'večerná')) {
            return ['poznámka', ''];
        }
        if (str_contains($s, 'ľubovoľná')) {
            return ['ľubovoľná spomienka', 'ľubovoľná spomienka'];
        }

        $base = str_contains($s, 'slávnosť') ? 'slávnosť' : (str_contains($s, 'sviatok') ? 'sviatok' : (str_contains($s, 'spomienka') ? 'spomienka' : ''));

        // place/diocese-bound variants — markers may be in the solemnity OR the title (e.g. "(len v katedrále)")
        if (preg_match('/katedrál|diecéz|ordinariát|posvät|konsekrov|konkatedrál|arcidiecéz|okrem|\biba\b|\blen\b/u', $s . ' ' . $lower($title))) {
            return ['variant', $base];
        }

        return $base ? [$base, $base] : [$solemnity, ''];
    }

    /** True for the top liturgical ranks (solemnity / feast). */
    private function isHighRank(string $solemnity): bool
    {
        $s = function_exists('mb_strtolower') ? mb_strtolower($solemnity) : strtolower($solemnity);

        return str_contains($s, 'slávnosť') || str_contains($s, 'sviatok');
    }

    private function cleanTitle(string $raw): string
    {
        // strip /* ... */ and [ ... ] notes (same as the farnost repo)
        return trim(preg_replace('/\s+/', ' ', (string)preg_replace(['~/\*.*?\*/~s', '~\[.*?\]~'], '', $raw)));
    }

    private function fetchMonth(string $ym): ?SimpleXMLElement
    {
        $key = 'bulletin_liturgy_' . $ym;
        $body = get_transient($key);

        if (!is_string($body) || $body === '') {
            $response = wp_remote_get(self::API . $ym, ['timeout' => 8]);

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return null;
            }

            $body = wp_remote_retrieve_body($response);

            if (empty($body)) {
                return null;
            }

            set_transient($key, $body, self::CACHE_TTL);
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $xml instanceof SimpleXMLElement ? $xml : null;
    }
}
