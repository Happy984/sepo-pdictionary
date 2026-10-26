<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/**
 * Suggestions from the community and the votes on them.
 *
 * Kinds:
 *   new      a word that is not in the dictionary yet
 *   meaning  add a meaning, replace one meaning with a better one, or remove one
 *   edit     change the other details: spelling, pronunciation, synonyms and so on
 *
 * Proposals that compete for the same thing share a group: new words with
 * the same spelling and language, or replacements and removals of the same
 * meaning. Only the leader of a group can win; when it is merged the others
 * in the group are superseded. Added meanings and edits never block each
 * other: edits are merged field by field, and a field that changed since the
 * proposal was made goes to an editor instead of being overwritten.
 *
 * Statuses: open (voting), passed (won the vote, waiting for an editor),
 * accepted, rejected, withdrawn, expired, superseded.
 */
final class Proposals
{
    public const KINDS = ['new', 'meaning', 'edit'];
    public const OPS = ['add', 'replace', 'remove'];
    public const LIVE = ['open', 'passed'];
    public const STATUSES = ['open', 'passed', 'accepted', 'rejected', 'withdrawn', 'expired', 'superseded'];
    public const EDIT_KEYS = ['term', 'language', 'category', 'tags', 'pronunciation', 'pos', 'synonyms', 'antonyms', 'related', 'etymology', 'notes', 'source'];

    // ------------------------------------------------------------- creating

    /**
     * Record a proposal from $in and open it for votes.
     *
     * @param array<string,mixed> $in kind, op, entry_id, target, entry{...}, definitions[], definition{}, reason
     * @return array<string,mixed>
     */
    public static function create(array $in, int $userId): array
    {
        global $wpdb;
        $kind = (string) ($in['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) {
            throw new ProposalError(__('Choose what you are suggesting.', 'sepo-dictionary'));
        }
        $manager = user_can($userId, (string) apply_filters('sepo_dictionary_manage_capability', 'edit_others_posts'));
        $max = Settings::int('max_open');
        if (!$manager && $max > 0 && self::liveCountForUser($userId) >= $max) {
            /* translators: %d: how many open proposals a member may have */
            throw new ProposalError(sprintf(__('You already have %d suggestions waiting for votes. Please wait for some of them to be settled.', 'sepo-dictionary'), $max));
        }

        $reason = Entry::text($in['reason'] ?? '', 1000);
        $row = ['kind' => $kind, 'op' => '', 'target' => '', 'entry_id' => 0, 'term' => '', 'language' => '', 'data' => [], 'base' => null];

        if ($kind === 'new') {
            $data = Entry::clean(is_array($in['entry'] ?? null) ? $in['entry'] : []);
            unset($data['status']);
            // Recordings are added by editors, not through suggestions.
            $data['audio'] = 0;
            $data = self::limitTaxonomies($data, $manager);
            if ($data['term'] === '') {
                throw new ProposalError(__('Enter the word.', 'sepo-dictionary'));
            }
            if ($data['definitions'] === []) {
                throw new ProposalError(__('Add at least one meaning.', 'sepo-dictionary'));
            }
            $existing = Entry::find($data['term'], $data['language']);
            if ($existing > 0 && get_post_status($existing) === 'publish') {
                throw new ProposalError(__('That word is already in the dictionary. Open it to suggest a new meaning or a correction.', 'sepo-dictionary'), ['entry_url' => Entry::url($existing)]);
            }
            $row['term'] = $data['term'];
            $row['language'] = $data['language'];
            $row['data'] = $data;
            $row['group_key'] = 'new:' . md5(Entry::fold($data['term']) . '|' . Entry::fold($data['language']));
        } else {
            $entryId = (int) ($in['entry_id'] ?? 0);
            $current = $entryId > 0 ? Entry::get($entryId) : null;
            if ($current === null || ($current['status'] !== 'publish' && !$manager)) {
                throw new ProposalError(__('That entry could not be found.', 'sepo-dictionary'));
            }
            $row['entry_id'] = $entryId;
            $row['term'] = $current['term'];
            $row['language'] = $current['language'];

            if ($kind === 'meaning') {
                $op = (string) ($in['op'] ?? 'add');
                if (!in_array($op, self::OPS, true)) {
                    throw new ProposalError(__('Choose what to do with the meaning.', 'sepo-dictionary'));
                }
                $row['op'] = $op;
                if ($op === 'add') {
                    $defs = Entry::defs($in['definitions'] ?? []);
                    $known = array_map(static fn(array $d): string => Entry::fold($d['text']), $current['definitions']);
                    $defs = array_values(array_filter($defs, static fn(array $d): bool => !in_array(Entry::fold($d['text']), $known, true)));
                    if ($defs === []) {
                        throw new ProposalError(__('Write the meaning you want to add. It must differ from the meanings already listed.', 'sepo-dictionary'));
                    }
                    $row['data'] = ['definitions' => array_slice($defs, 0, 5)];
                    $row['group_key'] = 'add:' . $entryId . ':' . wp_generate_uuid4();
                } else {
                    $target = (string) ($in['target'] ?? '');
                    $def = self::findDef($current['definitions'], $target);
                    if ($def === null) {
                        throw new ProposalError(__('That meaning is no longer in the entry. Reload the page and try again.', 'sepo-dictionary'));
                    }
                    $row['target'] = $target;
                    $row['base'] = ['definition' => $def];
                    $row['group_key'] = 'def:' . $entryId . ':' . $target;
                    if ($op === 'replace') {
                        $new = Entry::defs([is_array($in['definition'] ?? null) ? $in['definition'] : []])[0] ?? null;
                        if ($new === null) {
                            throw new ProposalError(__('Write the corrected meaning.', 'sepo-dictionary'));
                        }
                        if (trim($new['text']) === trim($def['text']) && trim($new['example']) === trim($def['example'])) {
                            throw new ProposalError(__('The corrected meaning is the same as the current one.', 'sepo-dictionary'));
                        }
                        $new['id'] = $target;
                        $row['data'] = ['definition' => $new];
                    } else {
                        if ($reason === '') {
                            throw new ProposalError(__('Say why this meaning is wrong, so voters can judge.', 'sepo-dictionary'));
                        }
                        if (count($current['definitions']) < 2) {
                            throw new ProposalError(__('This is the only meaning of the word. Suggest a better meaning instead of removing it.', 'sepo-dictionary'));
                        }
                    }
                }
            } else {
                $proposed = self::limitTaxonomies(Entry::clean(is_array($in['entry'] ?? null) ? $in['entry'] : [], $current), $manager);
                $data = [];
                $base = [];
                foreach (self::EDIT_KEYS as $k) {
                    if (Entry::comparable($proposed[$k]) !== Entry::comparable($current[$k])) {
                        $data[$k] = $proposed[$k];
                        $base[$k] = $current[$k];
                    }
                }
                if ($data === []) {
                    throw new ProposalError(__('Nothing was changed.', 'sepo-dictionary'));
                }
                if (isset($data['term']) && $data['term'] === '') {
                    throw new ProposalError(__('The word cannot be empty.', 'sepo-dictionary'));
                }
                $row['data'] = $data;
                $row['base'] = $base;
                $row['group_key'] = 'edit:' . $entryId;
            }
        }

        if (str_starts_with($row['group_key'], 'add:') === false) {
            $mine = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::proposals() . " WHERE group_key = %s AND user_id = %d AND status IN ('open','passed')", $row['group_key'], $userId));
            if ($mine > 0) {
                throw new ProposalError(__('You already have a suggestion for this waiting for votes. Withdraw it first to suggest something else.', 'sepo-dictionary'));
            }
        }

