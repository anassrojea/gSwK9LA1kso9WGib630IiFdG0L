import { router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, Download, FileUp, Loader2, UserPlus, XCircle } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/fields';
import { toastFlash } from '@/lib/dashboard/flash';
import { parseGuestList } from '@/lib/guest-list';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { CsvPreview } from '@/types/dashboard';

interface Urls {
    paste: string;
    csv_preview: string;
    csv_import: string;
}

/**
 * "Add guests": paste a list (the wizard's parser and endpoint) or import a
 * CSV file after a preview that shows every row's problems.
 */
export function AddGuestsDialog({ open, onOpenChange, urls }: { open: boolean; onOpenChange: (open: boolean) => void; urls: Urls }) {
    const { t, dir } = useT();
    const [tab, setTab] = useState('paste');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent closeLabel={t('wizard.common.close')} className="max-h-[92svh] overflow-y-auto sm:max-w-2xl" data-add-guests>
                <DialogHeader>
                    <DialogTitle>{t('dashboard.add.title')}</DialogTitle>
                </DialogHeader>
                <Tabs value={tab} onValueChange={setTab} dir={dir}>
                    <TabsList>
                        <TabsTrigger value="paste">{t('dashboard.add.paste')}</TabsTrigger>
                        <TabsTrigger value="csv" data-tab="csv">
                            {t('dashboard.add.csv')}
                        </TabsTrigger>
                    </TabsList>
                    <TabsContent value="paste">
                        <PasteForm url={urls.paste} onDone={() => onOpenChange(false)} />
                    </TabsContent>
                    <TabsContent value="csv">
                        <CsvImport urls={urls} onDone={() => onOpenChange(false)} />
                    </TabsContent>
                </Tabs>
            </DialogContent>
        </Dialog>
    );
}

