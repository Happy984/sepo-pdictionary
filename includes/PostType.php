<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/**
 * Entries are posts of their own type, so they get permalinks, the trash,
 * search and sitemaps from WordPress. Language, category and tags are
 * taxonomies.
 */
final class PostType
{
    public const TYPE = 'sepo_word';
    public const LANGUAGE = 'sepo_word_language';
    public const CATEGORY = 'sepo_word_category';
    public const TAG = 'sepo_word_tag';

    public static function hook(): void
    {
        add_action('init', [self::class, 'register']);
        add_filter('manage_' . self::TYPE . '_posts_columns', [self::class, 'columns']);
        add_action('manage_' . self::TYPE . '_posts_custom_column', [self::class, 'column'], 10, 2);
        add_filter('post_row_actions', [self::class, 'rowActions'], 10, 2);
        add_action('pre_get_posts', static function (\WP_Query $q): void {
            if (is_admin() && $q->is_main_query() && $q->get('post_type') === self::TYPE && !$q->get('orderby')) {
                $q->set('orderby', 'title');
                $q->set('order', 'ASC');
            }
        });
        add_filter('enter_title_here', static function (string $t, \WP_Post $p): string {
            return $p->post_type === self::TYPE ? __('Word or phrase', 'sepo-dictionary') : $t;
        }, 10, 2);
        // The language's speech code, used by the Listen button.
        add_action(self::LANGUAGE . '_add_form_fields', [self::class, 'codeFieldAdd']);
        add_action(self::LANGUAGE . '_edit_form_fields', [self::class, 'codeFieldEdit']);
        add_action('created_' . self::LANGUAGE, [self::class, 'saveCode']);
        add_action('edited_' . self::LANGUAGE, [self::class, 'saveCode']);
    }

    public static function base(): string
    {
        $b = sanitize_title((string) apply_filters('sepo_dictionary_slug', 'dictionary'));
        return $b !== '' ? $b : 'dictionary';
    }

    public static function register(): void
    {
        register_post_type(self::TYPE, [
            'labels' => [
                'name'               => __('Dictionary', 'sepo-dictionary'),
                'singular_name'      => __('Word', 'sepo-dictionary'),
                'menu_name'          => __('Dictionary', 'sepo-dictionary'),
                'all_items'          => __('All words', 'sepo-dictionary'),
                'add_new'            => __('Add word', 'sepo-dictionary'),
                'add_new_item'       => __('Add a word', 'sepo-dictionary'),
                'edit_item'          => __('Edit word', 'sepo-dictionary'),
                'new_item'           => __('New word', 'sepo-dictionary'),
                'view_item'          => __('View word', 'sepo-dictionary'),
                'search_items'       => __('Search words', 'sepo-dictionary'),
                'not_found'          => __('No words yet.', 'sepo-dictionary'),
                'not_found_in_trash' => __('No words in the trash.', 'sepo-dictionary'),
            ],
            'public'          => true,
            'show_in_rest'    => false,
            'has_archive'     => false,
            'menu_icon'       => 'dashicons-book-alt',
            'menu_position'   => 22,
            'supports'        => ['title', 'author'],
            'rewrite'         => ['slug' => self::base(), 'with_front' => false],
            'capability_type' => 'post',
            'map_meta_cap'    => true,
        ]);

        $common = ['public' => true, 'show_admin_column' => true, 'show_in_rest' => false, 'show_in_nav_menus' => false];
        register_taxonomy(self::LANGUAGE, self::TYPE, $common + [
            'labels' => [
                'name'          => __('Languages', 'sepo-dictionary'),
                'singular_name' => __('Language', 'sepo-dictionary'),
                'add_new_item'  => __('Add a language', 'sepo-dictionary'),
                'search_items'  => __('Search languages', 'sepo-dictionary'),
            ],
            'hierarchical' => false,
            'meta_box_cb'  => false,
            'rewrite'      => ['slug' => self::base() . '-language', 'with_front' => false],
        ]);
        register_taxonomy(self::CATEGORY, self::TYPE, $common + [
            'labels' => [
                'name'          => __('Categories', 'sepo-dictionary'),
                'singular_name' => __('Category', 'sepo-dictionary'),
                'add_new_item'  => __('Add a category', 'sepo-dictionary'),
                'search_items'  => __('Search categories', 'sepo-dictionary'),
            ],
            'hierarchical' => true,
            'meta_box_cb'  => false,
            'rewrite'      => ['slug' => self::base() . '-category', 'with_front' => false],
        ]);
        register_taxonomy(self::TAG, self::TYPE, $common + [
            'labels' => [
                'name'          => __('Tags', 'sepo-dictionary'),
                'singular_name' => __('Tag', 'sepo-dictionary'),
            ],
            'hierarchical' => false,
            'meta_box_cb'  => false,
            'rewrite'      => ['slug' => self::base() . '-tag', 'with_front' => false],
        ]);
    }

