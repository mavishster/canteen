<?php

namespace App\Support\I18n;

/**
 * Wraps the fixed English text of a Blade view in __('...') so it can be translated.
 *
 * It only touches plain text between tags, and the placeholder / title / aria-label / alt attributes, and the page title.
 * It leaves alone: text mixed with {{ }} values (a person must decide how to split those), anything inside
 * scripts, styles, code and textareas, HTML entities, short codes such as USD, and anything with unusual characters.
 * Running it twice changes nothing the second time.
 */
class BladeWrapper
{
    /** @var array<string, string> */
    private array $stash = [];

    private int $counter = 0;

    /** @var array<int, string> */
    private array $keys = [];

    /** @return array{0: string, 1: array<int, string>} the new source, and the English texts that were wrapped */
    public function wrap(string $source): array
    {
        $this->stash = [];
        $this->counter = 0;
        $this->keys = [];

        $src = $this->wrapPageTitle($source);

        // Put aside everything that must never be touched, leaving a marker where it was
        $src = $this->protect($src, '~\{\{--.*?--\}\}~s', 'P');
        $src = $this->protect($src, '~<(script|style|pre|textarea|code)\b.*?</\1>~is', 'P');
        $src = $this->protect($src, '~@php\b.*?@endphp~s', 'P');
        $src = $this->protect($src, '~\{!!.*?!!\}~s', 'E');
        $src = $this->protect($src, '~\{\{.*?\}\}~s', 'E');
        $src = $this->protect($src, '~@\w+(?:\s*\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?~', 'D');

        $src = $this->wrapTextNodes($src);
        $src = $this->wrapAttributes($src);

        // Put it all back, the last thing set aside first (a directive can hold an expression)
        foreach (array_reverse($this->stash, true) as $token => $original) {
            $src = str_replace($token, $original, $src);
        }

        return [$src, $this->keys];
    }

    /** @section('title', 'Products')  ->  @section('title', __('Products')) */
    private function wrapPageTitle(string $src): string
    {
        return preg_replace_callback(
            '~@section\(\s*\'title\'\s*,\s*\'((?:[^\'\\\\]|\\\\.)*)\'\s*\)~',
            function (array $m) {
                $text = stripslashes($m[1]);

                if (! $this->translatable($text)) {
                    return $m[0];
                }

                $this->keys[] = $text;

                return "@section('title', __('" . addcslashes($text, "'\\") . "'))";
            },
            $src
        ) ?? $src;
    }

    private function protect(string $src, string $pattern, string $kind): string
    {
        return preg_replace_callback($pattern, function (array $m) use ($kind) {
            $token = "\x00" . $kind . $this->counter++ . "\x00";
            $this->stash[$token] = $m[0];

            return $token;
        }, $src) ?? $src;
    }

    private function wrapTextNodes(string $src): string
    {
        return preg_replace_callback('~>([^<>]+)<~u', function (array $m) {
            $text = $m[1];

            if (str_contains($text, "\x00E")) {
                return $m[0];   // text mixed with a value: left for a person
            }

            // A directive (@if / @else) only separates pieces of text; each piece is its own phrase
            $parts = preg_split('~(\x00[PD]\d+\x00)~', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

            foreach ($parts as $i => $part) {
                if ($i % 2 === 0) {
                    $parts[$i] = $this->wrapPiece($part);
                }
            }

            return '>' . implode('', $parts) . '<';
        }, $src) ?? $src;
    }

    private function wrapPiece(string $piece): string
    {
        if (! preg_match('~^(\s*)(.*?)(\s*)$~su', $piece, $m)) {
            return $piece;
        }

        [, $lead, $text, $trail] = $m;

        if (! $this->translatable($text)) {
            return $piece;
        }

        $key = preg_replace('~\s+~u', ' ', $text);
        $this->keys[] = $key;

        return $lead . "{{ __('" . addcslashes($key, "'\\") . "') }}" . $trail;
    }

    private function wrapAttributes(string $src): string
    {
        return preg_replace_callback('~\b(placeholder|title|aria-label|alt)="([^"\x00]*)"~u', function (array $m) {
            $text = trim($m[2]);

            if (! $this->translatable($text)) {
                return $m[0];
            }

            $key = preg_replace('~\s+~u', ' ', $text);
            $this->keys[] = $key;

            return $m[1] . '="{{ __(\'' . addcslashes($key, "'\\") . '\') }}"';
        }, $src) ?? $src;
    }

    /** Is this text a phrase a person reads, and safe to hand to __()? */
    public function translatable(string $text): bool
    {
        if ($text === '' || mb_strlen($text) > 400) {
            return false;
        }

        if (! preg_match('~\p{L}~u', $text)) {
            return false;   // numbers, symbols
        }

        if (preg_match('~[^\p{L}\p{N}\s.,:;!?\'’"“”()/&\-–—·…%+←→↑↓×•©@*#]~u', $text)) {
            return false;   // anything that looks like code
        }

        if (preg_match('~&#?\w+;~', $text)) {
            return false;   // an HTML entity: {{ }} would print it literally
        }

        if (preg_match('~^[\p{Lu}\p{N}\s]{1,6}$~u', $text)) {
            return false;   // short codes: USD, ID, KHR
        }

        if (preg_match('~(://|^/)~', $text)) {
            return false;   // an address
        }

        return true;
    }
}
