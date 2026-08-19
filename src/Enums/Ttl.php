<?php

declare(strict_types=1);

namespace Kivara\Cache\Enums;

enum Ttl: int
{
    case SECOND = 1;
    case FIVE_SECONDS = 5;
    case TEN_SECONDS = 10;
    case THIRTY_SECONDS = 30;
    case MINUTE = 60;
    case FIVE_MINUTES = 300;
    case TEN_MINUTES = 600;
    case FIFTEEN_MINUTES = 900;
    case THIRTY_MINUTES = 1800;
    case HOUR = 3600;
    case TWO_HOURS = 7200;
    case THREE_HOURS = 10800;
    case FOUR_HOURS = 14400;
    case SIX_HOURS = 21600;
    case TWELVE_HOURS = 43200;
    case DAY = 86400;
    case TWO_DAYS = 172800;
    case THREE_DAYS = 259200;
    case WEEK = 604800;
    case TWO_WEEKS = 1209600;
    case MONTH = 2592000;
    case YEAR = 31536000;
}
