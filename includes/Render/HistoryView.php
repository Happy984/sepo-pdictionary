<?php

declare(strict_types=1);

namespace SepoDictionary\Render;

use SepoDictionary\Diff;
use SepoDictionary\Entry;
use SepoDictionary\History;
use SepoDictionary\Proposals;

defined('ABSPATH') || exit;

/** An entry's change history as a timeline, newest first. */
final class HistoryView
{
    public static function render(int $entryId, bool $manager): string
    {
        $rows = History::forEntry($entryId, 100);
        if ($rows === []) {
            return '<div class="sdict-empty sdict-empty--sm"><p>' . esc_html__('No changes have been recorded for this word yet.', 'sepo-dictionary') . '</p></div>';
        }
        $h = '<ol class="sdict-hist">';
        foreach ($rows as $i => $r) {
            $h .= self::item($r, $i, $manager && $i > 0);
        }
        return $h . '</ol>';
    }

    private static function name(int $uid): string
    {
        if ($uid < 1) {
            return '';
        }
        $u = get_userdata($uid);
        return $u ? (string) $u->display_name : __('a former member', 'sepo-dictionary');
    }

    private static function item(array $r, int $i, bool $canRestore): string
    {
        $action = (string) $r['action'];
        $author = self::name((int) $r['user_id']);
        $actor = self::name((int) $r['actor_id']);

        $who = '';
        if ($r['proposal_id'] > 0) {
            $how = $r['actor_id'] > 0
                /* translators: %s: editor's name */
                ? sprintf(__('accepted by %s', 'sepo-dictionary'), $actor)
                /* translators: 1: votes for, 2: votes against */
                : sprintf(__('accepted by community vote, %1$d for and %2$d against', 'sepo-dictionary'), $r['votes_up'], $r['votes_down']);
            /* translators: 1: member's name, 2: how it was accepted */
            $who = sprintf(__('Suggested by %1$s, %2$s', 'sepo-dictionary'), $author !== '' ? $author : __('a member', 'sepo-dictionary'), $how);
        } elseif ($author !== '') {
            /* translators: %s: name */
            $who = sprintf(__('By %s', 'sepo-dictionary'), $author);
        }

        $h = '<li class="sdict-hist__item sdict-hist__item--' . esc_attr($action) . '" id="change-' . (int) $r['id'] . '">';
        $h .= '<span class="sdict-hist__dot" aria-hidden="true">' . Icons::get(match ($action) {
            'merged'   => 'merge',
            'created'  => 'plus',
            'restored' => 'undo',
            'trashed', 'untrashed' => 'trash',
            default    => 'edit',
        }, 13) . '</span>';
        $h .= '<div class="sdict-hist__main"><div class="sdict-hist__head">';
        $h .= '<strong>' . esc_html(History::actionLabel($action)) . '</strong>';
        $h .= '<time datetime="' . esc_attr(gmdate('c', (int) $r['ts'])) . '">' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $r['ts'])) . '</time>';
        if ($i === 0) {
            $h .= '<span class="sdict-badge sdict-badge--current">' . esc_html__('Current', 'sepo-dictionary') . '</span>';
        }
        $h .= '</div>';
        if ($who !== '') {
            $h .= '<p class="sdict-hist__who">' . esc_html($who);
            if ($r['proposal_id'] > 0 && Proposals::get((int) $r['proposal_id']) !== null) {
                $h .= ' <a href="#proposal-' . (int) $r['proposal_id'] . '" data-sdict-tab-link="proposals">' . esc_html__('View the suggestion', 'sepo-dictionary') . '</a>';
            }
            $h .= '</p>';
        }
        if ($r['summary'] !== '' && $r['proposal_id'] < 1) {
            $h .= '<p class="sdict-hist__summary">' . esc_html((string) $r['summary']) . '</p>';
        }

        $prev = $r['prev_state'];
        $next = (array) $r['new_state'];
        $rows = $prev === null
            ? array_values(array_filter(Diff::states(null, $next, ['term', 'pronunciation', 'pos', 'language', 'category', 'definitions']), static fn(array $x): bool => $x['html'] !== ''))
            : Diff::states($prev, $next);
        if ($rows !== []) {
            /* translators: %d: number of changed fields */
            $h .= '<details class="sdict-hist__diff"' . ($i < 3 ? ' open' : '') . '><summary>' . esc_html(sprintf(_n('%d change', '%d changes', count($rows), 'sepo-dictionary'), count($rows))) . '</summary><dl class="sdict-diff">';
            foreach ($rows as $row) {
                $h .= '<dt>' . esc_html($row['label']) . '</dt><dd>' . $row['html'] . '</dd>';
            }
            $h .= '</dl></details>';
        }
        if ($canRestore && $next !== [] && !in_array($action, ['trashed'], true)) {
            $h .= '<button type="button" class="sdict-btn sdict-btn--sm sdict-btn--quiet" data-sdict-act="restore" data-id="' . (int) $r['id'] . '">' . Icons::get('undo', 14) . esc_html__('Restore this version', 'sepo-dictionary') . '</button>';
        }
        return $h . '</div></li>';
    }
}
