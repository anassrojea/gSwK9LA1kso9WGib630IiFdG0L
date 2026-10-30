import type { HallLayout } from '@/lib/hall-layout';
import type { Assignment, Family, Warning } from '@/lib/seating/plan';

export interface HallSummary {
    id: string;
    name: string;
    org: string | null;
    tables: number;
    seats: number;
    split: boolean;
}

export interface EventHall {
    id: string;
    name: string;
    layout: HallLayout;
    shared: boolean;
}

export interface MealBucket {
    families: number;
    people: number;
    adults: number;
    kids: number;
}

export interface Meals {
    sections: { men: MealBucket; women: MealBucket; none: MealBucket };
    total: MealBucket;
    meals: { meal: string; people: number }[];
}

export interface PlannerProps {
    event: { id: string; title: string; starts_at: string | null; timezone: string; has_org: boolean };
    locked: boolean;
    hall?: EventHall | null;
    halls?: HallSummary[];
    families?: Family[];
    assignments?: Assignment[];
    warnings?: Warning[];
    meals?: Meals;
    limits?: { tables: number; chairs: number };
    /** the venue changed the hall after the seats were last saved (phase 8B) */
    hall_change?: { at: string; count: number } | null;
    urls: {
        dashboard: string;
        upgrade: string;
        hall?: string;
        seats?: string;
        auto?: string;
        section?: string;
        chart_pdf?: string;
        cards_pdf?: string;
        find_pdf?: string | null;
        halls?: string;
    };
}

export interface AutoSeatResult {
    assignments: Assignment[];
    placed: number;
    unplaced: { id: string; name: string; missing: number }[];
    warnings: Warning[];
}
