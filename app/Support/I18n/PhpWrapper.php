<?php

namespace App\Support\I18n;

/**
 * Wraps the messages the server produces in __('...') so they can be translated, using PHP's own tokenizer.
 * It only looks in the places messages go:
 *   ->with('status', MESSAGE)     ->withErrors([... => MESSAGE])     ::withMessages([... => MESSAGE])
 *   abort(CODE, MESSAGE)          abort_if / abort_unless(COND, CODE, MESSAGE)
 *   'message' => MESSAGE  (also title, body, subject, hint)
 *   new self('code', MESSAGE) inside an *Exception class, new CardException('code', MESSAGE) and so on,
 *   new InvalidArgumentException(MESSAGE)
 * A MESSAGE may be a string, strings joined with ".", or a "double quoted {$value} string": every value becomes a
 * named placeholder (:name) and is passed as the second argument. Anything else is left alone and listed.
 */
class PhpWrapper
{
    private const FLASH_KEYS = ['status', 'error', 'success', 'warning', 'info', 'message'];

    private const MESSAGE_KEYS = ['message', 'title', 'body', 'subject', 'hint'];

    private const OWN_EXCEPTIONS = ['CardException', 'SaleException', 'RuleException', 'RefundException', 'MealException', 'TopUpException', 'SisLoginException', 'ConsentException'];

    /** @var array<int, array{0: int|null, 1: string, 2: int}> */
    private array $t = [];

    private int $n = 0;

    /** @var array<int, array{0: int, 1: int, 2: string}> */
    private array $edits = [];

    /** @var array<int, string> */
    private array $keys = [];

    /** @var array<int, array{line: int, text: string}> */
    private array $skipped = [];

    private bool $exceptionFile = false;

    /** @return array{0: string, 1: array<int, string>, 2: array<int, array{line: int, text: string}>} new source, the phrases wrapped, what was left for a person */
    public function wrap(string $source): array
    {
        $this->edits = [];
        $this->keys = [];
        $this->skipped = [];
        $this->tokenize($source);
        $this->exceptionFile = (bool) preg_match('~\bclass\s+\w*Exception\b~', $source);

        for ($i = 0; $i < $this->n; $i++) {
            $this->visit($i);
        }

        return [$this->apply(), $this->keys, $this->skipped];
    }

    // ------------------------------------------------------------------ finding the places

    private function visit(int $i): void
    {
        [$id, $text] = $this->t[$i];

        if ($id === T_STRING) {
            $open = $this->next($i);

            if ($open === null || ! $this->isChar($open, '(')) {
                return;
            }

            $prev = $this->prev($i);
            $via = $prev === null ? '' : $this->t[$prev][1];

            if ($text === 'with' && $via === '->') {
                $this->flash($open);
            } elseif ($text === 'withErrors' && $via === '->') {
                $this->errors($open);
            } elseif ($text === 'withMessages' && $via === '::') {
                $this->errors($open);
            } elseif ($text === 'abort' && $via !== '->' && $via !== '::') {
                $this->argAt($open, 1);
            } elseif (in_array($text, ['abort_if', 'abort_unless'], true) && $via !== '->' && $via !== '::') {
                $this->argAt($open, 2);
            }

            return;
        }

        if ($id === T_NEW) {
            $this->newException($i);

            return;
        }

        if ($id === T_CONSTANT_ENCAPSED_STRING && in_array($this->decode($text), self::MESSAGE_KEYS, true)) {
            $arrow = $this->next($i);

            if ($arrow !== null && $this->t[$arrow][0] === T_DOUBLE_ARROW) {
                $start = $this->next($arrow);

                if ($start !== null) {
                    $this->message($start, $this->exprEnd($start));
                }
            }
        }
    }

    private function flash(int $open): void
    {
        $args = $this->args($open);

        if (count($args) < 2) {
            return;
        }

        $key = $this->singleLiteral($args[0][0], $args[0][1]);

        if ($key !== null && in_array($key, self::FLASH_KEYS, true)) {
            $this->message($args[1][0], $args[1][1]);
        }
    }

    private function errors(int $open): void
    {
        $args = $this->args($open);

        if (! $args) {
            return;
        }

        [$s, $e] = $this->trimRange($args[0][0], $args[0][1]);

        if ($s < $e && $this->isChar($s, '[')) {
            foreach ($this->args($s) as [$elementStart, $elementEnd]) {
                $arrow = $this->topLevelArrow($elementStart, $elementEnd);
                $this->message($arrow === null ? $elementStart : $this->next($arrow), $elementEnd);
            }

            return;
        }

        $this->message($s, $e);
    }