        $user = get_userdata($userId);
        $days = Settings::int('voting_days');
        $now = Schema::now();
        $wpdb->insert(Schema::proposals(), [
            'entry_id'  => $row['entry_id'],
            'kind'      => $row['kind'],
            'op'        => $row['op'],
            'target'    => $row['target'],
            'group_key' => $row['group_key'],
            'term'      => mb_substr($row['term'], 0, 191),
            'language'  => mb_substr($row['language'], 0, 100),
            'data'      => Schema::encode($row['data']),
            'base'      => $row['base'] === null ? null : Schema::encode($row['base']),
            'reason'    => $reason,
            'user_id'   => $userId,
            'author'    => $user ? mb_substr((string) $user->display_name, 0, 120) : '',
            'status'    => 'open',
            'created'   => $now,
            'updated'   => $now,
            'closes'    => $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS) : null,
        ]);
        $id = (int) $wpdb->insert_id;
        if ($id < 1) {
            throw new \RuntimeException(__('Your suggestion could not be saved. Please try again.', 'sepo-dictionary'));
        }
        $p = self::get($id);
        do_action('sepo_dictionary_proposed', $p);
        Mail::proposed($p);
        return $p;
    }

    /**
     * Members choose from the languages and categories the editors set up;
     * editors may name new ones.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function limitTaxonomies(array $data, bool $manager): array
    {
        if ($manager) {
            return $data;
        }
        foreach (['language' => PostType::LANGUAGE, 'category' => PostType::CATEGORY] as $k => $tax) {
            if ($data[$k] !== '' && !term_exists($data[$k], $tax)) {
                $data[$k] = '';
            }
        }
        $data['tags'] = array_values(array_filter($data['tags'], static fn(string $t): bool => (bool) term_exists($t, PostType::TAG)));
        return $data;
    }

    /** @param list<array<string,string>> $defs @return array<string,string>|null */
    public static function findDef(array $defs, string $id): ?array
    {
        foreach ($defs as $d) {
            if ((string) ($d['id'] ?? '') === $id && $id !== '') {
                return $d;
            }
        }
        return null;
    }

    // ------------------------------------------------------------- reading

    /** @return array<string,mixed>|null */
    private static function row(?array $r): ?array
    {
        if ($r === null) {
            return null;
        }
        foreach (['id', 'entry_id', 'user_id', 'up', 'down', 'closed_by', 'history_id'] as $k) {
            $r[$k] = (int) $r[$k];
        }
        $r['data'] = Schema::decode($r['data']) ?? [];
        $r['base'] = Schema::decode($r['base']);
        $r['net'] = $r['up'] - $r['down'];
        $r['created_ts'] = Schema::ts($r['created']);
        $r['closes_ts'] = Schema::ts($r['closes']);
        $r['closed_ts'] = Schema::ts($r['closed']);
        return $r;
    }

    /** @return array<string,mixed>|null */
    public static function get(int $id): ?array
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::proposals() . ' WHERE id = %d', $id), ARRAY_A);
        return self::row(is_array($r) ? $r : null);
    }

    /**
     * A page of proposals.
     *
     * @param array{status?:string|list<string>,kind?:string,entry_id?:int,user_id?:int,q?:string,order?:string,page?:int,per_page?:int} $a
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public static function query(array $a = []): array
    {
        global $wpdb;
        $where = ['1=1'];
        $params = [];
        $status = $a['status'] ?? self::LIVE;
        $status = array_values(array_intersect((array) $status, self::STATUSES));
        if ($status !== []) {
            $where[] = 'status IN (' . implode(',', array_fill(0, count($status), '%s')) . ')';
            array_push($params, ...$status);
        }
        $kind = (string) ($a['kind'] ?? '');
        if (in_array($kind, self::KINDS, true)) {
            $where[] = 'kind = %s';
            $params[] = $kind;
        }
        foreach (['entry_id', 'user_id'] as $k) {
            if (!empty($a[$k])) {
                $where[] = "$k = %d";
                $params[] = (int) $a[$k];
            }
        }
        if (!empty($a['q'])) {
            $where[] = 'term LIKE %s';
            $params[] = '%' . $wpdb->esc_like((string) $a['q']) . '%';
        }
        $order = match ((string) ($a['order'] ?? 'new')) {
            'top'     => '(up - down) DESC, up DESC, id ASC',
            'closing' => 'closes IS NULL, closes ASC, id ASC',
            'old'     => 'id ASC',
            'closed'  => 'closed DESC, id DESC',
            default   => 'id DESC',
        };
        $per = max(1, min(100, (int) ($a['per_page'] ?? 20)));
        $page = max(1, (int) ($a['page'] ?? 1));
        $sqlWhere = implode(' AND ', $where);
        // phpcs:disable WordPress.DB.PreparedSQL -- the clauses above use placeholders
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::proposals() . " WHERE $sqlWhere", ...$params) : 'SELECT COUNT(*) FROM ' . Schema::proposals() . " WHERE $sqlWhere");
        $sql = 'SELECT * FROM ' . Schema::proposals() . " WHERE $sqlWhere ORDER BY $order LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...array_merge($params, [$per, ($page - 1) * $per])), ARRAY_A);
        // phpcs:enable
        return ['rows' => array_values(array_filter(array_map([self::class, 'row'], $rows ?: []))), 'total' => $total];
    }

    /** @return array<string,int> status => count */
    public static function counts(): array
    {
        global $wpdb;
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . Schema::proposals() . ' GROUP BY status', ARRAY_A) ?: [] as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    public static function openCount(int $entryId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::proposals() . " WHERE entry_id = %d AND status IN ('open','passed')", $entryId));
    }

    public static function liveCountForUser(int $userId): int
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::proposals() . " WHERE user_id = %d AND status IN ('open','passed')", $userId));
    }

    /** @return list<array<string,mixed>> */
    private static function liveInGroup(string $group, array $statuses = ['open']): array
    {
        global $wpdb;
        $in = implode(',', array_fill(0, count($statuses), '%s'));
        // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders built above
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::proposals() . " WHERE group_key = %s AND status IN ($in) ORDER BY id ASC", $group, ...$statuses), ARRAY_A);
        return array_values(array_filter(array_map([self::class, 'row'], $rows ?: [])));
    }

    /**
     * The proposal ahead in its group: most net votes, then most votes for,
     * then the earliest.
     *
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>|null
     */
    public static function leader(array $rows): ?array
    {
        usort($rows, static fn(array $a, array $b): int => ($b['net'] <=> $a['net']) ?: (($b['up'] <=> $a['up']) ?: ($a['id'] <=> $b['id'])));
        return $rows[0] ?? null;
    }

    /** How many other live proposals compete with this one. */
    public static function rivals(array $p): int
    {
        if (str_starts_with((string) $p['group_key'], 'add:')) {
            return 0;
        }
        return max(0, count(self::liveInGroup((string) $p['group_key'])) - 1);
    }

    /**
     * Each proposal's vote by one person: proposal id => 1 or -1.
     *
     * @param list<int> $ids
     * @return array<int,int>
     */
    public static function votesBy(array $ids, int $userId): array
    {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === [] || $userId < 1) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '%d'));
        // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders built above
        $rows = $wpdb->get_results($wpdb->prepare('SELECT proposal_id, value FROM ' . Schema::votes() . " WHERE user_id = %d AND proposal_id IN ($in)", $userId, ...$ids), ARRAY_A);
        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[(int) $r['proposal_id']] = (int) $r['value'];
        }
        return $out;
    }

    /**
     * The notes voters left with their votes.
     *
     * @return list<array{value:int,note:string,name:string,ts:int}>
     */
    public static function notes(int $id): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT v.value, v.note, v.created, u.display_name FROM ' . Schema::votes() . " v LEFT JOIN {$wpdb->users} u ON u.ID = v.user_id WHERE v.proposal_id = %d AND v.note <> '' ORDER BY v.created ASC", $id), ARRAY_A);
        return array_map(static fn(array $r): array => [
            'value' => (int) $r['value'],
            'note'  => (string) $r['note'],
            'name'  => (string) ($r['display_name'] ?? '') ?: __('A former member', 'sepo-dictionary'),
            'ts'    => Schema::ts($r['created']),
        ], $rows ?: []);
    }

    /**
     * What a proposal still needs to pass.
     *
     * @return array{up:int,net:int,pct:int}
     */
    public static function needs(array $p): array
    {
        $needed = Settings::int('votes_needed', 1);
        $margin = Settings::int('vote_margin', 0);
        $up = max(0, $needed - (int) $p['up']);
        $net = max(0, $margin - (int) $p['net']);
        // Progress is the lesser of the two requirements met so far.
        $ratio = min((int) $p['up'] / max(1, $needed), $margin > 0 ? max(0, (int) $p['net']) / $margin : 1.0);
        return ['up' => $up, 'net' => $net, 'pct' => (int) round(100 * max(0, min(1, $ratio)))];
    }

    // ------------------------------------------------------------- voting

    /**
     * Cast, change or clear a vote: 1 accurate, -1 not accurate, 0 clears.
     *
     * @return array<string,mixed> The proposal after the vote was counted.
     */
    public static function vote(int $id, int $userId, int $value, string $note = ''): array
    {
        global $wpdb;
        $p = self::get($id);
        if ($p === null) {
            throw new ProposalError(__('That suggestion could not be found.', 'sepo-dictionary'));
        }
        if ($p['status'] !== 'open') {
            throw new ProposalError(__('Voting on this suggestion has closed.', 'sepo-dictionary'));
        }
        if ($p['user_id'] === $userId) {
            throw new ProposalError(__('You cannot vote on your own suggestion.', 'sepo-dictionary'));
        }
        $value = $value > 0 ? 1 : ($value < 0 ? -1 : 0);
        $group = (string) $p['group_key'];
        $locked = Schema::lock('group:' . $group);
        try {
            if ($value === 0) {
                $wpdb->delete(Schema::votes(), ['proposal_id' => $id, 'user_id' => $userId]);
            } else {
                $wpdb->replace(Schema::votes(), [
                    'proposal_id' => $id,
                    'user_id'     => $userId,
                    'value'       => $value,
                    'note'        => mb_substr(sanitize_text_field($note), 0, 255),
                    'created'     => Schema::now(),
                ]);
            }
            self::tally($id);
            do_action('sepo_dictionary_voted', $id, $userId, $value);
            self::evaluate($group);
        } finally {
            if ($locked) {
                Schema::unlock('group:' . $group);
            }
        }
        return self::get($id) ?? $p;
    }

    /** Recount a proposal's votes from the votes table. */
    public static function tally(int $id): void
    {
        global $wpdb;
        $r = $wpdb->get_row($wpdb->prepare('SELECT SUM(value = 1) AS up, SUM(value = -1) AS down FROM ' . Schema::votes() . ' WHERE proposal_id = %d', $id), ARRAY_A);
        $wpdb->update(Schema::proposals(), ['up' => (int) ($r['up'] ?? 0), 'down' => (int) ($r['down'] ?? 0), 'updated' => Schema::now()], ['id' => $id]);
    }

    /**
     * Settle what the votes in a group have decided: reject proposals voted
     * down, and pass the leader once it has enough support and is clearly ahead.
     */
    public static function evaluate(string $group): void
    {
        $needed = Settings::int('votes_needed', 1);
        $margin = Settings::int('vote_margin', 0);
        $rejectAt = Settings::int('reject_at', 1);

        foreach (self::liveInGroup($group) as $p) {
            if ($p['down'] - $p['up'] >= $rejectAt) {
                self::reject($p, 0, __('Voted down by the community.', 'sepo-dictionary'));
            }
        }
        $rows = self::liveInGroup($group);
        $lead = self::leader($rows);
        if ($lead === null || $lead['up'] < $needed || $lead['net'] < $margin) {
            return;
        }
        foreach ($rows as $r) {
            // A tie with a rival waits for another vote or for the close of voting.
            if ($r['id'] !== $lead['id'] && $r['net'] >= $lead['net']) {
                return;
            }
        }
        self::accept($lead, 'community', 0);
    }

    // ------------------------------------------------------------- settling

    /**
     * Merge a proposal into the dictionary.
     *
     * $by: community (won the vote), close (led when voting closed), or
     * moderator (an editor accepted it). The community and close paths
     * hand the proposal to an editor when review is on, or when the entry
     * changed underneath it; an editor's accept always merges.
     *
     * @return array{status:string,entry_id:int,note:string}
     */
    public static function accept(array $p, string $by, int $actorId, string $note = ''): array
    {
        global $wpdb;
        $p = self::get((int) $p['id']) ?? $p;
        if (!in_array($p['status'], self::LIVE, true)) {
            return ['status' => (string) $p['status'], 'entry_id' => (int) $p['entry_id'], 'note' => ''];
        }
        $force = $by === 'moderator';
        $plan = self::plan($p, $force);

        if (!$force && ($plan['conflicts'] !== [] || (int) Settings::get('review') === 1)) {
            $why = $plan['conflicts'] !== []
                ? implode(' ', $plan['conflicts'])
                : __('Passed the community vote. Waiting for an editor to merge it.', 'sepo-dictionary');
            if ($p['status'] !== 'passed') {
                $wpdb->update(Schema::proposals(), ['status' => 'passed', 'close_note' => mb_substr($why, 0, 255), 'updated' => Schema::now()], ['id' => $p['id']]);
                $p = self::get((int) $p['id']) ?? $p;
                Mail::passed($p, $plan['conflicts'] !== []);
                do_action('sepo_dictionary_proposal_passed', $p);
            }
            return ['status' => 'passed', 'entry_id' => (int) $p['entry_id'], 'note' => $why];
        }

        $entryId = Entry::store($plan['entry_id'] ?: null, $plan['state'], [
            'action'      => $plan['action'],
            'proposal_id' => (int) $p['id'],
            'user_id'     => (int) $p['user_id'],
            'actor_id'    => $actorId,
            'summary'     => $plan['summary'],
            'votes_up'    => (int) $p['up'],
            'votes_down'  => (int) $p['down'],
        ]);
        $historyId = (int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM ' . Schema::history() . ' WHERE proposal_id = %d', $p['id']));
        $closeNote = $note !== '' ? $note : match ($by) {
            'moderator' => __('Accepted by an editor.', 'sepo-dictionary'),
            'close'     => __('Led the vote when voting closed.', 'sepo-dictionary'),
            default     => __('Accepted by community vote.', 'sepo-dictionary'),
        };
        $wpdb->update(Schema::proposals(), [
            'status'     => 'accepted',
            'entry_id'   => $entryId,
            'closed'     => Schema::now(),
            'updated'    => Schema::now(),
            'closed_by'  => $actorId,
            'close_note' => mb_substr(sanitize_text_field($closeNote), 0, 255),
            'history_id' => $historyId,
        ], ['id' => $p['id']]);

        self::supersede($p, $entryId);
        if ($p['user_id'] > 0) {
            update_user_meta((int) $p['user_id'], 'sd_accepted', (int) get_user_meta((int) $p['user_id'], 'sd_accepted', true) + 1);
        }
        $p = self::get((int) $p['id']) ?? $p;
        Mail::settled($p);
        do_action('sepo_dictionary_proposal_accepted', $p, $entryId);
        return ['status' => 'accepted', 'entry_id' => $entryId, 'note' => $closeNote];
    }

    /**
     * The entry state a proposal would produce, and anything that changed
     * since it was made. With $force the conflicts are resolved in the
     * proposal's favour.
     *
     * @return array{state:array<string,mixed>,entry_id:int,action:string,summary:string,conflicts:list<string>}
     */
    public static function plan(array $p, bool $force = false): array
    {
        $conflicts = [];
        $data = (array) $p['data'];
        $by = (string) $p['author'];

        if ($p['kind'] === 'new') {
            $existing = Entry::find((string) $p['term'], (string) $p['language']);
            if ($existing < 1) {
                return [
                    'state'     => $data + ['status' => 'publish'],
                    'entry_id'  => 0,
                    'action'    => 'created',
                    /* translators: %s: member's name */
                    'summary'   => sprintf(__('New word suggested by %s', 'sepo-dictionary'), $by),
                    'conflicts' => [],
                ];
            }
            // The word arrived another way meanwhile: its meanings join the entry.
            $state = Entry::get($existing) ?? [];
            $state['definitions'] = self::appendDefs($state['definitions'] ?? [], $data['definitions'] ?? []);
            if (($state['status'] ?? '') !== 'publish' && !$force) {
                $conflicts[] = __('An unpublished entry with this word already exists.', 'sepo-dictionary');
            }
            /* translators: %s: member's name */
            return ['state' => $state, 'entry_id' => $existing, 'action' => 'merged', 'summary' => sprintf(__('Meanings suggested by %s', 'sepo-dictionary'), $by), 'conflicts' => $conflicts];
        }

        $entryId = (int) $p['entry_id'];
        $state = Entry::get($entryId);
        if ($state === null || $state['status'] === 'trash') {
            if ($force) {
                throw new ProposalError(__('The entry this suggestion changes no longer exists.', 'sepo-dictionary'));
            }
            return ['state' => [], 'entry_id' => $entryId, 'action' => 'merged', 'summary' => '', 'conflicts' => [__('The entry this suggestion changes no longer exists.', 'sepo-dictionary')]];
        }

        if ($p['kind'] === 'meaning') {
            $defs = $state['definitions'];
            $base = (array) (($p['base'] ?? [])['definition'] ?? []);
            $at = null;
            foreach ($defs as $i => $d) {
                if ($d['id'] === $p['target']) {
                    $at = $i;
                }
            }
            if ($p['op'] === 'add') {
                $state['definitions'] = self::appendDefs($defs, $data['definitions'] ?? []);
                /* translators: %s: member's name */
                $summary = sprintf(__('Meaning added, suggested by %s', 'sepo-dictionary'), $by);
            } elseif ($p['op'] === 'replace') {
                $new = (array) ($data['definition'] ?? []);
                if ($at === null) {
                    $conflicts[] = __('The meaning it replaces has since been removed.', 'sepo-dictionary');
                    if ($force) {
                        $new['id'] = Entry::newId();
                        $defs[] = $new;
                    }
                } else {
                    if (trim($defs[$at]['text']) !== trim((string) ($base['text'] ?? ''))) {
                        $conflicts[] = __('The meaning it replaces was changed after the suggestion was made.', 'sepo-dictionary');
                    }
                    if ($force || $conflicts === []) {
                        $new['id'] = $defs[$at]['id'];
                        $defs[$at] = $new;
                    }
                }
                $state['definitions'] = array_values($defs);
                /* translators: %s: member's name */
                $summary = sprintf(__('Meaning corrected, suggested by %s', 'sepo-dictionary'), $by);
            } else {
                if ($at !== null) {
                    if (trim($defs[$at]['text']) !== trim((string) ($base['text'] ?? ''))) {
                        $conflicts[] = __('The meaning was changed after removal was suggested.', 'sepo-dictionary');
                    }
                    if (count($defs) < 2) {
                        $conflicts[] = __('Removing it would leave the word with no meaning.', 'sepo-dictionary');
                    }
                    if ($force || $conflicts === []) {
                        unset($defs[$at]);
                    }
                }
                $state['definitions'] = array_values($defs);
                /* translators: %s: member's name */
                $summary = sprintf(__('Meaning removed, suggested by %s', 'sepo-dictionary'), $by);
            }
            return ['state' => $state, 'entry_id' => $entryId, 'action' => 'merged', 'summary' => $summary, 'conflicts' => $conflicts];
        }

        // An edit: three-way, field by field.
        $base = (array) ($p['base'] ?? []);
        $labels = Diff::labels();
        foreach ($data as $k => $v) {
            if (!in_array($k, self::EDIT_KEYS, true)) {
                continue;
            }
            $cur = Entry::comparable($state[$k] ?? '');
            if ($cur !== Entry::comparable($base[$k] ?? '') && $cur !== Entry::comparable($v)) {
                /* translators: %s: name of a field, such as Pronunciation */
                $conflicts[] = sprintf(__('%s was changed after the suggestion was made.', 'sepo-dictionary'), $labels[$k] ?? $k);
                if (!$force) {
                    continue;
                }
            }
            $state[$k] = $v;
        }
        if (isset($data['term']) || isset($data['language'])) {
            $clash = Entry::find((string) $state['term'], (string) $state['language'], $entryId);
            if ($clash > 0) {
                $conflicts[] = __('Another entry already uses this spelling in this language.', 'sepo-dictionary');
            }
        }
        /* translators: %s: member's name */
        return ['state' => $state, 'entry_id' => $entryId, 'action' => 'merged', 'summary' => sprintf(__('Details corrected, suggested by %s', 'sepo-dictionary'), $by), 'conflicts' => $conflicts];
    }

    /**
     * @param list<array<string,string>> $defs
     * @param list<array<string,string>> $more
     * @return list<array<string,string>>
     */
    private static function appendDefs(array $defs, array $more): array
    {
        $known = array_map(static fn(array $d): string => Entry::fold((string) $d['text']), $defs);
        $ids = array_map(static fn(array $d): string => (string) $d['id'], $defs);
        foreach ($more as $d) {
            if (in_array(Entry::fold((string) ($d['text'] ?? '')), $known, true)) {
                continue;
            }
            if (in_array((string) ($d['id'] ?? ''), $ids, true) || empty($d['id'])) {
                $d['id'] = Entry::newId();
            }
            $defs[] = $d;
            $known[] = Entry::fold((string) $d['text']);
        }
        return array_values($defs);
    }

    /** Others competing for the same thing step aside once one is merged. */
    private static function supersede(array $p, int $entryId): void
    {
        global $wpdb;
        $g = (string) $p['group_key'];
        if (!str_starts_with($g, 'new:') && !str_starts_with($g, 'def:')) {
            return;
        }
        foreach (self::liveInGroup($g, self::LIVE) as $other) {
            if ($other['id'] === $p['id']) {
                continue;
            }
            $wpdb->update(Schema::proposals(), [
                'status'     => 'superseded',
                'entry_id'   => $other['kind'] === 'new' ? $entryId : $other['entry_id'],
                'closed'     => Schema::now(),
                'updated'    => Schema::now(),
                'close_note' => __('Another suggestion for this won the vote.', 'sepo-dictionary'),
            ], ['id' => $other['id']]);
            $o = self::get((int) $other['id']);
            if ($o !== null) {
                Mail::settled($o);
            }
        }
    }

    public static function reject(array $p, int $actorId, string $note = ''): void
    {
        global $wpdb;
        if (!in_array($p['status'], self::LIVE, true)) {
            return;
        }
        $wpdb->update(Schema::proposals(), [
            'status'     => 'rejected',
            'closed'     => Schema::now(),
            'updated'    => Schema::now(),
            'closed_by'  => $actorId,
            'close_note' => mb_substr(sanitize_text_field($note !== '' ? $note : __('Not accepted.', 'sepo-dictionary')), 0, 255),
        ], ['id' => $p['id']]);
        $p = self::get((int) $p['id']) ?? $p;
        Mail::settled($p);
        do_action('sepo_dictionary_proposal_rejected', $p);
    }

    public static function withdraw(int $id, int $userId): void
    {
        global $wpdb;
        $p = self::get($id);
        if ($p === null || $p['user_id'] !== $userId) {
            throw new ProposalError(__('Only the person who made a suggestion can withdraw it.', 'sepo-dictionary'));
        }
        if (!in_array($p['status'], self::LIVE, true)) {
            throw new ProposalError(__('This suggestion has already been settled.', 'sepo-dictionary'));
        }
        $wpdb->update(Schema::proposals(), ['status' => 'withdrawn', 'closed' => Schema::now(), 'updated' => Schema::now(), 'close_note' => __('Withdrawn by its author.', 'sepo-dictionary')], ['id' => $id]);
        do_action('sepo_dictionary_proposal_withdrawn', $id);
    }

    /**
     * Open a settled suggestion for a fresh vote. A new word that lost to
     * another becomes a suggestion to add its meanings to the winning entry;
     * a correction whose meaning has changed is measured against the new text.
     *
     * @return array<string,mixed>
     */
    public static function resubmit(int $id, int $userId): array
    {
        global $wpdb;
        $p = self::get($id);
        if ($p === null || $p['user_id'] !== $userId) {
            throw new ProposalError(__('Only the person who made a suggestion can put it forward again.', 'sepo-dictionary'));
        }
        if (!in_array($p['status'], ['expired', 'withdrawn', 'superseded'], true)) {
            throw new ProposalError(__('This suggestion cannot be put forward again.', 'sepo-dictionary'));
        }
        $max = Settings::int('max_open');
        if ($max > 0 && self::liveCountForUser($userId) >= $max && !Plugin::canManage()) {
            /* translators: %d: how many open proposals a member may have */
            throw new ProposalError(sprintf(__('You already have %d suggestions waiting for votes. Please wait for some of them to be settled.', 'sepo-dictionary'), $max));
        }
        $upd = ['kind' => $p['kind'], 'op' => $p['op'], 'target' => $p['target'], 'entry_id' => $p['entry_id'], 'group_key' => $p['group_key'], 'data' => $p['data'], 'base' => $p['base']];

        if ($p['kind'] === 'new') {
            $existing = Entry::find((string) $p['term'], (string) $p['language']);
            if ($existing > 0) {
                $state = Entry::get($existing) ?? ['definitions' => []];
                $known = array_map(static fn(array $d): string => Entry::fold($d['text']), $state['definitions']);
                $defs = array_values(array_filter((array) ($p['data']['definitions'] ?? []), static fn(array $d): bool => !in_array(Entry::fold((string) $d['text']), $known, true)));
                if ($defs === []) {
                    throw new ProposalError(__('The entry already lists these meanings.', 'sepo-dictionary'));
                }
                $upd = ['kind' => 'meaning', 'op' => 'add', 'target' => '', 'entry_id' => $existing, 'group_key' => 'add:' . $existing . ':' . wp_generate_uuid4(), 'data' => ['definitions' => $defs], 'base' => null];
            }
        } elseif ($p['kind'] === 'meaning' && $p['op'] !== 'add') {
            $state = Entry::get((int) $p['entry_id']);
            $def = $state ? self::findDef($state['definitions'], (string) $p['target']) : null;
            if ($def === null) {
                if ($p['op'] === 'remove') {
                    throw new ProposalError(__('That meaning has already been removed.', 'sepo-dictionary'));
                }
                $new = (array) ($p['data']['definition'] ?? []);
                $upd = ['kind' => 'meaning', 'op' => 'add', 'target' => '', 'entry_id' => $p['entry_id'], 'group_key' => 'add:' . $p['entry_id'] . ':' . wp_generate_uuid4(), 'data' => ['definitions' => [$new]], 'base' => null];
            } else {
                $upd['base'] = ['definition' => $def];
            }
        } elseif ($p['kind'] === 'edit') {
            $state = Entry::get((int) $p['entry_id']);
            if ($state === null) {
                throw new ProposalError(__('The entry this suggestion changes no longer exists.', 'sepo-dictionary'));
            }
            $data = [];
            $base = [];
            foreach ((array) $p['data'] as $k => $v) {
                if (Entry::comparable($state[$k] ?? '') !== Entry::comparable($v)) {
                    $data[$k] = $v;
                    $base[$k] = $state[$k] ?? '';
                }
            }
            if ($data === []) {
                throw new ProposalError(__('The entry already reads this way.', 'sepo-dictionary'));
            }
            $upd['data'] = $data;
            $upd['base'] = $base;
        }

        $days = Settings::int('voting_days');
        $wpdb->delete(Schema::votes(), ['proposal_id' => $id]);
        $wpdb->update(Schema::proposals(), [
            'kind'       => $upd['kind'],
            'op'         => $upd['op'],
            'target'     => $upd['target'],
            'entry_id'   => $upd['entry_id'],
            'group_key'  => $upd['group_key'],
            'data'       => Schema::encode($upd['data']),
            'base'       => $upd['base'] === null ? null : Schema::encode($upd['base']),
            'status'     => 'open',
            'up'         => 0,
            'down'       => 0,
            'closes'     => $days > 0 ? gmdate('Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS) : null,
            'closed'     => null,
            'closed_by'  => 0,
            'close_note' => '',
            'updated'    => Schema::now(),
        ], ['id' => $id]);
        return self::get($id) ?? $p;
    }

    public static function delete(int $id): void
    {
        global $wpdb;
        $wpdb->delete(Schema::votes(), ['proposal_id' => $id]);
        $wpdb->delete(Schema::proposals(), ['id' => $id]);
    }

    /** Settle proposals whose voting window has ended. */
    public static function closeDue(): void
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::proposals() . " WHERE status = 'open' AND closes IS NOT NULL AND closes <= %s ORDER BY closes ASC LIMIT 200", Schema::now()));
        foreach ($ids as $id) {
            $p = self::get((int) $id);
            if ($p === null || $p['status'] !== 'open') {
                continue;
            }
            $group = (string) $p['group_key'];
            $locked = Schema::lock('group:' . $group);
            try {
                $lead = self::leader(self::liveInGroup($group));
                $win = (int) Settings::get('settle_on_close') === 1 && $lead !== null && $lead['id'] === $p['id'] && $p['net'] > 0;
                if ($win) {
                    foreach (self::liveInGroup($group) as $r) {
                        if ($r['id'] !== $p['id'] && $r['net'] === $p['net'] && $r['up'] === $p['up']) {
                            // A dead heat is not a win; the rival keeps its own deadline.
                            $win = false;
                        }
                    }
                }
                if ($win) {
                    self::accept($p, 'close', 0);
                } else {
                    $wpdb->update(Schema::proposals(), ['status' => 'expired', 'closed' => Schema::now(), 'updated' => Schema::now(), 'close_note' => __('Voting closed without enough support.', 'sepo-dictionary')], ['id' => $p['id']]);
                    $e = self::get((int) $p['id']);
                    if ($e !== null) {
                        Mail::settled($e);
                    }
                }
            } finally {
                if ($locked) {
                    Schema::unlock('group:' . $group);
                }
            }
        }
    }

    public static function purgeEntry(int $entryId): void
    {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . Schema::proposals() . ' WHERE entry_id = %d', $entryId));
        foreach ($ids as $id) {
            self::delete((int) $id);
        }
    }

    /**
     * Members ranked by accepted suggestions.
     *
     * @return list<array{user_id:int,name:string,accepted:int,total:int,votes:int}>
     */
    public static function contributors(int $limit = 20): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT user_id, SUM(status = %s) AS accepted, COUNT(*) AS total FROM ' . Schema::proposals() . ' WHERE user_id > 0 GROUP BY user_id ORDER BY accepted DESC, total DESC LIMIT %d', 'accepted', $limit), ARRAY_A);
        $ids = array_map(static fn(array $r): int => (int) $r['user_id'], $rows ?: []);
        $votes = [];
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '%d'));
            // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders built above
            foreach ($wpdb->get_results($wpdb->prepare('SELECT user_id, COUNT(*) AS n FROM ' . Schema::votes() . " WHERE user_id IN ($in) GROUP BY user_id", ...$ids), ARRAY_A) ?: [] as $v) {
                $votes[(int) $v['user_id']] = (int) $v['n'];
            }
        }
        $out = [];
        foreach ($rows ?: [] as $r) {
            $u = get_userdata((int) $r['user_id']);
            if (!$u) {
                continue;
            }
            $out[] = ['user_id' => (int) $r['user_id'], 'name' => (string) $u->display_name, 'accepted' => (int) $r['accepted'], 'total' => (int) $r['total'], 'votes' => $votes[(int) $r['user_id']] ?? 0];
        }
        return $out;
    }

    public static function kindLabel(array $p): string
    {
        if ($p['kind'] === 'new') {
            return __('New word', 'sepo-dictionary');
        }
        if ($p['kind'] === 'edit') {
            return __('Correction', 'sepo-dictionary');
        }
        return match ((string) $p['op']) {
            'replace' => __('Better meaning', 'sepo-dictionary'),
            'remove'  => __('Remove meaning', 'sepo-dictionary'),
            default   => __('New meaning', 'sepo-dictionary'),
        };
    }

    public static function statusLabel(string $s): string
    {
        return match ($s) {
            'open'       => __('Voting', 'sepo-dictionary'),
            'passed'     => __('Waiting for an editor', 'sepo-dictionary'),
            'accepted'   => __('Accepted', 'sepo-dictionary'),
            'rejected'   => __('Not accepted', 'sepo-dictionary'),
            'withdrawn'  => __('Withdrawn', 'sepo-dictionary'),
            'expired'    => __('Voting closed', 'sepo-dictionary'),
            'superseded' => __('Superseded', 'sepo-dictionary'),
            default      => $s,
        };
    }
}
