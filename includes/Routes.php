<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/**
 * Addresses:
 *
 *   /dictionary/<dictionary>/          a dictionary's own page
 *   /dictionary/<dictionary>/<word>/   an entry
 *
 * "dictionary" is the base set by the sepo_dictionary_slug filter. A word's
 * address only has to be unique within its dictionary, so the same word can
 * live in two dictionaries. Addresses from before dictionaries existed
 * (/dictionary/<word>/), from a dictionary's earlier name, and from a word
 * that has moved to another dictionary all redirect to the current address.
 * Sites without pretty permalinks use ?sepo_word=<word>&sd_dict=<dictionary>.
 */
final class Routes
{
    public const QV_DICT = 'sd_dict';
    public const QV_HOME = 'sd_dict_home';

    public static function hook(): void
    {
        add_action('init', [self::class, 'addRules'], 20);
        add_filter('query_vars', static function (array $v): array {
            $v[] = self::QV_DICT;
            $v[] = self::QV_HOME;
            return $v;
        });
        add_filter('request', [self::class, 'request']);
        add_filter('post_type_link', [self::class, 'link'], 10, 2);
        add_filter('wp_unique_post_slug', [self::class, 'uniqueSlug'], 10, 6);
        add_filter('redirect_canonical', static function ($url) {
            // These addresses are already canonical; WordPress would send them to the plain page.
            return get_query_var(self::QV_DICT) !== '' ? false : $url;
        });
    }

    public static function addRules(): void
    {
        $b = PostType::base();
        add_rewrite_rule('^' . $b . '/([^/]+)/([^/]+)/?$', 'index.php?' . PostType::TYPE . '=$matches[2]&' . self::QV_DICT . '=$matches[1]', 'top');
        add_rewrite_rule('^' . $b . '/([^/]+)/?$', 'index.php?' . self::QV_HOME . '=$matches[1]', 'top');
    }

    /**
     * Turn the matched address into the page or entry it names.
     *
     * @param array<string,mixed> $vars
     * @return array<string,mixed>
     */
    public static function request(array $vars): array
    {
        if (isset($vars[self::QV_HOME])) {
            return self::home(sanitize_title((string) $vars[self::QV_HOME]));
        }
        if (isset($vars[PostType::TYPE], $vars[self::QV_DICT]) && is_string($vars[PostType::TYPE])) {
            return self::entry(sanitize_title((string) $vars[PostType::TYPE]), sanitize_title((string) $vars[self::QV_DICT]), $vars);
        }
        if (isset($vars[self::QV_DICT]) && !isset($vars[PostType::TYPE])) {
            // A dictionary on the plain dictionary page: ?page_id=…&sd_dict=…
            $vars[self::QV_DICT] = sanitize_title((string) $vars[self::QV_DICT]);
        }
        return $vars;
    }

    /** @return array<string,mixed> */
    private static function home(string $slug): array
    {
        $t = get_term_by('slug', $slug, Dictionaries::TAX);
        if ($t instanceof \WP_Term) {
            $page = (int) Settings::get('index_page');
            if ($page > 0 && get_post_status($page) === 'publish') {
                return ['page_id' => $page, self::QV_DICT => $t->slug];
            }
            return ['error' => '404'];
        }
        $renamed = Dictionaries::byOldSlug($slug);
        if ($renamed > 0) {
            self::go(Dictionaries::homeUrl($renamed));
        }
        // An entry's address from before there were several dictionaries.
        $word = self::findAny($slug);
        if ($word > 0) {
            self::go(Entry::url($word));
        }
        // A page that sits under the dictionary page, such as /dictionary/about/.
        $path = PostType::base() . '/' . $slug;
        if (get_page_by_path($path) instanceof \WP_Post) {
            return ['pagename' => $path];
        }
        return ['error' => '404'];
    }

    /**
     * @param array<string,mixed> $vars
     * @return array<string,mixed>
     */
    private static function entry(string $word, string $dict, array $vars): array
    {
        $t = get_term_by('slug', $dict, Dictionaries::TAX);
        $dictId = $t instanceof \WP_Term ? (int) $t->term_id : Dictionaries::byOldSlug($dict);
        $id = $dictId > 0 ? self::find($word, $dictId) : 0;
        if ($id > 0 && !$t instanceof \WP_Term) {
            self::go(Entry::url($id));
        }
        if ($id < 1) {
            // The word has moved to another dictionary.
            $moved = self::findAny($word);
            if ($moved > 0) {
                self::go(Entry::url($moved));
            }
            return ['error' => '404'];
        }
        $out = ['post_type' => PostType::TYPE, 'p' => $id, self::QV_DICT => $dict];
        foreach (['preview', 'preview_id', 'preview_nonce'] as $k) {
            if (isset($vars[$k])) {
                $out[$k] = $vars[$k];
            }
        }
        return $out;
    }

