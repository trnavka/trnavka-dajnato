<?php

namespace App\Roles;

use App\Metabox\BulletinMetabox;
use WP_Role;
use WP_User;

/**
 * "Správca farských oznamov" — a role that can manage only Farské oznamy (the
 * 'bulletin' custom post type) and nothing else.
 *
 * The bulletin CPT uses its own capability set (capability_type + map_meta_cap,
 * see BulletinMetabox), so the role gets just `read` + those bulletin caps and
 * WordPress hides everything else (Posts, Pages, Media, Comments, Appearance,
 * Plugins, Tools, Settings, Users).
 *
 * Because the CPT no longer uses the generic post caps, administrators/editors
 * would otherwise lose access to the bulletins too. Rather than persisting caps
 * onto those roles (which drifts and depends on a one-off sync having already
 * run), the bulletin caps are granted at runtime via a `user_has_cap` filter —
 * so they are present on every request (admin, REST, front-end) with no
 * activation/version dance.
 */
class BulletinRole
{
    public const ROLE = 'bulletin_manager';
    public const LABEL = 'Správca farských oznamov';

    /** Roles that also get full access to the bulletins, in addition to ROLE. */
    private const GRANT_TO = ['administrator', 'editor', self::ROLE];

    public function __construct()
    {
        add_action('init', [$this, 'registerRole']);
        add_filter('user_has_cap', [$this, 'grantBulletinCaps'], 10, 4);
    }

    /**
     * The primitive capabilities WordPress derives from the CPT's capability_type
     * (with map_meta_cap on). Built from the plural base so BulletinMetabox stays
     * the single source of truth; the meta caps (edit_post/read_post/delete_post)
     * are intentionally omitted — they are resolved through map_meta_cap.
     *
     * @return string[]
     */
    public static function caps(): array
    {
        $plural = BulletinMetabox::CAPABILITY_TYPE[1];

        return [
            "edit_$plural",
            "edit_others_$plural",
            "edit_published_$plural",
            "edit_private_$plural",
            "publish_$plural",
            "read_private_$plural",
            "delete_$plural",
            "delete_others_$plural",
            "delete_published_$plural",
            "delete_private_$plural",
        ];
    }

    /** Create the assignable role if missing. Its caps are added by the filter. */
    public function registerRole(): void
    {
        if (!get_role(self::ROLE) instanceof WP_Role) {
            add_role(self::ROLE, self::LABEL, ['read' => true]);
        }
    }

    /**
     * Grant the bulletin caps at runtime to the bulletin role and to admins/
     * editors, so they can manage oznamy even though the CPT no longer uses the
     * generic post caps.
     *
     * @param array<string,bool> $allcaps the user's current capabilities
     * @param string[]           $caps    required primitive caps (unused)
     * @param array              $args    [meta cap, user id, object id] (unused)
     * @param WP_User            $user    the user being checked
     * @return array<string,bool>
     */
    public function grantBulletinCaps($allcaps, $caps, $args, $user): array
    {
        $roles = $user instanceof WP_User ? (array)$user->roles : [];

        if (array_intersect(self::GRANT_TO, $roles)) {
            foreach (self::caps() as $cap) {
                $allcaps[$cap] = true;
            }
        }

        return (array)$allcaps;
    }
}
