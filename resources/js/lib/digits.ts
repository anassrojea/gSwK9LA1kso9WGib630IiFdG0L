/**
 * Arabic-Indic digits in Arabic invitations, Western digits in English ones
 * (plan section 6.4). Twin of App\Services\Digits.
 */
const ARABIC = '٠١٢٣٤٥٦٧٨٩';

export function toLocalDigits(value: string | number, locale: string): string {
    const text = String(value);

    if (locale.startsWith('ar')) {
        return text.replace(/\d/g, (d) => ARABIC[Number(d)]);
    }

    return text.replace(/[٠-٩]/g, (d) => String(ARABIC.indexOf(d)));
}
