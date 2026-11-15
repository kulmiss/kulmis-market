<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * Removes invisible control characters (null bytes etc.) and right-to-left
 * override characters from every text field. They have no place in names,
 * addresses or notes and are used to break exports or disguise text
 * ("‮gnp.exe"). Line breaks and tabs stay; Amharic and emojis are untouched.
 */
class StripControlCharacters extends TransformsRequest
{
    /** @var list<string> */
    protected array $except = ['password', 'password_confirmation', 'current_password'];

    protected function transform($key, $value)
    {
        if (! is_string($value) || in_array($key, $this->except, true)) {
            return $value;
        }

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\x{202A}|\x{202B}|\x{202C}|\x{202D}|\x{202E}|[\x{2066}-\x{2069}]/u', '', $value);
    }
}
