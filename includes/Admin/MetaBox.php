<?php

declare(strict_types=1);

namespace SepoDictionary\Admin;

use SepoDictionary\Dictionaries;
use SepoDictionary\Entry;
use SepoDictionary\History;
use SepoDictionary\Plugin;
use SepoDictionary\PostType;
use SepoDictionary\Proposals;
use SepoDictionary\Settings;

defined('ABSPATH') || exit;

/** The entry editor in the dashboard: meanings, details, and a summary of suggestions and history. */
final class MetaBox
{
    private static bool $saving = false;

    public static function hook(): void
    {
        add_action('add_meta_boxes_' . PostType::TYPE, static function (): void {
            add_meta_box('sdict-entry', __('Entry', 'sepo-dictionary'), [self::class, 'box'], PostType::TYPE, 'normal', 'high');
            add_meta_box('sdict-community', __('Suggestions and history', 'sepo-dictionary'), [self::class, 'side'], PostType::TYPE, 'side', 'default');
        });
        add_action('save_post_' . PostType::TYPE, [self::class, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', static function (string $hook): void {
            $screen = get_current_screen();
            if (!$screen || $screen->post_type !== PostType::TYPE) {
                return;
            }
            wp_enqueue_style('sepo-dictionary-admin', SDICT_URL . 'assets/css/admin.css', [], Plugin::assetVersion('assets/css/admin.css'));
            if (in_array($hook, ['post.php', 'post-new.php'], true)) {
                wp_enqueue_media();
                wp_enqueue_script('sepo-dictionary-admin', SDICT_URL . 'assets/js/admin.js', [], Plugin::assetVersion('assets/js/admin.js'), true);
                wp_add_inline_script('sepo-dictionary-admin', 'window.SepoDictionaryAdmin=' . wp_json_encode([
                    'meaning'  => __('Meaning', 'sepo-dictionary'),
                    'example'  => __('Example sentence (optional)', 'sepo-dictionary'),
                    'up'       => __('Move up', 'sepo-dictionary'),
                    'down'     => __('Move down', 'sepo-dictionary'),
                    'remove'   => __('Remove', 'sepo-dictionary'),
                    'api'      => esc_url_raw(rest_url(\SepoDictionary\Rest::NS)),
                    'nonce'    => wp_create_nonce('wp_rest'),
                    'pick'     => __('Choose a pronunciation recording', 'sepo-dictionary'),
                    'use'      => __('Use this recording', 'sepo-dictionary'),
                    'record'   => __('Record', 'sepo-dictionary'),
                    'stop'     => __('Stop and keep', 'sepo-dictionary'),
                    'saving'   => __('Saving the recording…', 'sepo-dictionary'),
                    'saved'    => __('Recorded. Update the entry to keep it.', 'sepo-dictionary'),
                    'micError' => __('The microphone could not be used. Allow it for this site, or upload a file instead.', 'sepo-dictionary'),
                    'noRec'    => __('Recording needs a secure (https) page in this browser. Upload a file instead.', 'sepo-dictionary'),
                ]) . ';', 'before');
            }
        });
    }