    private function argAt(int $open, int $index): void
    {
        $args = $this->args($open);

        if (isset($args[$index])) {
            $this->message($args[$index][0], $args[$index][1]);
        }
    }

    private function newException(int $i): void
    {
        $nameIndex = $this->next($i);

        if ($nameIndex === null) {
            return;
        }

        $open = $this->next($nameIndex);

        if ($open === null || ! $this->isChar($open, '(')) {
            return;
        }

        $name = $this->t[$nameIndex][1];
        $short = ltrim((string) strrchr('\\' . $name, '\\'), '\\');

        if (in_array(strtolower($short), ['self', 'static'], true)) {
            if (! $this->exceptionFile) {
                return;
            }

            $index = 1;
        } elseif (in_array($short, self::OWN_EXCEPTIONS, true)) {
            $index = 1;
        } elseif ($short === 'InvalidArgumentException') {
            $index = 0;
        } else {
            return;
        }

        $this->argAt($open, $index);
    }

    // ------------------------------------------------------------------ one message

    private function message(?int $start, int $end): void
    {
        if ($start === null) {
            return;
        }

        [$s, $e] = $this->trimRange($start, $end);

        if ($s >= $e) {
            return;
        }

        // already translated
        if ($this->t[$s][0] === T_STRING && in_array($this->t[$s][1], ['__', 'trans', 'trans_choice'], true)) {
            return;
        }

        $built = $this->build($s, $e);

        if ($built === null) {
            $this->reportWords($s, $e);

            return;
        }

        [$template, $pairs, $nested] = $built;

        // not a sentence: no letters, several lines, or a code such as student_inactive
        if (! preg_match('~\p{L}~u', $template) || preg_match('~[\r\n]~', $template) || preg_match('~^[a-z0-9_.:-]+$~', $template)) {
            return;
        }

        $call = "__('" . addcslashes($template, "'\\") . "'";

        if ($pairs) {
            $items = [];

            foreach ($pairs as $name => $expression) {
                $items[] = "'{$name}' => {$expression}";
            }

            $call .= ', [' . implode(', ', $items) . ']';
        }

        $call .= ')';

        $this->edits[] = [$s, $e, $call];
        $this->keys[] = $template;

        if ($nested) {
            $this->skipped[] = ['line' => $this->t[$s][2], 'text' => 'has words of its own inside a value: ' . $this->short($s, $e)];
        }
    }

