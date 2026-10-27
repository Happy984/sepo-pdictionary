<?php

declare(strict_types=1);

namespace SepoDictionary\Render;

use SepoDictionary\Dictionaries;
use SepoDictionary\Entry;
use SepoDictionary\Plugin;
use SepoDictionary\PostType;
use SepoDictionary\Proposals;
use SepoDictionary\Settings;

defined('ABSPATH') || exit;

/** Pieces shared by the dictionary page, entry pages and the review queue. */
final class Parts
{
    /** The address of the page the dictionary sits on, with query arguments. */
    public static function url(string $base, array $args = []): string
    {
        // Empty values and page 1 are left out, so links stay short.
        $args = array_filter($args, static fn($v): bool => !in_array($v, ['', null, 0, 1], true));
        return $args ? add_query_arg(array_map(static fn($v) => rawurlencode((string) $v), $args), $base) : $base;
    }

    public static function login(): string
    {
        $here = (is_ssl() ? 'https://' : 'http://') . sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST'] ?? '')) . esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        return wp_login_url($here);
    }

    /** "in 3 days" or "2 hours ago". */
    public static function ago(int $ts): string
    {
        if ($ts < 1) {
            return '';
        }
        $d = human_time_diff($ts, time());
        /* translators: %s: a span of time such as "3 days" */
        return $ts > time() ? sprintf(__('in %s', 'sepo-dictionary'), $d) : sprintf(__('%s ago', 'sepo-dictionary'), $d);
    }

    public static function date(int $ts): string
    {
        return $ts > 0 ? wp_date((string) get_option('date_format'), $ts) : '';
    }

    /** The search box with its typeahead list. */
    public static function search(string $base, string $q = '', string $language = '', bool $compact = false, array $languages = [], bool $all = false): string
    {
        $id = 'sdict-q-' . wp_rand(1000, 99999);
        $h = '<form class="sdict-search' . ($compact ? ' sdict-search--compact' : '') . '" role="search" method="get" action="' . esc_url($base) . '" data-sdict-search>';
        // Pages without pretty links keep their own query arguments.
        $parts = wp_parse_url($base);
        if (!empty($parts['query'])) {
            parse_str((string) $parts['query'], $keep);
            foreach ($keep as $k => $v) {
                if (is_string($v) && !str_starts_with((string) $k, 'sd_')) {
                    $h .= '<input type="hidden" name="' . esc_attr((string) $k) . '" value="' . esc_attr($v) . '">';
                }
            }
        }
        $all = $all && Dictionaries::many();
        $h .= '<label class="screen-reader-text" for="' . esc_attr($id) . '">' . esc_html($all ? __('Search all dictionaries', 'sepo-dictionary') : __('Search the dictionary', 'sepo-dictionary')) . '</label>';
        $h .= '<span class="sdict-search__icon">' . Icons::get('search', 18) . '</span>';
        $h .= '<input class="sdict-search__input" id="' . esc_attr($id) . '" type="search" name="sd_q" value="' . esc_attr($q) . '" placeholder="' . esc_attr($all ? __('Search every dictionary…', 'sepo-dictionary') : __('Search a word or a meaning…', 'sepo-dictionary')) . '" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="' . esc_attr($id) . '-list">';
        if ($languages !== [] && count($languages) > 1) {
            $h .= '<label class="screen-reader-text" for="' . esc_attr($id) . '-lang">' . esc_html__('Language', 'sepo-dictionary') . '</label>';
            $h .= '<select class="sdict-search__lang" id="' . esc_attr($id) . '-lang" name="sd_lang"><option value="">' . esc_html__('All languages', 'sepo-dictionary') . '</option>';
            foreach ($languages as $t) {
                $h .= '<option value="' . esc_attr($t->slug) . '"' . selected($language, $t->slug, false) . '>' . esc_html($t->name) . '</option>';
            }
            $h .= '</select>';
        } elseif ($language !== '') {
            $h .= '<input type="hidden" name="sd_lang" value="' . esc_attr($language) . '">';
        }
        $h .= '<button class="sdict-btn sdict-btn--primary sdict-search__go" type="submit">' . esc_html__('Search', 'sepo-dictionary') . '</button>';
        $h .= '<div class="sdict-suggest" id="' . esc_attr($id) . '-list" role="listbox" data-sdict-suggest hidden></div>';
        return $h . '</form>';
    }

