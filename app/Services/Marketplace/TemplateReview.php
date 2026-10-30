<?php

namespace App\Services\Marketplace;

use App\Models\AuditLog;
use App\Models\Template;
use App\Models\TemplateRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The admin's review queue (Filament, MarketplaceReviewResource):
 *
 *   template   draft → in_review → published (approve) | rejected (reject, with a reason) → unpublished
 *   revision   draft → in_review → approved | rejected; the approved one before it becomes superseded
 *
 * Approving copies the revision onto the template (its live columns), so a published
 * template only ever changes through a reviewed revision.
 */
class TemplateReview
{
    public function __construct(private TemplateStudio $studio) {}

    public function approve(TemplateRevision $revision, User $admin): Template
    {
        $this->ensureAdmin($admin);

        return DB::transaction(function () use ($revision, $admin) {
            $revision = TemplateRevision::query()->whereKey($revision->getKey())->lockForUpdate()->firstOrFail();
            if ($revision->status !== 'in_review') {
                throw ValidationException::withMessages(['revision' => trans('marketplace.errors.not_in_review')]);
            }

            $template = Template::query()->whereKey($revision->template_id)->lockForUpdate()->firstOrFail();

            TemplateRevision::query()
                ->where('template_id', $template->getKey())
                ->where('status', 'approved')
                ->update(['status' => 'superseded']);

            $revision->update(['status' => 'approved', 'reviewed_at' => now(), 'reviewed_by' => $admin->getKey(), 'rejection_reason' => null]);

            // an unpublished template gets its new revision but stays off sale until its designer republishes
            $status = $template->marketplace_status === 'unpublished' ? 'unpublished' : 'published';
            $this->studio->mirror($template, $revision, $status);
            $template->forceFill([
                'live_revision_id' => $revision->getKey(),
                'active' => $status === 'published',
                'published_at' => $template->published_at ?? now(),
            ])->save();

            AuditLog::record('marketplace.revision_approved', $template, ['revision' => $revision->number], $admin);

            return $template;
        });
    }

    public function reject(TemplateRevision $revision, User $admin, string $reason): void
    {
        $this->ensureAdmin($admin);
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => trans('validation.required', ['attribute' => 'reason'])]);
        }

        DB::transaction(function () use ($revision, $admin, $reason) {
            $revision = TemplateRevision::query()->whereKey($revision->getKey())->lockForUpdate()->firstOrFail();
            if ($revision->status !== 'in_review') {
                throw ValidationException::withMessages(['revision' => trans('marketplace.errors.not_in_review')]);
            }
            $revision->update(['status' => 'rejected', 'rejection_reason' => $reason, 'reviewed_at' => now(), 'reviewed_by' => $admin->getKey()]);

            $template = $revision->template;
            // a published template keeps selling its live revision; a new one is only rejected itself
            if ($template->published_at === null) {
                $template->forceFill(['marketplace_status' => 'rejected'])->save();
            }

            AuditLog::record('marketplace.revision_rejected', $template, ['revision' => $revision->number, 'reason' => $reason], $admin);
        });
    }

    private function ensureAdmin(User $user): void
    {
        if (! $user->isAdmin()) {
            throw new AuthorizationException(trans('app.payments.errors.admins_only'));
        }
    }
}
