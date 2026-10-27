<?php

declare(strict_types=1);

namespace SepoDictionary\Admin;

use SepoDictionary\Dictionaries;
use SepoDictionary\Entry;
use SepoDictionary\Plugin;
use SepoDictionary\PostType;
use SepoDictionary\Proposals;
use SepoDictionary\Settings;

defined('ABSPATH') || exit;

/**
 * Dictionary → Dictionaries: WordPress's own term screens, with the
 * dictionary's address, its editors and the settings it changes for itself.
 */
final class DictionaryScreen
{
    private const NONCE = 'sdict_dictionary_meta';

    public static function hook(): void
    {
        $tax = Dictionaries::TAX;
        add_action($tax . '_add_form_fields', [self::class, 'addFields']);
        add_action($tax . '_edit_form_fields', [self::class, 'editFields']);
        add_action('created_' . $tax, [self::class, 'save']);
        add_action('edited_' . $tax, [self::class, 'save']);
        add_filter('manage_edit-' . $tax . '_columns', [self::class, 'columns']);
        add_filter('manage_' . $tax . '_custom_column', [self::class, 'column'], 10, 3);
        add_filter($tax . '_row_actions', [self::class, 'rowActions'], 10, 2);
        add_action('admin_enqueue_scripts', static function (): void {
            $screen = get_current_screen();
            if ($screen && $screen->taxonomy === Dictionaries::TAX) {
                wp_enqueue_style('sepo-dictionary-admin', SDICT_URL . 'assets/css/admin.css', [], Plugin::assetVersion('assets/css/admin.css'));
            }
        });
        // A filter on the words list: one dictionary at a time.
        add_action('restrict_manage_posts', [self::class, 'listFilter']);
        add_action('pre_get_posts', static function (\WP_Query $q): void {
            if (!is_admin() || !$q->is_main_query() || $q->get('post_type') !== PostType::TYPE || empty($_GET[Dictionaries::TAX])) { // phpcs:ignore WordPress.Security.NonceVerification
                return;
            }
            $slug = sanitize_title(wp_unslash((string) $_GET[Dictionaries::TAX])); // phpcs:ignore WordPress.Security.NonceVerification
            $q->set('tax_query', [['taxonomy' => Dictionaries::TAX, 'field' => 'slug', 'terms' => [$slug]]]);
        });
        add_action('admin_notices', [self::class, 'notice']);
    }

