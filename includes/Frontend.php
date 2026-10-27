<?php

declare(strict_types=1);

namespace SepoDictionary;

use SepoDictionary\Render\AppView;
use SepoDictionary\Render\EntryView;

defined('ABSPATH') || exit;

/**
 * The dictionary on the site: entry pages, the shortcodes, and the
 * scripts and styles they need.
 *
 *   [sepo_dictionary]                    the full dictionary (dictionary, per_page, word_of_day, letters, language, category, title)
 *   [sepo_word term="ubuntu"]            one entry, compact (or id="12"; dictionary, language)
 *   [sepo_word_of_day]                   today's word (dictionary, language)
 *   [sepo_define term="ubuntu"]text[/sepo_define]   text that shows the meaning on hover or focus (dictionary, language)
 *
 * dictionary is a dictionary's slug or name. Without it, [sepo_dictionary]
 * shows the dictionary named in the address (/dictionary/<dictionary>/),
 * or the list of dictionaries when the site has several.
 */
final class Frontend
{
    private static bool $rendering = false;

    public static function hook(): void
    {
        add_action('wp_enqueue_scripts', [self::class, 'register']);
        add_action('admin_enqueue_scripts', [self::class, 'register']);
        add_action('enqueue_block_assets', [self::class, 'register']);
        add_filter('the_content', [self::class, 'content'], 20);
        add_action('init', static function (): void {
            add_shortcode('sepo_dictionary', [self::class, 'dictionaryShortcode']);
            add_shortcode('sepo_word', [self::class, 'wordShortcode']);
            add_shortcode('sepo_word_of_day', [self::class, 'wodShortcode']);
            add_shortcode('sepo_define', [self::class, 'defineShortcode']);
        });
        add_filter('body_class', static function (array $c): array {
            if (is_singular(PostType::TYPE)) {
                $c[] = 'sdict-word-page';
            }
            return $c;
        });
        add_action('template_redirect', [self::class, 'redirects'], 1);
        add_action('template_redirect', static function (): void {
            // Members see their own votes and forms: keep those pages out of shared caches.
            if (is_user_logged_in() && (is_singular(PostType::TYPE) || self::onIndex())) {
                nocache_headers();
            }
        });
        add_action('wp_head', [self::class, 'structuredData']);

        // The entry page draws its own headword, so the theme's title is left out there.
        add_filter('render_block_core/post-title', static function (string $html, array $block, $instance = null): string {
            if (!is_singular(PostType::TYPE) || !apply_filters('sepo_dictionary_hide_theme_title', true)) {
                return $html;
            }
            $id = is_object($instance) && isset($instance->context['postId']) ? (int) $instance->context['postId'] : (int) get_the_ID();
            return $id === (int) get_queried_object_id() ? '' : $html;
        }, 10, 3);
        // Blog details (date, author) mean little on a dictionary entry, which carries its own history.
        // A group that only holds those details (with its "by" text) goes with them.
        add_filter('render_block_core/group', static function (string $html, array $b): string {
            if (!is_singular(PostType::TYPE) || !apply_filters('sepo_dictionary_hide_theme_title', true)) {
                return $html;
            }
            $meta = ['core/post-date', 'core/post-author', 'core/post-author-name', 'core/post-author-biography', 'core/paragraph', 'core/post-terms'];
            $inner = array_column($b['innerBlocks'] ?? [], 'blockName');
            $hasMeta = array_intersect($inner, ['core/post-date', 'core/post-author', 'core/post-author-name']) !== [];
            return $hasMeta && array_diff($inner, $meta) === [] ? '' : $html;
        }, 10, 2);
        foreach (['core/post-date', 'core/post-author', 'core/post-author-name', 'core/post-author-biography', 'core/post-navigation-link'] as $block) {
            add_filter('render_block_' . $block, static function (string $html, array $b, $instance = null): string {
                if (!is_singular(PostType::TYPE) || !apply_filters('sepo_dictionary_hide_theme_title', true)) {
                    return $html;
                }
                $id = is_object($instance) && isset($instance->context['postId']) ? (int) $instance->context['postId'] : (int) get_the_ID();
                return $id === (int) get_queried_object_id() ? '' : $html;
            }, 10, 3);
        }
        add_filter('the_title', static function (string $title, int $id = 0): string {
            if (!is_admin() && $id > 0 && is_singular(PostType::TYPE) && in_the_loop() && is_main_query() && !self::$rendering
                && $id === (int) get_queried_object_id() && !doing_filter('wp_title') && !doing_filter('document_title_parts')
                && apply_filters('sepo_dictionary_hide_theme_title', true) && !wp_is_block_theme()) {
                return '';
            }
            return $title;
        }, 10, 2);
    }

