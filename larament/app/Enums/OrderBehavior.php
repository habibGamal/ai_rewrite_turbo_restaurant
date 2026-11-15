<?php

namespace App\Enums;

enum OrderBehavior: string
{
    case DINE_IN = 'dine_in';
    case DELIVERY = 'delivery';
    case COUNTER = 'counter';
    case COMPANIES = 'companies';
    case WEB_DELIVERY = 'web_delivery';
    case WEB_TAKEAWAY = 'web_takeaway';
    case INSTANT = 'instant';
}
