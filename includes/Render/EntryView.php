<?php

declare(strict_types=1);

namespace SepoDictionary\Render;

use SepoDictionary\Dictionaries;
use SepoDictionary\Entry;
use SepoDictionary\History;
use SepoDictionary\Plugin;
use SepoDictionary\PostType;
use SepoDictionary\Proposals;
use SepoDictionary\Settings;

defined('ABSPATH') || exit;

/** One entry's page: the meanings, the suggestions waiting for votes, and the history. */
final class EntryView
{
    /** @param array<string,mixed> $o compact (bool) for embedding without the search bar and tabs */
    public static function render(int $id, array $o = []): string
    {
        $s = Entry::get($id);
        if ($s === null) {
            return '';
        }
        $dict = (int) $s['dictionary'];
        $manager = Plugin::canManage($dict);
        if ($s['status'] !== 'publish' && !$manager) {
            return '';
        }
        $compact = !empty($o['compact']);
        $base = Dictionaries::homeUrl($dict);
        $tab = sanitize_key((string) ($_GET['sd_tab'] ?? 'meaning')); // phpcs:ignore WordPress.Security.NonceVerification
        if (!in_array($tab, ['meaning', 'proposals', 'history'], true) || $compact) {
            $tab = 'meaning';
        }

        $live = Proposals::query(['entry_id' => $id, 'status' => Proposals::LIVE, 'order' => 'top', 'per_page' => 100])['rows'];
        $settled = Proposals::query(['entry_id' => $id, 'status' => ['accepted', 'rejected', 'expired', 'superseded', 'withdrawn'], 'order' => 'closed', 'per_page' => 20]);
        $histCount = History::count($id);

        $h = '';
        if (!$compact) {
            $letter = Entry::letterFor($s['term']);
            $crumbs = Dictionaries::many()
                ? '<a href="' . esc_url(Settings::indexUrl()) . '">' . Icons::get('book', 14) . esc_html__('Dictionaries', 'sepo-dictionary') . '</a>'
                    . '<span aria-hidden="true">/</span><a href="' . esc_url($base) . '">' . esc_html(Dictionaries::name($dict)) . '</a>'
                : '<a href="' . esc_url($base) . '">' . Icons::get('book', 14) . esc_html__('Dictionary', 'sepo-dictionary') . '</a>';
            $h .= '<div class="sdict-topbar"><nav class="sdict-crumbs" aria-label="' . esc_attr__('Breadcrumb', 'sepo-dictionary') . '">'
                . $crumbs
                . '<span aria-hidden="true">/</span><a href="' . esc_url(Parts::url($base, ['sd_letter' => $letter])) . '">' . esc_html(Entry::letterLabel($letter)) . '</a></nav>'
                . Parts::search($base, '', '', true) . '</div>';
        }

        $h .= '<article class="sdict-entry" data-sdict-entry="' . esc_attr((string) wp_json_encode(['id' => $id] + $s)) . '" data-sdict-url="' . esc_url(Entry::url($id)) . '">';
        $h .= self::head($id, $s, $manager, $compact);

        if (!$compact) {
            $h .= '<div class="sdict-tabs" role="tablist" aria-label="' . esc_attr__('Entry', 'sepo-dictionary') . '">';
            foreach ([
                'meaning'   => [__('Meaning', 'sepo-dictionary'), 0],
                'proposals' => [__('Suggestions', 'sepo-dictionary'), count($live)],
                'history'   => [__('History', 'sepo-dictionary'), $histCount],
            ] as $key => [$label, $n]) {
                $on = $tab === $key;
                $h .= '<a class="sdict-tab' . ($on ? ' is-on' : '') . '" role="tab" id="sdict-tab-' . $key . '" href="' . esc_url(add_query_arg('sd_tab', $key, Entry::url($id))) . '" aria-controls="sdict-panel-' . $key . '" aria-selected="' . ($on ? 'true' : 'false') . '" data-sdict-tab="' . $key . '">'
                    . esc_html($label) . ($n > 0 ? '<span class="sdict-count">' . (int) $n . '</span>' : '') . '</a>';
            }
            $h .= '</div>';
        }

        $h .= '<section class="sdict-panel" role="tabpanel" id="sdict-panel-meaning" aria-labelledby="sdict-tab-meaning"' . ($tab !== 'meaning' ? ' hidden' : '') . '>'
            . self::meaning($id, $s, $live, $compact) . '</section>';

        if (!$compact) {
            $h .= '<section class="sdict-panel" role="tabpanel" id="sdict-panel-proposals" aria-labelledby="sdict-tab-proposals"' . ($tab !== 'proposals' ? ' hidden' : '') . '>';
            $h .= '<p class="sdict-rules">' . esc_html(ProposalView::rules($dict)) . '</p>';
            $h .= $live !== []
                ? ProposalView::list($live)
                : '<div class="sdict-empty sdict-empty--sm"><p>' . esc_html__('No suggestions are waiting for votes on this word.', 'sepo-dictionary') . '</p></div>';
            $h .= self::contribute($id, $s);
            if ($settled['rows'] !== []) {
                $h .= '<h3 class="sdict-subhead">' . esc_html__('Settled suggestions', 'sepo-dictionary') . '</h3>' . ProposalView::list($settled['rows']);
            }
            $h .= '</section>';
            $h .= '<section class="sdict-panel" role="tabpanel" id="sdict-panel-history" aria-labelledby="sdict-tab-history"' . ($tab !== 'history' ? ' hidden' : '') . '>'
                . HistoryView::render($id, $manager) . '</section>';
        }
        $h .= '</article>';

        return Parts::scope($h, 'sdict-app sdict-entry-page', ['data-sdict-app' => '1'] + Parts::dictAttrs($dict));
    }

