<?php

namespace App\Services\Marketplace;

use App\Models\AuditLog;
use App\Models\DesignerProfile;
use App\Models\Event;
use App\Models\Opening;
use App\Models\Template;
use App\Models\TemplateRevision;
use App\Models\Theme;
use App\Services\Catalog\CatalogValidator;
use App\Services\InvitationConfig\InvitationConfigValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * What an approved designer does in the portal (/designer): themes (colours and fonts,
 * checked like the admin's theme form), templates (an opening, one of their themes and a
 * default config checked against the frozen v1 schema), cover images, and submitting a
 * revision for review.
 *
 * Revisions: a template's live columns (opening, theme, default_config, prices) only change
 * when an admin approves a revision (TemplateReview). Before the first approval the template
 * row mirrors its draft (it is inactive, so no host sees it); after it, editing starts a new
 * draft revision and the live template stays as it was. Invitations already using a template
 * hold their own copy of the config, so nothing a designer does reaches them.
 */
class TemplateStudio
{
    public function __construct(private CatalogValidator $catalog) {}

    /**
     * @param  array{name_ar: string, name_en: string, tokens: array, fonts: array}  $data
     */
    public function saveTheme(DesignerProfile $profile, ?Theme $theme, array $data): Theme
    {
        if ($theme && $theme->designer_id !== $profile->user_id) {
            abort(403);
        }

        $tokens = ['colors' => CatalogValidator::filled($data['tokens']['colors'] ?? [])];
        $radius = $data['tokens']['radius'] ?? null;
        if ($radius !== null && $radius !== '') {
            $tokens['radius'] = is_numeric($radius) ? (int) $radius : $radius;
        }
        $fonts = CatalogValidator::filled($data['fonts'] ?? []);

        $id = $theme?->id ?? $this->newId('d');
        $errors = $this->catalog->themeErrors($id, $tokens, $fonts);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $theme ??= new Theme(['id' => $id, 'designer_id' => $profile->user_id, 'tier' => 'free', 'active' => true]);
        $theme->fill(['name_ar' => $data['name_ar'], 'name_en' => $data['name_en'], 'tokens' => $tokens, 'fonts' => $fonts])->save();
        AuditLog::record($theme->wasRecentlyCreated ? 'designer.theme_created' : 'designer.theme_updated', $theme, [], $profile->user);

        return $theme;
    }

    /**
     * A new template with its first (draft) revision.
     *
     * @param  array<string, mixed>  $data  see saveDraft
     */
    public function createTemplate(DesignerProfile $profile, array $data): Template
    {
        return DB::transaction(function () use ($profile, $data) {
            $clean = $this->clean($profile, $data);

            $template = new Template([
                'id' => $this->newId('m'),
                'occasion' => $clean['occasion'],
                'name_ar' => $clean['name_ar'],
                'name_en' => $clean['name_en'],
                'tier' => 'free',
                'opening_id' => $clean['opening_id'],
                'theme_id' => $clean['theme_id'],
                'default_config' => $clean['default_config'],
                'sort' => (int) config('marketplace.template_sort', 1000),
                'active' => false,
            ]);
            $template->forceFill([
                'designer_id' => $profile->user_id,
                'marketplace_status' => 'draft',
                'description_ar' => $clean['description_ar'],
                'description_en' => $clean['description_en'],
                'prices' => $clean['prices'],
                'included_in_signature' => $clean['included_in_signature'],
            ])->save();

            $template->revisions()->create($clean + ['number' => 1, 'status' => 'draft']);
            AuditLog::record('designer.template_created', $template, [], $profile->user);

            return $template;
        });
    }