    public static function box(\WP_Post $post): void
    {
        $s = Entry::get((int) $post->ID) ?? Entry::clean([]);
        wp_nonce_field('sd_entry_save', 'sd_entry_nonce');
        $list = static fn(array $v): string => implode(', ', $v);
        $datalist = static function (string $id, array $values): string {
            $h = '<datalist id="' . esc_attr($id) . '">';
            foreach ($values as $v) {
                $h .= '<option value="' . esc_attr($v) . '"></option>';
            }
            return $h . '</datalist>';
        };
        $dict = (int) $s['dictionary'] ?: Dictionaries::defaultId();
        $choices = array_values(array_filter(Dictionaries::all(), static fn(\WP_Term $t): bool => Plugin::canManage((int) $t->term_id)));
        ?>
        <div class="sdict-admin">
          <?php if (count($choices) > 1) : ?>
            <p class="sdict-admin__dict"><label for="sdict-dict"><?php esc_html_e('Dictionary', 'sepo-dictionary'); ?></label>
              <select id="sdict-dict" name="sd[dictionary]">
                <?php foreach ($choices as $t) : ?>
                  <option value="<?php echo (int) $t->term_id; ?>" <?php selected($dict, (int) $t->term_id); ?>><?php echo esc_html($t->name); ?></option>
                <?php endforeach; ?>
              </select></p>
          <?php else : ?>
            <input type="hidden" name="sd[dictionary]" value="<?php echo (int) $dict; ?>">
          <?php endif; ?>
          <div class="sdict-admin__grid">
            <p><label for="sdict-pron"><?php esc_html_e('Pronunciation', 'sepo-dictionary'); ?></label>
              <input type="text" id="sdict-pron" name="sd[pronunciation]" value="<?php echo esc_attr($s['pronunciation']); ?>" placeholder="/ʊˈbʊntʊ/"></p>
            <?php $audioUrl = Entry::audioUrl($s); ?>
            <div class="sdict-admin__audio" data-sdict-admin-audio>
              <label><?php esc_html_e('Pronunciation recording', 'sepo-dictionary'); ?></label>
              <input type="hidden" name="sd[audio]" value="<?php echo (int) $s['audio']; ?>" data-sdict-audio-id>
              <audio controls preload="none" src="<?php echo esc_url($audioUrl); ?>" data-sdict-audio-player<?php echo $audioUrl === '' ? ' hidden' : ''; ?>></audio>
              <p class="sdict-admin__audio-actions">
                <button type="button" class="button" data-sdict-audio="record"><?php esc_html_e('Record', 'sepo-dictionary'); ?></button>
                <button type="button" class="button" data-sdict-audio="pick"><?php esc_html_e('Choose or upload', 'sepo-dictionary'); ?></button>
                <button type="button" class="button-link sdict-admin__del" data-sdict-audio="remove"<?php echo $audioUrl === '' ? ' hidden' : ''; ?>><?php esc_html_e('Remove', 'sepo-dictionary'); ?></button>
                <span class="description" data-sdict-audio-status></span>
              </p>
            </div>
            <p><label for="sdict-pos"><?php esc_html_e('Part of speech', 'sepo-dictionary'); ?></label>
              <input type="text" id="sdict-pos" name="sd[pos]" value="<?php echo esc_attr($s['pos']); ?>" list="sdict-pos-list" autocomplete="off">
              <?php echo $datalist('sdict-pos-list', Dictionaries::partsOfSpeech($dict)); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
            <p><label for="sdict-lang"><?php esc_html_e('Language', 'sepo-dictionary'); ?></label>
              <input type="text" id="sdict-lang" name="sd[language]" value="<?php echo esc_attr($s['language']); ?>" list="sdict-lang-list" autocomplete="off">
              <?php echo $datalist('sdict-lang-list', PostType::names(PostType::LANGUAGE)); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
            <p><label for="sdict-cat"><?php esc_html_e('Category', 'sepo-dictionary'); ?></label>
              <input type="text" id="sdict-cat" name="sd[category]" value="<?php echo esc_attr($s['category']); ?>" list="sdict-cat-list" autocomplete="off">
              <?php echo $datalist('sdict-cat-list', PostType::names(PostType::CATEGORY)); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
          </div>

          <h3 class="sdict-admin__h"><?php esc_html_e('Meanings', 'sepo-dictionary'); ?></h3>
          <ol class="sdict-admin__defs" data-sdict-admin-defs>
            <?php foreach ($s['definitions'] !== [] ? $s['definitions'] : [['id' => '', 'text' => '', 'example' => '']] as $i => $d) : ?>
              <li class="sdict-admin__def">
                <input type="hidden" name="sd[definitions][<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr($d['id']); ?>">
                <div class="sdict-admin__def-fields">
                  <textarea name="sd[definitions][<?php echo (int) $i; ?>][text]" rows="2" aria-label="<?php esc_attr_e('Meaning', 'sepo-dictionary'); ?>" placeholder="<?php esc_attr_e('Meaning', 'sepo-dictionary'); ?>"><?php echo esc_textarea($d['text']); ?></textarea>
                  <input type="text" name="sd[definitions][<?php echo (int) $i; ?>][example]" value="<?php echo esc_attr($d['example']); ?>" aria-label="<?php esc_attr_e('Example sentence', 'sepo-dictionary'); ?>" placeholder="<?php esc_attr_e('Example sentence (optional)', 'sepo-dictionary'); ?>">
                </div>
                <div class="sdict-admin__def-tools">
                  <button type="button" class="button-link" data-sdict-def="up" aria-label="<?php esc_attr_e('Move up', 'sepo-dictionary'); ?>">&#8593;</button>
                  <button type="button" class="button-link" data-sdict-def="down" aria-label="<?php esc_attr_e('Move down', 'sepo-dictionary'); ?>">&#8595;</button>
                  <button type="button" class="button-link sdict-admin__del" data-sdict-def="del" aria-label="<?php esc_attr_e('Remove', 'sepo-dictionary'); ?>">&times;</button>
                </div>
              </li>
            <?php endforeach; ?>
          </ol>
          <p><button type="button" class="button" data-sdict-def="add">+ <?php esc_html_e('Add a meaning', 'sepo-dictionary'); ?></button></p>

          <div class="sdict-admin__grid">
            <p><label for="sdict-syn"><?php esc_html_e('Synonyms', 'sepo-dictionary'); ?></label>
              <input type="text" id="sdict-syn" name="sd[synonyms]" value="<?php echo esc_attr($list($s['synonyms'])); ?>"></p>
            <p><label for="sdict-ant"><?php esc_html_e('Antonyms', 'sepo-dictionary'); ?></label>
              <input type="text" id="sdict-ant" name="sd[antonyms]" value="<?php echo esc_attr($list($s['antonyms'])); ?>"></p>
            <p><label for="sdict-rel"><?php esc_html_e('See also', 'sepo-dictionary'); ?></label>
              <input type="text" id="sdict-rel" name="sd[related]" value="<?php echo esc_attr($list($s['related'])); ?>"></p>
            <p><label for="sdict-tags"><?php esc_html_e('Tags', 'sepo-dictionary'); ?></label>
              <input type="text" id="sdict-tags" name="sd[tags]" value="<?php echo esc_attr($list($s['tags'])); ?>"></p>
          </div>
          <p class="description"><?php esc_html_e('Separate words with commas. Words that have their own entry are linked on the page.', 'sepo-dictionary'); ?></p>
          <p><label for="sdict-ety"><?php esc_html_e('Origin', 'sepo-dictionary'); ?></label>
            <textarea id="sdict-ety" name="sd[etymology]" rows="2"><?php echo esc_textarea($s['etymology']); ?></textarea></p>
          <p><label for="sdict-notes"><?php esc_html_e('Usage notes', 'sepo-dictionary'); ?></label>
            <textarea id="sdict-notes" name="sd[notes]" rows="3"><?php echo esc_textarea($s['notes']); ?></textarea></p>
          <p><label for="sdict-src"><?php esc_html_e('Source', 'sepo-dictionary'); ?></label>
            <input type="text" id="sdict-src" name="sd[source]" value="<?php echo esc_attr($s['source']); ?>"></p>
          <p><label for="sdict-sum"><?php esc_html_e('Summary of this change', 'sepo-dictionary'); ?></label>
            <input type="text" id="sdict-sum" name="sd_summary" value="" placeholder="<?php esc_attr_e('Optional. Shown in the entry history.', 'sepo-dictionary'); ?>"></p>
        </div>
        <?php
    }

