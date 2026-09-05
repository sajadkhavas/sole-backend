<?php

namespace App\Policies;

use App\Models\PaymentAttempt;
use App\Models\User;

class PaymentAttemptPolicy extends OperationalPolicy
{
    protected const VIEW_PERMISSION = 'payments.view';

    protected const MANAGE_PERMISSION = 'payments.reconcile';

    public function reconcile(User $user, PaymentAttempt $attempt): bool
    {
        return $user->hasPermission('payments.reconcile');
    }
}