    /**
     * Save the designer's edits into the template's editable revision (a new one when the
     * latest was approved).
     *
     * @param  array{name_ar: string, name_en: string, description_ar?: ?string, description_en?: ?string, occasion: string, opening_id: string, theme_id: string, default_config: array, prices: array, included_in_signature?: bool}  $data
     */
    public function saveDraft(DesignerProfile $profile, Template $template, array $data): TemplateRevision
    {
        $this->ensureOwner($profile, $template);

        return DB::transaction(function () use ($profile, $template, $data) {
            $clean = $this->clean($profile, $data);
            $revision = $this->editableRevision($template);
            $revision->fill($clean + ['status' => 'draft', 'rejection_reason' => null])->save();

            if ($template->published_at === null) {
                $this->mirror($template, $revision, $template->marketplace_status === 'rejected' ? 'draft' : $template->marketplace_status);
            }

            return $revision;
        });
    }

    /**
     * draft → in_review: the admin's review queue (Filament) shows it.
     */
    public function submit(DesignerProfile $profile, Template $template): TemplateRevision
    {
        $this->ensureOwner($profile, $template);

        return DB::transaction(function () use ($profile, $template) {
            $revision = $template->revisions()->lockForUpdate()->first();
            if (! $revision || ! $revision->isEditable()) {
                throw ValidationException::withMessages(['template' => trans('marketplace.errors.nothing_to_submit')]);
            }

            // checked again: the theme or the schema may have changed since the draft was saved
            $this->clean($profile, $revision->only(['name_ar', 'name_en', 'description_ar', 'description_en', 'occasion', 'opening_id', 'theme_id', 'default_config', 'prices', 'included_in_signature']));

            $revision->update(['status' => 'in_review', 'submitted_at' => now(), 'rejection_reason' => null]);
            if ($template->published_at === null) {
                $template->forceFill(['marketplace_status' => 'in_review'])->save();
            }
            AuditLog::record('designer.template_submitted', $template, ['revision' => $revision->number], $profile->user);

            return $revision;
        });
    }

    /**
     * Off sale (by its designer or an admin). Hosts who bought it keep it.
     */
    public function unpublish(Template $template, ?DesignerProfile $profile = null): void
    {
        if ($profile) {
            $this->ensureOwner($profile, $template);
        }
        if ($template->marketplace_status !== 'published') {
            throw ValidationException::withMessages(['template' => trans('marketplace.errors.not_published')]);
        }
        $template->forceFill(['marketplace_status' => 'unpublished', 'active' => false])->save();
        AuditLog::record('marketplace.template_unpublished', $template, [], $profile?->user);
    }

    /**
     * Back on sale with its last approved revision (no new review needed).
     */
    public function republish(DesignerProfile $profile, Template $template): void
    {
        $this->ensureOwner($profile, $template);
        if ($template->marketplace_status !== 'unpublished' || $template->live_revision_id === null) {
            throw ValidationException::withMessages(['template' => trans('marketplace.errors.not_unpublished')]);
        }
        $template->forceFill(['marketplace_status' => 'published', 'active' => true])->save();
        AuditLog::record('marketplace.template_republished', $template, [], $profile->user);
    }

    /**
     * The cover image on the media disk under designers/{user_id}/ (shown in the marketplace;
     * not part of the review, like a product photo).
     */
    public function uploadCover(DesignerProfile $profile, Template $template, UploadedFile $file): string
    {
        $this->ensureOwner($profile, $template);

        $disk = Storage::disk(config('filesystems.media_disk', 'public'));
        $extension = $file->guessExtension() ?: 'jpg';
        $path = $file->storeAs("designers/{$profile->user_id}/covers", $template->id.'-'.Str::lower(Str::random(8)).'.'.$extension, ['disk' => config('filesystems.media_disk', 'public')]);

        if ($template->cover_path && $template->cover_path !== $path) {
            $disk->delete($template->cover_path);
        }
        $template->forceFill(['cover_path' => $path])->save();

        return $path;
    }

