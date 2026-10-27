<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/**
 * Separate dictionaries on one site. Each dictionary is a term of its own
 * taxonomy; every entry belongs to exactly one. A dictionary has its own
 * address (/dictionary/<dictionary>/), its own words (the same headword can
 * be in two dictionaries), optional editors of its own, and can change any
 * of the site-wide settings listed in OVERRIDES. Settings it leaves alone
 * follow Dictionary → Settings.
 */
final class Dictionaries
{
    public const TAX = 'sepo_dictionary';
    public const META_SETTINGS = 'sdict_settings';
    public const META_EDITORS = 'sdict_editors';
    public const META_OLD_SLUGS = 'sdict_old_slugs';

    /** Settings a dictionary may set for itself, with how each is stored. */
    public const OVERRIDES = [
        'accent'          => 'colour',
        'per_page'        => 'int',
        'word_of_day'     => 'bool',
        'parts_of_speech' => 'lines',
        'allow_proposals' => 'bool',
        'votes_needed'    => 'int',
        'vote_margin'     => 'int',
        'reject_at'       => 'int',
        'voting_days'     => 'int',
        'settle_on_close' => 'bool',
        'review'          => 'bool',
        'max_open'        => 'int',
        'notify_email'    => 'email',
        'notify_new'      => 'bool',
    ];

    /** Lowest and highest value for each number setting. */
    public const LIMITS = [
        'per_page'     => [6, 120],
        'votes_needed' => [1, 100],
        'vote_margin'  => [0, 100],
        'reject_at'    => [1, 100],
        'voting_days'  => [0, 365],
        'max_open'     => [0, 500],
    ];

    /** @var array<int,array<string,mixed>> */
    private static array $settingsMemo = [];

    /** @var list<\WP_Term>|null */
    private static ?array $allMemo = null;

    public static function hook(): void
    {
        add_action('init', [self::class, 'register'], 9);
        // Remember a dictionary's earlier address, so links to it keep working after a rename.
        add_action('edit_terms', [self::class, 'rememberSlug'], 10, 2);
        foreach (['created_', 'edited_', 'delete_'] as $hook) {
            add_action($hook . self::TAX, static function (): void {
                self::$allMemo = null;
                self::$settingsMemo = [];
            });
        }
        add_filter('user_has_cap', [self::class, 'grantEntryCaps'], 10, 4);
        // Words of a deleted dictionary move to the default one; each gets an address that is free there.
        add_action('delete_' . self::TAX, static function ($term, $ttId, $deleted, $objectIds): void {
            global $wpdb;
            $wpdb->update(Schema::proposals(), ['dict_id' => self::defaultId()], ['dict_id' => (int) $term]);
            foreach ((array) $objectIds as $id) {
                $post = get_post((int) $id);
                if ($post instanceof \WP_Post && $post->post_type === PostType::TYPE && $post->post_status !== 'trash') {
                    wp_update_post(['ID' => (int) $id, 'post_name' => sanitize_title($post->post_title)]);
                }
            }
        }, 10, 4);
    }

    public static function register(): void
    {
        register_taxonomy(self::TAX, PostType::TYPE, [
            'labels' => [
                'name'          => __('Dictionaries', 'sepo-dictionary'),
                'singular_name' => __('Dictionary', 'sepo-dictionary'),
                'menu_name'     => __('Dictionaries', 'sepo-dictionary'),
                'all_items'     => __('All dictionaries', 'sepo-dictionary'),
                'edit_item'     => __('Edit dictionary', 'sepo-dictionary'),
                'view_item'     => __('View dictionary', 'sepo-dictionary'),
                'update_item'   => __('Update dictionary', 'sepo-dictionary'),
                'add_new_item'  => __('Add a dictionary', 'sepo-dictionary'),
                'new_item_name' => __('New dictionary name', 'sepo-dictionary'),
                'search_items'  => __('Search dictionaries', 'sepo-dictionary'),
                'not_found'     => __('No dictionaries yet.', 'sepo-dictionary'),
                'back_to_items' => __('← Back to dictionaries', 'sepo-dictionary'),
            ],
            'public'             => true,
            'publicly_queryable' => false,
            'hierarchical'       => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_admin_column'  => true,
            'show_in_nav_menus'  => false,
            'show_in_rest'       => false,
            'show_tagcloud'      => false,
            'meta_box_cb'        => false,
            'query_var'          => false,
            'rewrite'            => false,
            // Only people who may change the site's settings add, rename or delete dictionaries.
            'capabilities'       => [
                'manage_terms' => 'manage_options',
                'edit_terms'   => 'manage_options',
                'delete_terms' => 'manage_options',
                'assign_terms' => (string) apply_filters('sepo_dictionary_manage_capability', 'edit_others_posts'),
            ],
            // WordPress keeps a default term that cannot be deleted, and gives it to entries with none.
            'default_term'       => [
                'name' => __('Main dictionary', 'sepo-dictionary'),
                'slug' => 'main',
            ],
        ]);
    }

