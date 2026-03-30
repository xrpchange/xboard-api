<?php

namespace App\Support;

/**
 * Deep link base for opening a deal in the Android app (intent-filter xboard://deal/open).
 * Not configurable: scheme/path are fixed by the client; host/query come from the lot.
 */
final class DealSellerOpenLinks
{
    public const APP_DEEP_LINK_BASE = 'xboard://deal/open';
}
