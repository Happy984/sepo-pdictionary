<?php

declare(strict_types=1);

namespace SepoDictionary\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use SepoDictionary\Frontend;

defined('ABSPATH') || exit;

/** Elementor widget: the full dictionary. */
final class DictionaryWidget extends Widget_Base
{
    public function get_name(): string
    {
        return 'sepo-dictionary';
    }

    public function get_title(): string
    {
        return __('Dictionary', 'sepo-dictionary');
    }

    public function get_icon(): string
    {
        return 'eicon-document-file';
    }

    /** @return list<string> */
    public function get_categories(): array
    {
        return ['sepo-dictionary'];
    }

    /** @return list<string> */
    public function get_keywords(): array
    {
        return ['dictionary', 'glossary', 'words'];
    }

    /** @return list<string> */
    public function get_style_depends(): array
    {
        return ['sepo-dictionary'];
    }

    /** @return list<string> */
    public function get_script_depends(): array
    {
        return ['sepo-dictionary'];
    }

    protected function register_controls(): void
    {
        $langs = ['' => __('All languages', 'sepo-dictionary')];
        $terms = get_terms(['taxonomy' => \SepoDictionary\PostType::LANGUAGE, 'hide_empty' => false]);
        foreach (is_array($terms) ? $terms : [] as $t) {
            $langs[$t->slug] = $t->name;
        }
        $this->start_controls_section('content', ['label' => __('Dictionary', 'sepo-dictionary')]);
        $this->add_control('language', ['label' => __('Language', 'sepo-dictionary'), 'type' => Controls_Manager::SELECT, 'default' => '', 'options' => $langs]);
        $this->add_control('per_page', ['label' => __('Words per page (0 uses the setting)', 'sepo-dictionary'), 'type' => Controls_Manager::NUMBER, 'min' => 0, 'max' => 120, 'default' => 0]);
        $this->add_control('word_of_day', ['label' => __('Show the word of the day', 'sepo-dictionary'), 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
        $this->add_control('letters', ['label' => __('Show the A to Z', 'sepo-dictionary'), 'type' => Controls_Manager::SWITCHER, 'default' => 'yes']);
        $this->add_control('title', ['label' => __('Heading', 'sepo-dictionary'), 'type' => Controls_Manager::TEXT, 'default' => '']);
        $this->add_control('accent', [
            'label'     => __('Accent colour', 'sepo-dictionary'),
            'type'      => Controls_Manager::COLOR,
            'selectors' => ['{{WRAPPER}} .sdict-scope' => '--sdict-accent: {{VALUE}};'],
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $s = $this->get_settings_for_display();
        echo Frontend::dictionaryShortcode([ // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
            'language'    => (string) ($s['language'] ?? ''),
            'per_page'    => (string) (int) ($s['per_page'] ?? 0),
            'word_of_day' => ($s['word_of_day'] ?? 'yes') === 'yes' ? '1' : '0',
            'letters'     => ($s['letters'] ?? 'yes') === 'yes' ? '1' : '0',
            'title'       => (string) ($s['title'] ?? ''),
        ]);
    }
}
