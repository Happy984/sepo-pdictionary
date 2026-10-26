<?php

declare(strict_types=1);

namespace SepoDictionary\Render;

use SepoDictionary\Entry;
use SepoDictionary\Plugin;
use SepoDictionary\PostType;
use SepoDictionary\Proposals;
use SepoDictionary\Settings;

defined('ABSPATH') || exit;

/**
 * The dictionary page: browse by letter, search, the community review
 * queue, a member's own suggestions, and the contributors.
 */
final class AppView
{
    /** @param array<string,mixed> $a per_page, word_of_day, letters, language, category, title */
    public static function render(array $a): string
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
        $base = is_singular() ? (string) get_permalink() : Settings::indexUrl();
        $keep = $fixedLang === '' && $p['lang'] !== '' ? ['sd_lang' => $p['lang']] : [];
        $languages = $fixedLang === '' ? Parts::terms(PostType::LANGUAGE) : [];

        $h = self::masthead($a, $p['lang']);
        $h .= Parts::search($base, $p['q'], $p['lang'], false, $languages);
        $h .= Parts::nav($base, in_array($p['view'], ['review', 'mine', 'contributors'], true) ? $p['view'] : '', $keep);

        $h .= match ($p['view']) {
            'review'       => self::review($base, $p, $keep),
            'mine'         => self::mine($base, $p),
            'contributors' => self::contributors(),
            default        => $p['q'] !== '' ? self::results($base, $p, $a, $keep) : self::browse($base, $p, $a, $keep),
        };

