<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/**
 * One dictionary entry: the headword (post title), its taxonomies and the
 * fields kept in post meta. Every write goes through store(), which records
 * the change in the entry's history.
 *
 * A "state" is the whole entry as one array:
 *   term, status, language, category, tags[], pronunciation, audio
 *   (attachment id of a recorded pronunciation), pos,
 *   definitions[ {id, text, example} ], synonyms[], antonyms[], related[],
 *   etymology, notes, source
 */
final class Entry
{
    public const META = '_sd_entry';
    public const LETTER = '_sd_letter';

    public const FIELDS = ['pronunciation', 'audio', 'pos', 'definitions', 'synonyms', 'antonyms', 'related', 'etymology', 'notes', 'source'];
    public const STATE_KEYS = ['term', 'status', 'language', 'category', 'tags', 'pronunciation', 'audio', 'pos', 'definitions', 'synonyms', 'antonyms', 'related', 'etymology', 'notes', 'source'];
    public const LIST_KEYS = ['tags', 'synonyms', 'antonyms', 'related'];
    public const STATUSES = ['publish', 'draft', 'pending', 'private'];

    /** Letter buckets in display order: a–z, then digits, then everything else. */
    public const LETTERS = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z', '0', '_'];

    /** True while store() writes, so the post hooks below leave the history to store(). */
    private static bool $writing = false;

    /** @var array<int,array<string,mixed>> */
    private static array $memo = [];

    public static function hook(): void
    {
        // Title or status changed somewhere other than store(), such as Quick Edit or the trash.
        add_action('post_updated', static function (int $id, \WP_Post $after, \WP_Post $before): void {
            if (self::$writing || $after->post_type !== PostType::TYPE || isset($_POST['sd_entry_nonce'])) { // phpcs:ignore WordPress.Security.NonceVerification
                return;
            }
            if ($after->post_title === $before->post_title && $after->post_status === $before->post_status) {
                return;
            }
            unset(self::$memo[$id]);
            update_post_meta($id, self::LETTER, self::letterFor($after->post_title));
            $prev = History::lastState($id) ?? self::stateFromPost($before);
            $next = self::get($id);
            if ($next !== null) {
                History::record($id, $after->post_status === 'trash' ? 'trashed' : ($before->post_status === 'trash' ? 'untrashed' : 'edited'), $prev, $next, [
                    'actor_id' => get_current_user_id(),
                    'user_id'  => get_current_user_id(),
                ]);
            }
        }, 10, 3);

        add_action('before_delete_post', static function (int $id): void {
            if (get_post_type($id) === PostType::TYPE) {
                Proposals::purgeEntry($id);
                History::purgeEntry($id);
            }
        });
    }

    // ------------------------------------------------------------------ reading

    /**
     * The meta fields of an entry, always in full shape.
     *
     * @return array<string,mixed>
     */
    public static function fields(int $id): array
    {
        $raw = get_post_meta($id, self::META, true);
        return self::cleanFields(is_array($raw) ? $raw : []);
    }

    /**
     * The whole entry as a state, or null when it is not a dictionary entry.
     *
     * @return array<string,mixed>|null
     */
    public static function get(int $id): ?array
    {
        if (isset(self::$memo[$id])) {
            return self::$memo[$id];
        }
        $post = get_post($id);
        if (!$post instanceof \WP_Post || $post->post_type !== PostType::TYPE) {
            return null;
        }
        return self::$memo[$id] = self::stateFromPost($post);
    }

    /** @return array<string,mixed> */
    private static function stateFromPost(\WP_Post $post): array
    {
        $names = static function (string $tax) use ($post): array {
            $t = get_the_terms($post, $tax);
            return is_array($t) ? array_values(array_map(static fn(\WP_Term $x): string => $x->name, $t)) : [];
        };
        $lang = $names(PostType::LANGUAGE);
        $cat = $names(PostType::CATEGORY);
        return [
            'term'     => (string) $post->post_title,
            'status'   => (string) $post->post_status,
            'language' => (string) ($lang[0] ?? ''),
            'category' => (string) ($cat[0] ?? ''),
            'tags'     => $names(PostType::TAG),
        ] + self::fields((int) $post->ID);
    }

