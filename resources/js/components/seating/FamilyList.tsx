import { useDraggable } from '@dnd-kit/core';
import { Baby, GripVertical, Search, Users } from 'lucide-react';
import { memo } from 'react';

import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { useT } from '@/lib/i18n';
import { tableNumber, type Family, type FamilyFilters, type Section } from '@/lib/seating/plan';
import { cn } from '@/lib/utils';

export interface FamilyRow {
    family: Family;
    color: string;
    seated: number;
    tables: string[];
}

interface Props {
    rows: FamilyRow[];
    total: number;
    filters: FamilyFilters;
    onFilters: (f: FamilyFilters) => void;
    selectedId: string | null;
    onSelect: (id: string) => void;
    onSection: (id: string, section: Section | null) => void;
    disabled?: boolean;
}

/**
 * Confirmed families, filterable, each one draggable onto a table (pointer,
 * touch or keyboard) or selectable with a tap for "tap a table" seating.
 */
export function FamilyList({ rows, total, filters, onFilters, selectedId, onSelect, onSection, disabled }: Props) {
    const { t, dir } = useT();

    return (
        <section className="grid min-h-0 gap-3" aria-labelledby="families-title" data-family-list>
            <div className="flex items-baseline justify-between gap-2">
                <h2 id="families-title" className="font-bold">
                    {t('seating.list.title')}
                </h2>
            </div>
            <div className="grid gap-2">
                <div className="relative">
                    <Search className="text-muted-foreground pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2" />
                    <Input value={filters.query} onChange={(e) => onFilters({ ...filters, query: e.target.value })} placeholder={t('seating.list.search')} aria-label={t('seating.list.search')} className="h-10 ps-9" type="search" />
                </div>
                <div className="grid grid-cols-2 gap-2">
                    <NativeSelect dir={dir} className="h-10" value={filters.section} onChange={(e) => onFilters({ ...filters, section: e.target.value as FamilyFilters['section'] })} aria-label={t('seating.section.label')}>
                        <option value="all">{t('seating.list.all_sections')}</option>
                        <option value="men">{t('seating.section.men')}</option>
                        <option value="women">{t('seating.section.women')}</option>
                        <option value="none">{t('seating.section.none')}</option>
                    </NativeSelect>
                    <NativeSelect dir={dir} className="h-10" value={filters.seated} onChange={(e) => onFilters({ ...filters, seated: e.target.value as FamilyFilters['seated'] })} aria-label={t('seating.list.all')}>
                        <option value="all">{t('seating.list.all')}</option>
                        <option value="unseated">{t('seating.list.unseated')}</option>
                        <option value="seated">{t('seating.list.seated')}</option>
                    </NativeSelect>
                </div>
            </div>
            <p className="text-muted-foreground text-xs">{t('seating.list.drag_hint')}</p>
            {total === 0 ? (
                <p className="text-muted-foreground rounded-lg border border-dashed p-4 text-sm">{t('seating.list.empty')}</p>
            ) : rows.length === 0 ? (
                <p className="text-muted-foreground p-2 text-sm">{t('seating.list.no_match')}</p>
            ) : (
                <ul className="grid gap-1.5" role="list">
                    {rows.map((row) => (
                        <FamilyItem key={row.family.id} row={row} selected={selectedId === row.family.id} onSelect={onSelect} onSection={onSection} disabled={disabled} />
                    ))}
                </ul>
            )}
        </section>
    );
}

const FamilyItem = memo(function FamilyItem({ row, selected, onSelect, onSection, disabled }: { row: FamilyRow; selected: boolean; onSelect: (id: string) => void; onSection: (id: string, s: Section | null) => void; disabled?: boolean }) {
    const { t, tc, n } = useT();
    const { family, seated, tables } = row;
    const { attributes, listeners, setNodeRef, isDragging } = useDraggable({ id: `family:${family.id}`, data: { family: family.id }, disabled });
    const missing = family.people - seated;

    return (
        <li
            ref={setNodeRef}
            className={cn(
                'group relative flex touch-manipulation items-stretch select-none gap-2 rounded-lg border bg-white p-2 transition-shadow',
                selected && 'border-primary ring-primary/30 ring-2',
                isDragging && 'opacity-40',
                missing === 0 && 'bg-zinc-50',
            )}
            data-family={family.id}
            data-seated={seated}
        >
            <span className="w-1 shrink-0 rounded-full" style={{ background: row.color }} aria-hidden />
            <button
                type="button"
                className="flex min-w-0 flex-1 cursor-grab touch-none items-start gap-1.5 text-start outline-none active:cursor-grabbing"
                {...listeners}
                {...attributes}
                aria-pressed={selected}
                aria-describedby={`fam-${family.id}-meta`}
                onClick={() => onSelect(family.id)}
            >
                <GripVertical className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-hidden />
                <span className="grid min-w-0 gap-1">
                    <span className="truncate text-sm font-semibold">
                        <bdi>{family.name}</bdi>
                    </span>
                    <span id={`fam-${family.id}-meta`} className="text-muted-foreground flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs">
                        <span className="inline-flex items-center gap-1">
                            <Users className="size-3.5" />
                            {tc('seating.list.people', family.people)}
                        </span>
                        {family.kids > 0 && (
                            <span className="inline-flex items-center gap-1">
                                <Baby className="size-3.5" />
                                {t('seating.list.kids', { count: family.kids })}
                            </span>
                        )}
                        {tables.length > 0 && (
                            <span className="text-foreground font-medium">
                                {tables.length === 1 ? t('seating.list.at_table', { table: n(tableNumber(tables[0])) }) : t('seating.list.at_tables', { table: '', tables: tables.map((x) => n(tableNumber(x))).join('، ') })}
                            </span>
                        )}
                        {missing > 0 && seated > 0 && <span className="font-medium text-amber-700">{t('seating.list.missing', { count: missing })}</span>}
                    </span>
                </span>
            </button>
            <SectionToggle value={family.section} onChange={(s) => onSection(family.id, s)} />
        </li>
    );
});

function SectionToggle({ value, onChange }: { value: Section | null; onChange: (s: Section | null) => void }) {
    const { t } = useT();
    const options: { v: Section | null; label: string; full: string }[] = [
        { v: 'men', label: t('seating.section.men').slice(0, 1), full: t('seating.section.men') },
        { v: 'women', label: t('seating.section.women').slice(0, 1), full: t('seating.section.women') },
        { v: null, label: '–', full: t('seating.section.none') },
    ];
    return (
        <div className="bg-muted flex shrink-0 items-center self-center rounded-md p-0.5" role="radiogroup" aria-label={t('seating.section.label')}>
            {options.map((o) => (
                <button
                    key={o.full}
                    type="button"
                    role="radio"
                    aria-checked={value === o.v}
                    title={o.full}
                    aria-label={o.full}
                    onClick={() => value !== o.v && onChange(o.v)}
                    className={cn(
                        'size-7 rounded text-xs font-bold',
                        value === o.v ? (o.v === 'men' ? 'bg-sky-600 text-white' : o.v === 'women' ? 'bg-rose-600 text-white' : 'bg-background shadow-sm') : 'text-muted-foreground',
                    )}
                >
                    {o.label}
                </button>
            ))}
        </div>
    );
}
