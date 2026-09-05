<?php

namespace App\Policies;

class SupportCasePolicy extends OperationalPolicy
{
    protected const VIEW_PERMISSION = 'support.view';

    protected const MANAGE_PERMISSION = 'support.manage';
}
