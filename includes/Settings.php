<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/** Site-wide settings, in one option. */
final class Settings
{
    public const OPTION = 'sepo_dictionary_settings';

    public const DEFAULT_POS = ['noun', 'verb', 'adjective', 'adverb', 'pronoun', 'preposition', 'conjunction', 'interjection', 'phrase', 'abbreviation'];

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return [
            'accent'              => '',
            'index_page'          => 0,
            'per_page'            => 30,
            'word_of_day'         => 1,
            'parts_of_speech'     => implode("\n", self::DEFAULT_POS),
            // Community review.
            'allow_proposals'     => 1,
            'votes_needed'        => 3,
            'vote_margin'         => 2,
            'reject_at'           => 3,
            'voting_days'         => 14,
            'settle_on_close'     => 1,
            'review'              => 0,
            'max_open'            => 10,
            // Email.
            'notify_authors'      => 1,
            'notify_email'        => '',
            'notify_new'          => 0,
            'delete_on_uninstall' => 0,
        ];
    }

    /** @return array<string,mixed> */
    public static function all(): array
    {
        $o = get_option(self::OPTION, []);
        return array_merge(self::defaults(), is_array($o) ? $o : []);
    }

    /** @return mixed */
    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }

    public static function int(string $key, int $min = 0): int
    {
        return max($min, (int) self::get($key));
    }

    /** @param array<string,mixed> $values */
    public static function update(array $values): void
    {
        update_option(self::OPTION, array_merge(self::all(), $values), false);
    }

    /** @return list<string> */
    public static function partsOfSpeech(): array
    {
        $out = [];
        foreach (preg_split('~[\r\n,|]+~', (string) self::get('parts_of_speech')) ?: [] as $p) {
            $p = trim(sanitize_text_field($p));
            if ($p !== '' && !in_array($p, $out, true)) {
                $out[] = $p;
            }
        }
        return (array) apply_filters('sepo_dictionary_parts_of_speech', $out !== [] ? $out : self::DEFAULT_POS);
    }

    /** White or near-black, whichever reads better on the colour. */
    public static function textOn(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!ctype_xdigit($hex) || strlen($hex) !== 6) {
            return '#ffffff';
        }
        $lin = static function (int $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin((int) hexdec(substr($hex, 0, 2))) + 0.7152 * $lin((int) hexdec(substr($hex, 2, 2))) + 0.0722 * $lin((int) hexdec(substr($hex, 4, 2)));
        return (1.05 / ($l + 0.05)) >= (($l + 0.05) / 0.0575) ? '#ffffff' : '#0b1215';
    }

    /** The page that holds the dictionary, made once if it does not exist. */
    public static function ensureIndexPage(): int
    {
        $id = (int) self::get('index_page');
        if ($id > 0 && get_post_status($id)) {
            return $id;
        }
        $existing = get_page_by_path('dictionary');
        if ($existing && str_contains((string) $existing->post_content, '[sepo_dictionary')) {
            self::update(['index_page' => (int) $existing->ID]);
            return (int) $existing->ID;
        }
        $id = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => __('Dictionary', 'sepo-dictionary'),
            'post_name'    => 'dictionary',
            'post_content' => '<!-- wp:shortcode -->[sepo_dictionary]<!-- /wp:shortcode -->',
        ]);
        if (is_int($id) && $id > 0) {
            self::update(['index_page' => $id]);
            return $id;
        }
        return 0;
    }

    /** The dictionary page's address, with optional query arguments. */
    public static function indexUrl(array $args = []): string
    {
        $id = (int) self::get('index_page');
        $url = $id > 0 && get_post_status($id) === 'publish' ? (string) get_permalink($id) : home_url('/');
        $args = array_filter($args, static fn($v): bool => $v !== '' && $v !== null && $v !== 0);
        return $args ? add_query_arg(array_map('rawurlencode', $args), $url) : $url;
    }
}