    /** The A–Z rail. Letters with no entries are shown but not linked. */
    public static function letters(string $base, array $counts, string $current, array $keep = []): string
    {
        $h = '<nav class="sdict-az" aria-label="' . esc_attr__('Browse by letter', 'sepo-dictionary') . '">';
        foreach (Entry::LETTERS as $l) {
            $n = (int) ($counts[$l] ?? 0);
            $label = Entry::letterLabel($l);
            if ($n < 1) {
                $h .= '<span class="sdict-az__l is-empty" aria-hidden="true">' . esc_html($label) . '</span>';
                continue;
            }
            /* translators: 1: letter, 2: number of words */
            $title = sprintf(_n('%1$s: %2$d word', '%1$s: %2$d words', $n, 'sepo-dictionary'), $label, $n);
            $h .= '<a class="sdict-az__l' . ($current === $l ? ' is-on' : '') . '" href="' . esc_url(self::url($base, $keep + ['sd_letter' => $l])) . '" title="' . esc_attr($title) . '"' . ($current === $l ? ' aria-current="page"' : '') . '>' . esc_html($label) . '</a>';
        }
        return $h . '</nav>';
    }

    /** Page links: previous, numbers with gaps, next. */
    public static function pager(string $base, array $args, int $page, int $pages): string
    {
        if ($pages < 2) {
            return '';
        }
        $h = '<nav class="sdict-pager" aria-label="' . esc_attr__('Pages', 'sepo-dictionary') . '">';
        $link = static fn(int $p): string => esc_url(self::url($base, $args + ['sd_page' => $p]));
        $h .= $page > 1
            ? '<a class="sdict-pager__step" href="' . $link($page - 1) . '" rel="prev">' . Icons::get('prev', 14) . '<span>' . esc_html__('Previous', 'sepo-dictionary') . '</span></a>'
            : '<span class="sdict-pager__step is-off">' . Icons::get('prev', 14) . '<span>' . esc_html__('Previous', 'sepo-dictionary') . '</span></span>';
        $shown = array_unique(array_filter([1, 2, $page - 1, $page, $page + 1, $pages - 1, $pages], static fn(int $p): bool => $p >= 1 && $p <= $pages));
        sort($shown);
        $last = 0;
        $h .= '<span class="sdict-pager__nums">';
        foreach ($shown as $p) {
            if ($p - $last > 1) {
                $h .= '<span class="sdict-pager__gap">…</span>';
            }
            $h .= $p === $page
                ? '<span class="sdict-pager__n is-on" aria-current="page">' . (int) $p . '</span>'
                : '<a class="sdict-pager__n" href="' . $link($p) . '">' . (int) $p . '</a>';
            $last = $p;
        }
        $h .= '</span>';
        $h .= $page < $pages
            ? '<a class="sdict-pager__step" href="' . $link($page + 1) . '" rel="next"><span>' . esc_html__('Next', 'sepo-dictionary') . '</span>' . Icons::get('next', 14) . '</a>'
            : '<span class="sdict-pager__step is-off"><span>' . esc_html__('Next', 'sepo-dictionary') . '</span>' . Icons::get('next', 14) . '</span>';
        return $h . '</nav>';
    }

    /** One row in a list of entries; $showDict names the word's dictionary, for lists that mix them. */
    public static function item(\WP_Post $post, bool $showDict = false): string
    {
        $s = Entry::get((int) $post->ID);
        if ($s === null) {
            return '';
        }
        $h = '<li class="sdict-item"><a class="sdict-item__link" href="' . esc_url(Entry::url((int) $post->ID)) . '">';
        $h .= '<span class="sdict-item__head"><span class="sdict-item__word">' . esc_html($s['term']) . '</span>';
        if ($s['pos'] !== '') {
            $h .= '<span class="sdict-pos">' . esc_html($s['pos']) . '</span>';
        }
        if ($s['status'] !== 'publish') {
            $h .= self::status($s['status']);
        }
        $h .= '</span>';
        $snip = Entry::snippet($s, 150);
        if ($snip !== '') {
            $h .= '<span class="sdict-item__def">' . esc_html($snip) . '</span>';
        }
        $where = array_filter([$showDict ? Dictionaries::name((int) $s['dictionary']) : '', $s['language']]);
        if ($where !== []) {
            $h .= '<span class="sdict-item__lang">' . esc_html(implode(' · ', $where)) . '</span>';
        }
        return $h . '</a></li>';
    }

    public static function status(string $s): string
    {
        $label = match ($s) {
            'draft'   => __('Draft', 'sepo-dictionary'),
            'pending' => __('Pending', 'sepo-dictionary'),
            'private' => __('Private', 'sepo-dictionary'),
            default   => '',
        };
        return $label === '' ? '' : '<span class="sdict-badge sdict-badge--' . esc_attr($s) . '">' . esc_html($label) . '</span>';
    }

