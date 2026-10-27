<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/**
 * Every change to an entry, with the state before and after, who made it,
 * and the proposal and votes behind it when it came from the community.
 */
final class History
{
    public const ACTIONS = ['created', 'edited', 'merged', 'restored', 'trashed', 'untrashed'];

    /** @param array<string,mixed> $ctx */
    public static function record(int $entryId, string $action, ?array $before, array $after, array $ctx = []): int
    {
        global $wpdb;
        $wpdb->insert(Schema::history(), [
            'entry_id'    => $entryId,
            'action'      => in_array($action, self::ACTIONS, true) ? $action : 'edited',
            'proposal_id' => (int) ($ctx['proposal_id'] ?? 0),
            'user_id'     => (int) ($ctx['user_id'] ?? get_current_user_id()),
            'actor_id'    => (int) ($ctx['actor_id'] ?? get_current_user_id()),
            'summary'     => mb_substr(sanitize_text_field((string) ($ctx['summary'] ?? '')), 0, 255),
            'prev_state'  => $before === null ? null : Schema::encode($before),
            'new_state'   => Schema::encode($after),
            'votes_up'    => (int) ($ctx['votes_up'] ?? 0),
            'votes_down'  => (int) ($ctx['votes_down'] ?? 0),
            'created'     => Schema::now(),
        ]);
        $id = (int) $wpdb->insert_id;
        do_action('sepo_dictionary_history_recorded', $id, $entryId, $action);
        return $id;
    }

    /** @return array<string,mixed>|null */
    private static function row(?array $r): ?array
    {
        if ($r === null) {
            return null;
        }
        $r['id'] = (int) $r['id'];
        $r['entry_id'] = (int) $r['entry_id'];
        $r['proposal_id'] = (int) $r['proposal_id'];
        $r['user_id'] = (int) $r['user_id'];
        $r['actor_id'] = (int) $r['actor_id'];
        $r['votes_up'] = (int) $r['votes_up'];
        $r['votes_down'] = (int) $r['votes_down'];
        $r['prev_state'] = Schema::decode($r['prev_state']);
        $r['new_state'] = Schema::decode($r['new_state']) ?? [];
        $r['ts'] = Schema::ts($r['created']);
        return $r;
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::history() . ' WHERE id = %d', $id), ARRAY_A);
        return self::row(is_array($r) ? $r : null);
    }

    /** @return list<array<string,mixed>> Newest first. */
    public static function forEntry(int $entryId, int $limit = 100): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::history() . ' WHERE entry_id = %d ORDER BY id DESC LIMIT %d', $entryId, $limit), ARRAY_A);
        return array_values(array_filter(array_map([self::class, 'row'], $rows ?: [])));
    }

    public static function count(int $entryId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::history() . ' WHERE entry_id = %d', $entryId));
    }

    /** The entry as the last recorded change left it. @return array<string,mixed>|null */
    public static function lastState(int $entryId): ?array
    {
        global $wpdb;
        $json = $wpdb->get_var($wpdb->prepare('SELECT new_state FROM ' . Schema::history() . ' WHERE entry_id = %d ORDER BY id DESC LIMIT 1', $entryId));
        return Schema::decode(is_string($json) ? $json : null);
    }

    /**
     * People who shaped an entry: whoever made a change, newest first.
     *
     * @return list<int>
     */
    public static function contributors(int $entryId): array
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT user_id FROM ' . Schema::history() . ' WHERE entry_id = %d AND user_id > 0 GROUP BY user_id ORDER BY MAX(id) DESC', $entryId));
        return array_map('intval', $ids);
    }

    /**
     * Put an entry back the way one history row left it. The restore is
     * itself a new history row, so nothing is lost.
     */
    public static function restore(int $historyId, int $userId): int
    {
        $row = self::get($historyId);
        if ($row === null || $row['new_state'] === []) {
            throw new \InvalidArgumentException(__('That version could not be found.', 'sepo-dictionary'));
        }
        $entryId = $row['entry_id'];
        $current = Entry::get($entryId);
        if ($current === null || $current['status'] === 'trash') {
            throw new \InvalidArgumentException(__('That entry no longer exists.', 'sepo-dictionary'));
        }
        $state = $row['new_state'];
        if (in_array($state['status'] ?? '', ['trash', ''], true)) {
            $state['status'] = $current['status'];
        }
        // A restore brings back the wording; the entry stays in the dictionary it is in now.
        $state['dictionary'] = $current['dictionary'];
        if (Entry::same($current, Entry::clean($state, $current))) {
            throw new \InvalidArgumentException(__('The entry already matches that version.', 'sepo-dictionary'));
        }
        return Entry::store($entryId, $state, [
            'action'   => 'restored',
            'user_id'  => $userId,
            'actor_id' => $userId,
            /* translators: %s: date and time of the version */
            'summary'  => sprintf(__('Restored the version from %s', 'sepo-dictionary'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), $row['ts'])),
        ]);
    }

    public static function purgeEntry(int $entryId): void
    {
        global $wpdb;
        $wpdb->delete(Schema::history(), ['entry_id' => $entryId]);
    }

    public static function actionLabel(string $a): string
    {
        return match ($a) {
            'created'   => __('Entry created', 'sepo-dictionary'),
            'merged'    => __('Proposal merged', 'sepo-dictionary'),
            'restored'  => __('Earlier version restored', 'sepo-dictionary'),
            'trashed'   => __('Moved to the trash', 'sepo-dictionary'),
            'untrashed' => __('Taken out of the trash', 'sepo-dictionary'),
            default     => __('Edited', 'sepo-dictionary'),
        };
    }
}
