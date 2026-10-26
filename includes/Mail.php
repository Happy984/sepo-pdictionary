<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/** Emails about suggestions: to their authors when settled, and to editors when one needs them. */
final class Mail
{
    private static function send(string $to, string $subject, string $body): void
    {
        if (!is_email($to)) {
            return;
        }
        $site = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
        wp_mail($to, '[' . $site . '] ' . $subject, $body);
    }

    /** Where editors are told about suggestions: the setting, or the site admin address. */
    private static function editors(): string
    {
        $to = trim((string) Settings::get('notify_email'));
        return $to !== '' ? $to : (string) get_option('admin_email');
    }

    private static function link(array $p): string
    {
        if ($p['entry_id'] > 0 && get_post_status((int) $p['entry_id'])) {
            return add_query_arg('sd_tab', 'proposals', Entry::url((int) $p['entry_id']));
        }
        return Settings::indexUrl(['sd_view' => 'review']);
    }

    public static function proposed(array $p): void
    {
        if ((int) Settings::get('notify_new') !== 1) {
            return;
        }
        /* translators: 1: kind of suggestion, 2: the word */
        $subject = sprintf(__('New suggestion: %1$s for "%2$s"', 'sepo-dictionary'), Proposals::kindLabel($p), $p['term']);
        $body = sprintf(
            /* translators: 1: member's name, 2: kind of suggestion, 3: the word, 4: link */
            __("%1\$s suggested a %2\$s for \"%3\$s\". It is open for votes:\n\n%4\$s", 'sepo-dictionary'),
            $p['author'],
            mb_strtolower(Proposals::kindLabel($p)),
            $p['term'],
            self::link($p)
        );
        self::send(self::editors(), $subject, $body);
    }

    public static function passed(array $p, bool $conflict): void
    {
        /* translators: %s: the word */
        $subject = sprintf(__('A suggestion for "%s" is waiting for you', 'sepo-dictionary'), $p['term']);
        $body = $conflict
            /* translators: 1: kind of suggestion, 2: the word, 3: what changed, 4: link */
            ? sprintf(__("A %1\$s for \"%2\$s\" won the vote, but the entry changed after it was made: %3\$s\n\nPlease review and merge it:\n\n%4\$s", 'sepo-dictionary'), mb_strtolower(Proposals::kindLabel($p)), $p['term'], $p['close_note'], admin_url('edit.php?post_type=' . PostType::TYPE . '&page=sepo-dictionary-proposals&status=passed'))
            /* translators: 1: kind of suggestion, 2: the word, 3: votes for, 4: votes against, 5: link */
            : sprintf(__("A %1\$s for \"%2\$s\" passed the community vote (%3\$d for, %4\$d against). Please review and merge it:\n\n%5\$s", 'sepo-dictionary'), mb_strtolower(Proposals::kindLabel($p)), $p['term'], $p['up'], $p['down'], admin_url('edit.php?post_type=' . PostType::TYPE . '&page=sepo-dictionary-proposals&status=passed'));
        self::send(self::editors(), $subject, $body);
    }

    /** Tell the author how their suggestion was settled. */
    public static function settled(array $p): void
    {
        if ((int) Settings::get('notify_authors') !== 1 || $p['user_id'] < 1) {
            return;
        }
        $u = get_userdata((int) $p['user_id']);
        if (!$u) {
            return;
        }
        $kind = mb_strtolower(Proposals::kindLabel($p));
        [$subject, $line] = match ((string) $p['status']) {
            /* translators: %s: the word */
            'accepted'   => [sprintf(__('Your suggestion for "%s" was accepted', 'sepo-dictionary'), $p['term']), __('Thank you. It is now part of the dictionary, and the change is recorded in the entry history.', 'sepo-dictionary')],
            /* translators: %s: the word */
            'rejected'   => [sprintf(__('Your suggestion for "%s" was not accepted', 'sepo-dictionary'), $p['term']), (string) $p['close_note']],
            /* translators: %s: the word */
            'superseded' => [sprintf(__('Another suggestion for "%s" was accepted', 'sepo-dictionary'), $p['term']), __('A different suggestion for the same thing won the vote. You can put yours forward again as an extra meaning from your suggestions list.', 'sepo-dictionary')],
            /* translators: %s: the word */
            'expired'    => [sprintf(__('Voting on your suggestion for "%s" has closed', 'sepo-dictionary'), $p['term']), __('It did not gather enough support in time. You can put it forward again from your suggestions list.', 'sepo-dictionary')],
            default      => ['', ''],
        };
        if ($subject === '') {
            return;
        }
        /* translators: 1: kind of suggestion, 2: votes for, 3: votes against */
        $tally = sprintf(__('Your %1$s received %2$d votes for and %3$d against.', 'sepo-dictionary'), $kind, $p['up'], $p['down']);
        self::send((string) $u->user_email, $subject, $line . "\n\n" . $tally . "\n\n" . self::link($p));
    }
}