    public static function side(\WP_Post $post): void
    {
        $id = (int) $post->ID;
        $open = Proposals::openCount($id);
        $rows = History::forEntry($id, 5);
        echo '<div class="sdict-admin-side">';
        echo '<p><strong>' . (int) $open . '</strong> ' . esc_html(_n('suggestion waiting', 'suggestions waiting', $open, 'sepo-dictionary'));
        if ($open > 0) {
            echo ' · <a href="' . esc_url(admin_url('edit.php?post_type=' . PostType::TYPE . '&page=' . Screens::PROPOSALS . '&entry=' . $id)) . '">' . esc_html__('Review', 'sepo-dictionary') . '</a>';
        }
        echo '</p>';
        if ($rows === []) {
            echo '<p class="description">' . esc_html__('Changes are recorded here once the entry is saved.', 'sepo-dictionary') . '</p></div>';
            return;
        }
        echo '<ul class="sdict-admin-side__hist">';
        foreach ($rows as $r) {
            $u = $r['user_id'] > 0 ? get_userdata((int) $r['user_id']) : null;
            echo '<li><strong>' . esc_html(History::actionLabel((string) $r['action'])) . '</strong><br><span class="description">'
                . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $r['ts']))
                . ($u ? ' · ' . esc_html($u->display_name) : '') . '</span></li>';
        }
        echo '</ul>';
        if ($post->post_status === 'publish') {
            echo '<p><a href="' . esc_url(add_query_arg('sd_tab', 'history', (string) get_permalink($post))) . '">' . esc_html__('See the full history', 'sepo-dictionary') . '</a></p>';
        }
        echo '</div>';
    }

    public static function save(int $id, \WP_Post $post): void
    {
        if (self::$saving || !isset($_POST['sd_entry_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['sd_entry_nonce'])), 'sd_entry_save')) {
            return;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || wp_is_post_revision($id) || !current_user_can('edit_post', $id)) {
            return;
        }
        if (in_array($post->post_status, ['auto-draft', 'trash'], true)) {
            return;
        }
        $in = isset($_POST['sd']) && is_array($_POST['sd']) ? wp_unslash($_POST['sd']) : []; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised by Entry::clean
        $defs = [];
        foreach ((array) ($in['definitions'] ?? []) as $d) {
            if (is_array($d)) {
                $defs[] = ['id' => (string) ($d['id'] ?? ''), 'text' => (string) ($d['text'] ?? ''), 'example' => (string) ($d['example'] ?? '')];
            }
        }
        $current = Dictionaries::forEntry($id);
        $target = Dictionaries::id($in['dictionary'] ?? 0) ?: $current;
        // A word only moves into a dictionary the editor may change.
        if (!Plugin::canManage($target)) {
            $target = $current;
        }
        $state = [
            'term'          => $post->post_title,
            'status'        => in_array($post->post_status, Entry::STATUSES, true) ? $post->post_status : 'draft',
            'dictionary'    => $target,
            'language'      => $in['language'] ?? '',
            'category'      => $in['category'] ?? '',
            'tags'          => $in['tags'] ?? '',
            'pronunciation' => $in['pronunciation'] ?? '',
            'audio'         => (int) ($in['audio'] ?? 0),
            'pos'           => $in['pos'] ?? '',
            'definitions'   => $defs,
            'synonyms'      => $in['synonyms'] ?? '',
            'antonyms'      => $in['antonyms'] ?? '',
            'related'       => $in['related'] ?? '',
            'etymology'     => $in['etymology'] ?? '',
            'notes'         => $in['notes'] ?? '',
            'source'        => $in['source'] ?? '',
        ];
        $last = History::lastState($id);
        self::$saving = true;
        try {
            Entry::store($id, $state, [
                'new'      => $last === null,
                'before'   => $last,
                'user_id'  => get_current_user_id(),
                'actor_id' => get_current_user_id(),
                'summary'  => isset($_POST['sd_summary']) ? sanitize_text_field(wp_unslash((string) $_POST['sd_summary'])) : '',
            ]);
        } catch (\Throwable $e) {
            error_log('[sepo-dictionary] ' . $e->getMessage()); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
        } finally {
            self::$saving = false;
        }
    }
}
