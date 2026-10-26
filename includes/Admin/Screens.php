<?php

declare(strict_types=1);

namespace SepoDictionary\Admin;

use SepoDictionary\Entry;
use SepoDictionary\Frontend;
use SepoDictionary\Plugin;
use SepoDictionary\PostType;
use SepoDictionary\Proposals;
use SepoDictionary\Render\Parts;
use SepoDictionary\Render\ProposalView;
use SepoDictionary\Settings;

defined('ABSPATH') || exit;

/** Dictionary → Suggestions and Dictionary → Settings. */
final class Screens
{
    public const PROPOSALS = 'sepo-dictionary-proposals';
    public const SETTINGS = 'sepo-dictionary-settings';

    public static function hook(): void
    {
        add_action('admin_menu', static function (): void {
            $parent = 'edit.php?post_type=' . PostType::TYPE;
            $cap = (string) apply_filters('sepo_dictionary_manage_capability', 'edit_others_posts');
            $c = Proposals::counts();
            $label = __('Suggestions', 'sepo-dictionary') . ($c['passed'] > 0 ? ' <span class="awaiting-mod">' . (int) $c['passed'] . '</span>' : '');
            add_submenu_page($parent, __('Suggestions', 'sepo-dictionary'), $label, $cap, self::PROPOSALS, [self::class, 'proposals']);
            add_submenu_page($parent, __('Dictionary settings', 'sepo-dictionary'), __('Settings', 'sepo-dictionary'), 'manage_options', self::SETTINGS, [self::class, 'settings']);
        });
        add_action('admin_post_sepo_dictionary_settings', [self::class, 'save']);
        add_action('admin_enqueue_scripts', static function (): void {
            $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
            if ($page === self::PROPOSALS) {
                Frontend::enqueue();
            }
        });
        add_filter('admin_body_class', static function (string $c): string {
            $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
            return $page === self::PROPOSALS ? $c . ' sdict-admin-page' : $c;
        });
        add_filter('plugin_action_links_' . plugin_basename(SDICT_FILE), static function (array $links): array {
            array_unshift($links, '<a href="' . esc_url(admin_url('edit.php?post_type=' . PostType::TYPE . '&page=' . self::SETTINGS)) . '">' . esc_html__('Settings', 'sepo-dictionary') . '</a>');
            return $links;
        });
    }

    public static function url(array $args = []): string
    {
        return add_query_arg(array_merge(['post_type' => PostType::TYPE, 'page' => self::PROPOSALS], $args), admin_url('edit.php'));
    }

