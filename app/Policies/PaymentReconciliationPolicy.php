<?php

namespace App\Policies;

class PaymentReconciliationPolicy extends OperationalPolicy
{
    protected const VIEW_PERMISSION = 'payments.view';
}
