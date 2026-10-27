<?php

declare(strict_types=1);

namespace SepoDictionary\Admin;

use SepoDictionary\Dictionaries;
use SepoDictionary\Entry;
use SepoDictionary\Plugin;
use SepoDictionary\PostType;

defined('ABSPATH') || exit;

/**
 * Dictionary → Import and export: the whole dictionary as JSON or CSV, and
 * entries in from either. Imported changes are recorded in each entry's
 * history like any other edit.
 *
 * CSV columns (a header row names them; order does not matter):
 *   dictionary, term, pronunciation, pos, language, category, tags,
 *   definition, example, synonyms, antonyms, related, etymology, notes, source
 * Several rows with the same dictionary, term and language become one entry
 * with several meanings. "dictionary" is a dictionary's slug or name; rows
 * that name none, or one that does not exist, go into the dictionary chosen
 * on the import form.
 */
final class Transfer
{
    public const PAGE = 'sepo-dictionary-transfer';
    public const COLUMNS = ['dictionary', 'term', 'pronunciation', 'pos', 'language', 'category', 'tags', 'definition', 'example', 'synonyms', 'antonyms', 'related', 'etymology', 'notes', 'source'];

    public static function hook(): void
    {
        add_action('admin_menu', static function (): void {
            add_submenu_page('edit.php?post_type=' . PostType::TYPE, __('Import and export', 'sepo-dictionary'), __('Import and export', 'sepo-dictionary'), (string) apply_filters('sepo_dictionary_manage_capability', 'edit_others_posts'), self::PAGE, [self::class, 'page']);
        }, 20);
        add_action('admin_post_sepo_dictionary_export', [self::class, 'export']);
        add_action('admin_post_sepo_dictionary_import', [self::class, 'import']);
    }

    private static function back(array $args): string
    {
        return add_query_arg(array_merge(['post_type' => PostType::TYPE, 'page' => self::PAGE], $args), admin_url('edit.php'));
    }

