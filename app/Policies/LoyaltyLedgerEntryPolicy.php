<?php

namespace App\Policies;

class LoyaltyLedgerEntryPolicy extends OperationalPolicy
{
    protected const VIEW_PERMISSION = 'loyalty.view';
    protected const MANAGE_PERMISSION = 'loyalty.adjust';
}
