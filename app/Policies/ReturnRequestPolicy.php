<?php

namespace App\Policies;

class ReturnRequestPolicy extends OperationalPolicy
{
    protected const VIEW_PERMISSION = 'returns.view';

    protected const MANAGE_PERMISSION = 'returns.manage';
}
