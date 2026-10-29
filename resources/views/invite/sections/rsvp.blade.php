{{-- Filled in by resources/js/invite.js from GET /i/{token}/state; the seat limit is enforced again on the server. --}}
<section class="blk pad sec-rsvp" id="rsvp" data-rsvp
         data-strings="{{ json_encode([
             'thanksYes' => __('invite.rsvp.thanks_yes'),
             'thanksNo' => __('invite.rsvp.thanks_no'),
             'error' => __('invite.rsvp.error'),
             'sending' => __('invite.rsvp.sending'),
             'submit' => __('invite.rsvp.submit'),
             'closed' => __('invite.rsvp.closed'),
             'seats' => collect(range(1, 20))->mapWithKeys(fn ($n) => [$n => trans_choice('invite.rsvp.seats', $n, ['count' => \App\Services\Digits::localize($n, $locale)])]),
             'people' => collect(range(1, 20))->mapWithKeys(fn ($n) => [$n => trans_choice('invite.rsvp.person', $n, ['count' => \App\Services\Digits::localize($n, $locale)])]),
             'confirmedPeople' => __('invite.rsvp.confirmed_people', ['people' => ':people']),
         ], JSON_UNESCAPED_UNICODE) }}">
    <span class="label rv">{{ $s['label'] ?? __('invite.rsvp.label') }}</span>
    <h2 class="rv">{{ $s['title'] ?? __('invite.rsvp.title') }}</h2>
    <div class="guest rv"><b data-guest-name>{{ __('invite.dear_guest') }}</b><span data-seats></span></div>

    <form class="rsvp-form rv" data-rsvp-form novalidate>
        <div class="choice" role="radiogroup" aria-label="{{ __('invite.rsvp.attendance') }}">
            <span><input type="radio" name="attending" id="att-yes" value="1" checked><label for="att-yes">{{ __('invite.rsvp.yes') }}</label></span>
            <span><input type="radio" name="attending" id="att-no" value="0"><label for="att-no">{{ __('invite.rsvp.no') }}</label></span>
        </div>
        <label for="rsvp-people">{{ __('invite.rsvp.people') }}
            <select id="rsvp-people" name="people_count" data-people><option value="1">{{ trans_choice('invite.rsvp.person', 1, ['count' => 1]) }}</option></select>
        </label>
        @if ($s['askMessage'] ?? false)
            <label for="rsvp-message">{{ __('invite.rsvp.message') }}
                <textarea id="rsvp-message" name="message" maxlength="500"></textarea>
            </label>
        @endif
        @if ($s['askAllergies'] ?? false)
            <label for="rsvp-allergies">{{ __('invite.rsvp.allergies') }}
                <input type="text" id="rsvp-allergies" name="allergies" maxlength="300">
            </label>
        @endif
        <p class="form-error" data-error role="alert" hidden></p>
        <button class="submit" type="submit">{{ __('invite.rsvp.submit') }}</button>
        <small class="consent">{{ __('invite.rsvp.consent') }}</small>
    </form>

    <div class="done" data-done hidden>
        <div class="ticket">
            <span class="label">{{ __('invite.rsvp.confirmed') }}</span>
            <span data-confirmed-people></span>
            <div data-table-wrap hidden>
                <span>{{ __('invite.rsvp.your_table') }}</span>
                <span class="tb" data-table></span>
            </div>
        </div>
        <button type="button" class="btn ghost" data-edit>{{ __('invite.rsvp.edit') }}</button>
    </div>
    <div class="done" data-sorry hidden>
        <div class="ticket"><span class="label">{{ __('invite.rsvp.declined') }}</span><p>{{ __('invite.rsvp.declined_text') }}</p></div>
        <button type="button" class="btn ghost" data-edit>{{ __('invite.rsvp.edit') }}</button>
    </div>
    <p class="note" data-closed hidden>{{ __('invite.rsvp.closed') }}</p>
</section>