    public static function page(): void
    {
        if (!Plugin::canManage()) {
            return;
        }
        // phpcs:disable WordPress.Security.NonceVerification -- display of a redirect's result
        $done = isset($_GET['sd_done']);
        $err = isset($_GET['sd_err']) ? sanitize_text_field(wp_unslash((string) $_GET['sd_err'])) : '';
        $n = static fn(string $k): int => isset($_GET[$k]) ? absint($_GET[$k]) : 0;
        // phpcs:enable
        ?>
        <div class="wrap sdict-transfer">
          <h1><?php esc_html_e('Import and export', 'sepo-dictionary'); ?></h1>
          <?php if ($done) : ?>
            <div class="notice notice-success is-dismissible"><p><?php
              /* translators: 1: added, 2: updated, 3: skipped */
              echo esc_html(sprintf(__('Import finished: %1$d added, %2$d updated, %3$d skipped.', 'sepo-dictionary'), $n('sd_added'), $n('sd_updated'), $n('sd_skipped')));
            ?></p></div>
          <?php elseif ($err !== '') : ?>
            <div class="notice notice-error is-dismissible"><p><?php echo esc_html($err); ?></p></div>
          <?php endif; ?>

          <div class="card">
            <h2><?php esc_html_e('Export', 'sepo-dictionary'); ?></h2>
            <p><?php esc_html_e('Download every entry, with all its meanings and details. JSON keeps everything and can be imported back; CSV opens in a spreadsheet, with one row per meaning.', 'sepo-dictionary'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
              <input type="hidden" name="action" value="sepo_dictionary_export">
              <?php wp_nonce_field('sepo_dictionary_export'); ?>
              <?php echo self::dictSelect('dict', __('Dictionary', 'sepo-dictionary'), true); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
              <p><label><input type="checkbox" name="all" value="1"> <?php esc_html_e('Include drafts and private entries', 'sepo-dictionary'); ?></label></p>
              <p><button class="button button-primary" name="format" value="json"><?php esc_html_e('Download JSON', 'sepo-dictionary'); ?></button>
                <button class="button" name="format" value="csv"><?php esc_html_e('Download CSV', 'sepo-dictionary'); ?></button></p>
            </form>
          </div>

          <div class="card">
            <h2><?php esc_html_e('Import', 'sepo-dictionary'); ?></h2>
            <p><?php
              /* translators: %s: list of column names */
              echo esc_html(sprintf(__('Upload a JSON file exported from this plugin, or a CSV file with a header row using these columns: %s. Rows with the same dictionary, word and language become one entry with several meanings.', 'sepo-dictionary'), implode(', ', self::COLUMNS)));
            ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
              <input type="hidden" name="action" value="sepo_dictionary_import">
              <?php wp_nonce_field('sepo_dictionary_import'); ?>
              <p><input type="file" name="file" accept=".json,.csv,application/json,text/csv" required></p>
              <?php echo self::dictSelect('dict', __('Dictionary for rows that name none', 'sepo-dictionary'), false); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside ?>
              <p><label for="sdict-mode"><strong><?php esc_html_e('When a word is already in the dictionary', 'sepo-dictionary'); ?></strong></label><br>
                <select name="mode" id="sdict-mode">
                  <option value="merge"><?php esc_html_e('Add any new meanings to it, and fill in empty details', 'sepo-dictionary'); ?></option>
                  <option value="skip"><?php esc_html_e('Leave it as it is', 'sepo-dictionary'); ?></option>
                  <option value="replace"><?php esc_html_e('Replace it with the imported entry', 'sepo-dictionary'); ?></option>
                </select></p>
              <p><label for="sdict-ilang"><strong><?php esc_html_e('Language for rows that name none', 'sepo-dictionary'); ?></strong></label><br>
                <input type="text" id="sdict-ilang" name="language" list="sdict-ilang-list" class="regular-text">
                <datalist id="sdict-ilang-list"><?php foreach (PostType::names(PostType::LANGUAGE) as $l) { echo '<option value="' . esc_attr($l) . '"></option>'; } ?></datalist></p>
              <p><label><input type="checkbox" name="draft" value="1"> <?php esc_html_e('Import new entries as drafts', 'sepo-dictionary'); ?></label></p>
              <p><button class="button button-primary"><?php esc_html_e('Import', 'sepo-dictionary'); ?></button></p>
            </form>
          </div>
        </div>
        <?php
    }

    /** A choice of dictionary for the forms above, when the site has more than one. */
    private static function dictSelect(string $name, string $label, bool $all): string
    {
        if (!Dictionaries::many()) {
            return '';
        }
        $h = '<p><label for="sdict-x-' . esc_attr($name . ($all ? 'e' : 'i')) . '"><strong>' . esc_html($label) . '</strong></label><br><select name="' . esc_attr($name) . '" id="sdict-x-' . esc_attr($name . ($all ? 'e' : 'i')) . '">';
        if ($all) {
            $h .= '<option value="">' . esc_html__('All dictionaries', 'sepo-dictionary') . '</option>';
        }
        foreach (Dictionaries::all() as $t) {
            $h .= '<option value="' . (int) $t->term_id . '"' . selected(!$all && (int) $t->term_id === Dictionaries::defaultId(), true, false) . '>' . esc_html($t->name) . '</option>';
        }
        return $h . '</select></p>';
    }

    public static function export(): void
    {
        if (!Plugin::canManage()) {
            wp_die(esc_html__('You are not allowed to export the dictionary.', 'sepo-dictionary'), 403);
        }
        check_admin_referer('sepo_dictionary_export');
        $format = ($_POST['format'] ?? '') === 'csv' ? 'csv' : 'json';
        $dict = isset($_POST['dict']) ? absint($_POST['dict']) : 0;
        $ids = get_posts([
            'post_type'      => PostType::TYPE,
            'post_status'    => !empty($_POST['all']) ? ['publish', 'draft', 'pending', 'private'] : ['publish'],
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ] + ($dict > 0 ? ['tax_query' => [['taxonomy' => Dictionaries::TAX, 'field' => 'term_id', 'terms' => [$dict]]]] : []));
        $name = ($dict > 0 ? Dictionaries::slug($dict) . '-' : 'dictionary-') . gmdate('Y-m-d') . '.' . $format;
        nocache_headers();
        header('Content-Disposition: attachment; filename="' . $name . '"');

        if ($format === 'json') {
            header('Content-Type: application/json; charset=utf-8');
            $out = [];
            foreach ($ids as $id) {
                $s = Entry::get((int) $id);
                if ($s !== null) {
                    $s['audio_url'] = Entry::audioUrl($s);
                    // Named by slug: term ids mean nothing on another site.
                    $s['dictionary'] = Dictionaries::slug((int) $s['dictionary']);
                    $out[] = $s;
                }
            }
            echo wp_json_encode(['generator' => 'sepo-dictionary', 'version' => SDICT_VERSION, 'entries' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        header('Content-Type: text/csv; charset=utf-8');
        $fh = fopen('php://output', 'w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, self::COLUMNS);
        foreach ($ids as $id) {
            $s = Entry::get((int) $id);
            if ($s === null) {
                continue;
            }
            $defs = $s['definitions'] !== [] ? $s['definitions'] : [['text' => '', 'example' => '']];
            foreach ($defs as $i => $d) {
                // Details are written on the first meaning's row only.
                $first = $i === 0;
                fputcsv($fh, array_map([self::class, 'cell'], [
                    Dictionaries::slug((int) $s['dictionary']), $s['term'], $first ? $s['pronunciation'] : '', $first ? $s['pos'] : '', $s['language'], $first ? $s['category'] : '', $first ? implode(', ', $s['tags']) : '',
                    $d['text'], $d['example'],
                    $first ? implode(', ', $s['synonyms']) : '', $first ? implode(', ', $s['antonyms']) : '', $first ? implode(', ', $s['related']) : '',
                    $first ? $s['etymology'] : '', $first ? $s['notes'] : '', $first ? $s['source'] : '',
                ]));
            }
        }
        fclose($fh);
        exit;
    }

    /** Spreadsheets run cells that start with these characters as formulas; a leading quote keeps them as text. */
    private static function cell(string $v): string
    {
        return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
    }

    public static function import(): void
    {
        if (!Plugin::canManage()) {
            wp_die(esc_html__('You are not allowed to import into the dictionary.', 'sepo-dictionary'), 403);
        }
        check_admin_referer('sepo_dictionary_import');
        $file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        if (!is_array($file) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            wp_safe_redirect(self::back(['sd_err' => rawurlencode(__('The file could not be uploaded.', 'sepo-dictionary'))]));
            exit;
        }
        $raw = (string) file_get_contents((string) $file['tmp_name']);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        try {
            $rows = $ext === 'csv' ? self::parseCsv($raw) : self::parseJson($raw);
        } catch (\Throwable $e) {
            wp_safe_redirect(self::back(['sd_err' => rawurlencode($e->getMessage())]));
            exit;
        }
        $mode = in_array($_POST['mode'] ?? '', ['merge', 'skip', 'replace'], true) ? (string) $_POST['mode'] : 'merge';
        $lang = Entry::line(wp_unslash((string) ($_POST['language'] ?? '')), 100);
        $draft = !empty($_POST['draft']);
        $dict = isset($_POST['dict']) ? absint($_POST['dict']) : 0;
        $r = self::apply($rows, $mode, $lang, $draft, Dictionaries::id($dict) ?: Dictionaries::defaultId());
        wp_safe_redirect(self::back(['sd_done' => 1, 'sd_added' => $r['added'], 'sd_updated' => $r['updated'], 'sd_skipped' => $r['skipped']]));
        exit;
    }

    /** @return list<array<string,mixed>> */
    public static function parseJson(string $raw): array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException(__('That file is not valid JSON.', 'sepo-dictionary'));
        }
        $list = isset($data['entries']) && is_array($data['entries']) ? $data['entries'] : (array_is_list($data) ? $data : []);
        $out = [];
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Older exports name the part of speech in full.
            if (!isset($row['pos']) && isset($row['part_of_speech'])) {
                $row['pos'] = $row['part_of_speech'];
            }
            if (!isset($row['definitions']) && isset($row['definition'])) {
                $row['definitions'] = $row['definition'];
            }
            $out[] = $row;
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public static function parseCsv(string $raw): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);
        $head = fgetcsv($fh);
        if (!is_array($head)) {
            throw new \InvalidArgumentException(__('The CSV file is empty.', 'sepo-dictionary'));
        }
        $head = array_map(static fn($h): string => strtolower(trim((string) $h)), $head);
        $alias = ['word' => 'term', 'headword' => 'term', 'part_of_speech' => 'pos', 'meaning' => 'definition', 'origin' => 'etymology', 'see_also' => 'related'];
        $head = array_map(static fn(string $h): string => $alias[$h] ?? $h, $head);
        if (!in_array('term', $head, true)) {
            throw new \InvalidArgumentException(__('The CSV file needs a "term" column.', 'sepo-dictionary'));
        }
        $entries = [];
        while (($cells = fgetcsv($fh)) !== false) {
            if ($cells === [null]) {
                continue;
            }
            $row = [];
            foreach ($head as $i => $k) {
                $v = (string) ($cells[$i] ?? '');
                // A leading quote added on export keeps formulas as text; drop it again.
                $row[$k] = str_starts_with($v, "'") && isset($v[1]) && in_array($v[1], ['=', '+', '-', '@'], true) ? substr($v, 1) : $v;
            }
            $term = trim($row['term'] ?? '');
            if ($term === '') {
                continue;
            }
            $key = Entry::fold($row['dictionary'] ?? '') . '|' . Entry::fold($term) . '|' . Entry::fold($row['language'] ?? '');
            if (!isset($entries[$key])) {
                $entries[$key] = $row + ['definitions' => []];
            }
            foreach (['pronunciation', 'pos', 'category', 'tags', 'synonyms', 'antonyms', 'related', 'etymology', 'notes', 'source'] as $k) {
                if (trim((string) ($entries[$key][$k] ?? '')) === '' && trim((string) ($row[$k] ?? '')) !== '') {
                    $entries[$key][$k] = $row[$k];
                }
            }
            if (trim($row['definition'] ?? '') !== '') {
                $entries[$key]['definitions'][] = ['text' => $row['definition'], 'example' => $row['example'] ?? ''];
            }
        }
        fclose($fh);
        return array_values($entries);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{added:int,updated:int,skipped:int}
     */
    public static function apply(array $rows, string $mode, string $language, bool $draft, int $dict = 0): array
    {
        $r = ['added' => 0, 'updated' => 0, 'skipped' => 0];
        $uid = get_current_user_id();
        $dict = $dict > 0 ? $dict : Dictionaries::defaultId();
        foreach ($rows as $row) {
            // A named dictionary that exists on this site is used; anything else goes to the chosen one.
            $named = is_scalar($row['dictionary'] ?? null) && !is_int($row['dictionary']) && !ctype_digit((string) $row['dictionary']) ? Dictionaries::id((string) $row['dictionary']) : 0;
            $row['dictionary'] = $named > 0 ? $named : $dict;
            if (trim((string) ($row['language'] ?? '')) === '' && $language !== '') {
                $row['language'] = $language;
            }
            // Recording ids belong to the site that exported them.
            unset($row['audio']);
            $clean = Entry::clean($row);
            if ($clean['term'] === '' || $clean['definitions'] === []) {
                $r['skipped']++;
                continue;
            }
            $existing = Entry::find($clean['term'], $clean['language'], 0, (int) $clean['dictionary']);
            $ctx = ['user_id' => $uid, 'actor_id' => $uid, 'summary' => __('Imported', 'sepo-dictionary')];
            try {
                if ($existing < 1) {
                    if ($draft || !isset($row['status'])) {
                        $clean['status'] = $draft ? 'draft' : 'publish';
                    }
                    Entry::store(null, $clean, $ctx);
                    $r['added']++;
                    continue;
                }
                if ($mode === 'skip') {
                    $r['skipped']++;
                    continue;
                }
                $cur = Entry::get($existing) ?? [];
                if ($mode === 'replace') {
                    $clean['status'] = $cur['status'] ?? 'publish';
                    $clean['dictionary'] = $cur['dictionary'] ?? $clean['dictionary'];
                    $clean['audio'] = $cur['audio'] ?? 0;
                    $next = $clean;
                } else {
                    $next = $cur;
                    $known = array_map(static fn(array $d): string => Entry::fold($d['text']), $cur['definitions'] ?? []);
                    foreach ($clean['definitions'] as $d) {
                        if (!in_array(Entry::fold($d['text']), $known, true)) {
                            $d['id'] = Entry::newId();
                            $next['definitions'][] = $d;
                        }
                    }
                    foreach (['pronunciation', 'pos', 'category', 'etymology', 'notes', 'source'] as $k) {
                        if (($next[$k] ?? '') === '' && $clean[$k] !== '') {
                            $next[$k] = $clean[$k];
                        }
                    }
                    foreach (['tags', 'synonyms', 'antonyms', 'related'] as $k) {
                        $next[$k] = Entry::listy(array_merge($next[$k] ?? [], $clean[$k]));
                    }
                }
                if (Entry::same($cur, Entry::clean($next, $cur))) {
                    $r['skipped']++;
                    continue;
                }
                Entry::store($existing, $next, $ctx);
                $r['updated']++;
            } catch (\Throwable $e) {
                $r['skipped']++;
            }
        }
        return $r;
    }
}
