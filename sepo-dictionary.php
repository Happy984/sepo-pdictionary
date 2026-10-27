<?php

/**
 * Plugin Name:       Sepo Dictionary
 * Description:       One or more community dictionaries. Visitors look words up by search or by letter; members suggest new words, new meanings and corrections, and the community votes on their accuracy. Suggestions that win the vote are merged into the entry, and every change is kept in the entry's history. Runs on WordPress alone.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Euneolink Solutions
 * Author URI:        https://sepodesk.com
 * Plugin URI:        https://edu.sepodesk.com
 * License:           GPL-2.0-or-later
 * Text Domain:       sepo-dictionary
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (version_compare(PHP_VERSION, '8.1', '<')) {
    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Sepo Dictionary needs PHP 8.1 or newer. The plugin is installed but not running.', 'sepo-dictionary')
            . '</p></div>';
    });
    return;
}

define('SDICT_VERSION', '1.3.0');
define('SDICT_FILE', __FILE__);
define('SDICT_DIR', plugin_dir_path(__FILE__));
define('SDICT_URL', plugin_dir_url(__FILE__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'SepoDictionary\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = SDICT_DIR . 'includes/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, ['SepoDictionary\\Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['SepoDictionary\\Plugin', 'deactivate']);

add_action('plugins_loaded', ['SepoDictionary\\Plugin', 'boot']);