    /** @param array<string,string> $cols @return array<string,string> */
    public static function columns(array $cols): array
    {
        $out = [];
        foreach ($cols as $k => $v) {
            $out[$k] = $v;
            if ($k === 'title') {
                $out['sd_pos'] = __('Part of speech', 'sepo-dictionary');
                $out['sd_senses'] = __('Meanings', 'sepo-dictionary');
                $out['sd_open'] = __('Open proposals', 'sepo-dictionary');
            }
        }
        unset($out['author']);
        return $out;
    }

    public static function column(string $col, int $id): void
    {
        $e = Entry::fields($id);
        if ($col === 'sd_pos') {
            echo $e['pos'] !== '' ? '<em>' . esc_html($e['pos']) . '</em>' : '—';
        } elseif ($col === 'sd_senses') {
            echo (int) count($e['definitions']);
        } elseif ($col === 'sd_open') {
            $n = Proposals::openCount($id);
            echo $n > 0
                ? '<a href="' . esc_url(admin_url('edit.php?post_type=' . self::TYPE . '&page=sepo-dictionary-proposals&entry=' . $id)) . '">' . (int) $n . '</a>'
                : '—';
        }
    }

    /** @param array<string,string> $actions @return array<string,string> */
    public static function rowActions(array $actions, \WP_Post $post): array
    {
        if ($post->post_type === self::TYPE && $post->post_status !== 'trash') {
            $actions['sd_history'] = '<a href="' . esc_url(add_query_arg('sd_tab', 'history', (string) get_permalink($post))) . '">' . esc_html__('History', 'sepo-dictionary') . '</a>';
        }
        return $actions;
    }

    public static function codeFieldAdd(): void
    {
        echo '<div class="form-field"><label for="sd_code">' . esc_html__('Speech code', 'sepo-dictionary') . '</label>'
            . '<input type="text" name="sd_code" id="sd_code" placeholder="en-GB">'
            . '<p>' . esc_html__('Optional. A language tag such as en-GB, fr or sw, so the Listen button reads words in this language when the browser has a voice for it.', 'sepo-dictionary') . '</p></div>';
    }

    public static function codeFieldEdit(\WP_Term $term): void
    {
        echo '<tr class="form-field"><th scope="row"><label for="sd_code">' . esc_html__('Speech code', 'sepo-dictionary') . '</label></th><td>'
            . '<input type="text" name="sd_code" id="sd_code" value="' . esc_attr((string) get_term_meta($term->term_id, 'sd_code', true)) . '" placeholder="en-GB">'
            . '<p class="description">' . esc_html__('Optional. A language tag such as en-GB, fr or sw, so the Listen button reads words in this language when the browser has a voice for it.', 'sepo-dictionary') . '</p></td></tr>';
    }

    public static function saveCode(int $termId): void
    {
        if (!isset($_POST['sd_code']) || !current_user_can('manage_categories')) {
            return;
        }
        // The term screen's own nonce has been checked by WordPress before these hooks run.
        $code = preg_replace('~[^A-Za-z0-9-]~', '', (string) wp_unslash($_POST['sd_code'])); // phpcs:ignore WordPress.Security.NonceVerification
        update_term_meta($termId, 'sd_code', substr((string) $code, 0, 20));
    }

    /**
     * Language, category or tag names for a select or a list.
     *
     * @return list<string>
     */
    public static function names(string $taxonomy): array
    {
        $terms = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name']);
        return is_array($terms) ? array_values(array_map(static fn(\WP_Term $t): string => $t->name, $terms)) : [];
    }
}
