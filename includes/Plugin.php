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
        }

        // A new version may add columns or rewrite rules: bring both up to date once.
        add_action('init', static function (): void {
            if (get_option(self::VERSION_OPTION) !== SDICT_VERSION) {
                Schema::install();
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

    /** Editors of the dictionary: change entries directly, settle proposals, restore history. */
    public static function canManage(): bool
    {
        return current_user_can((string) apply_filters('sepo_dictionary_manage_capability', 'edit_others_posts'));
    }

    /** Who may suggest words, meanings and edits. */
    public static function canPropose(): bool
    {
        if (!is_user_logged_in()) {
            return false;
        }
        if (self::canManage()) {
            return true;
        }
        return (bool) Settings::get('allow_proposals')
            && current_user_can((string) apply_filters('sepo_dictionary_propose_capability', 'read'));
    }

    /** Who may vote on the accuracy of a proposal. */
    public static function canVote(): bool
    {
        return is_user_logged_in()
            && current_user_can((string) apply_filters('sepo_dictionary_vote_capability', 'read'));
    }
}