    private static function head(int $id, array $s, bool $manager, bool $compact): string
    {
        $tag = $compact ? 'h2' : 'h1';
        $h = '<header class="sdict-entry__head"><div class="sdict-entry__title">';
        $h .= '<' . $tag . ' class="sdict-headword">' . esc_html($s['term']) . '</' . $tag . '>';
        $h .= '<div class="sdict-entry__line">';
        if ($s['pronunciation'] !== '') {
            $h .= '<span class="sdict-pron">' . esc_html($s['pronunciation']) . '</span>';
        }
        $h .= self::listen($s);
        if ($manager && !$compact && current_user_can('upload_files')) {
            $h .= '<button type="button" class="sdict-iconbtn sdict-iconbtn--sm" data-sdict-act="audio" title="' . esc_attr__('Record or upload the pronunciation', 'sepo-dictionary') . '" aria-label="' . esc_attr__('Record or upload the pronunciation', 'sepo-dictionary') . '">' . Icons::get('mic', 15) . '</button>';
        }
        if ($s['pos'] !== '') {
            $h .= '<span class="sdict-pos sdict-pos--lg">' . esc_html($s['pos']) . '</span>';
        }
        $h .= '</div><div class="sdict-entry__tags">';
        if ($s['language'] !== '') {
            $t = get_term_by('name', $s['language'], PostType::LANGUAGE);
            $h .= $t instanceof \WP_Term
                ? '<a class="sdict-tag sdict-tag--lang" href="' . esc_url(Parts::url(Dictionaries::homeUrl((int) $s['dictionary']), ['sd_lang' => $t->slug])) . '">' . esc_html($s['language']) . '</a>'
                : '<span class="sdict-tag sdict-tag--lang">' . esc_html($s['language']) . '</span>';
        }
        if ($s['category'] !== '') {
            $t = get_term_by('name', $s['category'], PostType::CATEGORY);
            $h .= $t instanceof \WP_Term
                ? '<a class="sdict-tag" href="' . esc_url(Parts::url(Dictionaries::homeUrl((int) $s['dictionary']), ['sd_cat' => $t->slug])) . '">' . esc_html($s['category']) . '</a>'
                : '<span class="sdict-tag">' . esc_html($s['category']) . '</span>';
        }
        $h .= Parts::status($s['status']);
        $h .= '</div></div>';

        $h .= '<div class="sdict-entry__tools">';
        $h .= '<button type="button" class="sdict-iconbtn" data-sdict-copy="' . esc_url(Entry::url($id)) . '" title="' . esc_attr__('Copy link', 'sepo-dictionary') . '" aria-label="' . esc_attr__('Copy link', 'sepo-dictionary') . '">' . Icons::get('link', 16) . '</button>';
        $h .= '<button type="button" class="sdict-iconbtn" data-sdict-cite title="' . esc_attr__('Cite this entry', 'sepo-dictionary') . '" aria-label="' . esc_attr__('Cite this entry', 'sepo-dictionary') . '">' . Icons::get('quote', 16) . '</button>';
        if ($manager) {
            $h .= '<button type="button" class="sdict-btn sdict-btn--sm" data-sdict-act="edit-entry">' . Icons::get('edit', 14) . esc_html__('Edit', 'sepo-dictionary') . '</button>';
            $h .= '<button type="button" class="sdict-iconbtn sdict-iconbtn--danger" data-sdict-act="trash-entry" title="' . esc_attr__('Move to the trash', 'sepo-dictionary') . '" aria-label="' . esc_attr__('Move to the trash', 'sepo-dictionary') . '">' . Icons::get('trash', 16) . '</button>';
        }
        return $h . '</div></header>';
    }