    private static function onIndex(): bool
    {
        $id = (int) Settings::get('index_page');
        return $id > 0 && is_page($id);
    }

    /** ?sd_term=slug on the dictionary page goes to that entry's own page. */
    public static function redirects(): void
    {
        if (!isset($_GET['sd_term'])) { // phpcs:ignore WordPress.Security.NonceVerification
            return;
        }
        $slug = sanitize_title(wp_unslash((string) $_GET['sd_term'])); // phpcs:ignore WordPress.Security.NonceVerification
        $dict = Routes::current();
        $id = $dict > 0 ? Routes::find($slug, $dict) : 0;
        if ($id < 1) {
            $post = $slug !== '' ? get_page_by_path($slug, OBJECT, PostType::TYPE) : null;
            $id = $post instanceof \WP_Post ? (int) $post->ID : 0;
        }
        if ($id > 0 && get_post_status($id) === 'publish') {
            wp_safe_redirect(Entry::url($id), 301);
            exit;
        }
    }

    public static function register(): void
    {
        if (wp_style_is('sepo-dictionary', 'registered')) {
            return;
        }
        wp_register_style('sepo-dictionary', SDICT_URL . 'assets/css/dictionary.css', [], Plugin::assetVersion('assets/css/dictionary.css'));
        wp_register_script('sepo-dictionary', SDICT_URL . 'assets/js/dictionary.js', [], Plugin::assetVersion('assets/js/dictionary.js'), true);
        $accent = (string) Settings::get('accent');
        if ($accent !== '' && preg_match('~^#[0-9a-fA-F]{3,6}$~', $accent) === 1) {
            wp_add_inline_style('sepo-dictionary', '.sdict-scope{--sdict-accent:' . $accent . ';--sdict-on-accent:' . Settings::textOn($accent) . ';}');
        }
        // The config is built late, when the script prints, so it sees the signed-in user.
        add_action('wp_print_footer_scripts', [self::class, 'printConfig'], 1);
        add_action('admin_print_footer_scripts', [self::class, 'printConfig'], 1);
    }

    public static function printConfig(): void
    {
        static $done = false;
        if ($done || !wp_script_is('sepo-dictionary', 'enqueued')) {
            return;
        }
        $done = true;
        wp_add_inline_script('sepo-dictionary', 'window.SepoDictionaryConfig=' . wp_json_encode(self::config()) . ';', 'before');
    }

    public static function enqueue(): void
    {
        self::register();
        wp_enqueue_style('sepo-dictionary');
        wp_enqueue_script('sepo-dictionary');
    }

    /** @return array<string,mixed> */
    private static function config(): array
    {
        $manager = Plugin::siteManager();
        $dicts = [];
        foreach (Dictionaries::all() as $t) {
            $id = (int) $t->term_id;
            $dicts[] = [
                'id'         => $id,
                'name'       => (string) $t->name,
                'canManage'  => Plugin::canManage($id),
                'canPropose' => Plugin::canPropose($id),
                'reviewUrl'  => Dictionaries::homeUrl($id, ['sd_view' => 'review']),
                'pos'        => Dictionaries::partsOfSpeech($id),
            ];
        }
        return [
            'api'             => esc_url_raw(rest_url(Rest::NS)),
            // Only signed-in visitors get a nonce: a cached page would carry a stale one for guests.
            'nonce'           => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'canManage'       => $manager,
            'siteName'        => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
            'reviewUrl'       => Settings::indexUrl(['sd_view' => 'review']),
            'dicts'           => $dicts,
            'defaultDict'     => Dictionaries::many() ? 0 : Dictionaries::defaultId(),
            'pos'             => Settings::partsOfSpeech(),
            'languages'       => PostType::names(PostType::LANGUAGE),
            'categories'      => PostType::names(PostType::CATEGORY),
            'tags'            => $manager ? PostType::names(PostType::TAG) : [],
            'defaultLanguage' => '',
            'i18n'            => self::strings(),
        ];
    }