    // ------------------------------------------------------------- reading

    /** @return list<\WP_Term> every dictionary, by name */
    public static function all(): array
    {
        if (self::$allMemo !== null) {
            return self::$allMemo;
        }
        $t = get_terms(['taxonomy' => self::TAX, 'hide_empty' => false, 'orderby' => 'name']);
        return self::$allMemo = is_array($t) ? array_values(array_filter($t, static fn($x): bool => $x instanceof \WP_Term)) : [];
    }

    public static function count(): int
    {
        return count(self::all());
    }

    /** More than one dictionary: the site shows a list of them and labels words with theirs. */
    public static function many(): bool
    {
        return self::count() > 1;
    }

    /** The dictionary WordPress gives to entries that have none. */
    public static function defaultId(): int
    {
        $id = (int) get_option('default_term_' . self::TAX);
        if ($id > 0 && term_exists($id, self::TAX)) {
            return $id;
        }
        $all = self::all();
        return $all !== [] ? (int) $all[0]->term_id : 0;
    }

    /** A dictionary by term id, slug or name. */
    public static function get(int|string $ref): ?\WP_Term
    {
        if (is_int($ref) || ctype_digit((string) $ref)) {
            $t = get_term((int) $ref, self::TAX);
            return $t instanceof \WP_Term ? $t : null;
        }
        $ref = trim((string) $ref);
        if ($ref === '') {
            return null;
        }
        $t = get_term_by('slug', sanitize_title($ref), self::TAX);
        if (!$t instanceof \WP_Term) {
            $t = get_term_by('name', $ref, self::TAX);
        }
        return $t instanceof \WP_Term ? $t : null;
    }

    /** A dictionary's id from an id, slug or name; 0 when there is no such dictionary. */
    public static function id(mixed $ref): int
    {
        if ($ref instanceof \WP_Term) {
            return (int) $ref->term_id;
        }
        if (!is_scalar($ref) || (string) $ref === '' || (string) $ref === '0') {
            return 0;
        }
        $t = self::get(is_int($ref) ? $ref : (string) $ref);
        return $t instanceof \WP_Term ? (int) $t->term_id : 0;
    }

    public static function name(int $id): string
    {
        $t = $id > 0 ? self::get($id) : null;
        return $t instanceof \WP_Term ? (string) $t->name : '';
    }

    public static function slug(int $id): string
    {
        $t = $id > 0 ? self::get($id) : null;
        return $t instanceof \WP_Term ? (string) $t->slug : '';
    }

    /** The dictionary an entry belongs to (the default one when it has none yet). */
    public static function forEntry(int $postId): int
    {
        $t = $postId > 0 ? get_the_terms($postId, self::TAX) : false;
        if (is_array($t) && $t !== []) {
            return (int) $t[0]->term_id;
        }
        return self::defaultId();
    }

