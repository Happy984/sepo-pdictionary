<?php

declare(strict_types=1);

namespace SepoDictionary;

defined('ABSPATH') || exit;

/**
 * Field-by-field differences between two states of an entry, with
 * word-level marking inside text, as escaped HTML ready to print.
 */
final class Diff
{
    private const MAX_TOKENS = 700;

    /** @return array<string,string> */
    public static function labels(): array
    {
        return [
            'term'          => __('Word', 'sepo-dictionary'),
            'status'        => __('Visibility', 'sepo-dictionary'),
            'language'      => __('Language', 'sepo-dictionary'),
            'category'      => __('Category', 'sepo-dictionary'),
            'tags'          => __('Tags', 'sepo-dictionary'),
            'pronunciation' => __('Pronunciation', 'sepo-dictionary'),
            'audio'         => __('Recording', 'sepo-dictionary'),
            'pos'           => __('Part of speech', 'sepo-dictionary'),
            'definitions'   => __('Meanings', 'sepo-dictionary'),
            'synonyms'      => __('Synonyms', 'sepo-dictionary'),
            'antonyms'      => __('Antonyms', 'sepo-dictionary'),
            'related'       => __('See also', 'sepo-dictionary'),
            'etymology'     => __('Origin', 'sepo-dictionary'),
            'notes'         => __('Usage notes', 'sepo-dictionary'),
            'source'        => __('Source', 'sepo-dictionary'),
        ];
    }

    /**
     * The changes from $a to $b. A null $a means the entry was new: every
     * filled field is listed as added.
     *
     * @param array<string,mixed>|null $a
     * @param array<string,mixed> $b
     * @return list<array{label:string,html:string}>
     */
    public static function states(?array $a, array $b, ?array $only = null): array
    {
        $rows = [];
        foreach (self::labels() as $k => $label) {
            if ($only !== null && !in_array($k, $only, true)) {
                continue;
            }
            $from = $a[$k] ?? (in_array($k, ['definitions', 'tags', 'synonyms', 'antonyms', 'related'], true) ? [] : '');
            $to = $b[$k] ?? (is_array($from) ? [] : '');
            if ($a !== null && Entry::comparable($from) === Entry::comparable($to)) {
                continue;
            }
            if ($k === 'audio') {
                $html = self::audio((int) $from, (int) $to);
                if ($html !== '') {
                    $rows[] = ['label' => $label, 'html' => $html];
                }
                continue;
            }
            if ($k === 'definitions') {
                foreach (self::defs(is_array($from) ? $from : [], is_array($to) ? $to : []) as $r) {
                    $rows[] = $r;
                }
                continue;
            }
            if (is_array($from) || is_array($to)) {
                $html = self::lists((array) $from, (array) $to);
            } else {
                if ($k === 'status') {
                    $from = self::statusLabel((string) $from);
                    $to = self::statusLabel((string) $to);
                }
                $html = self::words((string) $from, (string) $to);
            }
            if ($html !== '') {
                $rows[] = ['label' => $label, 'html' => $html];
            }
        }
        return $rows;
    }

    /** A recording added, replaced or removed, with a player for the one that remains. */
    public static function audio(int $from, int $to): string
    {
        if ($from === $to) {
            return '';
        }
        $player = static function (int $id): string {
            $url = $id > 0 ? (string) wp_get_attachment_url($id) : '';
            return $url !== '' ? '<audio class="sdict-diff-audio" controls preload="none" src="' . esc_url($url) . '"></audio>' : '';
        };
        if ($to > 0 && $from < 1) {
            return '<span class="sdict-chg sdict-chg--add">' . esc_html__('Added', 'sepo-dictionary') . '</span> ' . $player($to);
        }
        if ($to < 1) {
            return '<span class="sdict-chg sdict-chg--del">' . esc_html__('Removed', 'sepo-dictionary') . '</span> ' . $player($from);
        }
        return '<span class="sdict-chg sdict-chg--edit">' . esc_html__('Replaced', 'sepo-dictionary') . '</span> ' . $player($to);
    }

    public static function statusLabel(string $s): string
    {
        return match ($s) {
            'publish' => __('Published', 'sepo-dictionary'),
            'draft'   => __('Draft', 'sepo-dictionary'),
            'pending' => __('Pending review', 'sepo-dictionary'),
            'private' => __('Private', 'sepo-dictionary'),
            'trash'   => __('In the trash', 'sepo-dictionary'),
            default   => $s,
        };
    }

