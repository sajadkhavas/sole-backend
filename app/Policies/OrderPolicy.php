<?php

namespace App\Policies;

class OrderPolicy extends OperationalPolicy
{
    protected const VIEW_PERMISSION = 'orders.view';
    protected const MANAGE_PERMISSION = 'orders.manage';
}
