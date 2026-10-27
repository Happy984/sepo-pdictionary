<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/**
 * Wires the plugin together and keeps the database in step with the code.
 */
final class Plugin
{
    public const VERSION_OPTION = 'sepo_dictionary_version';
    public const CRON = 'sepo_dictionary_tick';

    public static function activate(): void
    {
        Schema::install();
        PostType::register();
        Dictionaries::migrate();
        Routes::addRules();
        Settings::ensureIndexPage();
        flush_rewrite_rules();
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON);
        }
        update_option(self::VERSION_OPTION, SDICT_VERSION);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON);
        flush_rewrite_rules();
    }

    public static function boot(): void
    {
        add_action('init', static function (): void {
            load_plugin_textdomain('sepo-dictionary', false, dirname(plugin_basename(SDICT_FILE)) . '/languages');
        }, 1);

        PostType::hook();
        Dictionaries::hook();
        Routes::hook();
        Entry::hook();
        Frontend::hook();
        Rest::hook();
        Embeds::hook();
        Privacy::hook();

        // Proposals whose voting window has ended are settled once an hour.
        add_action(self::CRON, [Proposals::class, 'closeDue']);

        if (is_admin()) {
            Admin\MetaBox::hook();
            Admin\Screens::hook();
            Admin\Transfer::hook();
            Admin\DictionaryScreen::hook();
        }

        // A new version may add columns or rewrite rules: bring both up to date once.
        add_action('init', static function (): void {
            if (get_option(self::VERSION_OPTION) !== SDICT_VERSION) {
                Schema::install();
                Dictionaries::migrate();
                Routes::addRules();
                flush_rewrite_rules(false);
                update_option(self::VERSION_OPTION, SDICT_VERSION);
            }
            if (!wp_next_scheduled(self::CRON)) {
                wp_schedule_event(time() + 300, 'hourly', self::CRON);
            }
        }, 99);
    }

    /** The plugin version plus the file's change time, so browsers fetch an edited file at once. */
    public static function assetVersion(string $rel): string
    {
        $file = SDICT_DIR . $rel;
        return SDICT_VERSION . (is_file($file) ? '.' . filemtime($file) : '');
    }

    /** Editors of every dictionary on the site. */
    public static function siteManager(): bool
    {
        return current_user_can(self::manageCap());
    }

    public static function manageCap(): string
    {
        return (string) apply_filters('sepo_dictionary_manage_capability', 'edit_others_posts');
    }

    /**
     * Who may change entries directly, settle suggestions and restore
     * history: the site's editors everywhere, and a dictionary's own editors
     * in that dictionary. With no dictionary given, only the site's editors.
     */
    public static function canManage(int $dict = 0): bool
    {
        return self::userCanManage(get_current_user_id(), $dict);
    }

    public static function userCanManage(int $userId, int $dict = 0): bool
    {
        if ($userId < 1) {
            return false;
        }
        if (user_can($userId, self::manageCap())) {
            return true;
        }
        return $dict > 0 && Dictionaries::isEditor($dict, $userId);
    }

    /** Whether the current member looks after any dictionary, or all of them. */
    public static function managesAny(): bool
    {
        return self::siteManager() || Dictionaries::editedBy(get_current_user_id()) !== [];
    }

    /** Who may suggest words, meanings and edits in a dictionary. */
    public static function canPropose(int $dict = 0): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }
        if (self::canManage($dict)) {
            return true;
        }
        $open = $dict > 0 ? Dictionaries::on($dict, 'allow_proposals') : (int) Settings::get('allow_proposals') === 1;
        return $open && current_user_can((string) apply_filters('sepo_dictionary_propose_capability', 'read'));
    }

    /** Whether suggestions are open to members in a dictionary, signed in or not. */
    public static function proposalsOpen(int $dict = 0): bool
    {
        return $dict > 0 ? Dictionaries::on($dict, 'allow_proposals') : (int) Settings::get('allow_proposals') === 1;
    }

    /** Who may vote on the accuracy of a proposal. */
    public static function canVote(): bool
    {
        return is_user_logged_in()
            && current_user_can((string) apply_filters('sepo_dictionary_vote_capability', 'read'));
    }
}
