import { FieldError } from '@/components/FieldError';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useT } from '@/lib/i18n';

export type ProfileForm = {
    name: string;
    type: string;
    city: string;
    contact_phone: string;
    contact_email: string;
    logo: File | null;
};

/** The slice of an Inertia useForm the fields need (Create and Settings have bigger forms). */
interface FormLike {
    data: ProfileForm;
    errors: Partial<Record<string, string>>;
    setData: <K extends keyof ProfileForm>(key: K, value: ProfileForm[K]) => void;
}

/**
 * Name, type, city, contact and logo: shared by "Create an organization" and Settings.
 */
export function OrgProfileFields({ form, types }: { form: FormLike; types: string[] }) {
    const { t, dir } = useT();
    const text = (name: 'name' | 'city' | 'contact_phone' | 'contact_email', label: string, extra: Record<string, unknown> = {}) => (
        <div className="grid gap-2">
            <Label htmlFor={`org-${name}`}>{label}</Label>
            <Input
                id={`org-${name}`}
                value={form.data[name]}
                onChange={(e) => form.setData(name, e.target.value)}
                aria-invalid={!!form.errors[name]}
                className="h-11"
                {...extra}
            />
            <FieldError message={form.errors[name]} />
        </div>
    );

    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <div className="sm:col-span-2">{text('name', t('org.create.name'), { required: true, placeholder: t('org.create.name_placeholder'), autoComplete: 'organization' })}</div>
            <div className="grid gap-2">
                <Label htmlFor="org-type">{t('org.create.type')}</Label>
                <NativeSelect id="org-type" dir={dir} value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}>
                    {types.map((type) => (
                        <option key={type} value={type}>
                            {t(`org.types.${type}`)}
                        </option>
                    ))}
                </NativeSelect>
                <FieldError message={form.errors.type} />
            </div>
            {text('city', t('org.create.city'), { autoComplete: 'address-level2' })}
            {text('contact_phone', t('org.create.phone'), { type: 'tel', dir: 'ltr', autoComplete: 'tel', className: 'h-11 text-start' })}
            {text('contact_email', t('org.create.email'), { type: 'email', dir: 'ltr', autoComplete: 'email', className: 'h-11 text-start' })}
            <div className="grid gap-2 sm:col-span-2">
                <Label htmlFor="org-logo">{t('org.create.logo')}</Label>
                <Input
                    id="org-logo"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    onChange={(e) => form.setData('logo', e.target.files?.[0] ?? null)}
                    className="h-11 py-2"
                    data-org-logo-input
                />
                <p className="text-muted-foreground text-xs">{t('org.create.logo_hint')}</p>
                <FieldError message={form.errors.logo} />
            </div>
        </div>
    );
}
