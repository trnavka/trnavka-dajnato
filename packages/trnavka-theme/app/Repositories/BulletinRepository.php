<?php

namespace App\Repositories;

use App\Entity\Bulletin;
use App\Metabox\BulletinMetabox;
use WP_Post;
use WP_Query;

class BulletinRepository
{
    public function find(int|WP_Post $postOrId): ?Bulletin
    {
        $post = $postOrId instanceof WP_Post ? $postOrId : get_post($postOrId);

        return $post instanceof WP_Post && $post->post_type === 'bulletin' ? $this->hydrate($post) : null;
    }

    /** Current bulletin for TV/homepage: latest whose Sunday has passed, else nearest upcoming. */
    public function current(): ?Bulletin
    {
        $today = current_time('Y-m-d');
        $post = $this->queryOne('<=', 'DESC', $today) ?? $this->queryOne('>=', 'ASC', $today);

        return $post ? $this->hydrate($post) : null;
    }

    /**
     * Bulletin for the current week: the latest published Sunday on or before today.
     * Since `date` is a Sunday, it stays put all week (Sun–Sat) and flips to the next
     * Sunday's bulletin exactly at Saturday-midnight (next Sunday 00:00, WP timezone).
     * A bulletin published ahead is not served until its Sunday arrives.
     */
    public function latest(): ?Bulletin
    {
        $post = $this->queryOne('<=', 'DESC', current_time('Y-m-d'));

        return $post ? $this->hydrate($post) : null;
    }

    /** @return Bulletin[] */
    public function recent(int $limit = 100): array
    {
        return array_map([$this, 'hydrate'], (new WP_Query([
            'post_type' => 'bulletin',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_key' => 'bulletin_date',
            'orderby' => 'meta_value',
            'meta_type' => 'DATE',
            'order' => 'DESC',
        ]))->get_posts());
    }

    private function queryOne(string $compare, string $order, string $today): ?WP_Post
    {
        $posts = (new WP_Query([
            'post_type' => 'bulletin',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'meta_key' => 'bulletin_date',
            'orderby' => 'meta_value',
            'meta_type' => 'DATE',
            'order' => $order,
            'meta_query' => [['key' => 'bulletin_date', 'value' => $today, 'compare' => $compare, 'type' => 'DATE']],
        ]))->get_posts();

        return $posts[0] ?? null;
    }

    private function hydrate(WP_Post $post): Bulletin
    {
        $data = get_post_meta($post->ID, BulletinMetabox::id(), true);
        $data = is_array($data) ? $data : [];

        return new Bulletin(
            $post->ID,
            $post->post_title,
            $data['date'] ?? (get_post_meta($post->ID, 'bulletin_date', true) ?: null),
            is_array($data['items'] ?? null) ? $data['items'] : [],
            is_array($data['liturgy'] ?? null) ? $data['liturgy'] : [],
        );
    }
}
