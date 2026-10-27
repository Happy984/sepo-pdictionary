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

    /** Where editors are told about a suggestion: the dictionary's or the site's address, or the site admin. */
    private static function editors(array $p): string
    {
        $to = trim((string) Dictionaries::setting((int) ($p['dict_id'] ?? 0), 'notify_email'));
        return $to !== '' ? $to : (string) get_option('admin_email');
    }

    /**
     * The dictionary's own editors, by email, leaving out an address that is
     * already told.
     *
     * @return list<string>
     */
    private static function dictionaryEditors(array $p, string $skip): array
    {
        $out = [];
        foreach (Dictionaries::editors((int) ($p['dict_id'] ?? 0)) as $uid) {
            $u = get_userdata($uid);
            if ($u && is_email($u->user_email) && strcasecmp($u->user_email, $skip) !== 0 && (int) $u->ID !== (int) $p['user_id']) {
                $out[] = (string) $u->user_email;
            }
        }
        return array_values(array_unique($out));
    }

    private static function link(array $p): string
    {
        if ($p['entry_id'] > 0 && get_post_status((int) $p['entry_id'])) {
            return add_query_arg('sd_tab', 'proposals', Entry::url((int) $p['entry_id']));
        }
        return Dictionaries::homeUrl((int) ($p['dict_id'] ?? 0), ['sd_view' => 'review']);
    }

    /** "Bemba" when the site has several dictionaries, so editors know which one. */
    private static function where(array $p): string
    {
        $name = Dictionaries::many() ? Dictionaries::name((int) ($p['dict_id'] ?? 0)) : '';
        /* translators: %s: name of a dictionary */
        return $name !== '' ? ' ' . sprintf(__('(%s)', 'sepo-dictionary'), $name) : '';
    }

    public static function proposed(array $p): void
    {
        if (!Dictionaries::on((int) ($p['dict_id'] ?? 0), 'notify_new')) {
            return;
        }
        /* translators: 1: kind of suggestion, 2: the word */
        $subject = sprintf(__('New suggestion: %1$s for "%2$s"', 'sepo-dictionary'), Proposals::kindLabel($p), $p['term']) . self::where($p);
        $body = sprintf(
            /* translators: 1: member's name, 2: kind of suggestion, 3: the word, 4: link */
            __("%1\$s suggested a %2\$s for \"%3\$s\". It is open for votes:\n\n%4\$s", 'sepo-dictionary'),
            $p['author'],
            mb_strtolower(Proposals::kindLabel($p)),
            $p['term'],
            self::link($p)
        );
        $to = self::editors($p);
        self::send($to, $subject, $body);
        foreach (self::dictionaryEditors($p, $to) as $e) {
            self::send($e, $subject, $body);
        }
    }

    public static function passed(array $p, bool $conflict): void
    {
        /* translators: %s: the word */
        $subject = sprintf(__('A suggestion for "%s" is waiting for you', 'sepo-dictionary'), $p['term']) . self::where($p);
        $admin = admin_url('edit.php?post_type=' . PostType::TYPE . '&page=sepo-dictionary-proposals&status=passed&dict=' . (int) ($p['dict_id'] ?? 0));
        $site = Dictionaries::homeUrl((int) ($p['dict_id'] ?? 0), ['sd_view' => 'review', 'sd_status' => 'passed']);
        $make = static fn(string $link): string => $conflict
            /* translators: 1: kind of suggestion, 2: the word, 3: what changed, 4: link */
            ? sprintf(__("A %1\$s for \"%2\$s\" won the vote, but the entry changed after it was made: %3\$s\n\nPlease review and merge it:\n\n%4\$s", 'sepo-dictionary'), mb_strtolower(Proposals::kindLabel($p)), $p['term'], $p['close_note'], $link)
            /* translators: 1: kind of suggestion, 2: the word, 3: votes for, 4: votes against, 5: link */
            : sprintf(__("A %1\$s for \"%2\$s\" passed the community vote (%3\$d for, %4\$d against). Please review and merge it:\n\n%5\$s", 'sepo-dictionary'), mb_strtolower(Proposals::kindLabel($p)), $p['term'], $p['up'], $p['down'], $link);
        $to = self::editors($p);
        self::send($to, $subject, $make($admin));
        // A dictionary's own editors work on the site, so their link opens its review page.
        foreach (self::dictionaryEditors($p, $to) as $e) {
            self::send($e, $subject, $make($site));
        }
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
