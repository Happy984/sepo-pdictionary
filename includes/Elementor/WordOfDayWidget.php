<?php

declare(strict_types=1);

namespace SepoDictionary\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use SepoDictionary\Frontend;

defined('ABSPATH') || exit;

/** Elementor widget: today's word. */
final class WordOfDayWidget extends Widget_Base
{
    public function get_name(): string
    {
        return 'sepo-word-of-day';
    }

    public function get_title(): string
    {
        return __('Word of the day', 'sepo-dictionary');
    }

    public function get_icon(): string
    {
        return 'eicon-star-o';
    }

    /** @return list<string> */
    public function get_categories(): array
    {
        return ['sepo-dictionary'];
    }

    /** @return list<string> */
    public function get_style_depends(): array
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
        $this->start_controls_section('content', ['label' => __('Word of the day', 'sepo-dictionary')]);
        $this->add_control('language', ['label' => __('Language', 'sepo-dictionary'), 'type' => Controls_Manager::SELECT, 'default' => '', 'options' => $langs]);
        $this->add_control('title', ['label' => __('Heading', 'sepo-dictionary'), 'type' => Controls_Manager::TEXT, 'default' => __('Word of the day', 'sepo-dictionary')]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $s = $this->get_settings_for_display();
        echo Frontend::wodShortcode(['language' => (string) ($s['language'] ?? ''), 'title' => (string) ($s['title'] ?? '')]); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts
    }
}