    /**
     * Words as chips; a word that has its own entry links to it.
     *
     * @param list<string> $words
     */
    public static function chips(array $words, string $language = '', int $dict = 0): string
    {
        $out = [];
        foreach ($words as $w) {
            $id = Entry::find($w, $language, 0, $dict) ?: Entry::findAny($w, $dict);
            $out[] = $id > 0 && get_post_status($id) === 'publish'
                ? '<a class="sdict-chip sdict-chip--link" href="' . esc_url(Entry::url($id)) . '">' . esc_html($w) . '</a>'
                : '<span class="sdict-chip">' . esc_html($w) . '</span>';
        }
        return implode('', $out);
    }

    /** The masthead tabs on the dictionary page. */
    public static function nav(string $base, string $view, array $keep = [], int $dict = 0): string
    {
        $counts = Proposals::counts($dict);
        $live = $counts['open'] + (Plugin::canManage($dict) ? $counts['passed'] : 0);
        $tabs = [
            ''             => [$dict === 0 && Dictionaries::many() ? __('Dictionaries', 'sepo-dictionary') : __('Browse', 'sepo-dictionary'), 'book', 0],
            'review'       => [__('Community review', 'sepo-dictionary'), 'vote', $live],
            'contributors' => [__('Contributors', 'sepo-dictionary'), 'award', 0],
        ];
        if (is_user_logged_in()) {
            $mine = Proposals::liveCountForUser(get_current_user_id(), $dict);
            $tabs = array_slice($tabs, 0, 2, true) + ['mine' => [__('My suggestions', 'sepo-dictionary'), 'edit', $mine]] + array_slice($tabs, 2, null, true);
        }
        $h = '<nav class="sdict-nav" aria-label="' . esc_attr__('Dictionary sections', 'sepo-dictionary') . '">';
        foreach ($tabs as $key => [$label, $icon, $n]) {
            $on = $view === $key;
            $h .= '<a class="sdict-nav__tab' . ($on ? ' is-on' : '') . '" href="' . esc_url(self::url($base, $keep + ['sd_view' => $key])) . '"' . ($on ? ' aria-current="page"' : '') . '>'
                . Icons::get($icon, 15) . '<span>' . esc_html($label) . '</span>'
                . ($n > 0 ? '<span class="sdict-count">' . (int) $n . '</span>' : '') . '</a>';
        }
        return $h . '</nav>';
    }

    /** The in-page dialog the script fills for forms and confirmations. */
    public static function modal(): string
    {
        return '<div class="sdict-modal" data-sdict-modal hidden><div class="sdict-modal__panel" role="dialog" aria-modal="true" aria-labelledby="sdict-modal-title">'
            . '<header class="sdict-modal__head"><h3 class="sdict-modal__title" id="sdict-modal-title" data-sdict-modal-title></h3>'
            . '<button type="button" class="sdict-iconbtn" data-sdict-modal-close aria-label="' . esc_attr__('Close', 'sepo-dictionary') . '">' . Icons::get('x', 18) . '</button></header>'
            . '<div class="sdict-modal__body" data-sdict-modal-body></div>'
            . '<footer class="sdict-modal__foot" data-sdict-modal-foot></footer>'
            . '</div></div><div class="sdict-toast" data-sdict-toast role="status" aria-live="polite" hidden></div>';
    }

    /** @return list<\WP_Term> */
    public static function terms(string $taxonomy): array
    {
        $t = get_terms(['taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name']);
        return is_array($t) ? array_values($t) : [];
    }

    /**
     * The attributes that tie a block of the page to its dictionary: which
     * one it is (for the script) and its own accent colour, when it has one.
     *
     * @return array<string,string>
     */
    public static function dictAttrs(int $dict): array
    {
        if ($dict < 1) {
            return [];
        }
        $out = ['data-sdict-dict' => (string) $dict];
        $a = Dictionaries::accent($dict);
        if ($a !== '') {
            $out['style'] = '--sdict-accent:' . $a . ';--sdict-on-accent:' . \SepoDictionary\Settings::textOn($a);
        }
        return $out;
    }

    /** Wrap markup in the plugin's scope. */
    public static function scope(string $inner, string $class = '', array $attrs = []): string
    {
        $a = '';
        foreach ($attrs as $k => $v) {
            $a .= ' ' . $k . '="' . esc_attr((string) $v) . '"';
        }
        $wide = apply_filters('sepo_dictionary_wide', false) ? ' alignwide' : '';
        return '<div class="sdict-scope ' . esc_attr(trim($class)) . $wide . '"' . $a . '><div class="sdict-wrap">' . $inner . '</div>' . self::modal() . '</div>';
    }
}