        return Parts::scope($h, 'sdict-app sdict-index', ['data-sdict-app' => '1', 'data-sdict-lang' => $p['lang']]);
    }

    private static function masthead(array $a, string $lang): string
    {
        $title = trim((string) ($a['title'] ?? ''));
        $total = Entry::total($lang);
        $langCount = (int) wp_count_terms(['taxonomy' => PostType::LANGUAGE, 'hide_empty' => true]);
        /* translators: %s: number of words */
        $sub = sprintf(_n('%s word', '%s words', $total, 'sepo-dictionary'), number_format_i18n($total));
        if ($langCount > 1 && $lang === '') {
            /* translators: %s: number of languages */
            $sub .= ' · ' . sprintf(_n('%s language', '%s languages', $langCount, 'sepo-dictionary'), number_format_i18n($langCount));
        }
        $h = '<header class="sdict-masthead"><div class="sdict-masthead__text">';
        if ($title !== '' && $title !== 'none') {
            $h .= '<h2 class="sdict-title">' . esc_html($title) . '</h2>';
        }
        $h .= '<p class="sdict-masthead__sub">' . esc_html($sub) . '</p></div><div class="sdict-masthead__actions">';
        if (Plugin::canManage()) {
            $h .= '<button type="button" class="sdict-btn sdict-btn--primary" data-sdict-act="new-entry">' . Icons::get('plus', 15) . esc_html__('Add a word', 'sepo-dictionary') . '</button>';
        } elseif (Plugin::canPropose()) {
            $h .= '<button type="button" class="sdict-btn sdict-btn--primary" data-sdict-act="propose-new">' . Icons::get('plus', 15) . esc_html__('Suggest a word', 'sepo-dictionary') . '</button>';
        } elseif (!is_user_logged_in() && (int) Settings::get('allow_proposals') === 1) {
            $h .= '<a class="sdict-btn" href="' . esc_url(Parts::login()) . '">' . esc_html__('Sign in to suggest a word', 'sepo-dictionary') . '</a>';
        }
        return $h . '</div></header>';
    }

    private static function browse(string $base, array $p, array $a, array $keep): string
    {
        $manager = Plugin::canManage();
        $h = '';
        $wod = !isset($a['word_of_day']) || (string) $a['word_of_day'] !== '0';
        if ($wod && (int) Settings::get('word_of_day') === 1 && $p['letter'] === '' && $p['cat'] === '' && $p['page'] === 1) {
            $w = Entry::wordOfDay($p['lang']);
            if ($w instanceof \WP_Post) {
                $h .= EntryView::card($w, __('Word of the day', 'sepo-dictionary'));
            }
        }
        if (!isset($a['letters']) || (string) $a['letters'] !== '0') {
            $h .= Parts::letters($base, Entry::letters($p['lang'], $p['cat'], $manager), $p['letter'], $keep + ['sd_cat' => $p['cat']]);
        }

        $per = max(6, min(120, (int) ($a['per_page'] ?? 0) ?: Settings::int('per_page', 6)));
        $res = Entry::browse(['letter' => $p['letter'], 'language' => $p['lang'], 'category' => $p['cat'], 'page' => $p['page'], 'per_page' => $per, 'manager' => $manager]);

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
            $h .= '<div class="sdict-empty"><p>' . esc_html__('No words here yet.', 'sepo-dictionary') . '</p>' . self::suggestButton('') . '</div>';
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
        $cats = array_values(array_filter(Parts::terms(PostType::CATEGORY), static fn(\WP_Term $t): bool => $t->count > 0));
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

    private static function suggestButton(string $term): string
    {
        if (Plugin::canManage()) {
            return '<button type="button" class="sdict-btn sdict-btn--primary" data-sdict-act="new-entry" data-prefill="' . esc_attr($term) . '">' . Icons::get('plus', 15) . esc_html__('Add this word', 'sepo-dictionary') . '</button>';
        }
        if (Plugin::canPropose()) {
            return '<button type="button" class="sdict-btn sdict-btn--primary" data-sdict-act="propose-new" data-prefill="' . esc_attr($term) . '">' . Icons::get('plus', 15) . esc_html__('Suggest this word', 'sepo-dictionary') . '</button>';
        }
        return '';
    }

    private static function results(string $base, array $p, array $a, array $keep): string
    {
        $all = Entry::search($p['q'], ['limit' => 500, 'language' => $p['lang'], 'category' => $p['cat'], 'manager' => Plugin::canManage()]);
        $per = max(6, min(120, (int) ($a['per_page'] ?? 0) ?: Settings::int('per_page', 6)));
        $pages = (int) ceil(count($all) / $per);
        $rows = array_slice($all, ($p['page'] - 1) * $per, $per);
        /* translators: %s: what was searched for */
        $h = '<div class="sdict-listhead"><h3 class="sdict-listhead__title">' . esc_html(sprintf(__('Results for "%s"', 'sepo-dictionary'), $p['q']))
            . ' <span class="sdict-listhead__n">' . esc_html(number_format_i18n(count($all))) . '</span></h3>'
            . '<a class="sdict-link" href="' . esc_url(Parts::url($base, $keep)) . '">' . esc_html__('Clear search', 'sepo-dictionary') . '</a></div>';
        if ($all === []) {
            /* translators: %s: what was searched for */
            $h .= '<div class="sdict-empty"><p class="sdict-empty__big">' . esc_html(sprintf(__('"%s" is not in the dictionary yet.', 'sepo-dictionary'), $p['q'])) . '</p>'
                . '<p>' . esc_html__('Check the spelling, or try a shorter part of the word.', 'sepo-dictionary') . '</p>' . self::suggestButton($p['q']) . '</div>';
            return $h;
        }
        $exact = Entry::fold($all[0]->post_title) === Entry::fold($p['q']);
        $h .= '<ul class="sdict-list">';
        foreach ($rows as $post) {
            $h .= Parts::item($post);
        }
        $h .= '</ul>' . Parts::pager($base, $keep + ['sd_q' => $p['q'], 'sd_cat' => $p['cat']], $p['page'], $pages);
        if (!$exact && $p['page'] === 1 && (Plugin::canPropose() || Plugin::canManage())) {
            /* translators: %s: what was searched for */
            $h .= '<div class="sdict-empty sdict-empty--sm"><p>' . esc_html(sprintf(__('No entry is spelt exactly "%s".', 'sepo-dictionary'), $p['q'])) . '</p>' . self::suggestButton($p['q']) . '</div>';
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
        $counts = Proposals::counts();
        $orders = [
            ''        => __('Newest', 'sepo-dictionary'),
            'top'     => __('Most support', 'sepo-dictionary'),
            'closing' => __('Closing soon', 'sepo-dictionary'),
        ];
        $kinds = ['' => __('All kinds', 'sepo-dictionary'), 'new' => __('New words', 'sepo-dictionary'), 'meaning' => __('Meanings', 'sepo-dictionary'), 'edit' => __('Corrections', 'sepo-dictionary')];
        $order = array_key_exists($p['order'], $orders) ? $p['order'] : '';
        $kind = array_key_exists($p['kind'], $kinds) ? $p['kind'] : '';

        $h = '<div class="sdict-review-intro"><p>' . esc_html(ProposalView::rules()) . '</p>';
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
            'kind'     => $kind,
            'order'    => $st === 'settled' ? 'closed' : ($order === '' ? 'new' : $order),
            'page'     => $p['page'],
            'per_page' => 15,
        ]);
        if ($res['rows'] === []) {
            $h .= '<div class="sdict-empty"><p>' . esc_html($st === '' ? __('Nothing is waiting for votes right now.', 'sepo-dictionary') : __('Nothing here.', 'sepo-dictionary')) . '</p>'
                . ($st === '' ? self::suggestButton('') : '') . '</div>';
            return $h;
        }
        $h .= ProposalView::list($res['rows'], ['showTerm' => true]);
        return $h . Parts::pager($base, $keep + ['sd_view' => 'review', 'sd_status' => $st, 'sd_kind' => $kind, 'sd_order' => $order], $p['page'], (int) ceil($res['total'] / 15));
    }

    private static function mine(string $base, array $p): string
    {
        if (!is_user_logged_in()) {
            return '<div class="sdict-empty"><p>' . esc_html__('Sign in to see your suggestions.', 'sepo-dictionary') . '</p><a class="sdict-btn" href="' . esc_url(Parts::login()) . '">' . esc_html__('Sign in', 'sepo-dictionary') . '</a></div>';
        }
        $uid = get_current_user_id();
        $res = Proposals::query(['user_id' => $uid, 'status' => Proposals::STATUSES, 'order' => 'new', 'page' => $p['page'], 'per_page' => 15]);
        $accepted = (int) get_user_meta($uid, 'sd_accepted', true);
        /* translators: %d: number of accepted suggestions */
        $h = '<div class="sdict-review-intro"><p>' . esc_html(sprintf(_n('%d of your suggestions has been accepted into the dictionary.', '%d of your suggestions have been accepted into the dictionary.', $accepted, 'sepo-dictionary'), $accepted)) . '</p></div>';
        if ($res['rows'] === []) {
            return $h . '<div class="sdict-empty"><p>' . esc_html__('You have not suggested anything yet.', 'sepo-dictionary') . '</p>' . self::suggestButton('') . '</div>';
        }
        $h .= ProposalView::list($res['rows'], ['showTerm' => true]);
        return $h . Parts::pager($base, ['sd_view' => 'mine'], $p['page'], (int) ceil($res['total'] / 15));
    }

    private static function contributors(): string
    {
        $rows = Proposals::contributors(30);
        if ($rows === []) {
            return '<div class="sdict-empty"><p>' . esc_html__('No one has made a suggestion yet. Be the first.', 'sepo-dictionary') . '</p>' . self::suggestButton('') . '</div>';
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
