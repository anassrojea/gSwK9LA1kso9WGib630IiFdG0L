export interface User {
    id: string;
    name: string;
    email: string | null;
    locale: 'ar' | 'en';
    role: 'user' | 'admin';
    /** set while an account deletion is pending (Account → Privacy) */
    deletion_scheduled_for?: string | null;
}

export type Translations = Record<string, string | Record<string, string>>;

export interface SharedProps {
    [key: string]: unknown;
    appName: string;
    auth: { user: User | null; providers?: { google: boolean } };
    locale: 'ar' | 'en';
    dir: 'rtl' | 'ltr';
    locales: string[];
    translations: Translations;
    flash: { status: string | null; warning?: string | null };
}