    /**
     * Meanings matched by id (or by their words when ids differ).
     *
     * @param list<array<string,string>> $a
     * @param list<array<string,string>> $b
     * @return list<array{label:string,html:string}>
     */
    public static function defs(array $a, array $b): array
    {
        $rows = [];
        $byId = [];
        foreach ($a as $d) {
            $byId[(string) ($d['id'] ?? '')] = $d;
        }
        $byText = [];
        foreach ($a as $d) {
            $byText[Entry::fold((string) ($d['text'] ?? ''))] = $d;
        }
        $used = [];
        foreach (array_values($b) as $i => $d) {
            /* translators: %d: the meaning's number */
            $label = sprintf(__('Meaning %d', 'sepo-dictionary'), $i + 1);
            $old = $byId[(string) ($d['id'] ?? '')] ?? ($byText[Entry::fold((string) $d['text'])] ?? null);
            if ($old === null) {
                $rows[] = ['label' => $label, 'html' => '<span class="sdict-chg sdict-chg--add">' . esc_html__('Added', 'sepo-dictionary') . '</span> ' . self::defHtml($d, 'ins')];
                continue;
            }
            $used[(string) ($old['id'] ?? '')] = true;
            $textSame = trim((string) $old['text']) === trim((string) $d['text']);
            $exSame = trim((string) ($old['example'] ?? '')) === trim((string) ($d['example'] ?? ''));
            if ($textSame && $exSame) {
                continue;
            }
            $html = '<span class="sdict-chg sdict-chg--edit">' . esc_html__('Changed', 'sepo-dictionary') . '</span> ' . self::words((string) $old['text'], (string) $d['text']);
            if (!$exSame) {
                $html .= '<span class="sdict-diff-ex">' . self::words((string) ($old['example'] ?? ''), (string) ($d['example'] ?? '')) . '</span>';
            }
            $rows[] = ['label' => $label, 'html' => $html];
        }
        foreach ($a as $d) {
            if (!isset($used[(string) ($d['id'] ?? '')])) {
                $rows[] = ['label' => __('Meaning', 'sepo-dictionary'), 'html' => '<span class="sdict-chg sdict-chg--del">' . esc_html__('Removed', 'sepo-dictionary') . '</span> ' . self::defHtml($d, 'del')];
            }
        }
        return $rows;
    }

    /** One meaning, marked whole as added or removed. */
    public static function defHtml(array $d, string $tag): string
    {
        $h = '<' . $tag . ' class="sdict-' . $tag . '">' . esc_html((string) $d['text']) . '</' . $tag . '>';
        if (trim((string) ($d['example'] ?? '')) !== '') {
            $h .= '<span class="sdict-diff-ex"><' . $tag . ' class="sdict-' . $tag . '">' . esc_html((string) $d['example']) . '</' . $tag . '></span>';
        }
        return $h;
    }

    /** @param list<string> $a @param list<string> $b */
    public static function lists(array $a, array $b): string
    {
        $fa = array_map([Entry::class, 'fold'], $a);
        $fb = array_map([Entry::class, 'fold'], $b);
        $out = [];
        foreach ($b as $i => $v) {
            $out[] = in_array($fb[$i], $fa, true) ? '<span class="sdict-same">' . esc_html($v) . '</span>' : '<ins class="sdict-ins">' . esc_html($v) . '</ins>';
        }
        foreach ($a as $i => $v) {
            if (!in_array($fa[$i], $fb, true)) {
                $out[] = '<del class="sdict-del">' . esc_html($v) . '</del>';
            }
        }
        return implode('<span class="sdict-sep">, </span>', $out);
    }

    /** Word-level difference between two strings. */
    public static function words(string $a, string $b): string
    {
        if ($a === $b) {
            return esc_html($b);
        }
        if ($a === '') {
            return '<ins class="sdict-ins">' . esc_html($b) . '</ins>';
        }
        if ($b === '') {
            return '<del class="sdict-del">' . esc_html($a) . '</del>';
        }
        $x = preg_split('~(\s+)~u', $a, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        $y = preg_split('~(\s+)~u', $b, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($x) > self::MAX_TOKENS || count($y) > self::MAX_TOKENS) {
            return '<del class="sdict-del">' . esc_html($a) . '</del> <ins class="sdict-ins">' . esc_html($b) . '</ins>';
        }
        $n = count($x);
        $m = count($y);
        // Longest common subsequence, filled from the end.
        $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $L[$i][$j] = $x[$i] === $y[$j] ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
            }
        }
        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($x[$i] === $y[$j]) {
                $ops[] = ['=', $x[$i]];
                $i++;
                $j++;
            } elseif ($L[$i + 1][$j] >= $L[$i][$j + 1]) {
                $ops[] = ['-', $x[$i++]];
            } else {
                $ops[] = ['+', $y[$j++]];
            }
        }
        while ($i < $n) {
            $ops[] = ['-', $x[$i++]];
        }
        while ($j < $m) {
            $ops[] = ['+', $y[$j++]];
        }
        // When most words changed, a word-by-word mark is harder to read than old and new side by side.
        $same = 0;
        $words = 0;
        foreach ($ops as [$k, $t]) {
            if (trim($t) === '') {
                continue;
            }
            $words++;
            if ($k === '=') {
                $same += 2;
            }
        }
        if ($words > 3 && $same / $words < 0.5) {
            return '<del class="sdict-del">' . esc_html($a) . '</del> <ins class="sdict-ins">' . esc_html($b) . '</ins>';
        }
        // Join runs of the same kind so a changed phrase reads as one mark.
        $html = '';
        $run = '';
        $kind = '';
        $flush = static function () use (&$html, &$run, &$kind): void {
            if ($run === '') {
                return;
            }
            $html .= match ($kind) {
                '+' => '<ins class="sdict-ins">' . esc_html($run) . '</ins>',
                '-' => '<del class="sdict-del">' . esc_html($run) . '</del>',
                default => esc_html($run),
            };
            $run = '';
        };
        foreach ($ops as [$k, $t]) {
            // Whitespace between two changed words joins the change rather than splitting it.
            if ($k === '=' && trim($t) === '' && $kind !== '=' && $kind !== '') {
                $run .= $t;
                continue;
            }
            if ($k !== $kind) {
                $flush();
                $kind = $k;
            }
            $run .= $t;
        }
        $flush();
        return $html;
    }
}
