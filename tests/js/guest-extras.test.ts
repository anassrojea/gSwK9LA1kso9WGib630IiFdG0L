/**
 * Phase 7B helpers: album uploads and the wall (lib/album), applying AI
 * suggestions (lib/ai), wallet buttons (lib/wallet), photo sizing (lib/image-compress).
 */
import assert from 'node:assert/strict';
import { test } from 'node:test';

import { formatBytes, kindOf, mergeFeed, nextSlide, uploadProblem } from '../../resources/js/lib/album.ts';
import { importRows, mergeChapters, pollDelay, poemSection } from '../../resources/js/lib/ai.ts';
import { fitWithin } from '../../resources/js/lib/image-compress.ts';
import { deviceFrom, walletPlatformsFor } from '../../resources/js/lib/wallet.ts';

const limits = { photoMaxMb: 15, video: { max_seconds: 60, max_mb: 100 } };
const MB = 1024 * 1024;

test('tells photos from videos and refuses other files', () => {
    assert.equal(kindOf('image/webp'), 'photo');
    assert.equal(kindOf('video/quicktime'), 'video');
    assert.equal(kindOf('application/pdf'), null);
    assert.equal(uploadProblem({ type: 'application/pdf', size: 10 }, null, limits), 'bad_type');
});

test('checks size and video length before uploading', () => {
    assert.equal(uploadProblem({ type: 'image/jpeg', size: 2 * MB }, null, limits), null);
    assert.equal(uploadProblem({ type: 'image/jpeg', size: 16 * MB }, null, limits), 'too_large');
    assert.equal(uploadProblem({ type: 'video/mp4', size: 101 * MB }, 10, limits), 'too_large');
    assert.equal(uploadProblem({ type: 'video/mp4', size: 20 * MB }, 61, limits), 'too_long');
    assert.equal(uploadProblem({ type: 'video/mp4', size: 20 * MB }, 60.3, limits), null);
    // unknown duration: the server still checks the size
    assert.equal(uploadProblem({ type: 'video/mp4', size: 20 * MB }, null, limits), null);
});

test('scales photos to at most 2560 px on the long edge, never up', () => {
    assert.deepEqual(fitWithin(4032, 3024), { width: 2560, height: 1920, scale: 2560 / 4032 });
    assert.deepEqual(fitWithin(3000, 6000), { width: 1280, height: 2560, scale: 2560 / 6000 });
    assert.deepEqual(fitWithin(800, 600), { width: 800, height: 600, scale: 1 });
});

test('writes sizes in the page language', () => {
    assert.equal(formatBytes(820 * 1024), '820 KB');
    assert.equal(formatBytes(3.45 * MB), '3.5 MB');
    assert.equal(formatBytes(5 * 1024 * MB, 'ar'), '٥ غ.ب');
});

test('puts new wall photos first and keeps the list short', () => {
    const a = { id: 'a' };
    const b = { id: 'b' };
    const c = { id: 'c' };
    assert.deepEqual(mergeFeed([a, b], [c, a, b]), { list: [c, a, b], added: ['c'] });
    assert.deepEqual(mergeFeed([], [a, b, c], 2).list, [a, b]);
    assert.equal(nextSlide(2, 3), 0);
    assert.equal(nextSlide(0, 0), 0);
});

test('applies a story suggestion only as the host chose', () => {
    const mine = [{ title: 'Mine', text: 'My words' }];
    const ai = [
        { year: '2019', title: 'First', text: 'Met' },
        { title: '  ', text: 'empty title dropped' },
    ];
    assert.deepEqual(mergeChapters(mine, ai, 'replace'), [ai[0]]);
    assert.deepEqual(mergeChapters(mine, ai, 'append'), [mine[0], ai[0]]);
    const many = Array.from({ length: 10 }, (_, i) => ({ title: `t${i}`, text: 'x' }));
    assert.equal(mergeChapters(many, many, 'append').length, 12);
});

test('turns a poem into an intro_line verse section', () => {
    const section = { type: 'intro_line' as const, text: 'old', style: 'line' as const, caption: 'mine' };
    assert.deepEqual(poemSection(section, { text: 'بيت\nبيت' }), { type: 'intro_line', text: 'بيت\nبيت', style: 'verse', caption: 'mine' });
    assert.equal(poemSection(section, { text: 'x'.repeat(2500), caption: 'AI' }).text.length, 2000);
    assert.equal(poemSection(section, { text: 'x', caption: 'AI' }).caption, 'AI');
});

test('sends reviewed families as import rows', () => {
    assert.deepEqual(
        importRows([
            { name: ' أم فيصل ', seats: 0, phone: ' ', notes: null, warnings: [] },
            { name: 'Sara', seats: 250, phone: '+971501234567', notes: 'x', warnings: [] },
            { name: '  ', seats: 2, phone: null, notes: null, warnings: [] },
        ]),
        [
            { name: 'أم فيصل', seats: 1, phone: null },
            { name: 'Sara', seats: 100, phone: '+971501234567' },
        ],
    );
    assert.equal(pollDelay(0), 800);
    assert.equal(pollDelay(50), 4000);
});

test('shows the wallet button of the phone, both elsewhere', () => {
    const iphone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15';
    const android = 'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 Chrome/131 Mobile';
    const ipad = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Safari/605.1.15';
    assert.equal(deviceFrom(iphone), 'ios');
    assert.equal(deviceFrom(android), 'android');
    assert.equal(deviceFrom(ipad, 5), 'ios');
    assert.equal(deviceFrom(ipad, 0), 'other');
    assert.deepEqual(walletPlatformsFor('ios'), ['apple']);
    assert.deepEqual(walletPlatformsFor('android'), ['google']);
    assert.deepEqual(walletPlatformsFor('other'), ['apple', 'google']);
});
