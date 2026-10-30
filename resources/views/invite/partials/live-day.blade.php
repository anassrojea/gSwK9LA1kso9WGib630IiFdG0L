{{-- "The invite changes on the day" (plan sections 4.1 and 14.1). Hidden and empty in the cached HTML:
     resources/js/invite/day.js fills it from GET /i/{token}/state ("live", "seat", "qr") inside the
     event-day window (App\Services\EventDay). Also styles the table map and door code on the RSVP ticket. --}}
<style>
.live-day{margin:14px 14px 0;padding:20px 18px 18px;border-radius:calc(var(--radius) + 10px);background:linear-gradient(160deg,var(--c-accent),color-mix(in srgb,var(--c-accent) 70%,black));color:var(--c-on-accent);display:grid;gap:14px;text-align:center;box-shadow:0 14px 34px color-mix(in srgb,var(--c-accent) 35%,transparent)}
.live-day[hidden]{display:none}
.live-badge{justify-self:center;display:inline-flex;align-items:center;gap:8px;font:700 12px/1 var(--f-label);letter-spacing:.06em;color:var(--c-gold-light);background:rgb(255 255 255 / .1);border:1px solid rgb(255 255 255 / .2);border-radius:999px;padding:6px 12px}
.live-badge i{width:8px;height:8px;border-radius:50%;background:#ff5a5a;box-shadow:0 0 0 0 rgb(255 90 90 / .6);animation:live-pulse 1.8s infinite}
@keyframes live-pulse{70%{box-shadow:0 0 0 8px rgb(255 90 90 / 0)}100%{box-shadow:0 0 0 0 rgb(255 90 90 / 0)}}
.live-day h2{color:var(--c-gold-light);font-size:26px;margin:0}
.live-day p{margin:-8px 0 0;font-size:15px;opacity:.85}
.live-day .btns .btn{border-color:var(--c-gold-light);color:var(--c-on-accent)}
.live-day .btns .btn.solid{background:var(--c-gold-light);border-color:var(--c-gold-light);color:var(--c-accent)}
.live-day .btn[hidden]{display:none}
.live-seat{background:var(--c-bg);color:var(--c-ink);border-radius:calc(var(--radius) + 4px);padding:14px;display:grid;gap:8px;justify-items:center}
.live-seat .label{color:var(--c-label)}
.live-seat .tb{font:700 34px/1 var(--f-display);color:var(--c-accent)}
.hall-map{width:100%;max-width:320px}
.hall-map svg{display:block;width:100%;height:auto}
.hall-map .m-hall{fill:color-mix(in srgb,var(--c-surface) 70%,var(--c-bg));stroke:color-mix(in srgb,var(--c-gold) 45%,transparent);stroke-width:3}
.hall-map .m-stage{fill:color-mix(in srgb,var(--c-gold) 30%,var(--c-bg))}
.hall-map .m-t{fill:color-mix(in srgb,var(--c-soft) 22%,var(--c-bg))}
.hall-map .m-n{font-family:var(--f-label);font-weight:600;fill:var(--c-soft);text-anchor:middle;dominant-baseline:central}
.hall-map .m-mine{fill:var(--c-accent)}
.hall-map .m-ring{fill:none;stroke:var(--c-gold);stroke-width:4}
.hall-map .m-mine-n{font-family:var(--f-label);font-weight:700;fill:var(--c-on-accent);text-anchor:middle;dominant-baseline:central}
.door-qr{margin:0;display:grid;gap:6px;justify-items:center;background:#fff;color:#222;border-radius:calc(var(--radius) + 4px);padding:12px 12px 10px}
.door-qr[hidden]{display:none}
.door-qr img{width:176px;height:176px;display:block}
.door-qr figcaption{font:600 13px/1.4 var(--f-label);color:#444}
.ticket .hall-map{margin-top:2px}
</style>
<section class="live-day" data-live hidden aria-labelledby="live-day-title">
    <span class="live-badge"><i aria-hidden="true"></i>{{ __('invite.live.badge') }}</span>
    <h2 id="live-day-title">{{ __('invite.live.title') }}</h2>
    <p>{{ __('invite.live.text') }}</p>
    <div class="btns">
        <a class="btn solid" data-live-directions href="#" target="_blank" rel="noopener" hidden>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5 7 13 7 13s7-8 7-13a7 7 0 0 0-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z" fill="currentColor"/></svg>{{ __('invite.live.directions') }}
        </a>
        <a class="btn" data-live-stream href="#" target="_blank" rel="noopener" hidden>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" fill="currentColor"/></svg>{{ __('invite.live.stream') }}
        </a>
        <a class="btn" data-live-wall href="#" hidden>
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="1.5" fill="currentColor"/><rect x="13" y="3" width="8" height="8" rx="1.5" fill="currentColor" opacity=".7"/><rect x="3" y="13" width="8" height="8" rx="1.5" fill="currentColor" opacity=".7"/><rect x="13" y="13" width="8" height="8" rx="1.5" fill="currentColor"/></svg>{{ __('invite.live.wall') }}
        </a>
    </div>
    <div class="live-seat" data-live-seat hidden>
        <span class="label">{{ __('invite.table.yours') }}</span>
        <b class="tb" data-live-table data-label="{{ __('invite.table.label', ['n' => ':n']) }}"></b>
        <div class="hall-map" data-live-map role="img" aria-label="{{ __('invite.table.map') }}"></div>
    </div>
    <figure class="door-qr" data-live-qr hidden>
        <img alt="{{ __('invite.live.qr_alt') }}" width="176" height="176" decoding="async">
        <figcaption>{{ __('invite.live.qr') }}</figcaption>
    </figure>
</section>