function PasteForm({ url, onDone }: { url: string; onDone: () => void }) {
    const { t, tc, n } = useT();
    const form = useForm({ text: '' });
    const parsed = useMemo(() => parseGuestList(form.data.text), [form.data.text]);
    const seats = parsed.reduce((sum, g) => sum + g.seats, 0);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.post(url, {
            preserveScroll: true,
            onSuccess: (page) => {
                toastFlash(page);
                form.reset();
                onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="grid gap-3">
            <Field label={t('wizard.guests.paste_label')} hint={t('wizard.guests.paste_hint')} error={form.errors.text}>
                <Textarea
                    dir="auto"
                    rows={6}
                    className="min-h-40 text-base"
                    placeholder={t('wizard.guests.paste_placeholder')}
                    value={form.data.text}
                    onChange={(e) => form.setData('text', e.target.value)}
                    aria-invalid={!!form.errors.text}
                />
            </Field>
            {parsed.length > 0 && (
                <div className="bg-muted/50 grid gap-2 rounded-lg p-3">
                    <p className="text-sm font-semibold">{t('wizard.guests.found', { families: tc('wizard.guests.families', parsed.length), seats: tc('wizard.guests.seat_count', seats) })}</p>
                    <ul className="flex flex-wrap gap-1.5">
                        {parsed.slice(0, 12).map((g, i) => (
                            <li key={i} className="bg-background rounded-full border px-2.5 py-1 text-xs">
                                {g.name} <b className="text-primary ms-1">{n(g.seats)}</b>
                            </li>
                        ))}
                        {parsed.length > 12 && <li className="text-muted-foreground px-1 text-xs">+{n(parsed.length - 12)}</li>}
                    </ul>
                </div>
            )}
            <Button type="submit" className="h-12 text-base" disabled={form.processing || parsed.length === 0}>
                <UserPlus /> {parsed.length ? t('wizard.guests.add_families', { families: tc('wizard.guests.families', parsed.length) }) : t('wizard.guests.add_list')}
            </Button>
        </form>
    );
}

function sampleCsv(t: (key: string) => string): string {
    const header = ['name', 'seats', 'phone', 'tags'].map((c) => t(`dashboard.csv.columns.${c}`)).join(',');
    const rows = ['عائلة خالد العلي,4,+962791234567,أقارب', 'أم فيصل,3,,جيران | أصدقاء', 'Sara & Omar,2,+971501234567,work'];
    return `﻿${header}\n${rows.join('\n')}\n`;
}

function CsvImport({ urls, onDone }: { urls: Urls; onDone: () => void }) {
    const { t, tc, n } = useT();
    const input = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<CsvPreview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [busy, setBusy] = useState(false);

    const send = async <T,>(url: string, chosen: File) => {
        const body = new FormData();
        body.append('file', chosen);
        return axios.post<T>(url, body, { headers: { Accept: 'application/json' } });
    };

    const errorOf = (err: unknown) => {
        const data = axios.isAxiosError(err) ? (err.response?.data as { errors?: Record<string, string[]>; message?: string } | undefined) : undefined;
        return data?.errors?.file?.[0] ?? data?.message ?? t('wizard.save.error');
    };

    const choose = async (chosen: File | undefined) => {
        if (!chosen) return;
        setFile(chosen);
        setPreview(null);
        setError(null);
        setBusy(true);
        try {
            const { data } = await send<CsvPreview>(urls.csv_preview, chosen);
            setPreview(data);
        } catch (err) {
            setError(errorOf(err));
        } finally {
            setBusy(false);
        }
    };

    const importRows = async () => {
        if (!file) return;
        setBusy(true);
        try {
            const { data } = await send<{ message: string }>(urls.csv_import, file);
            toast.success(data.message);
            router.reload();
            onDone();
        } catch (err) {
            setError(errorOf(err));
        } finally {
            setBusy(false);
        }
    };

    const downloadSample = () => {
        const blob = new Blob([sampleCsv(t)], { type: 'text/csv;charset=utf-8' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'daawa-guests-sample.csv';
        link.click();
        URL.revokeObjectURL(link.href);
    };

    return (
        <div className="grid gap-3" data-csv>
            <p className="text-muted-foreground text-sm leading-relaxed">{t('dashboard.add.csv_hint')}</p>
            <div className="flex flex-wrap gap-2">
                <input ref={input} type="file" accept=".csv,text/csv" className="sr-only" onChange={(e) => choose(e.target.files?.[0])} data-csv-input />
                <Button type="button" className="h-11" onClick={() => input.current?.click()} disabled={busy}>
                    {busy && !preview ? <Loader2 className="animate-spin" /> : <FileUp />} {file ? file.name : t('dashboard.add.choose')}
                </Button>
                <Button type="button" variant="ghost" className="h-11" onClick={downloadSample}>
                    <Download /> {t('dashboard.add.sample')}
                </Button>
            </div>

            {error && (
                <p className="text-destructive text-sm" role="alert">
                    {error}
                </p>
            )}

            {preview && (
                <div className="grid gap-3" data-csv-preview>
                    <div className="grid gap-1 text-sm">
                        <p className="text-muted-foreground text-xs">{preview.header ? t('dashboard.csv.header_found') : t('dashboard.csv.no_header')}</p>
                        <p className="font-semibold text-emerald-700 dark:text-emerald-400">{t('dashboard.csv.ready', { count: preview.valid, seats: preview.seats })}</p>
                        {preview.invalid > 0 && <p className="text-destructive font-medium">{t('dashboard.csv.skipped', { count: preview.invalid })}</p>}
                        {!preview.fits && preview.left !== null && (
                            <p className="rounded-md bg-amber-100 px-3 py-2 text-amber-950" role="alert">
                                {t('dashboard.csv.over_limit', { left: preview.left, seats: preview.seats })}
                            </p>
                        )}
                    </div>
                    <div className="max-h-72 overflow-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/60 text-muted-foreground sticky top-0">
                                <tr>
                                    <th className="px-2 py-1.5 text-start font-medium">{t('dashboard.csv.line')}</th>
                                    <th className="px-2 py-1.5 text-start font-medium">{t('dashboard.csv.columns.name')}</th>
                                    <th className="px-2 py-1.5 text-start font-medium">{t('dashboard.csv.columns.seats')}</th>
                                    <th className="hidden px-2 py-1.5 text-start font-medium sm:table-cell">{t('dashboard.csv.columns.phone')}</th>
                                    <th className="hidden px-2 py-1.5 text-start font-medium sm:table-cell">{t('dashboard.csv.columns.tags')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {preview.rows.map((row) => (
                                    <tr key={row.line} className={cn('border-t align-top', row.errors.length > 0 && 'bg-destructive/5')} data-csv-row={row.errors.length ? 'error' : 'ok'}>
                                        <td className="text-muted-foreground px-2 py-1.5 tabular-nums">{n(row.line)}</td>
                                        <td className="px-2 py-1.5">
                                            <bdi className={cn(!row.name && 'text-muted-foreground italic')}>{row.name || '—'}</bdi>
                                            {row.errors.map((message) => (
                                                <span key={message} className="text-destructive flex items-center gap-1 text-xs">
                                                    <XCircle className="size-3 shrink-0" /> {message}
                                                </span>
                                            ))}
                                            {row.warnings.map((message) => (
                                                <span key={message} className="flex items-center gap-1 text-xs text-amber-700">
                                                    <AlertTriangle className="size-3 shrink-0" /> {message}
                                                </span>
                                            ))}
                                        </td>
                                        <td className="px-2 py-1.5 tabular-nums">{n(row.seats)}</td>
                                        <td className="hidden px-2 py-1.5 sm:table-cell">
                                            <bdi dir="ltr">{row.phone ?? ''}</bdi>
                                        </td>
                                        <td className="hidden px-2 py-1.5 text-xs sm:table-cell">{row.tags.join('، ')}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <Button className="h-12 text-base" onClick={importRows} disabled={busy || preview.valid === 0 || !preview.fits} data-csv-import>
                        {busy ? <Loader2 className="animate-spin" /> : <UserPlus />}
                        {t('dashboard.csv.import', { families: tc('wizard.guests.families', preview.valid) })}
                    </Button>
                </div>
            )}
        </div>
    );
}