    /**
     * Normalized, validated fields of a revision.
     *
     * @return array<string, mixed>
     */
    private function clean(DesignerProfile $profile, array $data): array
    {
        $errors = [];

        foreach (['name_ar', 'name_en'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            if ($value === '' || mb_strlen($value) > 120) {
                $errors[$field] = trans('marketplace.errors.name');
            }
        }

        $occasion = (string) ($data['occasion'] ?? '');
        if (! in_array($occasion, Event::OCCASIONS, true)) {
            $errors['occasion'] = trans('marketplace.errors.occasion');
        }

        $opening = Opening::query()->where('active', true)->find($data['opening_id'] ?? null);
        if (! $opening) {
            $errors['opening_id'] = trans('marketplace.errors.opening');
        }

        // the theme is the design: a designer sells their own themes only
        $theme = Theme::query()->where('designer_id', $profile->user_id)->find($data['theme_id'] ?? null);
        if (! $theme) {
            $errors['theme_id'] = trans('marketplace.errors.theme');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $config = $data['default_config'] ?? null;
        if (! is_array($config) || array_is_list($config)) {
            throw ValidationException::withMessages(['default_config' => trans('admin.templates.errors.not_object')]);
        }

        // the chosen opening, theme and occasion always win over what the JSON says
        $config['version'] = $config['version'] ?? InvitationConfigValidator::CURRENT_VERSION;
        $config['occasion'] = $occasion;
        $config['opening'] = ['id' => $opening->id] + array_intersect_key(is_array($config['opening'] ?? null) ? $config['opening'] : [], ['options' => true]);
        $config['theme'] = $theme->toConfig();

        $problems = $this->catalog->templateErrors($config, $occasion, $opening->id, $theme->id);
        if ($problems !== []) {
            throw ValidationException::withMessages(['default_config' => $problems]);
        }

        return [
            'name_ar' => trim($data['name_ar']),
            'name_en' => trim($data['name_en']),
            'description_ar' => filled($data['description_ar'] ?? null) ? trim($data['description_ar']) : null,
            'description_en' => filled($data['description_en'] ?? null) ? trim($data['description_en']) : null,
            'occasion' => $occasion,
            'opening_id' => $opening->id,
            'theme_id' => $theme->id,
            'default_config' => $config,
            'prices' => MarketplacePrices::validate((array) ($data['prices'] ?? [])),
            'included_in_signature' => (bool) ($data['included_in_signature'] ?? false),
        ];
    }

    private function editableRevision(Template $template): TemplateRevision
    {
        $latest = $template->revisions()->lockForUpdate()->first();

        if ($latest && $latest->isEditable()) {
            return $latest;
        }
        if ($latest && $latest->status === 'in_review') {
            throw ValidationException::withMessages(['template' => trans('marketplace.errors.in_review')]);
        }

        // the latest is approved (live) or superseded: a new revision starts from it
        return $template->revisions()->make([
            'number' => ($latest?->number ?? 0) + 1,
            'status' => 'draft',
        ]);
    }

    /**
     * The template row copies a revision (TemplateReview::approve, or a never-published draft).
     */
    public function mirror(Template $template, TemplateRevision $revision, string $status): void
    {
        $template->fill([
            'occasion' => $revision->occasion,
            'name_ar' => $revision->name_ar,
            'name_en' => $revision->name_en,
            'opening_id' => $revision->opening_id,
            'theme_id' => $revision->theme_id,
            'default_config' => $revision->default_config,
        ]);
        $template->forceFill([
            'description_ar' => $revision->description_ar,
            'description_en' => $revision->description_en,
            'prices' => $revision->prices,
            'included_in_signature' => $revision->included_in_signature,
            'marketplace_status' => $status,
        ])->save();
    }

    private function ensureOwner(DesignerProfile $profile, Template $template): void
    {
        abort_unless($template->designer_id !== null && $template->designer_id === $profile->user_id, 403);
    }

    /** "m-k3x9q2…" for templates, "d-…" for designer themes: never clashes with the house catalog's names. */
    private function newId(string $prefix): string
    {
        $model = $prefix === 'm' ? Template::class : Theme::class;
        do {
            $id = $prefix.'-'.Str::lower(Str::random(10));
            $id = preg_replace('/[^a-z0-9-]/', 'x', $id);
        } while ($model::query()->whereKey($id)->exists());

        return $id;
    }
}
