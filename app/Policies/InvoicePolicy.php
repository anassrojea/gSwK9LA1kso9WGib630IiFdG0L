<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

/**
 * Invoices are read-only for everyone: a customer sees their own, admins see
 * all of them (Filament InvoiceResource). Nobody edits or deletes a document;
 * a refund issues a credit note instead.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->isAdmin() || ($invoice->user_id !== null && $invoice->user_id === $user->getKey());
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