    /** @return array{0: string, 1: array<string, string>, 2: bool}|null template, placeholders, whether a value holds words */
    private function build(int $s, int $e): ?array
    {
        $parts = [];
        $depth = 0;
        $from = $s;

        for ($j = $s; $j < $e; $j++) {
            [$id, $tx] = $this->t[$j];

            if ($id === null) {
                if (in_array($tx, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($tx, [')', ']', '}'], true)) {
                    $depth--;
                } elseif ($depth === 0 && $tx === '?') {
                    return null;   // a condition: a person decides
                } elseif ($depth === 0 && $tx === '.') {
                    $parts[] = [$from, $j];
                    $from = $j + 1;
                }
            } elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($depth === 0 && $id === T_COALESCE) {
                return null;
            }
        }

        $parts[] = [$from, $e];

        $template = '';
        $pairs = [];
        $nested = false;
        $hasLiteral = false;

        foreach ($parts as [$ps, $pe]) {
            [$ps, $pe] = $this->trimRange($ps, $pe);

            if ($ps >= $pe) {
                return null;
            }

            if ($pe - $ps === 1 && $this->t[$ps][0] === T_CONSTANT_ENCAPSED_STRING) {
                $template .= $this->decode($this->t[$ps][1]);
                $hasLiteral = true;

                continue;
            }

            if ($this->isChar($ps, '"') && $this->isChar($pe - 1, '"') && $pe - $ps >= 2) {
                $segments = $this->interpolated($ps, $pe);

                if ($segments === null) {
                    return null;
                }

                foreach ($segments as $segment) {
                    if (isset($segment['lit'])) {
                        $template .= $segment['lit'];
                        $hasLiteral = true;
                    } else {
                        $template .= $this->placeholder($segment['expr'], $pairs, $nested);
                    }
                }

                continue;
            }

            $template .= $this->placeholder(trim($this->code($ps, $pe)), $pairs, $nested);
        }

        return $hasLiteral ? [$template, $pairs, $nested] : null;
    }

    /** @return array<int, array{lit?: string, expr?: string}>|null the pieces of a "double quoted {$value} string" */
    private function interpolated(int $s, int $e): ?array
    {
        $out = [];
        $j = $s + 1;
        $last = $e - 1;   // the closing quote

        while ($j < $last) {
            [$id, $tx] = $this->t[$j];

            if ($id === T_ENCAPSED_AND_WHITESPACE) {
                $out[] = ['lit' => stripcslashes($tx)];
                $j++;

                continue;
            }

            if ($id === T_CURLY_OPEN) {
                $depth = 1;
                $k = $j + 1;
                $code = '';

                while ($k < $last) {
                    if ($this->isChar($k, '{')) {
                        $depth++;
                    } elseif ($this->isChar($k, '}')) {
                        $depth--;

                        if ($depth === 0) {
                            break;
                        }
                    }

                    $code .= $this->t[$k][1];
                    $k++;
                }

                if ($depth !== 0) {
                    return null;
                }

                $out[] = ['expr' => trim($code)];
                $j = $k + 1;

                continue;
            }

            if ($id === T_VARIABLE) {
                $code = $tx;
                $k = $j + 1;

                if ($k + 1 < $last && $this->t[$k][0] === T_OBJECT_OPERATOR && $this->t[$k + 1][0] === T_STRING) {
                    $code .= '->' . $this->t[$k + 1][1];
                    $k += 2;
                } elseif ($k + 2 < $last + 1 && $this->isChar($k, '[')) {
                    $inner = $this->t[$k + 1] ?? null;
                    $close = $this->t[$k + 2] ?? null;

                    if (! $inner || ! $close || $close[1] !== ']') {
                        return null;
                    }

                    if ($inner[0] === T_NUM_STRING) {
                        $code .= '[' . $inner[1] . ']';
                    } elseif ($inner[0] === T_STRING) {
                        $code .= "['" . $inner[1] . "']";
                    } elseif ($inner[0] === T_VARIABLE) {
                        $code .= '[' . $inner[1] . ']';
                    } else {
                        return null;
                    }

                    $k += 3;
                }

                $out[] = ['expr' => $code];
                $j = $k;

                continue;
            }

            return null;
        }

        return $out;
    }

    /** @param array<string, string> $pairs */
    private function placeholder(string $expression, array &$pairs, bool &$nested): string
    {
        if (preg_match('~[\'"][A-Z][a-z]+[^\'"]*[\'"]~', $expression)) {
            $nested = true;
        }

        $name = $this->nameFor($expression, $pairs);
        $pairs[$name] = $expression;

        return ':' . $name;
    }

    /** @param array<string, string> $used */
    private function nameFor(string $expression, array $used): string
    {
        $name = 'value';

        if (preg_match('~^\$[A-Za-z_]\w*(?:->\??[A-Za-z_]\w*|\[(?:\'[^\']*\'|"[^"]*"|\w+)\])*$~', $expression)
            && preg_match_all('~[A-Za-z_]\w*~', (string) preg_replace('~^\$~', '', $expression), $m)) {
            $name = end($m[0]);
        }

        $name = lcfirst((string) preg_replace('~[^A-Za-z0-9_]~', '', $name)) ?: 'value';

        $base = $name;
        $i = 2;

        while (isset($used[$name])) {
            $name = $base . $i++;
        }

        return $name;
    }

    private function reportWords(int $s, int $e): void
    {
        for ($j = $s; $j < $e; $j++) {
            if ($this->t[$j][0] === T_CONSTANT_ENCAPSED_STRING && preg_match('~[A-Za-z]{3,}~', $this->decode($this->t[$j][1])) && preg_match('~[A-Z\s]~', $this->decode($this->t[$j][1]))) {
                $this->skipped[] = ['line' => $this->t[$s][2], 'text' => 'needs a person: ' . $this->short($s, $e)];

                return;
            }
        }
    }

    // ------------------------------------------------------------------ tokens and ranges

    private function tokenize(string $source): void
    {
        $this->t = [];
        $line = 1;

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                [$id, $text, $tokenLine] = $token;
                $line = $tokenLine;
            } else {
                $id = null;
                $text = $token;
            }

            $this->t[] = [$id, $text, $line];
            $line += substr_count($text, "\n");
        }