    /** A dictionary that used this address before it was renamed. */
    public static function byOldSlug(string $slug): int
    {
        global $wpdb;
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT tm.term_id FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id AND tt.taxonomy = %s WHERE tm.meta_key = %s AND tm.meta_value = %s LIMIT 1",
            self::TAX,
            self::META_OLD_SLUGS,
            $slug
        ));
        return $id;
    }

    public static function rememberSlug(int $termId, string $taxonomy): void
    {
        if ($taxonomy !== self::TAX) {
            return;
        }
        $t = get_term($termId, self::TAX);
        $new = isset($_POST['slug']) ? sanitize_title(wp_unslash((string) $_POST['slug'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- the term screen checks its own nonce
        if ($t instanceof \WP_Term && $t->slug !== '' && $t->slug !== $new) {
            $old = get_term_meta($termId, self::META_OLD_SLUGS, false);
            if (!in_array($t->slug, (array) $old, true)) {
                add_term_meta($termId, self::META_OLD_SLUGS, $t->slug);
            }
        }
    }

    // ------------------------------------------------------------- settings

    /** @return array<string,mixed> only what this dictionary sets for itself */
    public static function overrides(int $id): array
    {
        if ($id < 1) {
            return [];
        }
        if (isset(self::$settingsMemo[$id])) {
            return self::$settingsMemo[$id];
        }
        $raw = get_term_meta($id, self::META_SETTINGS, true);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $k => $v) {
            if (isset(self::OVERRIDES[$k]) && $v !== '' && $v !== null) {
                $out[$k] = $v;
            }
        }
        return self::$settingsMemo[$id] = $out;
    }

    /** A setting as it applies in one dictionary: its own value, or the site's. */
    public static function setting(int $id, string $key): mixed
    {
        $own = self::overrides($id);
        return array_key_exists($key, $own) ? $own[$key] : Settings::get($key);
    }

    public static function int(int $id, string $key, int $min = 0): int
    {
        return max($min, (int) self::setting($id, $key));
    }

    public static function on(int $id, string $key): bool
    {
        return (int) self::setting($id, $key) === 1;
    }

    /** @return list<string> */
    public static function partsOfSpeech(int $id): array
    {
        return array_key_exists('parts_of_speech', self::overrides($id))
            ? Settings::parsePos((string) self::overrides($id)['parts_of_speech'])
            : Settings::partsOfSpeech();
    }

    /** The accent colour for a dictionary that sets its own, else ''. */
    public static function accent(int $id): string
    {
        $a = (string) (self::overrides($id)['accent'] ?? '');
        return preg_match('~^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$~', $a) === 1 ? $a : '';
    }

    /** Whether any dictionary sets its own voting rules. */
    public static function rulesDiffer(): bool
    {
        foreach (self::all() as $t) {
            if (array_intersect_key(self::overrides((int) $t->term_id), array_flip(['votes_needed', 'vote_margin', 'reject_at', 'voting_days', 'settle_on_close', 'review'])) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * Clean submitted overrides: an empty value means "follow the site setting".
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function cleanOverrides(array $in): array
    {
        $out = [];
        foreach (self::OVERRIDES as $k => $type) {
            $v = $in[$k] ?? '';
            if (is_string($v)) {
                $v = trim($v);
            }
            if ($v === '' || $v === null || $v === 'site') {
                continue;
            }
            switch ($type) {
                case 'int':
                    [$min, $max] = self::LIMITS[$k];
                    $out[$k] = max($min, min($max, (int) $v));
                    break;
                case 'bool':
                    $out[$k] = (string) $v === '1' || $v === 'on' ? 1 : 0;
                    break;
                case 'colour':
                    $v = (string) $v;
                    if ($v[0] !== '#') {
                        $v = '#' . $v;
                    }
                    if (preg_match('~^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$~', $v) === 1) {
                        $out[$k] = strtolower($v);
                    }
                    break;
                case 'email':
                    $e = sanitize_email((string) $v);
                    if ($e !== '') {
                        $out[$k] = $e;
                    }
                    break;
                case 'lines':
                    $out[$k] = sanitize_textarea_field((string) $v);
                    break;
            }
        }
        return $out;
    }

    /** @param array<string,mixed> $values */
    public static function saveOverrides(int $id, array $values): void
    {
        $clean = self::cleanOverrides($values);
        if ($clean === []) {
            delete_term_meta($id, self::META_SETTINGS);
        } else {
            update_term_meta($id, self::META_SETTINGS, $clean);
        }
        unset(self::$settingsMemo[$id]);
    }

    // ------------------------------------------------------------- editors

    /** @return list<int> members who look after this dictionary */
    public static function editors(int $id): array
    {
        if ($id < 1) {
            return [];
        }
        $raw = get_term_meta($id, self::META_EDITORS, true);
        return is_array($raw) ? array_values(array_unique(array_filter(array_map('intval', $raw)))) : [];
    }

    /** @param list<int> $users */
    public static function saveEditors(int $id, array $users): void
    {
        $users = array_values(array_unique(array_filter(array_map('intval', $users), static fn(int $u): bool => $u > 0 && get_userdata($u) !== false)));
        if ($users === []) {
            delete_term_meta($id, self::META_EDITORS);
        } else {
            update_term_meta($id, self::META_EDITORS, $users);
        }
    }

    public static function isEditor(int $id, int $userId): bool
    {
        return $id > 0 && $userId > 0 && in_array($userId, self::editors($id), true);
    }

    /** @return list<int> the dictionaries a member looks after */
    public static function editedBy(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        $out = [];
        foreach (self::all() as $t) {
            if (self::isEditor((int) $t->term_id, $userId)) {
                $out[] = (int) $t->term_id;
            }
        }
        return $out;
    }

    /**
     * A dictionary's editors may edit and trash its entries in the dashboard
     * when their role already lets them write posts. This gives them no
     * rights over other dictionaries or over anything else on the site.
     *
     * @param array<string,bool> $all
     * @param list<string> $caps
     * @param array<int,mixed> $args
     * @return array<string,bool>
     */
    public static function grantEntryCaps(array $all, array $caps, array $args, \WP_User $user): array
    {
        $cap = (string) ($args[0] ?? '');
        $postId = (int) ($args[2] ?? 0);
        if (!in_array($cap, ['edit_post', 'delete_post'], true) || $postId < 1 || empty($all['edit_posts'])) {
            return $all;
        }
        if (get_post_type($postId) !== PostType::TYPE || !self::isEditor(self::forEntry($postId), (int) $user->ID)) {
            return $all;
        }
        foreach ($caps as $c) {
            $all[$c] = true;
        }
        return $all;
    }

    // ------------------------------------------------------------- addresses

    /** A dictionary's own page: /dictionary/<dictionary>/ with optional query arguments. */
    public static function homeUrl(int $id, array $args = []): string
    {
        $slug = self::slug($id);
        // With one dictionary, the dictionary page is that dictionary's page.
        if ($slug === '' || !self::many()) {
            return Settings::indexUrl($args);
        }
        if ((string) get_option('permalink_structure') !== '') {
            $url = home_url(user_trailingslashit(PostType::base() . '/' . $slug));
        } else {
            $url = Settings::indexUrl(['sd_dict' => $slug]);
        }
        $args = array_filter($args, static fn($v): bool => $v !== '' && $v !== null && $v !== 0);
        return $args ? add_query_arg(array_map('rawurlencode', $args), $url) : $url;
    }

    // ------------------------------------------------------------- upgrading

    /** Give every entry and suggestion made before dictionaries existed to the default dictionary. */
    public static function migrate(): void
    {
        global $wpdb;
        self::register();
        $default = (int) get_option('default_term_' . self::TAX);
        if ($default < 1 || !term_exists($default, self::TAX)) {
            $made = term_exists('main', self::TAX) ?: wp_insert_term(__('Main dictionary', 'sepo-dictionary'), self::TAX, ['slug' => 'main']);
            if (is_array($made) && isset($made['term_id'])) {
                $default = (int) $made['term_id'];
                update_option('default_term_' . self::TAX, $default);
            }
        }
        self::$allMemo = null;
        if ($default < 1) {
            return;
        }
        $loose = $wpdb->get_col($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = %s AND p.post_status <> 'auto-draft' AND NOT EXISTS (
               SELECT 1 FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s WHERE tr.object_id = p.ID)",
            PostType::TYPE,
            self::TAX
        ));
        foreach ($loose as $id) {
            wp_set_object_terms((int) $id, [$default], self::TAX, false);
        }
        // Suggestions about an entry take that entry's dictionary; new words take the default one.
        $rows = $wpdb->get_results('SELECT id, entry_id FROM ' . Schema::proposals() . ' WHERE dict_id = 0', ARRAY_A);
        foreach ($rows ?: [] as $r) {
            $d = (int) $r['entry_id'] > 0 && get_post_type((int) $r['entry_id']) === PostType::TYPE ? self::forEntry((int) $r['entry_id']) : $default;
            $wpdb->update(Schema::proposals(), ['dict_id' => $d], ['id' => (int) $r['id']]);
        }
    }
}
