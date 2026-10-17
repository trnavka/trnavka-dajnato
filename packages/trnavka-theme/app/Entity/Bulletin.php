<?php

namespace App\Entity;

/**
 * One weekly parish bulletin ("farské oznamy"): a Sunday date, a title, the
 * week's liturgy rows and a list of announcement items.
 */
class Bulletin
{
    private const MONTHS = [
        'januára', 'februára', 'marca', 'apríla', 'mája', 'júna',
        'júla', 'augusta', 'septembra', 'októbra', 'novembra', 'decembra',
    ];
    private const WEEKDAYS = [1 => 'Pondelok', 2 => 'Utorok', 3 => 'Streda', 4 => 'Štvrtok', 5 => 'Piatok', 6 => 'Sobota', 7 => 'Nedeľa'];

    /**
     * @param array<int, array{label:string,text:string}> $items
     * @param array<int, array{day:int,month:int,text:string}> $liturgy
     */
    public function __construct(
        public int     $id,
        public string  $title,
        public ?string $date,
        public array   $items,
        public array   $liturgy,
    )
    {
    }

    public function url(): string
    {
        return (string)get_permalink($this->id);
    }

    public function formattedDate(): string
    {
        $ts = $this->date ? strtotime($this->date) : false;

        return $ts ? (int)date('j', $ts) . '. ' . self::MONTHS[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts) : '';
    }

    /**
     * Liturgy rows sorted by date, with the weekday derived from day + month +
     * the Sunday's year (handles the December→January boundary; invalid dates
     * keep an empty weekday and sort last).
     *
     * @return array<int, array{weekday:string,date:string,text:string}>
     */
    public function liturgyRows(): array
    {
        $baseYear = $this->date ? (int)substr($this->date, 0, 4) : (int)date('Y');
        $baseMonth = $this->date ? (int)substr($this->date, 5, 2) : 0;

        $rows = array_map(function ($row) use ($baseYear, $baseMonth) {
            $day = (int)($row['day'] ?? 0);
            $month = (int)($row['month'] ?? 0);
            $year = $baseMonth && $month < $baseMonth ? $baseYear + 1 : $baseYear;
            $valid = $day && $month && checkdate($month, $day, $year);

            return [
                'sort' => $valid ? mktime(0, 0, 0, $month, $day, $year) : PHP_INT_MAX,
                'weekday' => $valid ? (self::WEEKDAYS[(int)date('N', mktime(0, 0, 0, $month, $day, $year))] ?? '') : '',
                'date' => $day && $month ? "$day. $month." : '',
                'text' => $row['text'] ?? '',
            ];
        }, $this->liturgy);

        usort($rows, fn($a, $b) => $a['sort'] <=> $b['sort']);

        return array_map(fn($r) => ['weekday' => $r['weekday'], 'date' => $r['date'], 'text' => $r['text']], $rows);
    }
}
