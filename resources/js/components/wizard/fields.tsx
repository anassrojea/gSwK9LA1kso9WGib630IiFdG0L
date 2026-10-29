import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-react';
import { useId, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/** Drop keys set to undefined or '' so optional fields leave the config clean. */
export function patch<T extends object>(target: T, changes: Partial<Record<keyof T, unknown>>): T {
    const next: Record<string, unknown> = { ...target, ...changes };
    for (const [key, value] of Object.entries(changes)) {
        if (value === undefined || value === '') delete next[key];
    }
    return next as T;
}

export function Field({ label, hint, error, htmlFor, children, className }: { label: ReactNode; hint?: ReactNode; error?: string; htmlFor?: string; children: ReactNode; className?: string }) {
    return (
        <div className={cn('grid gap-2', className)}>
            <Label htmlFor={htmlFor} className="text-[0.95rem]">
                {label}
            </Label>
            {children}
            {hint && !error && <p className="text-muted-foreground text-xs leading-relaxed">{hint}</p>}
            {error && (
                <p className="text-destructive text-sm" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}

interface TextFieldProps {
    label: ReactNode;
    value: string | undefined;
    onChange: (value: string) => void;
    error?: string;
    hint?: ReactNode;
    placeholder?: string;
    multiline?: boolean;
    maxLength?: number;
    type?: string;
    dir?: 'ltr' | 'rtl' | 'auto';
    inputMode?: React.HTMLAttributes<HTMLInputElement>['inputMode'];
    className?: string;
}

export function TextField({ label, value, onChange, error, hint, placeholder, multiline, maxLength, type = 'text', dir = 'auto', inputMode, className }: TextFieldProps) {
    const id = useId();
    const common = {
        id,
        value: value ?? '',
        placeholder,
        maxLength,
        dir,
        'aria-invalid': !!error,
        onChange: (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => onChange(e.target.value),
    };

    return (
        <Field label={label} hint={hint} error={error} htmlFor={id} className={className}>
            {multiline ? <Textarea {...common} rows={3} className="min-h-24" /> : <Input {...common} type={type} inputMode={inputMode} className="h-11" />}
        </Field>
    );
}

export function SwitchField({ label, description, checked, onChange, badge }: { label: ReactNode; description?: ReactNode; checked: boolean; onChange: (value: boolean) => void; badge?: ReactNode }) {
    const id = useId();

    return (
        <div className="flex min-h-12 items-center justify-between gap-4 rounded-lg border px-3 py-2">
            <label htmlFor={id} className="grid flex-1 cursor-pointer gap-0.5">
                <span className="flex items-center gap-2 text-[0.95rem] font-medium">
                    {label}
                    {badge}
                </span>
                {description && <span className="text-muted-foreground text-xs">{description}</span>}
            </label>
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

interface ListEditorProps<T> {
    items: T[];
    onChange: (items: T[]) => void;
    renderItem: (item: T, update: (item: T) => void, index: number) => ReactNode;
    newItem: () => T;
    min?: number;
    max?: number;
    addLabel: string;
    itemLabel?: (index: number) => string;
}

/** Add, remove and reorder a list (story chapters, program items, hosts…). */
export function ListEditor<T>({ items, onChange, renderItem, newItem, min = 0, max = 20, addLabel, itemLabel }: ListEditorProps<T>) {
    const { t, n } = useT();
    const move = (from: number, to: number) => {
        const next = [...items];
        const [item] = next.splice(from, 1);
        next.splice(to, 0, item);
        onChange(next);
    };

    return (
        <div className="grid gap-3">
            {items.map((item, i) => (
                <div key={i} className="bg-muted/30 grid gap-3 rounded-lg border p-3">
                    <div className="flex items-center justify-between gap-2">
                        <span className="text-muted-foreground text-xs font-semibold">{itemLabel ? itemLabel(i) : n(i + 1)}</span>
                        <div className="flex items-center gap-1">
                            <Button type="button" variant="ghost" size="icon" className="size-9" disabled={i === 0} onClick={() => move(i, i - 1)} aria-label={t('wizard.common.move_up')}>
                                <ArrowUp />
                            </Button>
                            <Button type="button" variant="ghost" size="icon" className="size-9" disabled={i === items.length - 1} onClick={() => move(i, i + 1)} aria-label={t('wizard.common.move_down')}>
                                <ArrowDown />
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="text-destructive size-9"
                                disabled={items.length <= min}
                                onClick={() => onChange(items.filter((_, j) => j !== i))}
                                aria-label={t('wizard.common.remove')}
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    </div>
                    {renderItem(item, (updated) => onChange(items.map((old, j) => (j === i ? updated : old))), i)}
                </div>
            ))}
            {items.length < max && (
                <Button type="button" variant="outline" className="h-11 border-dashed" onClick={() => onChange([...items, newItem()])}>
                    <Plus /> {addLabel}
                </Button>
            )}
        </div>
    );
}