    /** @return never */
    private static function go(string $url): void
    {
        $qs = isset($_SERVER['QUERY_STRING']) ? (string) wp_unslash($_SERVER['QUERY_STRING']) : '';
        if ($qs !== '') {
            parse_str($qs, $q);
            $url = add_query_arg(array_map(static fn($v) => is_string($v) ? rawurlencode($v) : $v, array_filter($q, 'is_string')), $url);
        }
        wp_safe_redirect($url, 301);
        exit;
    }

    /** The entry with this address in this dictionary (trash excluded). */
    public static function find(string $slug, int $dictId): int
    {
        global $wpdb;
        if ($slug === '' || $dictId < 1) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
             WHERE p.post_type = %s AND p.post_name = %s AND tt.term_id = %d AND p.post_status NOT IN ('trash','auto-draft','inherit')
             ORDER BY (p.post_status = 'publish') DESC, p.ID ASC LIMIT 1",
            Dictionaries::TAX,
            PostType::TYPE,
            $slug,
            $dictId
        ));
    }

    /** A published entry with this address in any dictionary, the earliest first. */
    private static function findAny(string $slug): int
    {
        global $wpdb;
        if ($slug === '') {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status = 'publish' ORDER BY ID ASC LIMIT 1",
            PostType::TYPE,
            $slug
        ));
    }

    /** An entry's address: under its dictionary. Unpublished entries keep WordPress's preview address. */
    public static function link(string $url, \WP_Post $post): string
    {
        if ($post->post_type !== PostType::TYPE || $post->post_name === '' || !in_array($post->post_status, ['publish', 'private', 'future'], true)) {
            return $url;
        }
        $dict = Dictionaries::slug(Dictionaries::forEntry((int) $post->ID));
        if ($dict === '') {
            return $url;
        }
        if ((string) get_option('permalink_structure') === '') {
            return add_query_arg([PostType::TYPE => $post->post_name, self::QV_DICT => $dict], home_url('/'));
        }
        return home_url(user_trailingslashit(PostType::base() . '/' . $dict . '/' . $post->post_name));
    }

    /** The dictionary a slug is being made for, set by Entry::store() before it saves. */
    public static int $slugDict = 0;

    /**
     * A word's address only needs to be unique within its dictionary.
     * WordPress leaves drafts alone and calls this for everything else.
     */
    public static function uniqueSlug(string $slug, int $postId, string $status, string $type, int $parent, string $original): string
    {
        if ($type !== PostType::TYPE) {
            return $slug;
        }
        $dict = self::$slugDict;
        if ($dict < 1 && isset($_POST['sd']['dictionary'], $_POST['sd_entry_nonce'])) { // phpcs:ignore WordPress.Security.NonceVerification -- only picks which dictionary to check against; MetaBox verifies the nonce before saving
            $dict = Dictionaries::id(sanitize_text_field(wp_unslash((string) $_POST['sd']['dictionary']))); // phpcs:ignore WordPress.Security.NonceVerification
        }
        if ($dict < 1) {
            $dict = Dictionaries::forEntry($postId);
        }
        $base = $original !== '' ? $original : $slug;
        $candidate = $base;
        $n = 2;
        while (self::taken($candidate, $dict, $postId)) {
            $candidate = _truncate_post_slug($base, 200 - (strlen((string) $n) + 1)) . '-' . $n;
            ++$n;
        }
        return $candidate;
    }

    private static function taken(string $slug, int $dictId, int $exceptId): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = %s
             WHERE p.post_type = %s AND p.post_name = %s AND tt.term_id = %d AND p.ID <> %d LIMIT 1",
            Dictionaries::TAX,
            PostType::TYPE,
            $slug,
            $dictId,
            $exceptId
        ));
    }

    /** The dictionary the current request is about, from the address; 0 when none. */
    public static function current(): int
    {
        $slug = (string) get_query_var(self::QV_DICT);
        if ($slug === '') {
            return 0;
        }
        $t = get_term_by('slug', $slug, Dictionaries::TAX);
        return $t instanceof \WP_Term ? (int) $t->term_id : 0;
    }
}
