<?php

namespace App\Support\I18n;

/**
 * Wraps the fixed English text of a Blade view in __('...') so it can be translated.
 *
 * It only touches plain text between tags, and the placeholder / title / aria-label / alt attributes, and the page title.
 * It leaves alone: text mixed with {{ }} values (a person must decide how to split those), anything inside
 * scripts, styles, code and textareas, HTML entities, short codes such as USD, and anything with unusual characters.
 * Running it twice changes nothing the second time.
 *
 * The third thing wrap() returns is a list of what it left for a person:
 *   ['mixed', text]  text with a value inside it        ['expr', echo]  English words inside a {{ }}
 *   ['js', text]     an English string inside a script
 */
class BladeWrapper
{
    /** @var array<string, string> */
    private array $stash = [];

    private int $counter = 0;

    /** @var array<int, string> */
    private array $keys = [];

    /** @var array<int, array{0: string, 1: string}> */
    private array $manual = [];

    /** @return array{0: string, 1: array<int, string>, 2: array<int, array{0: string, 1: string}>} */
    public function wrap(string $source): array
    {
        $this->stash = [];
        $this->counter = 0;
        $this->keys = [];
        $this->manual = [];

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
        $this->collectManual();

        // Put it all back, the last thing set aside first (a directive can hold an expression)
        $src = $this->restoreRaw($src);

        return [$src, $this->keys, $this->manual];
    }

    private function restoreRaw(string $src): string
    {
        foreach (array_reverse($this->stash, true) as $token => $original) {
            $src = str_replace($token, $original, $src);
        }

        return $src;
    }

    private function restoreTidy(string $text): string
    {
        return trim((string) preg_replace('~\s+~u', ' ', $this->restoreRaw($text)));
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
                // Text mixed with a value: left for a person, and listed
                $plain = preg_replace('~\x00[EPD]\d+\x00~', '', $text);

                if (preg_match('~\p{L}~u', (string) $plain)) {
                    $this->manual[] = ['mixed', $this->restoreTidy($text)];
                }

                return $m[0];
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

    /** English words inside a {{ }} and inside a script: the things the automatic wrapping cannot decide. */
    private function collectManual(): void
    {
        foreach ($this->stash as $token => $original) {
            if (str_starts_with($token, "\x00E")) {
                if (str_contains($original, '__(') || str_contains($original, 'trans(')) {
                    continue;
                }

                if (preg_match_all('~\'([A-Z][^\']{2,})\'|"([A-Z][^"]{2,})"~', $original, $found)) {
                    foreach (array_merge($found[1], $found[2]) as $string) {
                        // a date format such as 'Y-m-d H:i' is not a phrase
                        if ($string !== '' && ! preg_match('~^[YmdHisjnDFMyAaGgh:\-/ ,.]+$~', $string)) {
                            $this->manual[] = ['expr', trim((string) preg_replace('~\s+~u', ' ', $original))];

                            break;
                        }
                    }
                }
            } elseif (preg_match('~^<script\b~i', $original)) {
                foreach ($this->jsStrings($original) as $string) {
                    $this->manual[] = ['js', $string];
                }
            }
        }
    }

    /** @return array<int, string> the English-looking strings in a piece of JavaScript */
    public function jsStrings(string $js): array
    {
        preg_match_all('~(["\'])((?:(?!\1)[^\\\\\n]|\\\\.){5,160})\1~u', $js, $m);

        return collect($m[2])
            ->filter(fn ($s) => preg_match('~^[A-Z][^<>{}=$]*[a-z]~u', $s) && (str_contains($s, ' ') || mb_strlen($s) >= 8))
            ->unique()
            ->values()
            ->all();
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
