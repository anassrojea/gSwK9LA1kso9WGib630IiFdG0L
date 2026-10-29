/**
 * The open link's RSVP on the guest page (one link for everyone, plan
 * section 8). After replying, the page keeps the reply token in localStorage
 * so the same device changes its reply instead of creating a new family.
 * Twin of the "open_link" part of App\Http\Controllers\Invite\OpenLinkController::statePayload.
 */

export interface OpenLinkState {
    seats_left: number;
    max_per_reply: number;
    full: boolean;
}

/** Seats-left notes are only shown when few remain. */
export const LOW_SEATS = 10;

/** One stored reply per open link; the state URL contains the link's token. */
export function replyStorageKey(stateUrl: string): string {
    return `daawa:reply:${stateUrl}`;
}

/** The /state query: ?open=1 on page load, plus the guest's reply token once they have one. */
export function stateQuery(open: boolean, replyToken: string | null): string {
    const params = new URLSearchParams();
    if (open) params.set('open', '1');
    if (replyToken && /^[A-Za-z0-9]{10,32}$/.test(replyToken)) params.set('reply', replyToken);
    const query = params.toString();
    return query ? `?${query}` : '';
}

/**
 * What the RSVP block shows on an open link: the form (no reply yet and room
 * left), the "all places are taken" note, and how many places are left when
 * few remain (before the guest replies).
 */
export function openLinkView(link: OpenLinkState, hasReply: boolean, rsvpOpen: boolean): { form: boolean; full: boolean; seatsLeft: number | null } {
    const full = !hasReply && rsvpOpen && link.full;
    return {
        form: !hasReply && rsvpOpen && !link.full,
        full,
        seatsLeft: !hasReply && rsvpOpen && link.seats_left > 0 && link.seats_left <= LOW_SEATS ? link.seats_left : null,
    };
}
