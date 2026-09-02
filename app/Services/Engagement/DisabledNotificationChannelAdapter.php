<?php

namespace App\Services\Engagement;

use App\Contracts\NotificationChannelAdapter;
use App\Models\NotificationSignal;

class DisabledNotificationChannelAdapter implements NotificationChannelAdapter
{
    public function deliver(NotificationSignal $signal, string $channel): array
    {
        return [
            'delivered' => false,
            'provider' => null,
            'reason' => 'adapter_unconfigured',
            'response_hash' => null,
        ];
    }
}
