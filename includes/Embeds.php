<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/**
 * The dictionary in page builders: blocks for the dictionary, one word and
 * the word of the day, and matching Elementor widgets when Elementor is active.
 */
final class Embeds
{
    public static function hook(): void
    {
        add_action('init', [self::class, 'blocks']);
        add_action('enqueue_block_editor_assets', [self::class, 'editorAssets']);
        add_action('elementor/elements/categories_registered', static function ($manager): void {
            $manager->add_category('sepo-dictionary', ['title' => __('Sepo dictionary', 'sepo-dictionary'), 'icon' => 'eicon-document-file']);
        });
        add_action('elementor/widgets/register', static function ($manager): void {
            $manager->register(new Elementor\DictionaryWidget());
            $manager->register(new Elementor\WordOfDayWidget());
        });
        add_action('elementor/frontend/after_register_styles', [Frontend::class, 'register']);
        add_action('elementor/frontend/after_register_scripts', [Frontend::class, 'register']);
    }

    public static function blocks(): void
    {
        register_block_type('sepo-dictionary/dictionary', [
            'api_version'     => 2,
            'title'           => __('Dictionary', 'sepo-dictionary'),
            'category'        => 'widgets',
            'attributes'      => [
                'dictionary' => ['type' => 'string', 'default' => ''],
                'perPage'   => ['type' => 'number', 'default' => 0],
                'wordOfDay' => ['type' => 'boolean', 'default' => true],
                'letters'   => ['type' => 'boolean', 'default' => true],
                'language'  => ['type' => 'string', 'default' => ''],
                'title'     => ['type' => 'string', 'default' => ''],
            ],
            'render_callback' => static fn(array $a): string => Frontend::dictionaryShortcode([
                'dictionary'  => (string) ($a['dictionary'] ?? ''),
                'per_page'    => (string) (int) ($a['perPage'] ?? 0),
                'word_of_day' => !empty($a['wordOfDay']) ? '1' : '0',
                'letters'     => !empty($a['letters']) ? '1' : '0',
                'language'    => (string) ($a['language'] ?? ''),
                'title'       => (string) ($a['title'] ?? ''),
            ]),
        ]);
        register_block_type('sepo-dictionary/word', [
            'api_version'     => 2,
            'title'           => __('Dictionary word', 'sepo-dictionary'),
            'category'        => 'widgets',
            'attributes'      => ['term' => ['type' => 'string', 'default' => ''], 'dictionary' => ['type' => 'string', 'default' => '']],
            'render_callback' => static fn(array $a): string => Frontend::wordShortcode(['term' => (string) ($a['term'] ?? ''), 'dictionary' => (string) ($a['dictionary'] ?? '')]),
        ]);
        register_block_type('sepo-dictionary/word-of-day', [
            'api_version'     => 2,
            'title'           => __('Word of the day', 'sepo-dictionary'),
            'category'        => 'widgets',
            'attributes'      => ['language' => ['type' => 'string', 'default' => ''], 'dictionary' => ['type' => 'string', 'default' => ''], 'title' => ['type' => 'string', 'default' => '']],
            'render_callback' => static fn(array $a): string => Frontend::wodShortcode(array_filter(['language' => (string) ($a['language'] ?? ''), 'dictionary' => (string) ($a['dictionary'] ?? ''), 'title' => (string) ($a['title'] ?? '')])),
        ]);
    }

    /**
     * Dictionaries for a builder's select: "the address decides" first, then each by slug.
     *
     * @return list<array{value:string,label:string}>
     */
    public static function dictionaryOptions(): array
    {
        $out = [['value' => '', 'label' => Dictionaries::many() ? __('All dictionaries (or the one in the address)', 'sepo-dictionary') : __('The dictionary', 'sepo-dictionary')]];
        foreach (Dictionaries::all() as $t) {
            $out[] = ['value' => (string) $t->slug, 'label' => (string) $t->name];
        }
        return $out;
    }

    public static function editorAssets(): void
    {
        Frontend::register();
        wp_enqueue_style('sepo-dictionary');
        wp_enqueue_script('sepo-dictionary-blocks', SDICT_URL . 'assets/js/blocks.js', ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n'], Plugin::assetVersion('assets/js/blocks.js'), true);
        $langs = [['value' => '', 'label' => __('All languages', 'sepo-dictionary')]];
        $terms = get_terms(['taxonomy' => PostType::LANGUAGE, 'hide_empty' => false]);
        foreach (is_array($terms) ? $terms : [] as $t) {
            $langs[] = ['value' => $t->slug, 'label' => $t->name];
        }
        wp_add_inline_script('sepo-dictionary-blocks', 'window.SepoDictionaryBlocks=' . wp_json_encode(['languages' => $langs, 'dictionaries' => self::dictionaryOptions()]) . ';', 'before');
        wp_set_script_translations('sepo-dictionary-blocks', 'sepo-dictionary');
    }
}
