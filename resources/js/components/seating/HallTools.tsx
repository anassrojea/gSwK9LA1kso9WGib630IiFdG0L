import { Move, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';

import { HallSvg } from '@/components/seating/HallSvg';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { generateHall, MAX_CHAIRS, MAX_TABLES, type GeneratorShape, type HallLayout, type HallLayoutMode } from '@/lib/hall-generator';
import { useT } from '@/lib/i18n';
import { nudgeTable } from '@/lib/seating/plan';
import { cn } from '@/lib/utils';

const clamp = (v: number, min: number, max: number) => Math.max(min, Math.min(max, Number.isFinite(v) ? Math.round(v) : min));

/**
 * The hall generator (plan section 7.2) with an instant preview from the
 * TypeScript port; the server normalises (and the PHP generator matches) on save.
 */
export function HallGenerator({
    initialName,
    limits,
    busy,
    onUse,
    submitLabel,
}: {
    initialName: string;
    limits?: { tables: number; chairs: number };
    busy?: boolean;
    onUse: (layout: HallLayout, name: string) => void;
    submitLabel?: string;
}) {
    const { t, n, sep } = useT();
    const [tables, setTables] = useState(20);
    const [chairs, setChairs] = useState(8);
    const [mode, setMode] = useState<HallLayoutMode>('mixed');
    const [shape, setShape] = useState<GeneratorShape>('round');
    const [name, setName] = useState(initialName);
    const maxTables = limits?.tables ?? MAX_TABLES;
    const maxChairs = limits?.chairs ?? MAX_CHAIRS;

    const preview = useMemo(
        () => generateHall({ tables: clamp(tables, 1, maxTables), chairs: clamp(chairs, 1, maxChairs), layout: mode, shape, name: name || 'Main hall' }),
        [tables, chairs, mode, shape, name, maxTables, maxChairs],
    );

    return (
        <div className="grid gap-4" data-hall-generator>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1.5 sm:col-span-2">
                    <Label htmlFor="hall-name">{t('seating.hall.name')}</Label>
                    <Input id="hall-name" value={name} maxLength={120} onChange={(e) => setName(e.target.value)} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="hall-tables">{t('seating.hall.table_count')}</Label>
                    <Input id="hall-tables" type="number" inputMode="numeric" min={1} max={maxTables} value={tables} onChange={(e) => setTables(Number(e.target.value))} onBlur={() => setTables((v) => clamp(v, 1, maxTables))} />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="hall-chairs">{t('seating.hall.chairs')}</Label>
                    <Input id="hall-chairs" type="number" inputMode="numeric" min={1} max={maxChairs} value={chairs} onChange={(e) => setChairs(Number(e.target.value))} onBlur={() => setChairs((v) => clamp(v, 1, maxChairs))} />
                </div>
                <fieldset className="grid gap-1.5 sm:col-span-2">
                    <legend className="mb-1.5 text-sm font-medium">{t('seating.hall.layout')}</legend>
                    <div className="bg-muted grid grid-cols-2 gap-1 rounded-lg p-1" role="radiogroup">
                        {(['mixed', 'split'] as const).map((m) => (
                            <button
                                key={m}
                                type="button"
                                role="radio"
                                aria-checked={mode === m}
                                onClick={() => setMode(m)}
                                className={cn('h-10 rounded-md text-sm font-medium', mode === m ? 'bg-background shadow-sm' : 'text-muted-foreground')}
                            >
                                {t(`seating.hall.${m}`)}
                            </button>
                        ))}
                    </div>
                </fieldset>
                <fieldset className="grid gap-1.5 sm:col-span-2">
                    <legend className="mb-1.5 text-sm font-medium">{t('halls.inspector.shape')}</legend>
                    <div className="bg-muted grid grid-cols-2 gap-1 rounded-lg p-1" role="radiogroup">
                        {(['round', 'rect'] as const).map((s) => (
                            <button
                                key={s}
                                type="button"
                                role="radio"
                                aria-checked={shape === s}
                                onClick={() => setShape(s)}
                                className={cn('h-10 rounded-md text-sm font-medium', shape === s ? 'bg-background shadow-sm' : 'text-muted-foreground')}
                                data-generator-shape={s}
                            >
                                {t(`halls.elements.${s}`)}
                            </button>
                        ))}
                    </div>
                </fieldset>
            </div>
            <div className="bg-muted/40 rounded-xl border p-2">
                <HallSvg layout={preview} mode="view" label={t('seating.hall.preview')} />
                <p className="text-muted-foreground px-1 pt-1 text-xs">
                    {t('seating.hall.preview')}
                    {sep}
                    {n(preview.tables.length)} × {n(clamp(chairs, 1, maxChairs))} = {n(preview.tables.reduce((s, x) => s + x.seats, 0))}
                </p>
            </div>
            <Button type="button" className="h-11" disabled={busy} onClick={() => onUse(preview, name.trim() || 'Main hall')} data-use-generated>
                <Sparkles /> {submitLabel ?? t('seating.hall.use_generated')}
            </Button>
        </div>
    );
}

/**
 * Nudge tables by dragging (pointer) or with the arrow keys; x / y go into the layout JSON.
 */
export function HallEditor({ layout, onChange, className }: { layout: HallLayout; onChange: (layout: HallLayout) => void; className?: string }) {
    const { t } = useT();
    const [selected, setSelected] = useState<string | null>(null);

    return (
        <div className={cn('grid gap-2', className)} data-hall-editor>
            <p className="text-muted-foreground flex items-start gap-2 text-sm">
                <Move className="mt-0.5 size-4 shrink-0" /> {t('seating.hall.edit_hint')}
            </p>
            <div className="rounded-xl border bg-white p-2">
                <HallSvg
                    layout={layout}
                    mode="edit"
                    selectedTable={selected}
                    onTableClick={setSelected}
                    onTableMove={(id, x, y) => {
                        const table = layout.tables.find((tb) => tb.id === id);
                        if (table) onChange(nudgeTable(layout, id, x - table.x, y - table.y));
                    }}
                    onTableNudge={(id, dx, dy) => {
                        setSelected(id);
                        onChange(nudgeTable(layout, id, dx, dy));
                    }}
                />
            </div>
        </div>
    );
}
