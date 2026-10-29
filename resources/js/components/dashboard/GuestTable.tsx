import { createColumnHelper, flexRender, getCoreRowModel, getSortedRowModel, useReactTable, type SortingState } from '@tanstack/react-table';
import { ArrowDown, ArrowUp, ArrowUpDown, Check, Copy, DoorOpen, MessageCircle, MoreVertical, Pencil, Phone, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useMediaQuery } from '@/hooks/useMediaQuery';
import { clockTime, relativeTime, STATUS_ORDER, type GuestRow, type GuestStatus } from '@/lib/dashboard/guests';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

const CHIP: Record<GuestStatus, string> = {
    not_opened: 'border-transparent bg-muted text-muted-foreground',
    opened: 'border-transparent bg-sky-100 text-sky-900 dark:bg-sky-900/40 dark:text-sky-100',
    attending: 'border-transparent bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100',
    declined: 'border-transparent bg-rose-100 text-rose-900 dark:bg-rose-900/40 dark:text-rose-100',
};

export function StatusChip({ status }: { status: GuestStatus }) {
    const { t } = useT();
    return (
        <Badge variant="outline" className={cn('h-6 px-2.5', CHIP[status])} data-status={status}>
            {t(`dashboard.status.${status}`)}
        </Badge>
    );
}

export interface RowHandlers {
    onEdit: (guest: GuestRow) => void;
    onDelete: (guest: GuestRow) => void;
    onCheckIn: (guest: GuestRow) => void;
    onCopy: (guest: GuestRow) => void;
}

interface Ctx {
    can: { update: boolean; check_in: boolean };
    timezone: string;
    handlers: RowHandlers;
    now: number;
}

