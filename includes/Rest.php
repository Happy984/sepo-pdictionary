<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/**
 * The REST API under /wp-json/sepo-dictionary/v1.
 *
 *   GET  search              typeahead and lookups (public)
 *   GET  entry               one entry by slug or id (public)
 *   POST entry               create or edit an entry directly (editors)
 *   POST entry/trash         move an entry to the trash (editors)
 *   POST audio               upload or remove a recorded pronunciation (editors)
 *   POST propose             suggest a word, a meaning or a correction (members)
 *   POST vote                vote on a suggestion's accuracy (members)
 *   POST proposal/withdraw   withdraw your own suggestion
 *   POST proposal/resubmit   put a closed suggestion of yours forward again
 *   POST proposal/moderate   accept, reject or delete a suggestion (editors)
 *   POST history/restore     restore an earlier version of an entry (editors)
 */
final class Rest
{
    public const NS = 'sepo-dictionary/v1';

    public static function hook(): void
    {
        add_action('rest_api_init', [self::class, 'routes']);
    }

    public static function routes(): void
    {
        $member = static fn(): bool => is_user_logged_in();
        $editor = static fn(): bool => Plugin::canManage();

        register_rest_route(self::NS, '/search', ['methods' => 'GET', 'callback' => [self::class, 'search'], 'permission_callback' => '__return_true']);
        register_rest_route(self::NS, '/entry', [
            ['methods' => 'GET', 'callback' => [self::class, 'entry'], 'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => [self::class, 'saveEntry'], 'permission_callback' => $editor],
        ]);
        register_rest_route(self::NS, '/audio', ['methods' => 'POST', 'callback' => [self::class, 'audio'], 'permission_callback' => static fn(): bool => Plugin::canManage() && current_user_can('upload_files')]);
        register_rest_route(self::NS, '/entry/trash', ['methods' => 'POST', 'callback' => [self::class, 'trash'], 'permission_callback' => $editor]);
        register_rest_route(self::NS, '/propose', ['methods' => 'POST', 'callback' => [self::class, 'propose'], 'permission_callback' => static fn(): bool => Plugin::canPropose()]);
        register_rest_route(self::NS, '/vote', ['methods' => 'POST', 'callback' => [self::class, 'vote'], 'permission_callback' => static fn(): bool => Plugin::canVote()]);
        register_rest_route(self::NS, '/proposal/withdraw', ['methods' => 'POST', 'callback' => [self::class, 'withdraw'], 'permission_callback' => $member]);
        register_rest_route(self::NS, '/proposal/resubmit', ['methods' => 'POST', 'callback' => [self::class, 'resubmit'], 'permission_callback' => static fn(): bool => Plugin::canPropose()]);
        register_rest_route(self::NS, '/proposal/moderate', ['methods' => 'POST', 'callback' => [self::class, 'moderate'], 'permission_callback' => $editor]);
        register_rest_route(self::NS, '/history/restore', ['methods' => 'POST', 'callback' => [self::class, 'restore'], 'permission_callback' => $editor]);
    }

    /** @param array<string,mixed> $data */
    private static function ok(array $data = [], string $message = ''): \WP_REST_Response
    {
        return new \WP_REST_Response(['status' => 'success', 'message' => $message, 'data' => $data], 200);
    }

    private static function fail(string $message, int $status = 422, array $data = []): \WP_Error
    {
        return new \WP_Error('sepo_dictionary', $message, ['status' => $status] + $data);
    }

    private static function failFrom(\Throwable $e): \WP_Error
    {
        if ($e instanceof ProposalError) {
            return self::fail($e->getMessage(), 422, $e->data);
        }
        if ($e instanceof \InvalidArgumentException) {
            return self::fail($e->getMessage());
        }
        error_log('[sepo-dictionary] ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        return self::fail(__('Something went wrong. Please try again.', 'sepo-dictionary'), 500);
    }

    /** @return array<string,mixed> */
    private static function body(\WP_REST_Request $r): array
    {
        $b = $r->get_json_params();
        return is_array($b) ? $b : $r->get_params();
    }

    // ------------------------------------------------------------- reading

    public static function search(\WP_REST_Request $r): \WP_REST_Response
    {
        $q = Entry::line($r->get_param('q'), 100);
        $limit = max(1, min(20, (int) ($r->get_param('limit') ?: 8)));
        $posts = Entry::search($q, [
            'limit'    => $limit,
            'language' => sanitize_title((string) $r->get_param('language')),
            'manager'  => Plugin::canManage(),
        ]);
        $out = [];
        foreach ($posts as $p) {
            $s = Entry::get((int) $p->ID);
            if ($s === null) {
                continue;
            }
            $out[] = [
                'id'       => (int) $p->ID,
                'term'     => $s['term'],
                'url'      => Entry::url((int) $p->ID),
                'pos'      => $s['pos'],
                'language' => $s['language'],
                'snippet'  => Entry::snippet($s, 120),
                'status'   => $s['status'],
            ];
        }
        return self::ok(['results' => $out, 'count' => count($out)]);
    }

    public static function entry(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $id = (int) $r->get_param('id');
        if ($id < 1) {
            $post = get_page_by_path(sanitize_title((string) $r->get_param('slug')), OBJECT, PostType::TYPE);
            $id = $post instanceof \WP_Post ? (int) $post->ID : 0;
        }
        $s = $id > 0 ? Entry::get($id) : null;
        if ($s === null || ($s['status'] !== 'publish' && !Plugin::canManage())) {
            return self::fail(__('Not found.', 'sepo-dictionary'), 404);
        }
        return self::ok(['entry' => ['id' => $id, 'url' => Entry::url($id)] + $s]);
    }

    // ------------------------------------------------------------- editors

    public static function saveEntry(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $b = self::body($r);
        $id = (int) ($b['id'] ?? 0);
        $in = is_array($b['entry'] ?? null) ? $b['entry'] : [];
        try {
            if ($id > 0 && Entry::get($id) === null) {
                return self::fail(__('That entry could not be found.', 'sepo-dictionary'), 404);
            }
            $clean = Entry::clean($in, $id > 0 ? (Entry::get($id) ?? []) : []);
            $clash = Entry::find($clean['term'], $clean['language'], $id);
            if ($clash > 0) {
                return self::fail(__('Another entry already uses this spelling in this language.', 'sepo-dictionary'), 409, ['entry_url' => Entry::url($clash)]);
            }
            if ($clean['definitions'] === []) {
                return self::fail(__('Add at least one meaning.', 'sepo-dictionary'));
            }
            $saved = Entry::store($id ?: null, $clean, [
                'user_id'  => get_current_user_id(),
                'actor_id' => get_current_user_id(),
                'summary'  => Entry::line($b['summary'] ?? '', 255),
            ]);
        } catch (\Throwable $e) {
            return self::failFrom($e);
        }
        return self::ok(['id' => $saved, 'url' => Entry::url($saved)], $id > 0 ? __('Entry saved.', 'sepo-dictionary') : __('Word added.', 'sepo-dictionary'));
    }

    /**
     * A pronunciation recording: the uploaded file (or the browser's own
     * recording) goes into the media library. With an entry id it is set on
     * the entry at once, which records the change in the history; without
     * one, the dashboard editor keeps it until the entry is saved.
     * remove=1 takes the recording off the entry; the file stays in the library.
     */
    public static function audio(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $entryId = (int) $r->get_param('entry_id');
        $state = $entryId > 0 ? Entry::get($entryId) : null;
        if ($entryId > 0 && ($state === null || !current_user_can('edit_post', $entryId))) {
            return self::fail(__('That entry could not be found.', 'sepo-dictionary'), 404);
        }
        $ctx = ['user_id' => get_current_user_id(), 'actor_id' => get_current_user_id()];

        if ((string) $r->get_param('remove') === '1') {
            if ($state === null || (int) $state['audio'] < 1) {
                return self::fail(__('This entry has no recording.', 'sepo-dictionary'));
            }
            Entry::store($entryId, ['audio' => 0] + $state, $ctx + ['summary' => __('Recording removed', 'sepo-dictionary')]);
            return self::ok(['id' => 0, 'url' => ''], __('Recording removed.', 'sepo-dictionary'));
        }

        $files = $r->get_file_params();
        $file = $files['file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return self::fail(__('Choose or record a sound first.', 'sepo-dictionary'));
        }
        $maxMb = (int) apply_filters('sepo_dictionary_audio_max_mb', 8);
        if ((int) $file['size'] > $maxMb * MB_IN_BYTES) {
            /* translators: %d: size in megabytes */
            return self::fail(sprintf(__('The recording is too large. The limit is %d MB.', 'sepo-dictionary'), $maxMb));
        }
        $check = wp_check_filetype_and_ext((string) $file['tmp_name'], (string) $file['name']);
        $mime = (string) ($check['type'] ?: '');
        if ($mime === '' || !Entry::isAudio($mime)) {
            return self::fail(__('That file is not a sound file. Use MP3, M4A, OGG, WAV or WebM.', 'sepo-dictionary'));
        }
        // The file is named after the word so the media library stays readable.
        $base = sanitize_file_name(($state !== null ? sanitize_title($state['term']) : 'word') . '-pronunciation');
        $ext = (string) ($check['ext'] ?: pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $_FILES['sd_audio'] = ['name' => $base . '.' . $ext] + $file;

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $title = $state !== null
            /* translators: %s: the word */
            ? sprintf(__('Pronunciation of %s', 'sepo-dictionary'), $state['term'])
            : __('Pronunciation', 'sepo-dictionary');
        $id = media_handle_upload('sd_audio', $entryId, ['post_title' => $title], ['test_form' => false]);
        unset($_FILES['sd_audio']);
        if (is_wp_error($id)) {
            return self::fail($id->get_error_message());
        }
        if ($state !== null) {
            Entry::store($entryId, ['audio' => (int) $id] + $state, $ctx + [
                'summary' => (int) $state['audio'] > 0 ? __('Recording replaced', 'sepo-dictionary') : __('Recording added', 'sepo-dictionary'),
            ]);
        }
        return self::ok(['id' => (int) $id, 'url' => (string) wp_get_attachment_url((int) $id)], __('Recording saved.', 'sepo-dictionary'));
    }

    public static function trash(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $id = (int) (self::body($r)['id'] ?? 0);
        if (Entry::get($id) === null) {
            return self::fail(__('That entry could not be found.', 'sepo-dictionary'), 404);
        }
        if (!current_user_can('delete_post', $id) || !wp_trash_post($id)) {
            return self::fail(__('The entry could not be moved to the trash.', 'sepo-dictionary'), 403);
        }
        return self::ok(['url' => Settings::indexUrl()], __('Moved to the trash.', 'sepo-dictionary'));
    }

    public static function moderate(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $b = self::body($r);
        $p = Proposals::get((int) ($b['id'] ?? 0));
        if ($p === null) {
            return self::fail(__('That suggestion could not be found.', 'sepo-dictionary'), 404);
        }
        $action = (string) ($b['action'] ?? '');
        $note = Entry::line($b['note'] ?? '', 255);
        try {
            if ($action === 'accept') {
                $res = Proposals::accept($p, 'moderator', get_current_user_id(), $note);
                return self::ok($res + ['url' => $res['entry_id'] ? Entry::url($res['entry_id']) : ''], __('Merged into the dictionary.', 'sepo-dictionary'));
            }
            if ($action === 'reject') {
                Proposals::reject($p, get_current_user_id(), $note);
                return self::ok(['status' => 'rejected'], __('Suggestion declined.', 'sepo-dictionary'));
            }
            if ($action === 'delete') {
                Proposals::delete((int) $p['id']);
                return self::ok(['status' => 'deleted'], __('Suggestion deleted.', 'sepo-dictionary'));
            }
        } catch (\Throwable $e) {
            return self::failFrom($e);
        }
        return self::fail(__('Unknown action.', 'sepo-dictionary'));
    }

    public static function restore(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        try {
            $id = History::restore((int) (self::body($r)['id'] ?? 0), get_current_user_id());
        } catch (\Throwable $e) {
            return self::failFrom($e);
        }
        return self::ok(['id' => $id, 'url' => Entry::url($id)], __('Version restored.', 'sepo-dictionary'));
    }

    // ------------------------------------------------------------- members

    public static function propose(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        try {
            $p = Proposals::create(self::body($r), get_current_user_id());
        } catch (\Throwable $e) {
            return self::failFrom($e);
        }
        return self::ok(['proposal' => self::brief($p)], __('Thank you. Your suggestion is open for votes.', 'sepo-dictionary'));
    }

    public static function vote(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        $b = self::body($r);
        $id = (int) ($b['id'] ?? 0);
        try {
            $p = Proposals::vote($id, get_current_user_id(), (int) ($b['value'] ?? 0), (string) ($b['note'] ?? ''));
        } catch (\Throwable $e) {
            return self::failFrom($e);
        }
        return self::ok(['proposal' => self::brief($p)]);
    }

    public static function withdraw(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        try {
            Proposals::withdraw((int) (self::body($r)['id'] ?? 0), get_current_user_id());
        } catch (\Throwable $e) {
            return self::failFrom($e);
        }
        return self::ok([], __('Suggestion withdrawn.', 'sepo-dictionary'));
    }

    public static function resubmit(\WP_REST_Request $r): \WP_REST_Response|\WP_Error
    {
        try {
            $p = Proposals::resubmit((int) (self::body($r)['id'] ?? 0), get_current_user_id());
        } catch (\Throwable $e) {
            return self::failFrom($e);
        }
        return self::ok(['proposal' => self::brief($p)], __('Your suggestion is open for votes again.', 'sepo-dictionary'));
    }

    /**
     * What the page needs to redraw a proposal's vote controls.
     *
     * @return array<string,mixed>
     */
    public static function brief(array $p): array
    {
        $mine = Proposals::votesBy([(int) $p['id']], get_current_user_id());
        $needs = Proposals::needs($p);
        return [
            'id'          => (int) $p['id'],
            'status'      => (string) $p['status'],
            'statusLabel' => Proposals::statusLabel((string) $p['status']),
            'up'          => (int) $p['up'],
            'down'        => (int) $p['down'],
            'net'         => (int) $p['net'],
            'mine'        => $mine[(int) $p['id']] ?? 0,
            'needs'       => $needs,
            'needsText'   => Render\ProposalView::needsText($p),
            'entry_url'   => $p['entry_id'] > 0 ? Entry::url((int) $p['entry_id']) : '',
            'note'        => (string) $p['close_note'],
        ];
    }
}
