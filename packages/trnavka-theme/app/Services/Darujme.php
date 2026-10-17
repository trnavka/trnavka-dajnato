<?php

namespace App\Services;

use App\Entity\Campaign;
use App\Repositories\CampaignRepository;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Darujme
{
    public function __construct(private CampaignRepository $campaignRepository)
    {
    }

    public function updateCampaigns(): void
    {
        foreach ($this->campaignRepository->findAllPublished() as $campaign) {
            $this->updateCampaignSources($campaign);
        }
    }

    private function updateCampaignSources(Campaign $campaign): void
    {
        $sources = [];
        $selfSources = [];
        $fundSource = null;
        $campaigns = [];

        $sum = 0;

        $sourceDefinitions = [];

        foreach (($campaign->getConfig()['sources'] ?? []) as $sourceDefinition) {
            $sourceCampaignId = $sourceDefinition['campaign_id'] ?? null;
            $sourceLabel = $sourceDefinition['label'] ?? null;

            if (in_array($sourceCampaignId, ['__self', $campaign->darujmeId]) || $sourceLabel === '__self') {
                $selfSource = $sourceDefinition;
                $selfSource['__label'] = '__self';

                if ('__self' === $sourceCampaignId) {
                    $selfSource['campaign_id'] = $campaign->darujmeId;
                }

                $selfSources[] = $selfSource;
            } elseif ($sourceCampaignId === '__fund') {
                $fundSource = [
                    'label' => '__fund',
                    'value' => empty($sourceDefinition['value']) ? $campaign->dajnatoAmount : $sourceDefinition['value'],
                ];
            } else {
                $sourceDefinitions[] = $sourceDefinition;
            }
        }

        if (empty($selfSources)) {
            $selfSources = [[
                'label' => '__self',
                'campaign_id' => $campaign->darujmeId,
                'value' => 0,
            ]];
        }

        if (null === $fundSource && $campaign->dajnatoAmount > 0) {
            $fundSource = [
                'label' => '__fund',
                'value' => $campaign->dajnatoAmount,
            ];
        }

        $sourceDefinitions = [...$selfSources, ...(null === $fundSource ? [] : [$fundSource]), ...$sourceDefinitions];
        $currentMonthValue = 0;
        $currentMonth = date('Y-m');

        foreach ($sourceDefinitions as $sourceDefinition) {
            $value = ($sourceDefinition['value'] ?? 0) * 100;

            if (isset($sourceDefinition['campaign_id'])) {
                $monthlyValues = $this->calculatePaymentsSum($sourceDefinition);
                $currentMonthValue += $monthlyValues->filter(fn($monthlyValue) => $monthlyValue->month === $currentMonth)->pluck('value')->sum();
                $value += $monthlyValues->pluck('value', 'month')->sum();
                $campaigns[$sourceDefinition['campaign_id']] = $value;
            }

            $sources[$sourceDefinition['label']] = ($sources[$sourceDefinition['label']] ?? 0) + $value;
            $sum += $value;
        }

        $campaign->setSources([
            'sources' => $sources,
            'campaigns' => $campaigns,
            'sum' => $sum,
            '__self' => $sources['__self'] ?? 0,
            '__self_current_month' => $currentMonthValue,
            '__fund' => $sources['__fund'] ?? 0,
        ]);

        update_post_meta($campaign->id, 'dajnato_campaign_sources', json_encode($campaign->sources, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function calculatePaymentsSum(array $source): Collection
    {
        $query = DB::table('darujme_payments')->where('campaign_id', '=', $source['campaign_id']);

        if (isset($source['since'])) {
            $query->where('received_at', '>=', $source['since']);
        }

        if (isset($source['until'])) {
            $query->where('received_at', '<', $source['until']);
        }

        return $query
            ->groupByRaw('month')
            ->selectRaw("DATE_FORMAT(received_at, '%Y-%m') AS month, SUM(value) AS value")
            ->get(['month', 'value']);
    }

    public function updatePayments(): void
    {
        try {
            $page = 1;
            $token = $this->auth($this->parameter('DARUJME_API_USERNAME'), $this->parameter('DARUJME_API_PASSWORD'));

            $timezone = new DateTimeZone('Europe/Bratislava');
            $now = (new DateTimeImmutable('30 minutes ago'))->setTimezone($timezone)->format('Y-m-d H:i:s');

            $lastReceivedAt = (new DateTimeImmutable(get_option('last_import_from_darujme_at') ?? '2019-01-01 00:00:00', $timezone))->setTimezone(new DateTimeZone('UTC'))->format("Y-m-d\TH:i:s\Z");

            do {
                $response = $this->request('GET', '/v1/payments/', $token, null, [
                    'status' => 'successful',
                    'limit' => 1000,
                    'page' => $page,
                    'updated_gte' => $lastReceivedAt
                ]);

                print_r($response['metadata']);

                foreach ($response['items'] ?? [] as $payment) {

                    if ('successful' !== $payment['status']) {
                        continue;
                    }

                    $updateFields = [
                        'payment_id' => $payment['id'],
                        'name' => $payment['donation']['donor']['name'],
                        'surname' => $payment['donation']['donor']['surname'],
                        'email' => $payment['donation']['donor']['email'],
                        'variable_symbol' => $payment['variable_symbol'],
                        'periodicity' => $payment['donation']['periodicity'],
                        'value' => (int)round($payment['value'] * 100),
                        'payment_type' => $payment['donation']['payment_method']['handle'],
                        'campaign' => $payment['donation']['campaign']['note'],
                        'campaign_id' => $payment['donation']['campaign']['id'],
                        'received_at' => (new DateTimeImmutable($payment['happened_at']))->setTimezone($timezone)->format('Y-m-d H:i:s'),
                        'registered_at' => (new DateTimeImmutable($payment['created_at']))->setTimezone($timezone)->format('Y-m-d H:i:s'),
                        'updated_at' => $now,
                    ];

                    $createFields = [
                        'created_at' => $now,
                    ];

                    DB::table('darujme_payments')->upsert($updateFields + $createFields, 'payment_id', array_keys($updateFields));
                }
                $page++;
            } while ($page <= ($response['metadata'] ?? [])['pages'] ?? 0);

            update_option('last_import_from_darujme_at', $now, false);
        } catch (Exception $e) {
            echo sprintf('Error: %s', $e->getMessage());
            exit;
        }
    }

    public function subscriptionStats(): \stdClass
    {
        return DB::select("
                SELECT
	                SUM(p.value) AS sum,
                    COUNT(DISTINCT IFNULL(u.authoritative_email, p.email)) AS count
                FROM
                    wp_darujme_payments AS p
                LEFT JOIN
                    wp_darujme_users AS u
                ON
                    p.email = u.email
                WHERE
                    p.campaign IN ('dielo_trnavka', 'ba_trnavka_dielo_trnavka')
                        AND
                    p.received_at >= '2023-01-01 00:00:00'")[0];
    }

    public function stats(): array
    {
        return [
            'all_payments' => $this->statsPayments(),
            'old_dielo_payments' => $this->statsPayments(['ba_trnavka_dielo_trnavka'], 'DATE_FORMAT(registered_at, \'%Y-%m\')'),
            'new_dielo_payments' => $this->statsPayments(['dielo_trnavka'], 'DATE_FORMAT(registered_at, \'%Y-%m\')'),
        ];
    }

    private function statsPayments(
        array|null $campaigns = null,
        string     $groupBy = 'YEAR(p.received_at)'
    ): array
    {
        $campaignsCondition = '';

        if (is_array($campaigns)) {
            $campaignsCondition = 'WHERE p.campaign IN (' . implode(',', array_map(fn(
                    $campaign
                ) => "'$campaign'", $campaigns)) . ')';
        }

        return [
            'all_users' => DB::select(sprintf(
                'SELECT
                    COUNT(DISTINCT IFNULL(u.authoritative_email, p.email)) AS count
                FROM
                    wp_darujme_payments AS p
                LEFT JOIN
                    wp_darujme_users AS u
                ON
                    p.email = u.email
                %s
                ', $campaignsCondition))[0]->count,
            'by_year' => DB::select(sprintf(
                'SELECT
                    %s AS year,
                    COUNT(DISTINCT IFNULL(u.authoritative_email, p.email)) AS count,
                    SUM(p.value) AS sum
                FROM
                    wp_darujme_payments AS p
                LEFT JOIN
                    wp_darujme_users AS u
                ON
                    p.email = u.email
                %s
                GROUP BY
                    YEAR
                ORDER BY
                    YEAR DESC
                ', $groupBy, $campaignsCondition)),
        ];
    }

    /**
     * @param string $login
     * @param string $password
     * @return string
     * @throws Exception
     */
    private function auth(
        string $login,
        string $password
    ): string
    {
        $response = $this->request('POST', '/v1/tokens/', null, [
            'username' => $login,
            'password' => $password,
        ]);

        if (isset($response['error'])) {
            throw new Exception($response['error']['message']);
        }

        return $response['response']['token'];
    }

    private function request(
        string      $method,
        string      $path,
        string|null $token = null,
        array|null  $data = null,
        array       $urlParameters = []
    ): array
    {
        $url = 'https://api.darujme.sk' . $path;

        $ch = curl_init($url . '?' . http_build_query($urlParameters));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        if (null === $data) {
            $payload = '';
        } else {
            $payload = json_encode($data);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $headers = [
            'Content-Type: application/json',
            'X-ApiKey: ' . $this->parameter('DARUJME_API_KEY'),
            'X-Organisation: ' . $this->parameter('DARUJME_ORGANISATION_ID'),
            'X-Signature: ' . $this->signature($payload, $path)
        ];

        if (null !== $token) {
            $headers[] = 'Authorization: TOKEN ' . $token;
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);

        return json_decode($response, true);
    }

    /**
     * @throws Exception
     */
    private function parameter(string $name): string
    {
        return env($name) ?? throw new Exception(sprintf('Missing environment parameter "%s"', $name));
    }

    private function signature(
        string $payload,
        string $url
    ): string
    {
        return hash_hmac('sha256', "$payload:$url", $this->parameter('DARUJME_API_SECRET'));
    }
}
