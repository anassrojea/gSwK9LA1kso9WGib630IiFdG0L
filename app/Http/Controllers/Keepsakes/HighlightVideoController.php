<?php

namespace App\Http\Controllers\Keepsakes;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\HighlightVideo;
use App\Services\Album\Album;
use App\Services\Keepsakes\HighlightVideos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * The highlight video (phase 8D):
 *
 *   POST /events/{event}/highlight-video        {formats[], music_track_id?}: queue a build (needs the add-on)
 *   GET  /highlight/{video}/{format}            signed: stream the MP4 (?download=1 as a file, ?poster=1 the JPEG)
 */
class HighlightVideoController extends Controller
{
    public function store(Request $request, Event $event, HighlightVideos $videos): RedirectResponse
    {
        Gate::authorize('update', $event);

        $data = $request->validate([
            'formats' => ['array', 'min:1'],
            'formats.*' => [Rule::in(HighlightVideos::FORMATS)],
            'music_track_id' => ['nullable', 'uuid'],
        ]);

        $videos->request($event, $request->user(), $data['formats'] ?? HighlightVideos::FORMATS, $data['music_track_id'] ?? null);

        return back()->with('status', trans('keepsakes.video.queued'));
    }

    public function file(Request $request, HighlightVideo $video, string $format, Album $album): Response
    {
        $output = $video->status === 'ready' ? ($video->outputs[$format] ?? null) : null;
        $key = $request->boolean('poster') ? ($output['poster'] ?? null) : ($output['key'] ?? null);
        $disk = $album->disk();
        abort_if(! $key || ! $disk->exists($key), 404);

        $name = 'highlight-'.$format.'.'.pathinfo($key, PATHINFO_EXTENSION);
        $headers = ['Cache-Control' => 'private, max-age=3600', 'X-Robots-Tag' => 'noindex'];

        return $request->boolean('download')
            ? $disk->download($key, $name, $headers)
            : $disk->response($key, $name, $headers);
    }
}