    /** @param list<array<string,mixed>> $live */
    private static function meaning(int $id, array $s, array $live, bool $compact): string
    {
        $pending = [];
        foreach ($live as $p) {
            if ($p['kind'] === 'meaning' && $p['target'] !== '') {
                $pending[$p['target']] = ($pending[$p['target']] ?? 0) + 1;
            }
        }
        $canPropose = Plugin::canPropose((int) $s['dictionary']) && !$compact;
        $h = '';
        if ($s['definitions'] === []) {
            $h .= '<p class="sdict-muted">' . esc_html__('No meaning has been recorded yet.', 'sepo-dictionary') . '</p>';
        } else {
            $h .= '<ol class="sdict-senses">';
            foreach ($s['definitions'] as $i => $d) {
                $h .= '<li class="sdict-sense" id="sense-' . esc_attr($d['id']) . '"><span class="sdict-sense__n" aria-hidden="true">' . ($i + 1) . '</span><div class="sdict-sense__body">';
                $h .= '<p class="sdict-def">' . nl2br(esc_html($d['text'])) . '</p>';
                if ($d['example'] !== '') {
                    $h .= '<p class="sdict-ex">' . esc_html($d['example']) . '</p>';
                }
                if (!empty($pending[$d['id']])) {
                    /* translators: %d: number of suggestions */
                    $h .= '<a class="sdict-sense__pending" href="' . esc_url(add_query_arg('sd_tab', 'proposals', Entry::url($id))) . '" data-sdict-tab-link="proposals">' . Icons::get('vote', 13) . esc_html(sprintf(_n('%d suggestion waiting for votes', '%d suggestions waiting for votes', $pending[$d['id']], 'sepo-dictionary'), $pending[$d['id']])) . '</a>';
                }
                if ($canPropose) {
                    $h .= '<div class="sdict-sense__tools">'
                        . '<button type="button" class="sdict-link" data-sdict-act="propose-replace" data-target="' . esc_attr($d['id']) . '">' . esc_html__('Suggest a better meaning', 'sepo-dictionary') . '</button>';
                    if (count($s['definitions']) > 1) {
                        $h .= '<button type="button" class="sdict-link sdict-link--muted" data-sdict-act="propose-remove" data-target="' . esc_attr($d['id']) . '">' . esc_html__('Flag as wrong', 'sepo-dictionary') . '</button>';
                    }
                    $h .= '</div>';
                }
                $h .= '</div></li>';
            }
            $h .= '</ol>';
        }

        $rel = '';
        foreach (['synonyms' => __('Synonyms', 'sepo-dictionary'), 'antonyms' => __('Antonyms', 'sepo-dictionary'), 'related' => __('See also', 'sepo-dictionary')] as $k => $label) {
            if ($s[$k] !== []) {
                $rel .= '<div class="sdict-rel__row"><dt>' . esc_html($label) . '</dt><dd>' . Parts::chips($s[$k], $s['language'], (int) $s['dictionary']) . '</dd></div>';
            }
        }
        if ($rel !== '') {
            $h .= '<dl class="sdict-rel">' . $rel . '</dl>';
        }
        foreach (['etymology' => __('Origin', 'sepo-dictionary'), 'notes' => __('Usage notes', 'sepo-dictionary')] as $k => $label) {
            if ($s[$k] !== '') {
                $h .= '<section class="sdict-block"><h3 class="sdict-block__h">' . esc_html($label) . '</h3><p>' . nl2br(esc_html($s[$k])) . '</p></section>';
            }
        }

        if (!$compact) {
            $h .= self::contribute($id, $s);
        }

        $foot = [];
        if ($s['tags'] !== []) {
            $tags = '';
            foreach ($s['tags'] as $t) {
                $tags .= '<span class="sdict-chip sdict-chip--tag">' . esc_html($t) . '</span>';
            }
            $foot[] = '<span class="sdict-entry__chips">' . $tags . '</span>';
        }
        if ($s['source'] !== '') {
            /* translators: %s: where the entry comes from */
            $foot[] = '<span>' . esc_html(sprintf(__('Source: %s', 'sepo-dictionary'), $s['source'])) . '</span>';
        }
        $post = get_post($id);
        if ($post instanceof \WP_Post) {
            /* translators: %s: date */
            $foot[] = '<span>' . esc_html(sprintf(__('Updated %s', 'sepo-dictionary'), Parts::date((int) get_post_modified_time('U', true, $post)))) . '</span>';
        }
        $people = array_slice(History::contributors($id), 0, 6);
        if ($people !== []) {
            $names = [];
            foreach ($people as $uid) {
                $u = get_userdata($uid);
                if ($u) {
                    $names[] = $u->display_name;
                }
            }
            if ($names !== []) {
                /* translators: %s: names */
                $foot[] = '<span>' . esc_html(sprintf(__('Contributors: %s', 'sepo-dictionary'), implode(', ', $names))) . '</span>';
            }
        }
        if ($compact) {
            $foot[] = '<a href="' . esc_url(Entry::url($id)) . '">' . esc_html__('Open the full entry', 'sepo-dictionary') . '</a>';
        }
        $h .= '<footer class="sdict-entry__foot">' . implode('', $foot) . '</footer>';
        return $h;
    }

