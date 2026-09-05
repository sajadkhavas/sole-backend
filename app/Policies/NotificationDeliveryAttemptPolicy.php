<?php

namespace App\Policies;

class NotificationDeliveryAttemptPolicy extends OperationalPolicy
{
    protected const VIEW_PERMISSION = 'notifications.view';
}
