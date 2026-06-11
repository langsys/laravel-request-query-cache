<?php

namespace Langsys\RequestQueryCache\Enums;

enum IdempotencyScope: string
{
    case User = 'user';
    case Ip = 'ip';
    case GlobalScope = 'global';
}
