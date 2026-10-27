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

/**
 * The dictionary page: browse by letter, search, the community review
 * queue, a member's own suggestions, and the contributors.
 *
 * With several dictionaries, the page without a dictionary lists them and
 * searches and reviews across all of them; each dictionary's own page
 * (/dictionary/<dictionary>/) is the full dictionary for its words only.
 */
final class AppView
{
    /**
     * @param array<string,mixed> $a per_page, word_of_day, letters, language, category, title
     * @param int $dict the dictionary shown; 0 for the list of all dictionaries
     * @param string $base the address the page's links and forms point at
     */
    public static function render(array $a, int $dict, string $base): string
    {
        // phpcs:disable WordPress.Security.NonceVerification -- read-only view filters
        $g = static fn(string $k): string => sanitize_text_field(wp_unslash((string) ($_GET[$k] ?? '')));
        $fixedLang = sanitize_title((string) ($a['language'] ?? ''));
        $p = [
            'view'   => sanitize_key($g('sd_view')),
            'q'      => Entry::line($g('sd_q'), 100),
            'letter' => strtolower($g('sd_letter')),
            'lang'   => $fixedLang !== '' ? $fixedLang : sanitize_title($g('sd_lang')),
            'cat'    => sanitize_title($g('sd_cat') ?: (string) ($a['category'] ?? '')),
            'page'   => max(1, (int) $g('sd_page')),
            'kind'   => sanitize_key($g('sd_kind')),
            'order'  => sanitize_key($g('sd_order')),
            'status' => sanitize_key($g('sd_status')),
        ];
        // phpcs:enable
        if (!in_array($p['letter'], Entry::LETTERS, true)) {
            $p['letter'] = '';
        }
        $p['dict'] = $dict;
        $keep = $fixedLang === '' && $p['lang'] !== '' ? ['sd_lang' => $p['lang']] : [];
        $languages = $fixedLang === '' ? Parts::terms(PostType::LANGUAGE) : [];

        $h = $dict > 0 ? self::masthead($a, $p['lang'], $dict) : self::directoryHead($a);
        $h .= Parts::search($base, $p['q'], $p['lang'], false, $languages, $dict === 0);
        $h .= Parts::nav($base, in_array($p['view'], ['review', 'mine', 'contributors'], true) ? $p['view'] : '', $keep, $dict);

        $h .= match ($p['view']) {
            'review'       => self::review($base, $p, $keep),
            'mine'         => self::mine($base, $p),
            'contributors' => self::contributors($dict),
            default        => $p['q'] !== '' ? self::results($base, $p, $a, $keep) : ($dict > 0 ? self::browse($base, $p, $a, $keep) : self::directory()),
        };

        return Parts::scope($h, 'sdict-app sdict-index' . ($dict === 0 ? ' sdict-directory-page' : ''), ['data-sdict-app' => '1', 'data-sdict-lang' => $p['lang']] + Parts::dictAttrs($dict));
    }

    private static function masthead(array $a, string $lang, int $dict): string
    {
        $title = trim((string) ($a['title'] ?? ''));
        $many = Dictionaries::many();
        $term = Dictionaries::get($dict);
        if ($title === '' && $many && $term instanceof \WP_Term) {
            $title = (string) $term->name;
        }
        $total = Entry::total($lang, $dict);
        $langCount = self::languageCount($dict);
        /* translators: %s: number of words */
        $sub = sprintf(_n('%s word', '%s words', $total, 'sepo-dictionary'), number_format_i18n($total));
        if ($langCount > 1 && $lang === '') {
            /* translators: %s: number of languages */
            $sub .= ' · ' . sprintf(_n('%s language', '%s languages', $langCount, 'sepo-dictionary'), number_format_i18n($langCount));
        }
        $h = '<header class="sdict-masthead"><div class="sdict-masthead__text">';
        if ($many) {
            $h .= '<nav class="sdict-crumbs sdict-crumbs--head" aria-label="' . esc_attr__('Breadcrumb', 'sepo-dictionary') . '"><a href="' . esc_url(Settings::indexUrl()) . '">' . Icons::get('book', 14) . esc_html__('All dictionaries', 'sepo-dictionary') . '</a></nav>';
        }
        if ($title !== '' && $title !== 'none') {
            $h .= '<h2 class="sdict-title">' . esc_html($title) . '</h2>';
        }
        if ($many && $term instanceof \WP_Term && trim((string) $term->description) !== '') {
            $h .= '<p class="sdict-masthead__desc">' . esc_html(wp_strip_all_tags((string) $term->description)) . '</p>';
        }
        $h .= '<p class="sdict-masthead__sub">' . esc_html($sub) . '</p></div><div class="sdict-masthead__actions">';
        if (Plugin::canManage($dict)) {
            $h .= '<button type="button" class="sdict-btn sdict-btn--primary" data-sdict-act="new-entry">' . Icons::get('plus', 15) . esc_html__('Add a word', 'sepo-dictionary') . '</button>';
        } elseif (Plugin::canPropose($dict)) {
            $h .= '<button type="button" class="sdict-btn sdict-btn--primary" data-sdict-act="propose-new">' . Icons::get('plus', 15) . esc_html__('Suggest a word', 'sepo-dictionary') . '</button>';
        } elseif (!is_user_logged_in() && Plugin::proposalsOpen($dict)) {
            $h .= '<a class="sdict-btn" href="' . esc_url(Parts::login()) . '">' . esc_html__('Sign in to suggest a word', 'sepo-dictionary') . '</a>';
        }
        return $h . '</div></header>';
    }