    /** @param array<string,string> $cols @return array<string,string> */
    public static function columns(array $cols): array
    {
        $out = [];
        foreach ($cols as $k => $v) {
            if ($k === 'posts') {
                $out['sdict_words'] = __('Words', 'sepo-dictionary');
                $out['sdict_editors'] = __('Editors', 'sepo-dictionary');
                $out['sdict_own'] = __('Own settings', 'sepo-dictionary');
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    public static function column(string $html, string $col, int $termId): string
    {
        if ($col === 'sdict_words') {
            $n = Entry::total('', $termId);
            return '<a href="' . esc_url(admin_url('edit.php?post_type=' . PostType::TYPE . '&' . Dictionaries::TAX . '=' . Dictionaries::slug($termId))) . '">' . esc_html(number_format_i18n($n)) . '</a>';
        }
        if ($col === 'sdict_editors') {
            $names = [];
            foreach (Dictionaries::editors($termId) as $uid) {
                $u = get_userdata($uid);
                if ($u) {
                    $names[] = esc_html($u->display_name);
                }
            }
            return $names !== [] ? implode(', ', $names) : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__('Site editors only', 'sepo-dictionary') . '</span>';
        }
        if ($col === 'sdict_own') {
            $n = count(Dictionaries::overrides($termId));
            /* translators: %d: number of settings */
            return $n > 0 ? esc_html(sprintf(_n('%d setting', '%d settings', $n, 'sepo-dictionary'), $n)) : esc_html__('Site settings', 'sepo-dictionary');
        }
        return $html;
    }

    /** @param array<string,string> $actions @return array<string,string> */
    public static function rowActions(array $actions, \WP_Term $term): array
    {
        unset($actions['view']);
        $actions['sdict_open'] = '<a href="' . esc_url(Dictionaries::homeUrl((int) $term->term_id)) . '">' . esc_html__('Open on the site', 'sepo-dictionary') . '</a>';
        if ((int) $term->term_id === Dictionaries::defaultId()) {
            $actions['sdict_default'] = '<span class="sdict-admin-default">' . esc_html__('Default', 'sepo-dictionary') . '</span>';
        }
        return $actions;
    }

    // ------------------------------------------------------------- forms

    public static function addFields(): void
    {
        wp_nonce_field(self::NONCE, 'sdict_dict_nonce');
        echo '<div class="form-field"><label for="sdict-editors">' . esc_html__('Editors of this dictionary', 'sepo-dictionary') . '</label>'
            . '<textarea id="sdict-editors" name="sdict_editors" rows="3" placeholder="' . esc_attr__('username or email, one per line', 'sepo-dictionary') . '"></textarea>'
            . '<p>' . esc_html(self::editorsHelp()) . '</p></div>';
        echo '<p class="description">' . esc_html__('After adding the dictionary, open it to give it settings of its own, such as its voting rules or accent colour.', 'sepo-dictionary') . '</p>';
    }

    public static function editFields(\WP_Term $term): void
    {
        $id = (int) $term->term_id;
        $own = Dictionaries::overrides($id);
        $site = Settings::all();
        wp_nonce_field(self::NONCE, 'sdict_dict_nonce');

        $url = Dictionaries::homeUrl($id);
        echo '<tr class="form-field"><th scope="row">' . esc_html__('Address', 'sepo-dictionary') . '</th><td>'
            . '<a href="' . esc_url($url) . '">' . esc_html($url) . '</a>'
            . '<p class="description">' . esc_html__('Words in this dictionary live under this address. Changing the slug changes it; old links redirect.', 'sepo-dictionary') . '</p>';
        if ((int) Settings::get('index_page') < 1 || get_post_status((int) Settings::get('index_page')) !== 'publish') {
            echo '<p class="sdict-admin-warn">' . esc_html__('Choose the dictionary page under Dictionary → Settings so this address can be shown.', 'sepo-dictionary') . '</p>';
        }
        echo '</td></tr>';

        $default = $id === Dictionaries::defaultId();
        echo '<tr class="form-field"><th scope="row">' . esc_html__('Default dictionary', 'sepo-dictionary') . '</th><td>';
        if ($default) {
            echo '<p>' . esc_html__('This is the default dictionary. Words added without a dictionary go here, and it cannot be deleted.', 'sepo-dictionary') . '</p>';
        } else {
            echo '<label><input type="checkbox" name="sdict_make_default" value="1"> ' . esc_html__('Make this the default dictionary', 'sepo-dictionary') . '</label>';
        }
        echo '</td></tr>';

        $lines = [];
        foreach (Dictionaries::editors($id) as $uid) {
            $u = get_userdata($uid);
            if ($u) {
                $lines[] = $u->user_login;
            }
        }
        echo '<tr class="form-field"><th scope="row"><label for="sdict-editors">' . esc_html__('Editors of this dictionary', 'sepo-dictionary') . '</label></th><td>'
            . '<textarea id="sdict-editors" name="sdict_editors" rows="3" placeholder="' . esc_attr__('username or email, one per line', 'sepo-dictionary') . '">' . esc_textarea(implode("\n", $lines)) . '</textarea>'
            . '<p class="description">' . esc_html(self::editorsHelp()) . '</p>';
        $missing = get_term_meta($id, 'sdict_editors_missing', true);
        if (is_array($missing) && $missing !== []) {
            /* translators: %s: names that were typed */
            echo '<p class="sdict-admin-warn">' . esc_html(sprintf(__('No member was found for: %s', 'sepo-dictionary'), implode(', ', array_map('strval', $missing)))) . '</p>';
            delete_term_meta($id, 'sdict_editors_missing');
        }
        echo '</td></tr>';

        echo '<tr><th colspan="2" scope="rowgroup"><h2 class="sdict-admin-h2">' . esc_html__('Settings for this dictionary', 'sepo-dictionary') . '</h2>'
            . '<p class="description sdict-admin-lead">' . esc_html__('Leave a setting on "Site setting" to follow Dictionary → Settings. Anything you set here applies to this dictionary only.', 'sepo-dictionary') . '</p></th></tr>';

        self::colour('accent', __('Accent colour', 'sepo-dictionary'), $own, (string) $site['accent'], __('Buttons, the selected letter and highlights on this dictionary\'s pages.', 'sepo-dictionary'));
        self::number('per_page', __('Words per page', 'sepo-dictionary'), $own, (int) $site['per_page']);
        self::toggle('word_of_day', __('Word of the day', 'sepo-dictionary'), $own, (int) $site['word_of_day']);
        $pos = (string) ($own['parts_of_speech'] ?? '');
        echo '<tr class="form-field"><th scope="row"><label for="sdict-o-pos">' . esc_html__('Parts of speech', 'sepo-dictionary') . '</label></th><td>'
            . '<textarea id="sdict-o-pos" name="sdict_o[parts_of_speech]" rows="4" class="code" placeholder="' . esc_attr(implode("\n", Settings::partsOfSpeech())) . '">' . esc_textarea($pos) . '</textarea>'
            . '<p class="description">' . esc_html__('One per line. Leave empty to use the site\'s list, shown faintly.', 'sepo-dictionary') . '</p></td></tr>';

        echo '<tr><th colspan="2" scope="rowgroup"><h3 class="sdict-admin-h3">' . esc_html__('Community review', 'sepo-dictionary') . '</h3></th></tr>';
        self::toggle('allow_proposals', __('Suggestions', 'sepo-dictionary'), $own, (int) $site['allow_proposals'], __('Signed-in members may suggest words, meanings and corrections here.', 'sepo-dictionary'));
        self::number('votes_needed', __('Votes needed', 'sepo-dictionary'), $own, (int) $site['votes_needed']);
        self::number('vote_margin', __('Lead needed', 'sepo-dictionary'), $own, (int) $site['vote_margin']);
        self::number('reject_at', __('Decline when behind by', 'sepo-dictionary'), $own, (int) $site['reject_at']);
        self::number('voting_days', __('Voting period (days)', 'sepo-dictionary'), $own, (int) $site['voting_days'], __('0 keeps voting open until a suggestion is accepted or declined.', 'sepo-dictionary'));
        self::toggle('settle_on_close', __('When voting closes', 'sepo-dictionary'), $own, (int) $site['settle_on_close'], __('Accept the leading suggestion if more members found it accurate than not.', 'sepo-dictionary'));
        self::toggle('review', __('Editor check', 'sepo-dictionary'), $own, (int) $site['review'], __('Suggestions that win the vote wait for an editor before they are merged.', 'sepo-dictionary'));
        self::number('max_open', __('Open suggestions per member', 'sepo-dictionary'), $own, (int) $site['max_open'], __('0 for no limit. Counted in this dictionary only.', 'sepo-dictionary'));

        echo '<tr><th colspan="2" scope="rowgroup"><h3 class="sdict-admin-h3">' . esc_html__('Email', 'sepo-dictionary') . '</h3></th></tr>';
        $mail = (string) ($own['notify_email'] ?? '');
        $siteMail = (string) $site['notify_email'] !== '' ? (string) $site['notify_email'] : (string) get_option('admin_email');
        echo '<tr class="form-field"><th scope="row"><label for="sdict-o-mail">' . esc_html__('Editors\' address', 'sepo-dictionary') . '</label></th><td>'
            . '<input type="email" id="sdict-o-mail" name="sdict_o[notify_email]" value="' . esc_attr($mail) . '" placeholder="' . esc_attr($siteMail) . '">'
            . '<p class="description">' . esc_html__('Where to send this dictionary\'s suggestions that need an editor. Its own editors are emailed too.', 'sepo-dictionary') . '</p></td></tr>';
        self::toggle('notify_new', __('New suggestions', 'sepo-dictionary'), $own, (int) $site['notify_new'], __('Also email editors about every new suggestion in this dictionary.', 'sepo-dictionary'));

        $c = Proposals::counts($id);
        /* translators: 1: open suggestions, 2: suggestions waiting for an editor */
        echo '<tr><th scope="row">' . esc_html__('Suggestions', 'sepo-dictionary') . '</th><td><a href="' . esc_url(Screens::url(['dict' => $id])) . '">' . esc_html(sprintf(__('%1$d open for votes, %2$d waiting for an editor', 'sepo-dictionary'), $c['open'], $c['passed'])) . '</a></td></tr>';
    }

    private static function editorsHelp(): string
    {
        return __('These members can add and change words, record pronunciations, settle suggestions and restore history in this dictionary only, from its pages on the site. The site\'s editors can always manage every dictionary.', 'sepo-dictionary');
    }

    /** @param array<string,mixed> $own */
    private static function toggle(string $key, string $label, array $own, int $site, string $help = ''): void
    {
        $v = array_key_exists($key, $own) ? (string) (int) $own[$key] : 'site';
        $siteLabel = $site === 1 ? __('Site setting (on)', 'sepo-dictionary') : __('Site setting (off)', 'sepo-dictionary');
        echo '<tr class="form-field"><th scope="row"><label for="sdict-o-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>'
            . '<select id="sdict-o-' . esc_attr($key) . '" name="sdict_o[' . esc_attr($key) . ']">'
            . '<option value="site"' . selected($v, 'site', false) . '>' . esc_html($siteLabel) . '</option>'
            . '<option value="1"' . selected($v, '1', false) . '>' . esc_html__('On', 'sepo-dictionary') . '</option>'
            . '<option value="0"' . selected($v, '0', false) . '>' . esc_html__('Off', 'sepo-dictionary') . '</option>'
            . '</select>' . ($help !== '' ? '<p class="description">' . esc_html($help) . '</p>' : '') . '</td></tr>';
    }

    /** @param array<string,mixed> $own */
    private static function number(string $key, string $label, array $own, int $site, string $help = ''): void
    {
        [$min, $max] = Dictionaries::LIMITS[$key];
        $v = array_key_exists($key, $own) ? (string) (int) $own[$key] : '';
        /* translators: %d: the site-wide value */
        $ph = sprintf(__('Site: %d', 'sepo-dictionary'), $site);
        echo '<tr class="form-field"><th scope="row"><label for="sdict-o-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>'
            . '<input type="number" class="small-text sdict-admin-num" id="sdict-o-' . esc_attr($key) . '" name="sdict_o[' . esc_attr($key) . ']" value="' . esc_attr($v) . '" min="' . $min . '" max="' . $max . '" placeholder="' . esc_attr($ph) . '">'
            /* translators: %d: the site-wide value */
            . '<p class="description">' . esc_html(($help !== '' ? $help . ' ' : '') . sprintf(__('Empty follows the site setting (%d).', 'sepo-dictionary'), $site)) . '</p></td></tr>';
    }

    /** @param array<string,mixed> $own */
    private static function colour(string $key, string $label, array $own, string $site, string $help): void
    {
        $v = (string) ($own[$key] ?? '');
        echo '<tr class="form-field"><th scope="row"><label for="sdict-o-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td>'
            . '<input type="text" id="sdict-o-' . esc_attr($key) . '" name="sdict_o[' . esc_attr($key) . ']" value="' . esc_attr($v) . '" placeholder="' . esc_attr($site !== '' ? $site : '#0b3d33') . '" pattern="#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?" class="regular-text">'
            . '<p class="description">' . esc_html($help . ' ' . __('Leave empty for the site\'s colour.', 'sepo-dictionary')) . '</p></td></tr>';
    }

    // ------------------------------------------------------------- saving

    public static function save(int $termId): void
    {
        if (!isset($_POST['sdict_dict_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['sdict_dict_nonce'])), self::NONCE)) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        if (isset($_POST['sdict_editors'])) {
            $found = [];
            $missing = [];
            foreach (preg_split('~[\r\n,]+~', (string) wp_unslash($_POST['sdict_editors'])) ?: [] as $line) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each line is looked up as a user, nothing is stored as typed
                $line = trim(sanitize_text_field($line));
                if ($line === '') {
                    continue;
                }
                $u = is_email($line) ? get_user_by('email', $line) : get_user_by('login', $line);
                if (!$u) {
                    $u = get_user_by('slug', sanitize_title($line));
                }
                if ($u) {
                    $found[] = (int) $u->ID;
                } else {
                    $missing[] = $line;
                }
            }
            Dictionaries::saveEditors($termId, $found);
            if ($missing !== []) {
                update_term_meta($termId, 'sdict_editors_missing', array_slice($missing, 0, 20));
            }
        }
        if (isset($_POST['sdict_o']) && is_array($_POST['sdict_o'])) {
            Dictionaries::saveOverrides($termId, wp_unslash($_POST['sdict_o'])); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- cleaned by cleanOverrides
        }
        if (!empty($_POST['sdict_make_default'])) {
            update_option('default_term_' . Dictionaries::TAX, $termId);
        }
    }

    // ------------------------------------------------------------- the words list

    public static function listFilter(string $postType): void
    {
        if ($postType !== PostType::TYPE || !Dictionaries::many()) {
            return;
        }
        $current = isset($_GET[Dictionaries::TAX]) ? sanitize_title(wp_unslash((string) $_GET[Dictionaries::TAX])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        echo '<label class="screen-reader-text" for="sdict-filter-dict">' . esc_html__('Filter by dictionary', 'sepo-dictionary') . '</label>'
            . '<select name="' . esc_attr(Dictionaries::TAX) . '" id="sdict-filter-dict"><option value="">' . esc_html__('All dictionaries', 'sepo-dictionary') . '</option>';
        foreach (Dictionaries::all() as $t) {
            echo '<option value="' . esc_attr($t->slug) . '"' . selected($current, $t->slug, false) . '>' . esc_html($t->name) . '</option>';
        }
        echo '</select>';
    }

    /** A short explanation above the list of dictionaries. */
    public static function notice(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->taxonomy !== Dictionaries::TAX || $screen->base !== 'edit-tags') {
            return;
        }
        echo '<div class="sdict-notice-tint sdict-admin-note"><p>' . esc_html__('Each dictionary has its own page and its own words; the same word can be in more than one. With two or more dictionaries, the dictionary page lists them all.', 'sepo-dictionary') . '</p></div>';
    }
}