    /** @return array<string,string> */
    private static function strings(): array
    {
        return [
            'network'          => __('Could not reach the site. Check your connection and try again.', 'sepo-dictionary'),
            'cancel'           => __('Cancel', 'sepo-dictionary'),
            'close'            => __('Close', 'sepo-dictionary'),
            'save'             => __('Save', 'sepo-dictionary'),
            'saving'           => __('Saving…', 'sepo-dictionary'),
            'optional'         => __('optional', 'sepo-dictionary'),
            'required'         => __('Please fill this in.', 'sepo-dictionary'),
            'none'             => __('— None —', 'sepo-dictionary'),
            /* translators: %s: what was typed */
            'noMatch'          => __('No entry matches "%s".', 'sepo-dictionary'),
            /* translators: %s: what was typed */
            'allResults'       => __('See all results for "%s"', 'sepo-dictionary'),
            'addWord'          => __('Add a word', 'sepo-dictionary'),
            'editWord'         => __('Edit entry', 'sepo-dictionary'),
            'suggestWord'      => __('Suggest a word', 'sepo-dictionary'),
            'suggestCorrection'=> __('Suggest a correction', 'sepo-dictionary'),
            'suggestMeaning'   => __('Suggest a meaning', 'sepo-dictionary'),
            'suggestBetter'    => __('Suggest a better meaning', 'sepo-dictionary'),
            'flagWrong'        => __('Flag a meaning as wrong', 'sepo-dictionary'),
            'newIntro'         => __('Your word is put to the community. Members vote on whether the meaning is accurate, and it joins the dictionary when enough of them agree.', 'sepo-dictionary'),
            'correctionIntro'  => __('Change what is wrong in the details below. Only the fields you change are put to the vote; meanings have their own buttons on the entry.', 'sepo-dictionary'),
            /* translators: %s: the word */
            'addIntro'         => __('Add a meaning of "%s" that the entry does not list yet. Members vote on whether it is accurate.', 'sepo-dictionary'),
            'replaceIntro'     => __('Rewrite the meaning so it is more accurate. If the vote favours your wording, it replaces the current one and the old wording stays in the history.', 'sepo-dictionary'),
            'removeIntro'      => __('If the vote agrees this meaning is wrong, it is removed from the entry. The removal is kept in the history.', 'sepo-dictionary'),
            'currentMeaning'   => __('Current meaning', 'sepo-dictionary'),
            'newMeanings'      => __('New meaning', 'sepo-dictionary'),
            'betterMeaning'    => __('Better meaning', 'sepo-dictionary'),
            'word'             => __('Word', 'sepo-dictionary'),
            'pronunciation'    => __('Pronunciation', 'sepo-dictionary'),
            'pronPh'           => __('e.g. /ʊˈbʊntʊ/', 'sepo-dictionary'),
            'pos'              => __('Part of speech', 'sepo-dictionary'),
            'language'         => __('Language', 'sepo-dictionary'),
            'dictionary'       => __('Dictionary', 'sepo-dictionary'),
            'chooseDict'       => __('Choose a dictionary', 'sepo-dictionary'),
            'dictRequired'     => __('Choose the dictionary the word belongs in.', 'sepo-dictionary'),
            'category'         => __('Category', 'sepo-dictionary'),
            'visibility'       => __('Visibility', 'sepo-dictionary'),
            'published'        => __('Published', 'sepo-dictionary'),
            'draft'            => __('Draft', 'sepo-dictionary'),
            'pending'          => __('Pending review', 'sepo-dictionary'),
            'private'          => __('Private', 'sepo-dictionary'),
            'meanings'         => __('Meanings', 'sepo-dictionary'),
            'meaning'          => __('Meaning', 'sepo-dictionary'),
            'meaningPh'        => __('What the word means', 'sepo-dictionary'),
            'example'          => __('Example sentence', 'sepo-dictionary'),
            'examplePh'        => __('An example sentence (optional)', 'sepo-dictionary'),
            'addMeaning'       => __('Add another meaning', 'sepo-dictionary'),
            'removeMeaning'    => __('Remove this meaning', 'sepo-dictionary'),
            'moveUp'           => __('Move up', 'sepo-dictionary'),
            'synonyms'         => __('Synonyms', 'sepo-dictionary'),
            'antonyms'         => __('Antonyms', 'sepo-dictionary'),
            'related'          => __('See also', 'sepo-dictionary'),
            'tags'             => __('Tags', 'sepo-dictionary'),
            'commaHint'        => __('Separate words with commas.', 'sepo-dictionary'),
            'origin'           => __('Origin', 'sepo-dictionary'),
            'notes'            => __('Usage notes', 'sepo-dictionary'),
            'source'           => __('Source', 'sepo-dictionary'),
            'sourcePh'         => __('A book, a speaker, a website…', 'sepo-dictionary'),
            'summary'          => __('Summary of the change', 'sepo-dictionary'),
            'summaryPh'        => __('Shown in the entry history', 'sepo-dictionary'),
            'reason'           => __('Why is this accurate?', 'sepo-dictionary'),
            'reasonPh'         => __('Where you know it from, or how it is used. This helps voters.', 'sepo-dictionary'),
            'removeReasonPh'   => __('Explain what is wrong with this meaning.', 'sepo-dictionary'),
            'reasonRequired'   => __('Say why, so voters can judge.', 'sepo-dictionary'),
            'submitVote'       => __('Submit for voting', 'sepo-dictionary'),
            'wordRequired'     => __('Enter the word.', 'sepo-dictionary'),
            'meaningRequired'  => __('Write at least one meaning.', 'sepo-dictionary'),
            'unchanged'        => __('This is the same as the current meaning.', 'sepo-dictionary'),
            'openEntry'        => __('Open the entry', 'sepo-dictionary'),
            'thanksTitle'      => __('Suggestion received', 'sepo-dictionary'),
            'thanksBody'       => __('You will get an email when it is settled.', 'sepo-dictionary'),
            'seeSuggestions'   => __('See the suggestions', 'sepo-dictionary'),
            'seeQueue'         => __('Go to community review', 'sepo-dictionary'),
            'notAccurateTitle' => __('Not accurate?', 'sepo-dictionary'),
            'notAccurateBody'  => __('A short note helps the author and other voters.', 'sepo-dictionary'),
            'whatsWrong'       => __('What is wrong with it?', 'sepo-dictionary'),
            'whatsWrongPh'     => __('For example: that is the meaning of a different word.', 'sepo-dictionary'),
            'voteNotAccurate'  => __('Vote not accurate', 'sepo-dictionary'),
            'voteSaved'        => __('Vote counted.', 'sepo-dictionary'),
            'voteCleared'      => __('Vote removed.', 'sepo-dictionary'),
            'nowAccepted'      => __('That vote settled it: the suggestion is merged into the dictionary.', 'sepo-dictionary'),
            'nowPassed'        => __('That vote settled it: the suggestion passed and is waiting for an editor.', 'sepo-dictionary'),
            'nowClosed'        => __('That vote settled it: the suggestion is declined.', 'sepo-dictionary'),
            'trashTitle'       => __('Move this entry to the trash?', 'sepo-dictionary'),
            /* translators: %s: the word */
            'trashBody'        => __('"%s" disappears from the dictionary. An editor can bring it back from the trash in the dashboard.', 'sepo-dictionary'),
            'trash'            => __('Move to the trash', 'sepo-dictionary'),
            'withdrawTitle'    => __('Withdraw your suggestion?', 'sepo-dictionary'),
            'withdrawBody'     => __('Voting stops. You can put it forward again later from My suggestions, but its votes start again from zero.', 'sepo-dictionary'),
            'withdraw'         => __('Withdraw', 'sepo-dictionary'),
            'acceptTitle'      => __('Accept and merge?', 'sepo-dictionary'),
            'acceptBody'       => __('The suggestion is merged into the entry now, whatever the vote. The change is recorded in the entry history.', 'sepo-dictionary'),
            'acceptYes'        => __('Accept and merge', 'sepo-dictionary'),
            'declineTitle'     => __('Decline this suggestion?', 'sepo-dictionary'),
            'declineLabel'     => __('Note for the author', 'sepo-dictionary'),
            'declinePh'        => __('Why it is not being accepted', 'sepo-dictionary'),
            'decline'          => __('Decline', 'sepo-dictionary'),
            'deleteTitle'      => __('Delete this suggestion?', 'sepo-dictionary'),
            'deleteBody'       => __('The suggestion and its votes are removed for good. Use this for spam; decline anything else.', 'sepo-dictionary'),
            'delete'           => __('Delete', 'sepo-dictionary'),
            'restoreTitle'     => __('Restore this version?', 'sepo-dictionary'),
            'restoreBody'      => __('The entry goes back to how it read after this change. The restore is added to the history, so nothing is lost.', 'sepo-dictionary'),
            'restoreYes'       => __('Restore', 'sepo-dictionary'),
            'recordTitle'      => __('Pronunciation recording', 'sepo-dictionary'),
            'currentRecording' => __('Current recording', 'sepo-dictionary'),
            'removeRecording'  => __('Remove recording', 'sepo-dictionary'),
            'startRecording'   => __('Start recording', 'sepo-dictionary'),
            'stopRecording'    => __('Stop', 'sepo-dictionary'),
            'recordAgain'      => __('Record again', 'sepo-dictionary'),
            'recordHint'       => __('Say the word clearly, once or twice, then stop. Listen back before you save.', 'sepo-dictionary'),
            'noRecorder'       => __('This browser cannot record here. Recording needs a secure (https) page; you can still upload a sound file.', 'sepo-dictionary'),
            'orUpload'         => __('Or upload a sound file', 'sepo-dictionary'),
            'uploadFile'       => __('Upload a sound file', 'sepo-dictionary'),
            'saveRecording'    => __('Save recording', 'sepo-dictionary'),
            'nothingRecorded'  => __('Record the word or choose a sound file first.', 'sepo-dictionary'),
            'micBlocked'       => __('The microphone is blocked. Allow it in the browser\'s address bar, or upload a sound file instead.', 'sepo-dictionary'),
            'linkCopied'       => __('Link copied.', 'sepo-dictionary'),
            'copy'             => __('Copy', 'sepo-dictionary'),
            'copied'           => __('Copied', 'sepo-dictionary'),
            'copyFailed'       => __('Could not copy. Select the text and copy it yourself.', 'sepo-dictionary'),
            'citeTitle'        => __('Cite this entry', 'sepo-dictionary'),
            /* translators: 1: the word, 2: site name, 3: date accessed, 4: address */
            'citation'         => __('"%1$s." %2$s Dictionary. Accessed %3$s. %4$s', 'sepo-dictionary'),
        ];
    }

