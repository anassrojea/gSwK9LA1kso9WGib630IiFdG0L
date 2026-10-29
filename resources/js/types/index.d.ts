export interface User {
    id: string;
    name: string;
    email: string | null;
    locale: 'ar' | 'en';
    role: 'user' | 'admin';
}

export type Translations = Record<string, string | Record<string, string>>;

export interface SharedProps {
    [key: string]: unknown;
    appName: string;
    auth: { user: User | null };
    locale: 'ar' | 'en';
    dir: 'rtl' | 'ltr';
    locales: string[];
    translations: Translations;
    flash: { status: string | null; warning?: string | null };
}
