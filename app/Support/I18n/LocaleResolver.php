<?php

namespace App\Support\I18n;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Which language to answer in. In order:
 *   ?lang=km on the address  ->  the person's saved choice  ->  this browser session  ->  the language cookie
 *   ->  (for the parent app only) the Accept-Language header  ->  APP_LOCALE.
 * A web browser's own language is NOT used: a Khmer school whose staff use English browsers would otherwise never get Khmer.
 */
class LocaleResolver
{
    /** @return array<int, string> */
    public function supported(): array
    {
        return array_keys((array) config('locales.supported', ['en' => 'English']));
    }

    public function resolve(Request $request, ?User $user, bool $useHeader = false): string
    {
        $supported = $this->supported();

        $candidates = [
            $request->query('lang'),
            $user?->locale,
            $request->hasSession() ? $request->session()->get('locale') : null,
            $request->cookie('locale'),
            $useHeader ? $this->fromHeader((string) $request->header('Accept-Language'), $supported) : null,
            config('locales.default', 'en'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $supported, true)) {
                return $candidate;
            }
        }

        return 'en';
    }

    /** "km-KH,km;q=0.9,en;q=0.8" -> km. Returns null when none of the languages is one of ours. */
    public function fromHeader(string $header, array $supported): ?string
    {
        if (trim($header) === '') {
            return null;
        }

        $choices = [];

        foreach (explode(',', $header) as $position => $part) {
            $bits = explode(';', trim($part));
            $language = preg_split('/[-_]/', strtolower(trim($bits[0])))[0] ?? '';
            $quality = 1.0;

            foreach (array_slice($bits, 1) as $parameter) {
                if (preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $parameter, $m)) {
                    $quality = (float) $m[1];
                }
            }

            if ($language !== '' && in_array($language, $supported, true)) {
                $choices[] = [$quality, -$position, $language];   // the best quality first, then the earliest
            }
        }

        if (! $choices) {
            return null;
        }

        rsort($choices);

        return $choices[0][2];
    }
}
