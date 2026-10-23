/** Helpful form feedback; Laravel remains authoritative. Passwords are never normalized. */
export function textError(value: string, required = false, maximum = 255): string | undefined {
    if (required && !value.trim()) return 'This field is required.';
    if (Array.from(value.trim()).length > maximum) return `Use at most ${maximum} characters.`;
    if (/[\p{Cc}\p{Zl}\p{Zp}]/u.test(value.trim())) return 'Use a single line without control characters.';
}

export function emailError(value: string): string | undefined {
    const text = textError(value, true);
    if (text) return text;
    if (/\s/u.test(value.trim()) || !/^[^@]+@[^@]+\.[^@]+$/u.test(value.trim())) return 'Enter an email address such as name@example.com, without spaces.';
}

export function newPasswordError(password: string, confirmation: string): string | undefined {
    if (Array.from(password).length < 8) return 'Use at least 8 characters.';
    if (new TextEncoder().encode(password).length > 72) return 'Use at most 72 UTF-8 bytes; accented letters and emoji can use several bytes.';
    if (password.includes('\0')) return 'The password must not contain a null character.';
    if (password !== confirmation) return 'The password confirmation does not match.';
}


export function registrationContact(value: string): string {
    if (!value) return '';
    let digits = value.replace(/\D/g, '');
    if (!digits) return '';
    if (value.startsWith('+63') || digits.startsWith('63')) digits = digits.slice(2);
    else if (digits.startsWith('0')) digits = digits.slice(1);
    return '+63' + digits.slice(0, 10);
}

export function registrationName(value: string): string {
    return value.replace(/[^\p{L}\p{M}\p{Zs}.'\u2019\u2018\u200c\u200d\u2010\u2011,\-]/gu, '');
}