        $this->n = count($this->t);
    }

    private function isWs(int $i): bool
    {
        return in_array($this->t[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }

    private function isChar(int $i, string $char): bool
    {
        return $this->t[$i][0] === null && $this->t[$i][1] === $char;
    }

    private function next(int $i): ?int
    {
        for ($j = $i + 1; $j < $this->n; $j++) {
            if (! $this->isWs($j)) {
                return $j;
            }
        }

        return null;
    }

    private function prev(int $i): ?int
    {
        for ($j = $i - 1; $j >= 0; $j--) {
            if (! $this->isWs($j)) {
                return $j;
            }
        }

        return null;
    }

    /** @return array{0: int, 1: int} */
    private function trimRange(int $s, int $e): array
    {
        while ($s < $e && $this->isWs($s)) {
            $s++;
        }

        while ($e > $s && $this->isWs($e - 1)) {
            $e--;
        }

        return [$s, $e];
    }

    /** The arguments (or array elements) between the bracket at $open and its closing bracket. @return array<int, array{0: int, 1: int}> */
    private function args(int $open): array
    {
        $args = [];
        $depth = 0;
        $start = $open + 1;

        for ($j = $open; $j < $this->n; $j++) {
            [$id, $tx] = $this->t[$j];

            if ($id === null && in_array($tx, ['(', '[', '{'], true)) {
                $depth++;
            } elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            } elseif ($id === null && in_array($tx, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    [$s, $e] = $this->trimRange($start, $j);

                    if ($s < $e) {
                        $args[] = [$s, $e];
                    }

                    break;
                }
            } elseif ($id === null && $tx === ',' && $depth === 1) {
                [$s, $e] = $this->trimRange($start, $j);

                if ($s < $e) {
                    $args[] = [$s, $e];
                }

                $start = $j + 1;
            }
        }

        return $args;
    }

    /** Where the expression that starts at $start ends: the next , ) ] } or ; outside any bracket. */
    private function exprEnd(int $start): int
    {
        $depth = 0;

        for ($j = $start; $j < $this->n; $j++) {
            [$id, $tx] = $this->t[$j];

            if ($id === null) {
                if (in_array($tx, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($tx, [')', ']', '}'], true)) {
                    if ($depth === 0) {
                        return $j;
                    }

                    $depth--;
                } elseif (($tx === ',' || $tx === ';') && $depth === 0) {
                    return $j;
                }
            } elseif ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
            }
        }

        return $this->n;
    }

    private function topLevelArrow(int $s, int $e): ?int
    {
        $depth = 0;

        for ($j = $s; $j < $e; $j++) {
            [$id, $tx] = $this->t[$j];

            if ($id === null && in_array($tx, ['(', '[', '{'], true)) {
                $depth++;
            } elseif ($id === null && in_array($tx, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($id === T_DOUBLE_ARROW && $depth === 0) {
                return $j;
            }
        }

        return null;
    }

    private function singleLiteral(int $s, int $e): ?string
    {
        [$s, $e] = $this->trimRange($s, $e);

        return ($e - $s === 1 && $this->t[$s][0] === T_CONSTANT_ENCAPSED_STRING) ? $this->decode($this->t[$s][1]) : null;
    }

    private function decode(string $token): string
    {
        $body = substr($token, 1, -1);

        return $token[0] === "'" ? strtr($body, ['\\\\' => '\\', "\\'" => "'"]) : stripcslashes($body);
    }

    private function code(int $s, int $e): string
    {
        $code = '';

        for ($j = $s; $j < $e; $j++) {
            $code .= $this->t[$j][1];
        }

        return $code;
    }

    private function short(int $s, int $e): string
    {
        $code = trim((string) preg_replace('~\s+~', ' ', $this->code($s, $e)));

        return mb_strlen($code) > 140 ? mb_substr($code, 0, 137) . '...' : $code;
    }

    private function apply(): string
    {
        $edits = $this->edits;
        usort($edits, fn ($a, $b) => $a[0] <=> $b[0]);

        $out = '';
        $i = 0;
        $k = 0;

        while ($i < $this->n) {
            if ($k < count($edits) && $edits[$k][0] === $i) {
                $out .= $edits[$k][2];
                $i = $edits[$k][1];
                $k++;

                while ($k < count($edits) && $edits[$k][0] < $i) {
                    $k++;   // an edit inside the one just made
                }

                continue;
            }

            $out .= $this->t[$i][1];
            $i++;
        }

        return $out;
    }
}