    // ------------------------------------------------------------- entry pages

    /** An entry's own page shows the entry in place of the post content. */
    public static function content(string $content): string
    {
        if (self::$rendering || !is_singular(PostType::TYPE) || !in_the_loop() || !is_main_query()) {
            return $content;
        }
        $id = (int) get_the_ID();
        if ($id !== (int) get_queried_object_id()) {
            return $content;
        }
        self::enqueue();
        self::$rendering = true;
        try {
            return EntryView::render($id);
        } finally {
            self::$rendering = false;
        }
    }

    /** Schema.org markup so search engines read the entry as a defined term. */
    public static function structuredData(): void
    {
        if (!is_singular(PostType::TYPE)) {
            return;
        }
        $id = (int) get_queried_object_id();
        $s = Entry::get($id);
        if ($s === null || $s['status'] !== 'publish') {
            return;
        }
        $data = [
            '@context'    => 'https://schema.org',
            '@type'       => 'DefinedTerm',
            'name'        => $s['term'],
            'description' => Entry::snippet($s, 300),
            'url'         => Entry::url($id),
            'inDefinedTermSet' => [
                '@type' => 'DefinedTermSet',
                'name'  => Dictionaries::many()
                    ? Dictionaries::name((int) $s['dictionary'])
                    : wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES) . ' ' . __('Dictionary', 'sepo-dictionary'),
                'url'   => Dictionaries::homeUrl((int) $s['dictionary']),
            ],
        ];
        if ($s['language'] !== '') {
            $code = Entry::speechCode($s['language']);
            $data['inLanguage'] = $code !== '' ? $code : $s['language'];
        }
        echo '<script type="application/ld+json">' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
    }

    // ------------------------------------------------------------- shortcodes

    /** @param array<string,string>|string $atts */
    public static function dictionaryShortcode($atts = []): string
    {
        $a = shortcode_atts(['dictionary' => '', 'per_page' => '', 'word_of_day' => '1', 'letters' => '1', 'language' => '', 'category' => '', 'title' => ''], is_array($atts) ? $atts : [], 'sepo_dictionary');
        self::enqueue();
        $fixed = Dictionaries::id(trim((string) $a['dictionary']));
        $route = Routes::current();
        if ($fixed > 0) {
            // A dictionary placed on a page of its own keeps its links on that page.
            $dict = $fixed;
            $base = is_singular() && $route === 0 ? (string) get_permalink() : Dictionaries::homeUrl($fixed);
        } elseif ($route > 0) {
            $dict = $route;
            $base = Dictionaries::homeUrl($route);
        } elseif (!Dictionaries::many()) {
            $dict = Dictionaries::defaultId();
            $base = is_singular() ? (string) get_permalink() : Settings::indexUrl();
        } else {
            $dict = 0;
            $base = is_singular() ? (string) get_permalink() : Settings::indexUrl();
        }
        return AppView::render($a, $dict, $base);
    }

    /** @param array<string,string>|string $atts */
    public static function wordShortcode($atts = []): string
    {
        $a = shortcode_atts(['term' => '', 'id' => '', 'language' => '', 'dictionary' => ''], is_array($atts) ? $atts : [], 'sepo_word');
        $id = (int) $a['id'];
        if ($id < 1 && $a['term'] !== '') {
            $dict = Dictionaries::id(trim((string) $a['dictionary']));
            $id = ($a['language'] !== '' ? Entry::find($a['term'], $a['language'], 0, $dict) : 0) ?: Entry::findAny($a['term'], $dict);
        }
        if ($id < 1 || get_post_status($id) !== 'publish' || self::$rendering) {
            return '';
        }
        self::enqueue();
        self::$rendering = true;
        try {
            return EntryView::render($id, ['compact' => true]);
        } finally {
            self::$rendering = false;
        }
    }

    /** @param array<string,string>|string $atts */
    public static function wodShortcode($atts = []): string
    {
        $a = shortcode_atts(['language' => '', 'dictionary' => '', 'title' => __('Word of the day', 'sepo-dictionary')], is_array($atts) ? $atts : [], 'sepo_word_of_day');
        $dict = Dictionaries::id(trim((string) $a['dictionary']));
        $post = Entry::wordOfDay(sanitize_title($a['language']), $dict);
        if (!$post instanceof \WP_Post) {
            return '';
        }
        self::enqueue();
        $attrs = '';
        foreach (Render\Parts::dictAttrs($dict) as $k => $v) {
            $attrs .= ' ' . $k . '="' . esc_attr($v) . '"';
        }
        return '<div class="sdict-scope sdict-wod-only" data-sdict-app="1"' . $attrs . '><div class="sdict-wrap">' . EntryView::card($post, (string) $a['title']) . '</div></div>';
    }

    /**
     * Text that shows a word's meaning when hovered or focused, and links to the entry.
     *
     * @param array<string,string>|string $atts
     */
    public static function defineShortcode($atts = [], ?string $content = null): string
    {
        $a = shortcode_atts(['term' => '', 'language' => '', 'dictionary' => ''], is_array($atts) ? $atts : [], 'sepo_define');
        $dict = Dictionaries::id(trim((string) $a['dictionary']));
        $text = trim(wp_strip_all_tags((string) $content));
        $term = trim($a['term']) !== '' ? $a['term'] : $text;
        if ($text === '') {
            $text = $term;
        }
        $id = ($a['language'] !== '' ? Entry::find($term, $a['language'], 0, $dict) : 0) ?: Entry::findAny($term, $dict);
        if ($id < 1 || get_post_status($id) !== 'publish') {
            return esc_html($text);
        }
        $s = Entry::get($id);
        self::enqueue();
        $tip = 'sdict-def-' . $id . '-' . wp_rand(100, 999);
        return '<span class="sdict-scope sdict-define-scope"><a class="sdict-define" href="' . esc_url(Entry::url($id)) . '" aria-describedby="' . esc_attr($tip) . '">' . esc_html($text)
            . '<span class="sdict-define__tip" role="tooltip" id="' . esc_attr($tip) . '"><strong>' . esc_html((string) $s['term']) . '</strong>'
            . ((string) $s['pos'] !== '' ? ' <em>' . esc_html((string) $s['pos']) . '</em>' : '')
            . '<span>' . esc_html(Entry::snippet((array) $s, 180)) . '</span></span></a></span>';
    }
}
