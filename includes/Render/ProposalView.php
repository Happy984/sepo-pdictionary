<?php

declare(strict_types=1);

namespace SepoDictionary\Render;

use SepoDictionary\Dictionaries;
use SepoDictionary\Diff;
use SepoDictionary\Entry;
use SepoDictionary\Plugin;
use SepoDictionary\Proposals;
use SepoDictionary\Settings;

defined('ABSPATH') || exit;

/** A suggestion as a card: what it changes, the votes so far, and what it still needs. */
final class ProposalView
{
    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $o showTerm, showDict (name each suggestion's dictionary)
     */
    public static function list(array $rows, array $o = []): string
    {
        if ($rows === []) {
            return '';
        }
        $mine = Proposals::votesBy(array_map(static fn(array $p): int => (int) $p['id'], $rows), get_current_user_id());
        $h = '<div class="sdict-props">';
        foreach ($rows as $p) {
            $h .= self::card($p, $o + ['mine' => $mine[(int) $p['id']] ?? 0]);
        }
        return $h . '</div>';
    }

    /** @param array<string,mixed> $o showTerm, mine */
    public static function card(array $p, array $o = []): string
    {
        $uid = get_current_user_id();
        $own = $uid > 0 && $uid === (int) $p['user_id'];
        $live = in_array($p['status'], Proposals::LIVE, true);
        $kindClass = $p['kind'] === 'meaning' ? 'meaning-' . $p['op'] : $p['kind'];

        $h = '<article class="sdict-prop sdict-prop--' . esc_attr((string) $p['status']) . '" id="proposal-' . (int) $p['id'] . '" data-sdict-prop="' . (int) $p['id'] . '">';
        $h .= '<header class="sdict-prop__head">';
        $h .= '<span class="sdict-kind sdict-kind--' . esc_attr($kindClass) . '">' . esc_html(Proposals::kindLabel($p)) . '</span>';
        if (!empty($o['showTerm'])) {
            $href = $p['entry_id'] > 0 && get_post_status((int) $p['entry_id']) === 'publish' ? Entry::url((int) $p['entry_id']) : '';
            $h .= $href !== ''
                ? '<a class="sdict-prop__term" href="' . esc_url(add_query_arg('sd_tab', 'proposals', $href) . '#proposal-' . (int) $p['id']) . '">' . esc_html((string) $p['term']) . '</a>'
                : '<span class="sdict-prop__term">' . esc_html((string) $p['term']) . '</span>';
            $where = array_filter([!empty($o['showDict']) && Dictionaries::many() ? Dictionaries::name((int) $p['dict_id']) : '', (string) $p['language']]);
            if ($where !== []) {
                $h .= '<span class="sdict-prop__lang">' . esc_html(implode(' · ', $where)) . '</span>';
            }
        }
        $h .= self::pill($p);
        $h .= '</header>';

        $h .= '<div class="sdict-prop__body">' . self::body($p) . '</div>';

        if (trim((string) $p['reason']) !== '') {
            $h .= '<p class="sdict-prop__reason"><span class="sdict-label">' . esc_html__('Why', 'sepo-dictionary') . '</span>' . esc_html((string) $p['reason']) . '</p>';
        }
        if ($p['close_note'] !== '' && $p['status'] !== 'open') {
            $h .= '<p class="sdict-prop__note sdict-prop__note--' . esc_attr((string) $p['status']) . '">' . esc_html((string) $p['close_note']) . '</p>';
        }

        $notes = Proposals::notes((int) $p['id']);
        if ($notes !== []) {
            /* translators: %d: number of notes */
            $h .= '<details class="sdict-notes"><summary>' . esc_html(sprintf(_n('%d note from a voter', '%d notes from voters', count($notes), 'sepo-dictionary'), count($notes))) . '</summary><ul>';
            foreach ($notes as $n) {
                $h .= '<li><span class="sdict-notes__v sdict-notes__v--' . ($n['value'] > 0 ? 'up' : 'down') . '">' . Icons::get($n['value'] > 0 ? 'check' : 'x', 12) . '</span>'
                    . '<span class="sdict-notes__t">' . esc_html($n['note']) . '</span><span class="sdict-notes__who">' . esc_html($n['name']) . '</span></li>';
            }
            $h .= '</ul></details>';
        }

        // Votes, progress and actions.
        $h .= '<footer class="sdict-prop__foot">';
        $h .= self::voteBar($p, (int) ($o['mine'] ?? 0), $own);
        $h .= '<div class="sdict-prop__meta">';
        /* translators: 1: member's name, 2: how long ago */
        $h .= '<span>' . esc_html(sprintf(__('Suggested by %1$s, %2$s', 'sepo-dictionary'), $p['author'] !== '' ? $p['author'] : __('a former member', 'sepo-dictionary'), Parts::ago((int) $p['created_ts']))) . '</span>';
        $rivals = $p['status'] === 'open' ? Proposals::rivals($p) : 0;
        if ($rivals > 0) {
            /* translators: %d: number of other suggestions */
            $h .= '<span class="sdict-prop__rivals">' . esc_html(sprintf(_n('Competing with %d other suggestion', 'Competing with %d other suggestions', $rivals, 'sepo-dictionary'), $rivals)) . '</span>';
        }
        if ($p['status'] === 'accepted' && $p['history_id'] > 0 && $p['entry_id'] > 0) {
            $h .= '<a href="' . esc_url(add_query_arg('sd_tab', 'history', Entry::url((int) $p['entry_id'])) . '#change-' . (int) $p['history_id']) . '">' . esc_html__('See the merged change', 'sepo-dictionary') . '</a>';
        }
        $h .= '</div>';

        $actions = '';
        if ($own && $live) {
            $actions .= '<button type="button" class="sdict-btn sdict-btn--quiet sdict-btn--sm" data-sdict-act="withdraw" data-id="' . (int) $p['id'] . '">' . esc_html__('Withdraw', 'sepo-dictionary') . '</button>';
        }
        if ($own && in_array($p['status'], ['expired', 'withdrawn', 'superseded'], true)) {
            $label = $p['status'] === 'superseded' && $p['kind'] === 'new' ? __('Suggest as an extra meaning', 'sepo-dictionary') : __('Put forward again', 'sepo-dictionary');
            $actions .= '<button type="button" class="sdict-btn sdict-btn--sm" data-sdict-act="resubmit" data-id="' . (int) $p['id'] . '">' . Icons::get('undo', 14) . esc_html($label) . '</button>';
        }
        if (Plugin::canManage((int) $p['dict_id'])) {
            if ($live) {
                $actions .= '<button type="button" class="sdict-btn sdict-btn--good sdict-btn--sm" data-sdict-act="accept" data-id="' . (int) $p['id'] . '">' . Icons::get('merge', 14) . esc_html__('Accept and merge', 'sepo-dictionary') . '</button>';
                $actions .= '<button type="button" class="sdict-btn sdict-btn--sm" data-sdict-act="reject" data-id="' . (int) $p['id'] . '">' . esc_html__('Decline', 'sepo-dictionary') . '</button>';
            }
            $actions .= '<button type="button" class="sdict-iconbtn sdict-iconbtn--danger" data-sdict-act="delete" data-id="' . (int) $p['id'] . '" title="' . esc_attr__('Delete suggestion', 'sepo-dictionary') . '" aria-label="' . esc_attr__('Delete suggestion', 'sepo-dictionary') . '">' . Icons::get('trash', 15) . '</button>';
        }
        if ($actions !== '') {
            $h .= '<div class="sdict-prop__actions">' . $actions . '</div>';
        }
        return $h . '</footer></article>';
    }

    private static function pill(array $p): string
    {
        $s = (string) $p['status'];
        $text = Proposals::statusLabel($s);
        if ($s === 'open' && $p['closes_ts'] > 0) {
            /* translators: %s: "in 3 days" */
            $text = sprintf(__('Voting closes %s', 'sepo-dictionary'), Parts::ago((int) $p['closes_ts']));
        } elseif (!in_array($s, Proposals::LIVE, true) && $p['closed_ts'] > 0) {
            /* translators: 1: status, 2: date */
            $text = sprintf(__('%1$s %2$s', 'sepo-dictionary'), $text, Parts::date((int) $p['closed_ts']));
        }
        return '<span class="sdict-pill sdict-pill--' . esc_attr($s) . '">' . ($s === 'open' ? Icons::get('clock', 12) : '') . esc_html($text) . '</span>';
    }

    /** The two vote buttons with their counts, and the progress towards acceptance. */
    public static function voteBar(array $p, int $mine, bool $own): string
    {
        $open = $p['status'] === 'open';
        $can = $open && Plugin::canVote() && !$own;
        $h = '<div class="sdict-vote" data-sdict-vote="' . (int) $p['id'] . '">';
        foreach ([1 => ['check', __('Accurate', 'sepo-dictionary'), (int) $p['up']], -1 => ['x', __('Not accurate', 'sepo-dictionary'), (int) $p['down']]] as $v => [$icon, $label, $n]) {
            $cls = 'sdict-vote__btn sdict-vote__btn--' . ($v > 0 ? 'up' : 'down');
            $inner = Icons::get($icon, 14) . '<span class="sdict-vote__label">' . esc_html($label) . '</span><b class="sdict-vote__n" data-sdict-n="' . ($v > 0 ? 'up' : 'down') . '">' . $n . '</b>';
            if ($can) {
                $h .= '<button type="button" class="' . $cls . '" data-sdict-v="' . $v . '" aria-pressed="' . ($mine === $v ? 'true' : 'false') . '">' . $inner . '</button>';
            } elseif ($open && !is_user_logged_in()) {
                $h .= '<a class="' . $cls . '" href="' . esc_url(Parts::login()) . '" title="' . esc_attr__('Sign in to vote', 'sepo-dictionary') . '">' . $inner . '</a>';
            } else {
                $h .= '<span class="' . $cls . ' is-static"' . ($own && $open ? ' title="' . esc_attr__('You cannot vote on your own suggestion', 'sepo-dictionary') . '"' : '') . '>' . $inner . '</span>';
            }
        }
        if ($open) {
            $needs = Proposals::needs($p);
            $h .= '<div class="sdict-progress"><span class="sdict-meter" aria-hidden="true"><span class="sdict-meter__fill" data-sdict-meter style="width:' . (int) $needs['pct'] . '%"></span></span>'
                . '<span class="sdict-progress__text" data-sdict-needs>' . esc_html(self::needsText($p)) . '</span></div>';
        }
        return $h . '</div>';
    }

    /** What a suggestion still needs, in words. */
    public static function needsText(array $p): string
    {
        if ($p['status'] !== 'open') {
            return Proposals::statusLabel((string) $p['status']);
        }
        $n = Proposals::needs($p);
        if ($n['up'] > 0) {
            /* translators: %d: number of votes */
            return sprintf(_n('Needs %d more "accurate" vote', 'Needs %d more "accurate" votes', $n['up'], 'sepo-dictionary'), $n['up']);
        }
        if ($n['net'] > 0) {
            /* translators: %d: number of votes */
            return sprintf(_n('Needs to be %d vote further ahead', 'Needs to be %d votes further ahead', $n['net'], 'sepo-dictionary'), $n['net']);
        }
        return __('Has enough support; waiting to lead its rivals', 'sepo-dictionary');
    }

    /** What the suggestion changes. */
    public static function body(array $p): string
    {
        $d = (array) $p['data'];
        if ($p['kind'] === 'new') {
            return self::preview($d);
        }
        if ($p['kind'] === 'meaning') {
            if ($p['op'] === 'add') {
                $h = '<ol class="sdict-mini-senses sdict-mini-senses--add">';
                foreach ((array) ($d['definitions'] ?? []) as $def) {
                    $h .= '<li>' . Diff::defHtml($def, 'ins') . '</li>';
                }
                return $h . '</ol>';
            }
            $base = (array) (($p['base'] ?? [])['definition'] ?? []);
            $h = '';
            if ($p['op'] === 'replace') {
                $new = (array) ($d['definition'] ?? []);
                $h .= '<div class="sdict-compare"><div class="sdict-compare__row"><span class="sdict-label">' . esc_html__('Current', 'sepo-dictionary') . '</span><p>' . esc_html((string) ($base['text'] ?? '')) . '</p></div>';
                $h .= '<div class="sdict-compare__row"><span class="sdict-label">' . esc_html__('Suggested', 'sepo-dictionary') . '</span><p>' . Diff::words((string) ($base['text'] ?? ''), (string) ($new['text'] ?? '')) . '</p>';
                if (($new['example'] ?? '') !== '' || ($base['example'] ?? '') !== '') {
                    $h .= '<p class="sdict-diff-ex">' . Diff::words((string) ($base['example'] ?? ''), (string) ($new['example'] ?? '')) . '</p>';
                }
                $h .= '</div></div>';
            } else {
                $h .= '<div class="sdict-compare"><div class="sdict-compare__row"><span class="sdict-label">' . esc_html__('Remove', 'sepo-dictionary') . '</span><p>' . Diff::defHtml($base, 'del') . '</p></div></div>';
            }
            $state = Entry::get((int) $p['entry_id']);
            $cur = $state ? Proposals::findDef($state['definitions'], (string) $p['target']) : null;
            if (in_array($p['status'], Proposals::LIVE, true) && ($cur === null || trim($cur['text']) !== trim((string) ($base['text'] ?? '')))) {
                $h .= '<p class="sdict-stale">' . esc_html__('This meaning has changed since the suggestion was made.', 'sepo-dictionary') . '</p>';
            }
            return $h;
        }
        $base = (array) ($p['base'] ?? []);
        $rows = Diff::states($base, array_merge($base, $d), array_keys($d));
        $h = '<dl class="sdict-diff">';
        foreach ($rows as $r) {
            $h .= '<dt>' . esc_html($r['label']) . '</dt><dd>' . $r['html'] . '</dd>';
        }
        return $h . '</dl>';
    }

    /** A new word as it would read in the dictionary. */
    public static function preview(array $d): string
    {
        $h = '<div class="sdict-preview">';
        $line = [];
        if (($d['pronunciation'] ?? '') !== '') {
            $line[] = '<span class="sdict-pron">' . esc_html((string) $d['pronunciation']) . '</span>';
        }
        if (($d['pos'] ?? '') !== '') {
            $line[] = '<span class="sdict-pos">' . esc_html((string) $d['pos']) . '</span>';
        }
        if (($d['category'] ?? '') !== '') {
            $line[] = '<span class="sdict-chip">' . esc_html((string) $d['category']) . '</span>';
        }
        if ($line !== []) {
            $h .= '<div class="sdict-preview__line">' . implode('', $line) . '</div>';
        }
        $h .= '<ol class="sdict-mini-senses">';
        foreach ((array) ($d['definitions'] ?? []) as $def) {
            $h .= '<li><span>' . esc_html((string) $def['text']) . '</span>' . (($def['example'] ?? '') !== '' ? '<span class="sdict-ex">' . esc_html((string) $def['example']) . '</span>' : '') . '</li>';
        }
        $h .= '</ol>';
        foreach (['synonyms' => __('Synonyms', 'sepo-dictionary'), 'antonyms' => __('Antonyms', 'sepo-dictionary'), 'related' => __('See also', 'sepo-dictionary')] as $k => $label) {
            if (!empty($d[$k])) {
                $h .= '<p class="sdict-preview__rel"><span class="sdict-label">' . esc_html($label) . '</span>' . esc_html(implode(', ', (array) $d[$k])) . '</p>';
            }
        }
        if (($d['etymology'] ?? '') !== '') {
            $h .= '<p class="sdict-preview__rel"><span class="sdict-label">' . esc_html__('Origin', 'sepo-dictionary') . '</span>' . esc_html((string) $d['etymology']) . '</p>';
        }
        return $h . '</div>';
    }

    /** The voting rules in one sentence, shown above the review queue. */
    public static function rules(int $dict = 0): string
    {
        $needed = Dictionaries::int($dict, 'votes_needed', 1);
        $margin = Dictionaries::int($dict, 'vote_margin', 0);
        $reject = Dictionaries::int($dict, 'reject_at', 1);
        $days = Dictionaries::int($dict, 'voting_days');
        /* translators: 1: votes needed, 2: lead needed */
        $s = sprintf(__('A suggestion is accepted when at least %1$d members vote that it is accurate and it is %2$d votes ahead.', 'sepo-dictionary'), $needed, $margin);
        /* translators: %d: votes */
        $s .= ' ' . sprintf(__('It is declined when it falls %d votes behind.', 'sepo-dictionary'), $reject);
        if ($days > 0) {
            $s .= ' ' . (Dictionaries::on($dict, 'settle_on_close')
                /* translators: %d: days */
                ? sprintf(__('Voting stays open for %d days; then the suggestion that leads, if more members found it accurate than not, is accepted.', 'sepo-dictionary'), $days)
                /* translators: %d: days */
                : sprintf(__('Voting stays open for %d days.', 'sepo-dictionary'), $days));
        }
        if (Dictionaries::on($dict, 'review')) {
            $s .= ' ' . __('An editor checks each accepted suggestion before it is merged.', 'sepo-dictionary');
        }
        return $s;
    }
}
