<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/**
 * WordPress's personal data tools: export a member's suggestions and votes,
 * and erase them on request. Erasing keeps the dictionary's content and
 * history intact but removes the link to the person.
 */
final class Privacy
{
    public static function hook(): void
    {
        add_filter('wp_privacy_personal_data_exporters', static function (array $e): array {
            $e['sepo-dictionary'] = ['exporter_friendly_name' => __('Dictionary suggestions and votes', 'sepo-dictionary'), 'callback' => [self::class, 'export']];
            return $e;
        });
        add_filter('wp_privacy_personal_data_erasers', static function (array $e): array {
            $e['sepo-dictionary'] = ['eraser_friendly_name' => __('Dictionary suggestions and votes', 'sepo-dictionary'), 'callback' => [self::class, 'erase']];
            return $e;
        });
        add_action('admin_init', static function (): void {
            if (function_exists('wp_add_privacy_policy_content')) {
                wp_add_privacy_policy_content(__('Sepo Dictionary', 'sepo-dictionary'), wp_kses_post(wpautop(
                    __('When you suggest a word, a meaning or a correction in the dictionary, we keep your suggestion with your account name so other members can vote on it. When you vote, we keep your vote and any note you add. Accepted suggestions become part of the dictionary, and the entry history shows who suggested each change. You can ask for your suggestions and votes to be exported or erased; erasing removes your name from them but keeps the dictionary text.', 'sepo-dictionary')
                )));
            }
        });
    }

    /** @return array{data:list<array<string,mixed>>,done:bool} */
    public static function export(string $email, int $page = 1): array
    {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['data' => [], 'done' => true];
        }
        $data = [];
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::proposals() . ' WHERE user_id = %d ORDER BY id ASC', $user->ID), ARRAY_A);
        foreach ($rows ?: [] as $r) {
            $p = Proposals::get((int) $r['id']);
            if ($p === null) {
                continue;
            }
            $data[] = [
                'group_id'    => 'sepo-dictionary-proposals',
                'group_label' => __('Dictionary suggestions', 'sepo-dictionary'),
                'item_id'     => 'sdict-proposal-' . $p['id'],
                'data'        => [
                    ['name' => __('Word', 'sepo-dictionary'), 'value' => (string) $p['term']],
                    ['name' => __('Kind', 'sepo-dictionary'), 'value' => Proposals::kindLabel($p)],
                    ['name' => __('Status', 'sepo-dictionary'), 'value' => Proposals::statusLabel((string) $p['status'])],
                    ['name' => __('Suggested', 'sepo-dictionary'), 'value' => (string) $p['created']],
                    ['name' => __('Reason', 'sepo-dictionary'), 'value' => (string) $p['reason']],
                    ['name' => __('Content', 'sepo-dictionary'), 'value' => wp_json_encode($p['data'], JSON_UNESCAPED_UNICODE)],
                ],
            ];
        }
        $votes = $wpdb->get_results($wpdb->prepare('SELECT v.*, p.term FROM ' . Schema::votes() . ' v LEFT JOIN ' . Schema::proposals() . ' p ON p.id = v.proposal_id WHERE v.user_id = %d', $user->ID), ARRAY_A);
        foreach ($votes ?: [] as $v) {
            $data[] = [
                'group_id'    => 'sepo-dictionary-votes',
                'group_label' => __('Dictionary votes', 'sepo-dictionary'),
                'item_id'     => 'sdict-vote-' . $v['proposal_id'],
                'data'        => [
                    ['name' => __('Word', 'sepo-dictionary'), 'value' => (string) ($v['term'] ?? '')],
                    ['name' => __('Vote', 'sepo-dictionary'), 'value' => (int) $v['value'] > 0 ? __('Accurate', 'sepo-dictionary') : __('Not accurate', 'sepo-dictionary')],
                    ['name' => __('Note', 'sepo-dictionary'), 'value' => (string) $v['note']],
                    ['name' => __('Date', 'sepo-dictionary'), 'value' => (string) $v['created']],
                ],
            ];
        }
        return ['data' => $data, 'done' => true];
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    public static function erase(string $email, int $page = 1): array
    {
        global $wpdb;
        $user = get_user_by('email', $email);
        if (!$user) {
            return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
        }
        $uid = (int) $user->ID;
        $open = $wpdb->get_col($wpdb->prepare('SELECT proposal_id FROM ' . Schema::votes() . ' WHERE user_id = %d', $uid));
        $votes = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . Schema::votes() . ' WHERE user_id = %d', $uid));
        foreach ($open as $pid) {
            $p = Proposals::get((int) $pid);
            // Closed proposals keep the counts they were settled with.
            if ($p !== null && $p['status'] === 'open') {
                Proposals::tally((int) $pid);
            }
        }
        $props = (int) $wpdb->query($wpdb->prepare('UPDATE ' . Schema::proposals() . " SET user_id = 0, author = '' WHERE user_id = %d", $uid));
        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::history() . ' SET user_id = 0 WHERE user_id = %d', $uid));
        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::history() . ' SET actor_id = 0 WHERE actor_id = %d', $uid));
        delete_user_meta($uid, 'sd_accepted');
        return [
            'items_removed'  => ($votes + $props) > 0,
            'items_retained' => $props > 0,
            'messages'       => $props > 0 ? [__('Dictionary suggestions were kept without the member\'s name, because accepted ones are part of the dictionary.', 'sepo-dictionary')] : [],
            'done'           => true,
        ];
    }
}
