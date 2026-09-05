<?php

namespace App\Policies;

class ShipmentPolicy extends OperationalPolicy
{
    protected const VIEW_PERMISSION = 'fulfillment.view';
    protected const MANAGE_PERMISSION = 'fulfillment.manage';
}