function RowActions({ guest, ctx, layout }: { guest: GuestRow; ctx: Ctx; layout: 'row' | 'card' }) {
    const { t, locale } = useT();
    const { can, handlers } = ctx;
    const card = layout === 'card';
    const arrived = guest.checked_in_at !== null;

    return (
        <div className={cn('flex items-center gap-1', card && 'flex-wrap gap-2')}>
            {can.update && (
                <Button
                    asChild
                    variant={card ? 'default' : 'ghost'}
                    size={card ? 'default' : 'icon'}
                    className={cn(card ? 'h-10 flex-1 bg-[#1f8f4e] text-white hover:bg-[#1a7a43]' : 'size-9 text-[#1f8f4e]')}
                >
                    <a href={guest.whatsapp_url} target="_blank" rel="noopener" aria-label={t('dashboard.actions.whatsapp')} title={t('dashboard.actions.whatsapp')} data-action="whatsapp">
                        <MessageCircle />
                        {card && t('dashboard.actions.whatsapp_short')}
                    </a>
                </Button>
            )}
            {can.update && (
                <Button
                    variant={card ? 'outline' : 'ghost'}
                    size={card ? 'default' : 'icon'}
                    className={card ? 'h-10 flex-1' : 'size-9'}
                    onClick={() => handlers.onCopy(guest)}
                    aria-label={t('dashboard.actions.copy_link')}
                    title={t('dashboard.actions.copy_link')}
                    data-action="copy"
                >
                    <Copy />
                    {card && t('dashboard.actions.copy_link')}
                </Button>
            )}
            {can.check_in && (
                <Button
                    variant={arrived ? 'secondary' : 'outline'}
                    size={card ? 'default' : 'sm'}
                    className={cn(card ? 'h-10 flex-1' : 'h-9', arrived && 'text-emerald-800 dark:text-emerald-200')}
                    onClick={() => handlers.onCheckIn(guest)}
                    data-action="checkin"
                >
                    {arrived ? <Check /> : <DoorOpen />}
                    {arrived ? t('dashboard.actions.arrived_at', { time: clockTime(guest.checked_in_at!, locale, ctx.timezone) }) : t('dashboard.actions.arrived')}
                </Button>
            )}
            {can.update && (
                <DropdownMenu dir={locale === 'ar' ? 'rtl' : 'ltr'}>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className={card ? 'size-10' : 'size-9'} aria-label={t('dashboard.actions.more', { name: guest.name })} data-action="more">
                            <MoreVertical />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onSelect={() => handlers.onEdit(guest)} data-action="edit">
                            <Pencil /> {t('dashboard.actions.edit')}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem variant="destructive" onSelect={() => handlers.onDelete(guest)} data-action="delete">
                            <Trash2 /> {t('dashboard.actions.delete')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            )}
        </div>
    );
}

function LastOpened({ guest, now }: { guest: GuestRow; now: number }) {
    const { t, tc, locale } = useT();
    if (!guest.last_opened_at) return <span className="text-muted-foreground">{t('dashboard.table.never')}</span>;
    return (
        <time dateTime={guest.last_opened_at} title={tc('dashboard.table.opens', guest.open_count)}>
            {relativeTime(guest.last_opened_at, locale, now)}
        </time>
    );
}

function Tags({ tags }: { tags: string[] }) {
    if (tags.length === 0) return null;
    return (
        <div className="flex flex-wrap gap-1">
            {tags.map((tag) => (
                <bdi key={tag} className="bg-secondary text-secondary-foreground rounded-full px-2 py-0.5 text-xs">
                    {tag}
                </bdi>
            ))}
        </div>
    );
}

const column = createColumnHelper<GuestRow>();
const time = (iso: string | null) => (iso ? new Date(iso).getTime() : -Infinity);

/**
 * The guest list: a sortable table on wide screens, cards on phones. The
 * rows arrive filtered (status, tag, search); sorting is TanStack Table's.
 */
export function GuestTable({ guests, can, timezone, handlers }: { guests: GuestRow[]; can: Ctx['can']; timezone: string; handlers: RowHandlers }) {
    const { t, n, locale } = useT();
    const wide = useMediaQuery('(min-width: 768px)');
    const [sorting, setSorting] = useState<SortingState>([]);
    const [now, setNow] = useState(() => Date.now());

    // "5 minutes ago" keeps moving
    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 60_000);
        return () => window.clearInterval(id);
    }, []);

    const ctx: Ctx = { can, timezone, handlers, now };
    const collator = useMemo(() => new Intl.Collator(locale, { sensitivity: 'base', numeric: true }), [locale]);

    const columns = useMemo(
        () => [
            column.accessor('name', {
                header: () => t('dashboard.table.name'),
                sortingFn: (a, b) => collator.compare(a.original.name, b.original.name),
                cell: (info) => (
                    <div className="grid gap-0.5">
                        <span className="font-medium">
                            <bdi>{info.getValue()}</bdi>
                        </span>
                        {info.row.original.note && (
                            <span className="text-muted-foreground line-clamp-1 text-xs" title={info.row.original.note}>
                                <bdi>“{info.row.original.note}”</bdi>
                            </span>
                        )}
                    </div>
                ),
            }),
            column.accessor('seats', { header: () => t('dashboard.table.seats'), cell: (info) => n(info.getValue()) }),
            column.accessor('status', {
                header: () => t('dashboard.table.status'),
                sortingFn: (a, b) => STATUS_ORDER[a.original.status] - STATUS_ORDER[b.original.status],
                cell: (info) => <StatusChip status={info.getValue()} />,
            }),
            column.accessor((row) => row.people ?? -1, {
                id: 'people',
                header: () => t('dashboard.table.people'),
                cell: (info) => (info.row.original.people === null ? <span className="text-muted-foreground">—</span> : n(info.row.original.people)),
            }),
            column.accessor((row) => row.table ?? '', {
                id: 'table',
                header: () => t('dashboard.table.table'),
                sortingFn: (a, b) => collator.compare(a.original.table ?? '￿', b.original.table ?? '￿'),
                cell: (info) => (info.row.original.table ? n(info.row.original.table) : <span className="text-muted-foreground">—</span>),
            }),
            column.accessor((row) => time(row.last_opened_at), {
                id: 'last_opened',
                header: () => t('dashboard.table.last_opened'),
                cell: (info) => <LastOpened guest={info.row.original} now={now} />,
            }),
            column.accessor('tags', { header: () => t('dashboard.table.tags'), enableSorting: false, cell: (info) => <Tags tags={info.getValue()} /> }),
            column.display({
                id: 'actions',
                header: () => <span className="sr-only">{t('dashboard.table.actions')}</span>,
                cell: (info) => <RowActions guest={info.row.original} ctx={ctx} layout="row" />,
            }),
        ],
        // ctx changes with every render; the cells read it through the closure below
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [t, n, collator, now, can, handlers, timezone],
    );

    const table = useReactTable({
        data: guests,
        columns,
        state: { sorting },
        onSortingChange: setSorting,
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getRowId: (row) => row.id,
    });

    const rows = table.getRowModel().rows;

    if (!wide) {
        return (
            <div className="grid gap-2">
                <div className="flex items-center gap-2 text-sm">
                    <label htmlFor="mobile-sort" className="text-muted-foreground shrink-0">
                        <ArrowUpDown className="inline size-4" />
                        <span className="sr-only">{t('dashboard.table.sort', { column: '' })}</span>
                    </label>
                    <select
                        id="mobile-sort"
                        className="border-input h-10 flex-1 rounded-md border bg-transparent px-2 text-sm"
                        value={sorting[0] ? `${sorting[0].id}:${sorting[0].desc ? 'desc' : 'asc'}` : ''}
                        onChange={(e) => {
                            const [id, dir] = e.target.value.split(':');
                            setSorting(id ? [{ id, desc: dir === 'desc' }] : []);
                        }}
                    >
                        <option value="">{t('dashboard.table.sort', { column: '…' })}</option>
                        <option value="name:asc">{t('dashboard.table.name')} ↑</option>
                        <option value="status:asc">{t('dashboard.table.status')}</option>
                        <option value="last_opened:desc">{t('dashboard.table.last_opened')}</option>
                        <option value="people:desc">{t('dashboard.table.people')}</option>
                        <option value="seats:desc">{t('dashboard.table.seats')}</option>
                    </select>
                </div>
                <ul className="grid gap-2" data-guest-cards>
                    {rows.map((row) => {
                        const guest = row.original;
                        return (
                            <li key={row.id} className="bg-card grid gap-3 rounded-xl border p-3 shadow-xs" data-guest={guest.id}>
                                <div className="flex items-start justify-between gap-2">
                                    <div className="grid min-w-0 gap-1">
                                        <span className="font-semibold">
                                            <bdi>{guest.name}</bdi>
                                        </span>
                                        {guest.phone && (
                                            <span className="text-muted-foreground flex items-center gap-1 text-xs">
                                                <Phone className="size-3" />
                                                <bdi dir="ltr">{guest.phone}</bdi>
                                            </span>
                                        )}
                                    </div>
                                    <StatusChip status={guest.status} />
                                </div>
                                <dl className="text-muted-foreground grid grid-cols-3 gap-2 text-xs">
                                    <div>
                                        <dt>{t('dashboard.table.seats')}</dt>
                                        <dd className="text-foreground text-sm font-semibold">
                                            {guest.people !== null ? t('dashboard.table.people_of', { people: guest.people, seats: guest.seats }) : n(guest.seats)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>{t('dashboard.table.table')}</dt>
                                        <dd className="text-foreground text-sm font-semibold">{guest.table ? n(guest.table) : '—'}</dd>
                                    </div>
                                    <div>
                                        <dt>{t('dashboard.table.last_opened')}</dt>
                                        <dd className="text-foreground text-sm">
                                            <LastOpened guest={guest} now={now} />
                                        </dd>
                                    </div>
                                </dl>
                                {guest.note && (
                                    <p className="bg-muted/60 rounded-md px-2 py-1 text-xs">
                                        <bdi>“{guest.note}”</bdi>
                                    </p>
                                )}
                                <Tags tags={guest.tags} />
                                <RowActions guest={guest} ctx={ctx} layout="card" />
                            </li>
                        );
                    })}
                </ul>
            </div>
        );
    }

    return (
        <div className="bg-card overflow-x-auto rounded-xl border shadow-xs">
            <table className="w-full text-sm" data-guest-table>
                <thead className="bg-muted/50 text-muted-foreground">
                    {table.getHeaderGroups().map((group) => (
                        <tr key={group.id}>
                            {group.headers.map((header) => {
                                const sorted = header.column.getIsSorted();
                                return (
                                    <th
                                        key={header.id}
                                        className="px-3 py-2.5 text-start font-medium whitespace-nowrap"
                                        aria-sort={sorted === 'asc' ? 'ascending' : sorted === 'desc' ? 'descending' : undefined}
                                    >
                                        {header.column.getCanSort() ? (
                                            <button
                                                type="button"
                                                className="hover:text-foreground inline-flex items-center gap-1"
                                                onClick={header.column.getToggleSortingHandler()}
                                                title={t('dashboard.table.sort', { column: String(flexRender(header.column.columnDef.header, header.getContext()) ?? '') })}
                                            >
                                                {flexRender(header.column.columnDef.header, header.getContext())}
                                                {sorted === 'asc' ? <ArrowUp className="size-3.5" /> : sorted === 'desc' ? <ArrowDown className="size-3.5" /> : <ArrowUpDown className="size-3.5 opacity-40" />}
                                            </button>
                                        ) : (
                                            flexRender(header.column.columnDef.header, header.getContext())
                                        )}
                                    </th>
                                );
                            })}
                        </tr>
                    ))}
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={row.id} className="hover:bg-muted/30 border-t" data-guest={row.original.id}>
                            {row.getVisibleCells().map((cell) => (
                                <td key={cell.id} className={cn('px-3 py-2 align-middle', cell.column.id === 'actions' && 'w-0 whitespace-nowrap')}>
                                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