    /** The suggestions queue, with the same cards and actions as the site. */
    public static function proposals(): void
    {
        if (!Plugin::canManage()) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- read-only filters
        $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : 'live';
        $kind = isset($_GET['kind']) ? sanitize_key((string) $_GET['kind']) : '';
        $entry = isset($_GET['entry']) ? absint($_GET['entry']) : 0;
        $q = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        // phpcs:enable
        $tabs = [
            'live'     => [__('Waiting', 'sepo-dictionary'), Proposals::LIVE],
            'passed'   => [__('Passed, waiting for you', 'sepo-dictionary'), ['passed']],
            'accepted' => [__('Accepted', 'sepo-dictionary'), ['accepted']],
            'closed'   => [__('Declined or closed', 'sepo-dictionary'), ['rejected', 'expired', 'superseded', 'withdrawn']],
        ];
        if (!isset($tabs[$status])) {
            $status = 'live';
        }
        $counts = Proposals::counts();
        $res = Proposals::query(['status' => $tabs[$status][1], 'kind' => $kind, 'entry_id' => $entry, 'q' => $q, 'page' => $page, 'per_page' => 20, 'order' => $status === 'live' ? 'top' : 'closed']);

        echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__('Suggestions', 'sepo-dictionary') . '</h1>';
        if ($entry > 0 && ($s = Entry::get($entry)) !== null) {
            /* translators: %s: the word */
            echo ' <span class="subtitle">' . esc_html(sprintf(__('for "%s"', 'sepo-dictionary'), $s['term'])) . ' · <a href="' . esc_url(self::url(['status' => $status])) . '">' . esc_html__('Show all', 'sepo-dictionary') . '</a></span>';
        }
        echo '<hr class="wp-header-end"><ul class="subsubsub">';
        $i = 0;
        foreach ($tabs as $key => [$label, $sts]) {
            $n = array_sum(array_map(static fn(string $x): int => $counts[$x] ?? 0, $sts));
            echo ($i++ > 0 ? ' | ' : '') . '<li><a href="' . esc_url(self::url(['status' => $key, 'entry' => $entry ?: null])) . '"' . ($status === $key ? ' class="current" aria-current="page"' : '') . '>' . esc_html($label) . ' <span class="count">(' . (int) $n . ')</span></a></li>';
        }
        echo '</ul>';
        echo '<form method="get" class="search-box" style="margin-top:8px"><input type="hidden" name="post_type" value="' . esc_attr(PostType::TYPE) . '"><input type="hidden" name="page" value="' . esc_attr(self::PROPOSALS) . '"><input type="hidden" name="status" value="' . esc_attr($status) . '">'
            . '<select name="kind"><option value="">' . esc_html__('All kinds', 'sepo-dictionary') . '</option>';
        foreach (['new' => __('New words', 'sepo-dictionary'), 'meaning' => __('Meanings', 'sepo-dictionary'), 'edit' => __('Corrections', 'sepo-dictionary')] as $k => $label) {
            echo '<option value="' . esc_attr($k) . '"' . selected($kind, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select> <label class="screen-reader-text" for="sdict-ps">' . esc_html__('Search suggestions', 'sepo-dictionary') . '</label><input type="search" id="sdict-ps" name="s" value="' . esc_attr($q) . '" placeholder="' . esc_attr__('Word', 'sepo-dictionary') . '"> '
            . '<button class="button">' . esc_html__('Filter', 'sepo-dictionary') . '</button></form><br class="clear">';

        $inner = '<p class="sdict-rules">' . esc_html(ProposalView::rules()) . ' <a href="' . esc_url(admin_url('edit.php?post_type=' . PostType::TYPE . '&page=' . self::SETTINGS)) . '">' . esc_html__('Change the rules', 'sepo-dictionary') . '</a></p>';
        $inner .= $res['rows'] === []
            ? '<div class="sdict-empty"><p>' . esc_html__('Nothing here.', 'sepo-dictionary') . '</p></div>'
            : ProposalView::list($res['rows'], ['showTerm' => true]);
        $pages = (int) ceil($res['total'] / 20);
        if ($pages > 1) {
            $inner .= Parts::pager(self::url(['status' => $status, 'kind' => $kind, 'entry' => $entry ?: null, 's' => $q]), [], $page, $pages);
            $inner = str_replace('sd_page=', 'paged=', $inner);
        }
        echo Parts::scope($inner, 'sdict-app', ['data-sdict-app' => '1']); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
        echo '</div>';
    }

    public static function settings(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $s = Settings::all();
        $num = static function (string $name, $value, int $min, int $max): string {
            return '<input type="number" class="small-text" name="' . esc_attr($name) . '" id="sdict-' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" min="' . $min . '" max="' . $max . '">';
        };
        $check = static function (string $name, $value, string $label): string {
            return '<label><input type="checkbox" name="' . esc_attr($name) . '" value="1"' . checked((int) $value, 1, false) . '> ' . esc_html($label) . '</label>';
        };
        ?>
        <div class="wrap sdict-settings">
          <h1><?php esc_html_e('Dictionary settings', 'sepo-dictionary'); ?></h1>
          <?php if (isset($_GET['updated'])) : // phpcs:ignore WordPress.Security.NonceVerification ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'sepo-dictionary'); ?></p></div>
          <?php endif; ?>
          <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sepo_dictionary_settings">
            <?php wp_nonce_field('sepo_dictionary_settings'); ?>

            <h2><?php esc_html_e('Look and pages', 'sepo-dictionary'); ?></h2>
            <table class="form-table" role="presentation">
              <tr><th scope="row"><label for="sdict-accent"><?php esc_html_e('Accent colour', 'sepo-dictionary'); ?></label></th>
                <td><input type="text" id="sdict-accent" name="accent" value="<?php echo esc_attr((string) $s['accent']); ?>" placeholder="#0b3d33" class="regular-text" pattern="#?[0-9a-fA-F]{3,6}">
                  <p class="description"><?php esc_html_e('Buttons, the selected letter and highlights. Leave empty for the default deep green.', 'sepo-dictionary'); ?></p></td></tr>
              <tr><th scope="row"><label for="sdict-index"><?php esc_html_e('Dictionary page', 'sepo-dictionary'); ?></label></th>
                <td><?php wp_dropdown_pages(['name' => 'index_page', 'id' => 'sdict-index', 'selected' => (int) $s['index_page'], 'show_option_none' => esc_html__('— None —', 'sepo-dictionary'), 'option_none_value' => '0']); ?>
                  <p class="description"><?php esc_html_e('The page with the [sepo_dictionary] shortcode or the Dictionary block. Entry pages link back to it.', 'sepo-dictionary'); ?></p></td></tr>
              <tr><th scope="row"><label for="sdict-per_page"><?php esc_html_e('Words per page', 'sepo-dictionary'); ?></label></th>
                <td><?php echo $num('per_page', $s['per_page'], 6, 120); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
              <tr><th scope="row"><?php esc_html_e('Word of the day', 'sepo-dictionary'); ?></th>
                <td><?php echo $check('word_of_day', $s['word_of_day'], __('Show a featured word at the top of the dictionary, changing every day', 'sepo-dictionary')); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
              <tr><th scope="row"><label for="sdict-pos"><?php esc_html_e('Parts of speech', 'sepo-dictionary'); ?></label></th>
                <td><textarea id="sdict-pos" name="parts_of_speech" rows="5" class="large-text code" style="max-width:360px"><?php echo esc_textarea((string) $s['parts_of_speech']); ?></textarea>
                  <p class="description"><?php esc_html_e('One per line. Offered as suggestions in the forms.', 'sepo-dictionary'); ?></p></td></tr>
            </table>

            <h2><?php esc_html_e('Community review', 'sepo-dictionary'); ?></h2>
            <p class="sdict-rules-preview"><?php echo esc_html(ProposalView::rules()); ?></p>
            <table class="form-table" role="presentation">
              <tr><th scope="row"><?php esc_html_e('Suggestions', 'sepo-dictionary'); ?></th>
                <td><?php echo $check('allow_proposals', $s['allow_proposals'], __('Signed-in members may suggest words, meanings and corrections', 'sepo-dictionary')); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                  <p class="description"><?php esc_html_e('Editors can always add and change entries directly. Members can always vote.', 'sepo-dictionary'); ?></p></td></tr>
              <tr><th scope="row"><label for="sdict-votes_needed"><?php esc_html_e('Votes needed', 'sepo-dictionary'); ?></label></th>
                <td><?php echo $num('votes_needed', $s['votes_needed'], 1, 100); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                  <p class="description"><?php esc_html_e('How many members must vote that a suggestion is accurate before it can be accepted.', 'sepo-dictionary'); ?></p></td></tr>
              <tr><th scope="row"><label for="sdict-vote_margin"><?php esc_html_e('Lead needed', 'sepo-dictionary'); ?></label></th>
                <td><?php echo $num('vote_margin', $s['vote_margin'], 0, 100); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                  <p class="description"><?php esc_html_e('How many more "accurate" than "not accurate" votes it needs. When several suggestions compete for the same thing, the one with the most support wins.', 'sepo-dictionary'); ?></p></td></tr>
              <tr><th scope="row"><label for="sdict-reject_at"><?php esc_html_e('Decline when behind by', 'sepo-dictionary'); ?></label></th>
                <td><?php echo $num('reject_at', $s['reject_at'], 1, 100); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
              <tr><th scope="row"><label for="sdict-voting_days"><?php esc_html_e('Voting period (days)', 'sepo-dictionary'); ?></label></th>
                <td><?php echo $num('voting_days', $s['voting_days'], 0, 365); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                  <p class="description"><?php esc_html_e('0 keeps voting open until a suggestion is accepted or declined.', 'sepo-dictionary'); ?></p>
                  <p><?php echo $check('settle_on_close', $s['settle_on_close'], __('When voting closes, accept the leading suggestion if more members found it accurate than not', 'sepo-dictionary')); // phpcs:ignore WordPress.Security.EscapeOutput ?></p></td></tr>
              <tr><th scope="row"><?php esc_html_e('Editor check', 'sepo-dictionary'); ?></th>
                <td><?php echo $check('review', $s['review'], __('Suggestions that win the vote wait for an editor before they are merged', 'sepo-dictionary')); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                  <p class="description"><?php esc_html_e('Either way, a suggestion whose entry changed after it was made always goes to an editor rather than overwriting the newer text.', 'sepo-dictionary'); ?></p></td></tr>
              <tr><th scope="row"><label for="sdict-max_open"><?php esc_html_e('Open suggestions per member', 'sepo-dictionary'); ?></label></th>
                <td><?php echo $num('max_open', $s['max_open'], 0, 500); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                  <p class="description"><?php esc_html_e('0 for no limit.', 'sepo-dictionary'); ?></p></td></tr>
            </table>

            <h2><?php esc_html_e('Email', 'sepo-dictionary'); ?></h2>
            <table class="form-table" role="presentation">
              <tr><th scope="row"><?php esc_html_e('Members', 'sepo-dictionary'); ?></th>
                <td><?php echo $check('notify_authors', $s['notify_authors'], __('Email members when their suggestion is accepted, declined or closed', 'sepo-dictionary')); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
              <tr><th scope="row"><label for="sdict-notify"><?php esc_html_e('Editors', 'sepo-dictionary'); ?></label></th>
                <td><input type="email" id="sdict-notify" name="notify_email" value="<?php echo esc_attr((string) $s['notify_email']); ?>" class="regular-text" placeholder="<?php echo esc_attr((string) get_option('admin_email')); ?>">
                  <p class="description"><?php esc_html_e('Where to send suggestions that need an editor. Empty uses the site admin address.', 'sepo-dictionary'); ?></p>
                  <p><?php echo $check('notify_new', $s['notify_new'], __('Also email editors about every new suggestion', 'sepo-dictionary')); // phpcs:ignore WordPress.Security.EscapeOutput ?></p></td></tr>
            </table>

            <h2><?php esc_html_e('Data', 'sepo-dictionary'); ?></h2>
            <table class="form-table" role="presentation">
              <tr><th scope="row"><?php esc_html_e('When deleting the plugin', 'sepo-dictionary'); ?></th>
                <td><?php echo $check('delete_on_uninstall', $s['delete_on_uninstall'], __('Delete all entries, suggestions, votes and history', 'sepo-dictionary')); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
            </table>
            <?php submit_button(); ?>
          </form>
        </div>
        <?php
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to change these settings.', 'sepo-dictionary'), 403);
        }
        check_admin_referer('sepo_dictionary_settings');
        $p = wp_unslash($_POST);
        $accent = trim((string) ($p['accent'] ?? ''));
        if ($accent !== '' && $accent[0] !== '#') {
            $accent = '#' . $accent;
        }
        $int = static fn(string $k, int $min, int $max): int => max($min, min($max, (int) ($p[$k] ?? 0)));
        Settings::update([
            'accent'              => preg_match('~^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$~', $accent) === 1 ? strtolower($accent) : '',
            'index_page'          => absint($p['index_page'] ?? 0),
            'per_page'            => $int('per_page', 6, 120),
            'word_of_day'         => empty($p['word_of_day']) ? 0 : 1,
            'parts_of_speech'     => sanitize_textarea_field((string) ($p['parts_of_speech'] ?? '')),
            'allow_proposals'     => empty($p['allow_proposals']) ? 0 : 1,
            'votes_needed'        => $int('votes_needed', 1, 100),
            'vote_margin'         => $int('vote_margin', 0, 100),
            'reject_at'           => $int('reject_at', 1, 100),
            'voting_days'         => $int('voting_days', 0, 365),
            'settle_on_close'     => empty($p['settle_on_close']) ? 0 : 1,
            'review'              => empty($p['review']) ? 0 : 1,
            'max_open'            => $int('max_open', 0, 500),
            'notify_authors'      => empty($p['notify_authors']) ? 0 : 1,
            'notify_email'        => sanitize_email((string) ($p['notify_email'] ?? '')),
            'notify_new'          => empty($p['notify_new']) ? 0 : 1,
            'delete_on_uninstall' => empty($p['delete_on_uninstall']) ? 0 : 1,
        ]);
        wp_safe_redirect(admin_url('edit.php?post_type=' . PostType::TYPE . '&page=' . self::SETTINGS . '&updated=1'));
        exit;
    }
}
