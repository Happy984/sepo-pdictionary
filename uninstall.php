<?php
/**
 * Removes the plugin's data when it is deleted, if the site chose that in
 * Dictionary → Settings. Otherwise entries, suggestions and history stay.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$sdSettings = get_option('sepo_dictionary_settings', []);
wp_clear_scheduled_hook('sepo_dictionary_tick');

if (is_array($sdSettings) && !empty($sdSettings['delete_on_uninstall'])) {
    global $wpdb;
    $sdIds = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'sepo_word'));
    foreach ($sdIds as $sdId) {
        wp_delete_post((int) $sdId, true);
    }
    foreach (['sepo_word_language', 'sepo_word_category', 'sepo_word_tag'] as $sdTax) {
        $sdTerms = $wpdb->get_col($wpdb->prepare("SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s", $sdTax));
        foreach ($sdTerms as $sdTerm) {
            $wpdb->delete($wpdb->termmeta, ['term_id' => (int) $sdTerm]);
            $wpdb->delete($wpdb->term_taxonomy, ['term_id' => (int) $sdTerm, 'taxonomy' => $sdTax]);
            $wpdb->delete($wpdb->terms, ['term_id' => (int) $sdTerm]);
        }
    }
    foreach (['sepo_dict_proposals', 'sepo_dict_votes', 'sepo_dict_history'] as $sdTable) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . $sdTable); // phpcs:ignore WordPress.DB.PreparedSQL
    }
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'sd_accepted'));
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_sd\\_wod\\_%' OR option_name LIKE '\\_transient\\_timeout\\_sd\\_wod\\_%'");
    delete_option('sepo_dictionary_settings');
    delete_option('sepo_dictionary_version');
}
