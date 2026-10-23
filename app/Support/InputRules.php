<?php

namespace App\Support;

use App\Models\User;
use Closure;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class InputRules
{
    public static function academicYear(): array
    {
        return ['string', function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || ! preg_match('/^([1-9]\d{3})-([1-9]\d{3})$/', $value, $years)) {
                $fail('Enter the academic year as YYYY-YYYY, for example 2025-2026.');
            } elseif ((int) $years[2] !== (int) $years[1] + 1) {
                $fail('Use consecutive academic years, for example 2025-2026.');
            }
        }];
    }

    public static function contactNumber(string $value): string
    {
        $value = preg_replace('/[ ()-]/', '', $value);
        if (preg_match('/^0([0-9]{10})$/D', $value, $matches) || preg_match('/^(?:63)?([0-9]{10})$/D', $value, $matches)) {
            return '+63'.$matches[1];
        }

        return $value;
    }

    /** Normalize only explicitly selected text fields; passwords are never included. */
    public static function normalize(array $input, array $fields, array $emails = []): array
    {
        foreach ($fields as $field) {
            if (isset($input[$field]) && is_string($input[$field])) {
                $input[$field] = Str::trim($input[$field]);
                if (in_array($field, $emails, true)) {
                    $input[$field] = Str::lower($input[$field]);
                }
            }
        }

        return $input;
    }

    public static function name(): array
    {
        return ['bail', 'required', 'string', 'max:255', function (string $attribute, mixed $value, Closure $fail): void {
            if (! preg_match("/\\A[\\p{L}\\p{M}\\p{Zs}.'\\x{2019}\\x{2018}\\x{200c}\\x{200d}\\x{2010}\\x{2011},\\-]+\\z/u", $value) || ! preg_match('/\\p{L}/u', $value)) {
                $fail('Enter a name using letters, spaces, apostrophes, hyphens, periods or commas.');
            }
        }];
    }

    public static function email(?int $ignoreUser = null, bool $unique = false, bool $gmail = false): array
    {
        $rules = ['bail', 'required', 'string', 'max:255', 'email', function (string $attribute, mixed $value, Closure $fail): void {
            $domain = substr(strrchr($value, '@') ?: '', 1);
            if (preg_match('/[\\s\\p{Z}\\p{C}]/u', $value) || ! str_contains($domain, '.')) {
                $fail('Enter an email address without spaces and with a complete domain, such as name@example.com.');
            }
        }];
        if ($gmail) {
            $rules[] = function (string $attribute, mixed $value, Closure $fail): void {
                if (! Str::endsWith(Str::lower($value), '@gmail.com')) {
                    $fail('Use an @gmail.com email address.');
                }
            };
        }
        if ($unique) {
            $rules[] = function (string $attribute, mixed $value, Closure $fail) use ($ignoreUser): void {
                if (User::query()->when($ignoreUser !== null, fn ($query) => $query->whereKeyNot($ignoreUser))
                    ->whereRaw('LOWER(email) = ?', [Str::lower($value)])->exists()) {
                    $fail('The email has already been taken.');
                }
            };
        }

        return $rules;
    }

    public static function password(int $minimum = 8): array
    {
        return ['bail', 'required', 'string', Password::min($minimum), self::maxBytes(72), function (string $attribute, mixed $value, Closure $fail): void {
            if (str_contains($value, "\0")) {
                $fail('The password must not contain a null character.');
            }
        }, 'confirmed'];
    }

    public static function maxBytes(int $maximum): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($maximum): void {
            if (is_string($value) && strlen($value) > $maximum) {
                $fail("The {$attribute} must not exceed {$maximum} UTF-8 bytes.");
            }
        };
    }

    public static function singleLine(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && preg_match('/[\\p{Cc}\\p{Zl}\\p{Zp}]/u', $value)) {
                $fail("The {$attribute} must be a single line without control characters.");
            }
        };
    }
}
