/**
 * The open link's RSVP helpers used by resources/js/invite.js.
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { openLinkView, replyStorageKey, stateQuery } from '../../resources/js/lib/open-link.ts';

test('stores one reply per open link', () => {
    assert.equal(replyStorageKey('/i/AbCdEfGhIjKlMnOp/state'), 'daawa:reply:/i/AbCdEfGhIjKlMnOp/state');
    assert.notEqual(replyStorageKey('/i/AbCdEfGhIjKlMnOp/state'), replyStorageKey('/i/QrStUvWxYz012345/state'));
});

test('builds the state query with the open flag and a valid reply token only', () => {
    assert.equal(stateQuery(true, null), '?open=1');
    assert.equal(stateQuery(false, null), '');
    assert.equal(stateQuery(true, 'Abc123Def456'), '?open=1&reply=Abc123Def456');
    assert.equal(stateQuery(false, 'Abc123Def456'), '?reply=Abc123Def456');
    // anything that is not a token is dropped rather than sent
    assert.equal(stateQuery(true, 'x&open=0'), '?open=1');
    assert.equal(stateQuery(true, ''), '?open=1');
});

test('shows the form while places remain and nobody replied from this device', () => {
    assert.deepEqual(openLinkView({ seats_left: 40, max_per_reply: 10, full: false }, false, true), { form: true, full: false, seatsLeft: null });
    assert.deepEqual(openLinkView({ seats_left: 3, max_per_reply: 3, full: false }, false, true), { form: true, full: false, seatsLeft: 3 });
    // once they replied, the guest sees their reply rather than the places left
    assert.equal(openLinkView({ seats_left: 3, max_per_reply: 5, full: false }, true, true).seatsLeft, null);
});

test('shows the "all places are taken" note only to guests who have not replied', () => {
    const full = { seats_left: 0, max_per_reply: 0, full: true };
    assert.deepEqual(openLinkView(full, false, true), { form: false, full: true, seatsLeft: null });
    // someone who already replied sees their reply, not the note
    assert.deepEqual(openLinkView(full, true, true), { form: false, full: false, seatsLeft: null });
});

test('after the cutoff neither the form nor the full note shows', () => {
    assert.deepEqual(openLinkView({ seats_left: 0, max_per_reply: 0, full: true }, false, false), { form: false, full: false, seatsLeft: null });
    assert.equal(openLinkView({ seats_left: 5, max_per_reply: 5, full: false }, false, false).form, false);
});
