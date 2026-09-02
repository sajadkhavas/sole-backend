<?php

namespace App\Contracts;

use App\Models\NotificationSignal;

interface NotificationChannelAdapter
{
    /**
     * @return array{delivered: bool, provider: ?string, reason: string, response_hash: ?string}
     */
    public function deliver(NotificationSignal $signal, string $channel): array;
}