    /** The language's speech code for the Listen button, when one is set. */
    public static function speechCode(string $language): string
    {
        if ($language === '') {
            return '';
        }
        $t = get_term_by('name', $language, PostType::LANGUAGE);
        return $t instanceof \WP_Term ? (string) get_term_meta($t->term_id, 'sd_code', true) : '';
    }

    /** An entry with this headword in this language, if there is one (trash excluded). */
    public static function find(string $term, string $language = '', int $except = 0): int
    {
        global $wpdb;
        $term = trim($term);
        if ($term === '') {
            return 0;
        }
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ('publish','draft','pending','private') AND post_title = %s AND ID <> %d ORDER BY ID ASC",
            PostType::TYPE,
            $term,
            $except
        ));
        foreach ($ids as $id) {
            $s = self::get((int) $id);
            if ($s !== null && self::fold($s['language']) === self::fold($language)) {
                return (int) $id;
            }
        }
        return 0;
    }

    /** A published entry with this headword in any language, the earliest first. */
    public static function findAny(string $term): int
    {
        global $wpdb;
        $term = trim($term);
        if ($term === '') {
            return 0;
        }
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_title = %s ORDER BY ID ASC LIMIT 1",
            PostType::TYPE,
            $term
        ));
    }

    public static function url(int $id): string
    {
        return (string) get_permalink($id);
    }

    // ------------------------------------------------------------------ writing

    /**
     * Create or update an entry from a state and record the change.
     *
     * $ctx: action (created|edited|merged|restored), proposal_id, user_id
     * (whose change this is), actor_id (who applied it), summary, votes_up,
     * votes_down, before (the state to compare against).
     *
     * @param array<string,mixed> $state
     * @param array<string,mixed> $ctx
     */
    public static function store(?int $id, array $state, array $ctx = []): int
    {
        $id = $id ?: null;
        $before = null;
        // A post WordPress has just created (from the dashboard) has no earlier state to compare with.
        if ($id !== null && empty($ctx['new'])) {
            $before = isset($ctx['before']) && is_array($ctx['before']) ? $ctx['before'] : self::get($id);
            if ($before === null) {
                throw new \InvalidArgumentException(__('That entry no longer exists.', 'sepo-dictionary'));
            }
        } elseif ($id !== null && get_post_type($id) !== PostType::TYPE) {
            throw new \InvalidArgumentException(__('That entry no longer exists.', 'sepo-dictionary'));
        }
        $clean = self::clean($state, $before ?? []);
        if ($clean['term'] === '') {
            throw new \InvalidArgumentException(__('A word is required.', 'sepo-dictionary'));
        }

        $post = [
            'post_type'    => PostType::TYPE,
            'post_title'   => $clean['term'],
            'post_status'  => $clean['status'],
            'post_content' => self::plainText($clean),
            'post_excerpt' => self::snippet($clean, 300),
        ];

        self::$writing = true;
        try {
            if ($id === null) {
                $post['post_author'] = (int) ($ctx['user_id'] ?? 0) ?: get_current_user_id();
                $result = wp_insert_post(wp_slash($post), true);
            } else {
                $post['ID'] = $id;
                $current = get_post($id);
                if ($current instanceof \WP_Post && $current->post_title !== $clean['term']) {
                    // A changed spelling gets a matching address; WordPress redirects the old one.
                    $post['post_name'] = '';
                }
                $result = wp_update_post(wp_slash($post), true);
            }
            if (is_wp_error($result)) {
                throw new \RuntimeException($result->get_error_message());
            }
            $id = (int) $result;

            $meta = [];
            foreach (self::FIELDS as $k) {
                $meta[$k] = $clean[$k];
            }
            update_post_meta($id, self::META, wp_slash($meta));
            update_post_meta($id, self::LETTER, self::letterFor($clean['term']));
            self::setTerms($id, PostType::LANGUAGE, $clean['language'] !== '' ? [$clean['language']] : []);
            self::setTerms($id, PostType::CATEGORY, $clean['category'] !== '' ? [$clean['category']] : []);
            self::setTerms($id, PostType::TAG, $clean['tags']);
        } finally {
            self::$writing = false;
        }

        unset(self::$memo[$id]);
        clean_post_cache($id);
        $after = self::get($id) ?? $clean;

        if ($before === null || !self::same($before, $after)) {
            History::record($id, (string) ($ctx['action'] ?? ($before === null ? 'created' : 'edited')), $before, $after, $ctx);
        }

        do_action('sepo_dictionary_entry_saved', $id, $after, $before, $ctx);
        return $id;
    }

    /** @param list<string> $names */
    private static function setTerms(int $id, string $taxonomy, array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $found = term_exists($name, $taxonomy);
            if (!$found) {
                $found = wp_insert_term($name, $taxonomy);
            }
            if (is_array($found) && isset($found['term_id'])) {
                $ids[] = (int) $found['term_id'];
            }
        }
        wp_set_object_terms($id, $ids, $taxonomy, false);
    }

    /** Whether two states hold the same entry. */
    public static function same(array $a, array $b): bool
    {
        foreach (self::STATE_KEYS as $k) {
            if (self::comparable($a[$k] ?? null) !== self::comparable($b[$k] ?? null)) {
                return false;
            }
        }
        return true;
    }

    public static function comparable(mixed $v): string
    {
        if (is_array($v)) {
            $v = array_values($v);
            // Meanings are compared by their words, not by their ids.
            $v = array_map(static fn($x) => is_array($x) ? [trim((string) ($x['text'] ?? '')), trim((string) ($x['example'] ?? ''))] : trim((string) $x), $v);
            return (string) wp_json_encode($v, JSON_UNESCAPED_UNICODE);
        }
        return trim((string) $v);
    }

    // ------------------------------------------------------------- sanitising

    /**
     * A full, safe state. Missing keys keep the value from $base.
     *
     * @param array<string,mixed> $in
     * @param array<string,mixed> $base
     * @return array<string,mixed>
     */
    public static function clean(array $in, array $base = []): array
    {
        $pick = static fn(string $k) => array_key_exists($k, $in) ? $in[$k] : ($base[$k] ?? null);
        $status = (string) $pick('status');
        $out = [
            'term'     => self::line($pick('term'), 160),
            'status'   => in_array($status, self::STATUSES, true) ? $status : 'publish',
            'language' => self::line($pick('language'), 100),
            'category' => self::line($pick('category'), 100),
            'tags'     => self::listy($pick('tags'), 12),
        ];
        $fields = [];
        foreach (self::FIELDS as $k) {
            $fields[$k] = $pick($k);
        }
        return $out + self::cleanFields($fields);
    }

    /**
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    public static function cleanFields(array $in): array
    {
        return [
            'pronunciation' => self::line($in['pronunciation'] ?? '', 120),
            'audio'         => self::audioId($in['audio'] ?? 0),
            'pos'           => self::line($in['pos'] ?? '', 40),
            'definitions'   => self::defs($in['definitions'] ?? []),
            'synonyms'      => self::listy($in['synonyms'] ?? [], 30),
            'antonyms'      => self::listy($in['antonyms'] ?? [], 30),
            'related'       => self::listy($in['related'] ?? [], 30),
            'etymology'     => self::text($in['etymology'] ?? '', 1000),
            'notes'         => self::text($in['notes'] ?? '', 2000),
            'source'        => self::line($in['source'] ?? '', 300),
        ];
    }

    /** An attachment id when it is an audio file in the media library, otherwise 0. */
    public static function audioId(mixed $v): int
    {
        $id = is_numeric($v) ? (int) $v : 0;
        if ($id < 1 || get_post_type($id) !== 'attachment') {
            return 0;
        }
        return self::isAudio((string) get_post_mime_type($id)) ? $id : 0;
    }

    /** Browsers record voice as WebM or MP4, which WordPress files under video types. */
    public static function isAudio(string $mime): bool
    {
        return str_starts_with($mime, 'audio/') || in_array($mime, ['video/webm', 'video/mp4', 'video/ogg'], true);
    }

    /** The recorded pronunciation's address, or '' when there is none. */
    public static function audioUrl(array $state): string
    {
        $id = (int) ($state['audio'] ?? 0);
        return $id > 0 ? (string) wp_get_attachment_url($id) : '';
    }

    public static function line(mixed $v, int $max): string
    {
        return mb_substr(trim(sanitize_text_field(is_scalar($v) ? (string) $v : '')), 0, $max);
    }

    public static function text(mixed $v, int $max): string
    {
        return mb_substr(trim(sanitize_textarea_field(is_scalar($v) ? (string) $v : '')), 0, $max);
    }

    /**
     * Anything into a list of meanings, each with a stable id.
     * Accepts [{text, example}], "text | example" lines, or one string.
     *
     * @return list<array{id:string,text:string,example:string}>
     */
    public static function defs(mixed $val): array
    {
        $rows = is_array($val) ? $val : (is_string($val) && trim($val) !== '' ? (preg_split('~\R+~', $val) ?: []) : []);
        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $text = (string) ($row['text'] ?? ($row['definition'] ?? ''));
                $ex = (string) ($row['example'] ?? '');
                $id = (string) ($row['id'] ?? '');
            } else {
                $parts = array_map('trim', explode('|', (string) $row, 2));
                $text = $parts[0];
                $ex = $parts[1] ?? '';
                $id = '';
            }
            $text = self::text($text, 2000);
            if ($text === '') {
                continue;
            }
            if (preg_match('~^[a-z0-9]{6,16}$~', $id) !== 1 || isset($seen[$id])) {
                $id = self::newId();
            }
            $seen[$id] = true;
            $out[] = ['id' => $id, 'text' => $text, 'example' => self::text($ex, 500)];
            if (count($out) >= 30) {
                break;
            }
        }
        return $out;
    }

    public static function newId(): string
    {
        return bin2hex(random_bytes(4));
    }

    /**
     * Anything into a clean list of short strings (array, or comma, pipe or line separated).
     *
     * @return list<string>
     */
    public static function listy(mixed $val, int $max = 30): array
    {
        $rows = is_array($val) ? $val : (is_string($val) && trim($val) !== '' ? (preg_split('~[|,\r\n]+~', $val) ?: []) : []);
        $out = [];
        $folded = [];
        foreach ($rows as $r) {
            $s = self::line(is_array($r) ? ($r['label'] ?? '') : $r, 120);
            $f = self::fold($s);
            if ($s !== '' && !isset($folded[$f])) {
                $folded[$f] = true;
                $out[] = $s;
            }
            if (count($out) >= $max) {
                break;
            }
        }
        return $out;
    }

    // ------------------------------------------------------------ text helpers

    /** Lower case without accents, for comparing and matching. */
    public static function fold(string $s): string
    {
        return mb_strtolower(remove_accents(trim($s)), 'UTF-8');
    }

    /** Which A–Z bucket a headword sits in. */
    public static function letterFor(string $term): string
    {
        $f = self::fold($term);
        $f = ltrim($f, " \t'\"‘’“”-([");
        $first = mb_substr($f, 0, 1, 'UTF-8');
        if (preg_match('~^[a-z]$~', $first) === 1) {
            return $first;
        }
        if (preg_match('~^[0-9]$~', $first) === 1) {
            return '0';
        }
        return '_';
    }

    public static function letterLabel(string $l): string
    {
        return $l === '0' ? '0–9' : ($l === '_' ? '#' : strtoupper($l));
    }

    /** The first meaning, shortened. */
    public static function snippet(array $state, int $len = 160): string
    {
        $text = (string) ($state['definitions'][0]['text'] ?? '');
        return mb_strlen($text) > $len ? rtrim(mb_substr($text, 0, $len - 1)) . '…' : $text;
    }

    /** A plain-text rendering kept in post_content, so WordPress search, feeds and excerpts see the meanings. */
    public static function plainText(array $state): string
    {
        $lines = [];
        foreach ($state['definitions'] ?? [] as $i => $d) {
            $lines[] = ($i + 1) . '. ' . $d['text'] . ($d['example'] !== '' ? ' — ' . $d['example'] : '');
        }
        foreach (['synonyms' => __('Synonyms', 'sepo-dictionary'), 'antonyms' => __('Antonyms', 'sepo-dictionary'), 'related' => __('See also', 'sepo-dictionary')] as $k => $label) {
            if (!empty($state[$k])) {
                $lines[] = $label . ': ' . implode(', ', $state[$k]);
            }
        }
        if (($state['etymology'] ?? '') !== '') {
            $lines[] = __('Origin', 'sepo-dictionary') . ': ' . $state['etymology'];
        }
        if (($state['notes'] ?? '') !== '') {
            $lines[] = $state['notes'];
        }
        return implode("\n\n", $lines);
    }

    // ------------------------------------------------------------ queries

    /** @return list<string> */
    public static function visibleStatuses(bool $manager): array
    {
        return $manager ? ['publish', 'draft', 'pending', 'private'] : ['publish'];
    }

    /**
     * One page of entries for browsing.
     *
     * @param array{letter?:string,language?:string,category?:string,page?:int,per_page?:int,manager?:bool} $o
     * @return array{posts:list<\WP_Post>,total:int,pages:int}
     */
    public static function browse(array $o): array
    {
        $args = [
            'post_type'      => PostType::TYPE,
            'post_status'    => self::visibleStatuses(!empty($o['manager'])),
            'orderby'        => 'title',
            'order'          => 'ASC',
            'posts_per_page' => max(1, (int) ($o['per_page'] ?? 30)),
            'paged'          => max(1, (int) ($o['page'] ?? 1)),
            'no_found_rows'  => false,
        ];
        $letter = (string) ($o['letter'] ?? '');
        if ($letter !== '' && in_array($letter, self::LETTERS, true)) {
            $args['meta_key'] = self::LETTER;
            $args['meta_value'] = $letter;
        }
        $tax = self::taxQuery((string) ($o['language'] ?? ''), (string) ($o['category'] ?? ''));
        if ($tax !== []) {
            $args['tax_query'] = $tax;
        }
        $q = new \WP_Query($args);
        return ['posts' => array_values($q->posts), 'total' => (int) $q->found_posts, 'pages' => (int) $q->max_num_pages];
    }

    /** @return array<int|string,mixed> */
    private static function taxQuery(string $language, string $category): array
    {
        $tax = [];
        if ($language !== '') {
            $tax[] = ['taxonomy' => PostType::LANGUAGE, 'field' => 'slug', 'terms' => [$language]];
        }
        if ($category !== '') {
            $tax[] = ['taxonomy' => PostType::CATEGORY, 'field' => 'slug', 'terms' => [$category]];
        }
        if (count($tax) > 1) {
            $tax['relation'] = 'AND';
        }
        return $tax;
    }

    /**
     * Ranked lookup: exact headword, then words that start with it, then
     * words that contain it, then synonyms, then meanings.
     *
     * @param array{limit?:int,language?:string,category?:string,manager?:bool} $o
     * @return list<\WP_Post>
     */
    public static function search(string $q, array $o = []): array
    {
        global $wpdb;
        $q = trim($q);
        if ($q === '') {
            return [];
        }
        $limit = max(1, min(500, (int) ($o['limit'] ?? 50)));
        $statuses = self::visibleStatuses(!empty($o['manager']));
        $like = '%' . $wpdb->esc_like($q) . '%';
        $in = implode(',', array_fill(0, count($statuses), '%s'));

        $join = '';
        $where = '';
        $params = array_merge([PostType::TYPE], $statuses, [$like, $like]);
        foreach ([PostType::LANGUAGE => (string) ($o['language'] ?? ''), PostType::CATEGORY => (string) ($o['category'] ?? '')] as $tax => $slug) {
            if ($slug === '') {
                continue;
            }
            $term = get_term_by('slug', $slug, $tax);
            if (!$term instanceof \WP_Term) {
                return [];
            }
            $alias = $tax === PostType::LANGUAGE ? 'trl' : 'trc';
            $join .= " INNER JOIN {$wpdb->term_relationships} {$alias} ON {$alias}.object_id = p.ID";
            $where .= $wpdb->prepare(" AND {$alias}.term_taxonomy_id = %d", (int) $term->term_taxonomy_id);
        }

        // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders are built above
        $ids = $wpdb->get_col($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p{$join} WHERE p.post_type = %s AND p.post_status IN ($in) AND (p.post_title LIKE %s OR p.post_content LIKE %s){$where} LIMIT 600", ...$params));
        if ($ids === []) {
            return [];
        }
        $posts = get_posts(['post_type' => PostType::TYPE, 'post__in' => array_map('intval', $ids), 'post_status' => $statuses, 'posts_per_page' => -1, 'orderby' => 'post__in']);

        $needle = self::fold($q);
        $hits = [];
        foreach ($posts as $p) {
            $title = self::fold($p->post_title);
            $f = self::fields((int) $p->ID);
            if ($title === $needle) {
                $score = 100;
            } elseif (str_starts_with($title, $needle)) {
                $score = 80;
            } elseif (str_contains($title, $needle)) {
                $score = 60;
            } else {
                $score = 10;
                foreach ($f['synonyms'] as $syn) {
                    if (str_contains(self::fold($syn), $needle)) {
                        $score = 40;
                        break;
                    }
                }
                if ($score < 40) {
                    foreach ($f['definitions'] as $d) {
                        if (str_contains(self::fold($d['text']), $needle)) {
                            $score = 20;
                            break;
                        }
                    }
                }
            }
            $hits[] = ['s' => $score, 'p' => $p];
        }
        usort($hits, static fn(array $a, array $b): int => ($b['s'] <=> $a['s']) ?: strcasecmp($a['p']->post_title, $b['p']->post_title));
        return array_map(static fn(array $h): \WP_Post => $h['p'], array_slice($hits, 0, $limit));
    }

    /**
     * letter => how many entries, for the A–Z rail.
     *
     * @return array<string,int>
     */
    public static function letters(string $language = '', string $category = '', bool $manager = false): array
    {
        global $wpdb;
        $counts = array_fill_keys(self::LETTERS, 0);
        $statuses = self::visibleStatuses($manager);
        $in = implode(',', array_fill(0, count($statuses), '%s'));
        $join = '';
        $where = '';
        foreach ([PostType::LANGUAGE => $language, PostType::CATEGORY => $category] as $tax => $slug) {
            if ($slug === '') {
                continue;
            }
            $term = get_term_by('slug', $slug, $tax);
            if (!$term instanceof \WP_Term) {
                return $counts;
            }
            $alias = $tax === PostType::LANGUAGE ? 'trl' : 'trc';
            $join .= " INNER JOIN {$wpdb->term_relationships} {$alias} ON {$alias}.object_id = p.ID";
            $where .= $wpdb->prepare(" AND {$alias}.term_taxonomy_id = %d", (int) $term->term_taxonomy_id);
        }
        // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders are built above
        $rows = $wpdb->get_results($wpdb->prepare("SELECT pm.meta_value AS l, COUNT(*) AS n FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id{$join} WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status IN ($in){$where} GROUP BY pm.meta_value", self::LETTER, PostType::TYPE, ...$statuses), ARRAY_A);
        foreach ($rows ?: [] as $r) {
            if (isset($counts[$r['l']])) {
                $counts[$r['l']] = (int) $r['n'];
            }
        }
        return $counts;
    }

    /** How many published entries, optionally in one language. */
    public static function total(string $language = ''): int
    {
        if ($language === '') {
            return (int) (wp_count_posts(PostType::TYPE)->publish ?? 0);
        }
        $t = get_term_by('slug', $language, PostType::LANGUAGE);
        return $t instanceof \WP_Term ? (int) $t->count : 0;
    }

    /** The same published entry all day; it changes at local midnight. */
    public static function wordOfDay(string $language = ''): ?\WP_Post
    {
        $day = wp_date('Y-m-d');
        $key = 'sd_wod_' . md5($language . '|' . $day);
        $cached = get_transient($key);
        if (is_numeric($cached) && (int) $cached > 0) {
            $p = get_post((int) $cached);
            if ($p instanceof \WP_Post && $p->post_status === 'publish' && $p->post_type === PostType::TYPE) {
                return $p;
            }
        }
        $args = ['post_type' => PostType::TYPE, 'post_status' => 'publish', 'posts_per_page' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids'];
        $tax = self::taxQuery($language, '');
        if ($tax !== []) {
            $args['tax_query'] = $tax;
        }
        $count = (new \WP_Query($args + ['no_found_rows' => false]))->found_posts;
        if ($count < 1) {
            return null;
        }
        $args['offset'] = (int) (sprintf('%u', crc32($day . $language)) % $count);
        $ids = (new \WP_Query($args + ['no_found_rows' => true]))->posts;
        $id = (int) ($ids[0] ?? 0);
        if ($id < 1) {
            return null;
        }
        set_transient($key, $id, DAY_IN_SECONDS);
        return get_post($id);
    }
}