    /** The invitation to improve the entry. */
    private static function contribute(int $id, array $s): string
    {
        $dict = (int) $s['dictionary'];
        if (Plugin::canPropose($dict)) {
            return '<div class="sdict-contribute"><div class="sdict-contribute__text"><strong>' . esc_html__('Know this word well?', 'sepo-dictionary') . '</strong>'
                . '<span>' . esc_html__('Suggest another meaning or a correction. Members vote on its accuracy, and the suggestion that wins is merged into this entry.', 'sepo-dictionary') . '</span></div>'
                . '<div class="sdict-contribute__actions"><button type="button" class="sdict-btn sdict-btn--primary" data-sdict-act="propose-add">' . Icons::get('plus', 15) . esc_html__('Suggest a meaning', 'sepo-dictionary') . '</button>'
                . '<button type="button" class="sdict-btn" data-sdict-act="propose-edit">' . Icons::get('edit', 14) . esc_html__('Suggest a correction', 'sepo-dictionary') . '</button></div></div>';
        }
        if (!is_user_logged_in() && Plugin::proposalsOpen($dict)) {
            return '<div class="sdict-contribute"><div class="sdict-contribute__text"><strong>' . esc_html__('Know this word well?', 'sepo-dictionary') . '</strong>'
                . '<span>' . esc_html__('Sign in to suggest a meaning or a correction, and to vote on other suggestions.', 'sepo-dictionary') . '</span></div>'
                . '<div class="sdict-contribute__actions"><a class="sdict-btn" href="' . esc_url(Parts::login()) . '">' . esc_html__('Sign in', 'sepo-dictionary') . '</a></div></div>';
        }
        return '';
    }

    /**
     * The Listen button: plays the recorded pronunciation when there is one,
     * otherwise the browser reads the word aloud.
     */
    public static function listen(array $s): string
    {
        $url = Entry::audioUrl($s);
        return '<button type="button" class="sdict-listen' . ($url !== '' ? ' sdict-listen--recorded' : '') . '" data-sdict-say="' . esc_attr($s['term']) . '" data-sdict-lang="' . esc_attr(Entry::speechCode($s['language'])) . '"'
            . ($url !== '' ? ' data-sdict-audio="' . esc_url($url) . '"' : ' hidden')
            . ' title="' . esc_attr($url !== '' ? __('Listen to the recording', 'sepo-dictionary') : __('Listen', 'sepo-dictionary')) . '" aria-label="' . esc_attr__('Listen to the word', 'sepo-dictionary') . '">' . Icons::get('volume', 16) . '</button>';
    }

    /** A word's card for the word of the day and the [sepo_word] shortcode. */
    public static function card(\WP_Post $post, string $eyebrow = ''): string
    {
        $s = Entry::get((int) $post->ID);
        if ($s === null) {
            return '';
        }
        $h = '<section class="sdict-wod">';
        if ($eyebrow !== '') {
            $h .= '<div class="sdict-wod__eyebrow">' . Icons::get('sun', 14) . '<span>' . esc_html($eyebrow) . '</span><span class="sdict-wod__date">' . esc_html(wp_date('l, j F')) . '</span></div>';
        }
        $h .= '<div class="sdict-wod__head"><a class="sdict-wod__word" href="' . esc_url(Entry::url((int) $post->ID)) . '">' . esc_html($s['term']) . '</a>';
        if ($s['pronunciation'] !== '') {
            $h .= '<span class="sdict-pron">' . esc_html($s['pronunciation']) . '</span>';
        }
        $h .= self::listen($s);
        if ($s['pos'] !== '') {
            $h .= '<span class="sdict-pos">' . esc_html($s['pos']) . '</span>';
        }
        $h .= '</div>';
        $def = $s['definitions'][0] ?? null;
        if ($def !== null) {
            $h .= '<p class="sdict-wod__def">' . esc_html($def['text']) . '</p>';
            if ($def['example'] !== '') {
                $h .= '<p class="sdict-ex">' . esc_html($def['example']) . '</p>';
            }
        }
        return $h . '<a class="sdict-wod__more" href="' . esc_url(Entry::url((int) $post->ID)) . '">' . esc_html__('Read the entry', 'sepo-dictionary') . Icons::get('next', 14) . '</a></section>';
    }
}