    /** How many languages a dictionary's published words are in. */
    private static function languageCount(int $dict): int
    {
        global $wpdb;
        if ($dict < 1) {
            return (int) wp_count_terms(['taxonomy' => PostType::LANGUAGE, 'hide_empty' => true]);
        }
        $d = get_term($dict, Dictionaries::TAX);
        if (!$d instanceof \WP_Term) {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT ttl.term_id) FROM {$wpdb->term_relationships} trd
             INNER JOIN {$wpdb->posts} p ON p.ID = trd.object_id AND p.post_status = 'publish'
             INNER JOIN {$wpdb->term_relationships} trl ON trl.object_id = p.ID
             INNER JOIN {$wpdb->term_taxonomy} ttl ON ttl.term_taxonomy_id = trl.term_taxonomy_id AND ttl.taxonomy = %s
             WHERE trd.term_taxonomy_id = %d",
            PostType::LANGUAGE,
            (int) $d->term_taxonomy_id
        ));
    }

    /** The heading of the list of dictionaries. */
    private static function directoryHead(array $a): string
    {
        $title = trim((string) ($a['title'] ?? ''));
        $n = Dictionaries::count();
        $total = Entry::total();
        /* translators: 1: number of dictionaries, 2: number of words */
        $sub = sprintf(_n('%1$s dictionary', '%1$s dictionaries', $n, 'sepo-dictionary'), number_format_i18n($n))
            /* translators: %s: number of words */
            . ' · ' . sprintf(_n('%s word', '%s words', $total, 'sepo-dictionary'), number_format_i18n($total));
        $h = '<header class="sdict-masthead"><div class="sdict-masthead__text">';
        if ($title !== '' && $title !== 'none') {
            $h .= '<h2 class="sdict-title">' . esc_html($title) . '</h2>';
        }
        $h .= '<p class="sdict-masthead__sub">' . esc_html($sub) . '</p></div><div class="sdict-masthead__actions">';
        if (Plugin::managesAny()) {
            $h .= '<button type="button" class="sdict-btn sdict-btn--primary" data-sdict-act="new-entry">' . Icons::get('plus', 15) . esc_html__('Add a word', 'sepo-dictionary') . '</button>';
        } elseif (self::proposable() !== []) {
            $h .= '<button type="button" class="sdict-btn sdict-btn--primary" data-sdict-act="propose-new">' . Icons::get('plus', 15) . esc_html__('Suggest a word', 'sepo-dictionary') . '</button>';
        } elseif (!is_user_logged_in() && self::anyOpen()) {
            $h .= '<a class="sdict-btn" href="' . esc_url(Parts::login()) . '">' . esc_html__('Sign in to suggest a word', 'sepo-dictionary') . '</a>';
        }
        return $h . '</div></header>';
    }

    /** @return list<int> dictionaries the current member may suggest words in */
    private static function proposable(): array
    {
        $out = [];
        foreach (Dictionaries::all() as $t) {
            if (Plugin::canPropose((int) $t->term_id)) {
                $out[] = (int) $t->term_id;
            }
        }
        return $out;
    }

    private static function anyOpen(): bool
    {
        foreach (Dictionaries::all() as $t) {
            if (Plugin::proposalsOpen((int) $t->term_id)) {
                return true;
            }
        }
        return false;
    }

    /** Every dictionary as a card: its name, description and size. */
    private static function directory(): string
    {
        $h = '<div class="sdict-listhead"><h3 class="sdict-listhead__title">' . esc_html__('Dictionaries', 'sepo-dictionary')
            . ' <span class="sdict-listhead__n">' . esc_html(number_format_i18n(Dictionaries::count())) . '</span></h3></div>';
        $h .= '<ul class="sdict-dicts">';
        foreach (Dictionaries::all() as $t) {
            $id = (int) $t->term_id;
            $n = Entry::total('', $id);
            $desc = trim(wp_strip_all_tags((string) $t->description));
            $open = Proposals::counts($id)['open'];
            $h .= '<li class="sdict-dict"><a class="sdict-dict__link" href="' . esc_url(Dictionaries::homeUrl($id)) . '"' . self::accentStyle($id) . '>'
                . '<span class="sdict-dict__name">' . esc_html((string) $t->name) . '</span>'
                . ($desc !== '' ? '<span class="sdict-dict__desc">' . esc_html(wp_trim_words($desc, 28)) . '</span>' : '')
                /* translators: %s: number of words */
                . '<span class="sdict-dict__meta"><span>' . esc_html(sprintf(_n('%s word', '%s words', $n, 'sepo-dictionary'), number_format_i18n($n))) . '</span>'
                /* translators: %d: number of suggestions */
                . ($open > 0 ? '<span>' . esc_html(sprintf(_n('%d suggestion open for votes', '%d suggestions open for votes', $open, 'sepo-dictionary'), $open)) . '</span>' : '')
                . '</span><span class="sdict-dict__go">' . esc_html__('Open', 'sepo-dictionary') . Icons::get('next', 14) . '</span></a></li>';
        }
        return $h . '</ul>';
    }

    /** A dictionary card in its own accent, when it has one. */
    private static function accentStyle(int $dict): string
    {
        $a = Dictionaries::accent($dict);
        return $a !== '' ? ' style="--sdict-accent:' . esc_attr($a) . ';--sdict-on-accent:' . esc_attr(Settings::textOn($a)) . '"' : '';
    }

    private static function browse(string $base, array $p, array $a, array $keep): string
    {
        $dict = (int) $p['dict'];
        $manager = Plugin::canManage($dict);
        $h = '';
        $wod = !isset($a['word_of_day']) || (string) $a['word_of_day'] !== '0';
        if ($wod && Dictionaries::on($dict, 'word_of_day') && $p['letter'] === '' && $p['cat'] === '' && $p['page'] === 1) {
            $w = Entry::wordOfDay($p['lang'], $dict);
            if ($w instanceof \WP_Post) {
                $h .= EntryView::card($w, __('Word of the day', 'sepo-dictionary'));
            }
        }
        if (!isset($a['letters']) || (string) $a['letters'] !== '0') {
            $h .= Parts::letters($base, Entry::letters($p['lang'], $p['cat'], $manager, $dict), $p['letter'], $keep + ['sd_cat' => $p['cat']]);
        }

        $per = max(6, min(120, (int) ($a['per_page'] ?? 0) ?: Dictionaries::int($dict, 'per_page', 6)));
        $res = Entry::browse(['dictionary' => $dict, 'letter' => $p['letter'], 'language' => $p['lang'], 'category' => $p['cat'], 'page' => $p['page'], 'per_page' => $per, 'manager' => $manager]);

        if ($p['letter'] !== '') {
            /* translators: %s: letter */
            $heading = sprintf(__('Words starting with %s', 'sepo-dictionary'), Entry::letterLabel($p['letter']));
        } else {
            $heading = __('All words', 'sepo-dictionary');
        }
        $h .= '<div class="sdict-listhead"><h3 class="sdict-listhead__title">' . esc_html($heading)
            . ' <span class="sdict-listhead__n">' . esc_html(number_format_i18n($res['total'])) . '</span></h3>'
            . self::categoryFilter($base, $p, $keep) . '</div>';

        if ($res['posts'] === []) {
            $h .= '<div class="sdict-empty"><p>' . esc_html__('No words here yet.', 'sepo-dictionary') . '</p>' . self::suggestButton('', $dict) . '</div>';
            return $h;
        }
        $h .= '<ul class="sdict-list">';
        foreach ($res['posts'] as $post) {
            $h .= Parts::item($post);
        }
        $h .= '</ul>';
        return $h . Parts::pager($base, $keep + ['sd_letter' => $p['letter'], 'sd_cat' => $p['cat']], $p['page'], $res['pages']);
    }

    private static function categoryFilter(string $base, array $p, array $keep): string
    {
        $cats = array_values(array_filter(Parts::terms(PostType::CATEGORY), static fn(\WP_Term $t): bool => $t->count > 0 && ((int) $p['dict'] < 1 || Entry::total('', (int) $p['dict'], (string) $t->slug) > 0)));
        if ($cats === []) {
            return '';
        }
        $h = '<form class="sdict-filter" method="get" action="' . esc_url($base) . '" data-sdict-autosubmit>';
        foreach ($keep + ['sd_letter' => $p['letter']] as $k => $v) {
            if ($v !== '') {
                $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
            }
        }
        $h .= '<label class="screen-reader-text" for="sdict-cat">' . esc_html__('Category', 'sepo-dictionary') . '</label><select id="sdict-cat" name="sd_cat"><option value="">' . esc_html__('All categories', 'sepo-dictionary') . '</option>';
        foreach ($cats as $t) {
            $h .= '<option value="' . esc_attr($t->slug) . '"' . selected($p['cat'], $t->slug, false) . '>' . esc_html($t->name) . '</option>';
        }
        return $h . '</select><noscript><button class="sdict-btn sdict-btn--sm" type="submit">' . esc_html__('Filter', 'sepo-dictionary') . '</button></noscript></form>';
    }

    private static function suggestButton(string $term, int $dict): string
    {
        if ($dict > 0 ? Plugin::canManage($dict) : Plugin::managesAny()) {
            return '<button type="button" class="sdict-btn sdict-btn--primary" data-sdict-act="new-entry" data-prefill="' . esc_attr($term) . '">' . Icons::get('plus', 15) . esc_html__('Add this word', 'sepo-dictionary') . '</button>';
        }
        if ($dict > 0 ? Plugin::canPropose($dict) : self::proposable() !== []) {
            return '<button type="button" class="sdict-btn sdict-btn--primary" data-sdict-act="propose-new" data-prefill="' . esc_attr($term) . '">' . Icons::get('plus', 15) . esc_html__('Suggest this word', 'sepo-dictionary') . '</button>';
        }
        return '';
    }

    private static function results(string $base, array $p, array $a, array $keep): string
    {
        $dict = (int) $p['dict'];
        $all = Entry::search($p['q'], ['limit' => 500, 'dictionary' => $dict, 'language' => $p['lang'], 'category' => $p['cat'], 'manager' => Plugin::canManage($dict)]);
        $per = max(6, min(120, (int) ($a['per_page'] ?? 0) ?: Dictionaries::int($dict, 'per_page', 6)));
        $pages = (int) ceil(count($all) / $per);
        $rows = array_slice($all, ($p['page'] - 1) * $per, $per);
        /* translators: %s: what was searched for */
        $h = '<div class="sdict-listhead"><h3 class="sdict-listhead__title">' . esc_html(sprintf(__('Results for "%s"', 'sepo-dictionary'), $p['q']))
            . ' <span class="sdict-listhead__n">' . esc_html(number_format_i18n(count($all))) . '</span></h3>'
            . '<a class="sdict-link" href="' . esc_url(Parts::url($base, $keep)) . '">' . esc_html__('Clear search', 'sepo-dictionary') . '</a></div>';
        if ($all === []) {
            /* translators: %s: what was searched for */
            $h .= '<div class="sdict-empty"><p class="sdict-empty__big">' . esc_html(sprintf(__('"%s" is not in the dictionary yet.', 'sepo-dictionary'), $p['q'])) . '</p>'
                . '<p>' . esc_html__('Check the spelling, or try a shorter part of the word.', 'sepo-dictionary') . '</p>' . self::suggestButton($p['q'], $dict) . '</div>';
            return $h;
        }
        $exact = Entry::fold($all[0]->post_title) === Entry::fold($p['q']);
        $h .= '<ul class="sdict-list">';
        foreach ($rows as $post) {
            $h .= Parts::item($post, $dict === 0 && Dictionaries::many());
        }
        $h .= '</ul>' . Parts::pager($base, $keep + ['sd_q' => $p['q'], 'sd_cat' => $p['cat']], $p['page'], $pages);
        $suggest = $exact || $p['page'] !== 1 ? '' : self::suggestButton($p['q'], $dict);
        if ($suggest !== '') {
            /* translators: %s: what was searched for */
            $h .= '<div class="sdict-empty sdict-empty--sm"><p>' . esc_html(sprintf(__('No entry is spelt exactly "%s".', 'sepo-dictionary'), $p['q'])) . '</p>' . $suggest . '</div>';
        }
        return $h;
    }

    private static function review(string $base, array $p, array $keep): string
    {
        $statusTabs = [
            ''        => [__('Open for votes', 'sepo-dictionary'), ['open']],
            'passed'  => [__('Waiting for an editor', 'sepo-dictionary'), ['passed']],
            'settled' => [__('Recently settled', 'sepo-dictionary'), ['accepted', 'rejected', 'expired', 'superseded']],
        ];
        $st = array_key_exists($p['status'], $statusTabs) ? $p['status'] : '';
        $dict = (int) $p['dict'];
        $counts = Proposals::counts($dict);
        $orders = [
            ''        => __('Newest', 'sepo-dictionary'),
            'top'     => __('Most support', 'sepo-dictionary'),
            'closing' => __('Closing soon', 'sepo-dictionary'),
        ];
        $kinds = ['' => __('All kinds', 'sepo-dictionary'), 'new' => __('New words', 'sepo-dictionary'), 'meaning' => __('Meanings', 'sepo-dictionary'), 'edit' => __('Corrections', 'sepo-dictionary')];
        $order = array_key_exists($p['order'], $orders) ? $p['order'] : '';
        $kind = array_key_exists($p['kind'], $kinds) ? $p['kind'] : '';

        $rules = $dict === 0 && Dictionaries::rulesDiffer()
            ? __('Each dictionary sets its own voting rules. Open a dictionary to see them on its review page.', 'sepo-dictionary')
            : ProposalView::rules($dict);
        $h = '<div class="sdict-review-intro"><p>' . esc_html($rules) . '</p>';
        if (!is_user_logged_in()) {
            $h .= '<p><a href="' . esc_url(Parts::login()) . '">' . esc_html__('Sign in to vote.', 'sepo-dictionary') . '</a></p>';
        }
        $h .= '</div>';

        $h .= '<div class="sdict-toolbar"><div class="sdict-seg" role="group" aria-label="' . esc_attr__('Status', 'sepo-dictionary') . '">';
        foreach ($statusTabs as $k => [$label, $sts]) {
            $n = array_sum(array_map(static fn(string $s): int => $counts[$s] ?? 0, $sts));
            $h .= '<a class="sdict-seg__b' . ($st === $k ? ' is-on' : '') . '" href="' . esc_url(Parts::url($base, $keep + ['sd_view' => 'review', 'sd_status' => $k, 'sd_kind' => $kind, 'sd_order' => $order])) . '"' . ($st === $k ? ' aria-current="true"' : '') . '>'
                . esc_html($label) . ($k !== 'settled' && $n > 0 ? '<span class="sdict-count">' . (int) $n . '</span>' : '') . '</a>';
        }
        $h .= '</div><form class="sdict-filter" method="get" action="' . esc_url($base) . '" data-sdict-autosubmit>';
        foreach ($keep + ['sd_view' => 'review', 'sd_status' => $st] as $k => $v) {
            if ($v !== '') {
                $h .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
            }
        }
        $h .= '<label class="screen-reader-text" for="sdict-kind">' . esc_html__('Kind', 'sepo-dictionary') . '</label><select id="sdict-kind" name="sd_kind">';
        foreach ($kinds as $k => $label) {
            $h .= '<option value="' . esc_attr($k) . '"' . selected($kind, $k, false) . '>' . esc_html($label) . '</option>';
        }
        $h .= '</select>';
        if ($st === '') {
            $h .= '<label class="screen-reader-text" for="sdict-order">' . esc_html__('Order', 'sepo-dictionary') . '</label><select id="sdict-order" name="sd_order">';
            foreach ($orders as $k => $label) {
                $h .= '<option value="' . esc_attr($k) . '"' . selected($order, $k, false) . '>' . esc_html($label) . '</option>';
            }
            $h .= '</select>';
        }
        $h .= '<noscript><button class="sdict-btn sdict-btn--sm" type="submit">' . esc_html__('Filter', 'sepo-dictionary') . '</button></noscript></form></div>';

        $res = Proposals::query([
            'status'   => $statusTabs[$st][1],
            'dict_id'  => $dict,
            'kind'     => $kind,
            'order'    => $st === 'settled' ? 'closed' : ($order === '' ? 'new' : $order),
            'page'     => $p['page'],
            'per_page' => 15,
        ]);
        if ($res['rows'] === []) {
            $h .= '<div class="sdict-empty"><p>' . esc_html($st === '' ? __('Nothing is waiting for votes right now.', 'sepo-dictionary') : __('Nothing here.', 'sepo-dictionary')) . '</p>'
                . ($st === '' ? self::suggestButton('', $dict) : '') . '</div>';
            return $h;
        }
        $h .= ProposalView::list($res['rows'], ['showTerm' => true, 'showDict' => $dict === 0]);
        return $h . Parts::pager($base, $keep + ['sd_view' => 'review', 'sd_status' => $st, 'sd_kind' => $kind, 'sd_order' => $order], $p['page'], (int) ceil($res['total'] / 15));
    }

    private static function mine(string $base, array $p): string
    {
        if (!is_user_logged_in()) {
            return '<div class="sdict-empty"><p>' . esc_html__('Sign in to see your suggestions.', 'sepo-dictionary') . '</p><a class="sdict-btn" href="' . esc_url(Parts::login()) . '">' . esc_html__('Sign in', 'sepo-dictionary') . '</a></div>';
        }
        $uid = get_current_user_id();
        $dict = (int) $p['dict'];
        $res = Proposals::query(['user_id' => $uid, 'dict_id' => $dict, 'status' => Proposals::STATUSES, 'order' => 'new', 'page' => $p['page'], 'per_page' => 15]);
        $accepted = (int) get_user_meta($uid, 'sd_accepted', true);
        /* translators: %d: number of accepted suggestions */
        $h = '<div class="sdict-review-intro"><p>' . esc_html(sprintf(_n('%d of your suggestions has been accepted into the dictionary.', '%d of your suggestions have been accepted into the dictionary.', $accepted, 'sepo-dictionary'), $accepted)) . '</p></div>';
        if ($res['rows'] === []) {
            return $h . '<div class="sdict-empty"><p>' . esc_html__('You have not suggested anything yet.', 'sepo-dictionary') . '</p>' . self::suggestButton('', $dict) . '</div>';
        }
        $h .= ProposalView::list($res['rows'], ['showTerm' => true, 'showDict' => $dict === 0]);
        return $h . Parts::pager($base, ['sd_view' => 'mine'], $p['page'], (int) ceil($res['total'] / 15));
    }

    private static function contributors(int $dict): string
    {
        $rows = Proposals::contributors(30, $dict);
        if ($rows === []) {
            return '<div class="sdict-empty"><p>' . esc_html__('No one has made a suggestion yet. Be the first.', 'sepo-dictionary') . '</p>' . self::suggestButton('', $dict) . '</div>';
        }
        $h = '<div class="sdict-tablewrap"><table class="sdict-table"><thead><tr><th scope="col">#</th><th scope="col">' . esc_html__('Member', 'sepo-dictionary') . '</th>'
            . '<th scope="col" class="num">' . esc_html__('Accepted', 'sepo-dictionary') . '</th><th scope="col" class="num">' . esc_html__('Suggested', 'sepo-dictionary') . '</th><th scope="col" class="num">' . esc_html__('Votes cast', 'sepo-dictionary') . '</th></tr></thead><tbody>';
        foreach ($rows as $i => $r) {
            $h .= '<tr><td class="sdict-rank">' . ($i + 1) . '</td><td><span class="sdict-avatar" aria-hidden="true">' . esc_html(self::initials($r['name'])) . '</span>' . esc_html($r['name']) . '</td>'
                . '<td class="num"><strong>' . (int) $r['accepted'] . '</strong></td><td class="num">' . (int) $r['total'] . '</td><td class="num">' . (int) $r['votes'] . '</td></tr>';
        }
        return $h . '</tbody></table></div>';
    }

    /** One or two letters for a member's badge, drawn locally rather than fetched from an avatar service. */
    private static function initials(string $name): string
    {
        $parts = preg_split('~\s+~u', trim($name)) ?: [];
        $out = '';
        foreach (array_slice($parts, 0, 2) as $w) {
            $out .= mb_strtoupper(mb_substr($w, 0, 1));
        }
        return $out !== '' ? $out : '?';
    }
}
