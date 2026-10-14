<?php
/**
 * Delivery Coverage (geofence) helper.
 * LPG Delivery System v2
 *
 * Single source of truth for the circular 3 km delivery zone centered on the
 * store. Both the customer-facing map circle and the server-side order
 * validation read their numbers from the constants defined here (ultimately
 * from config/app.php), so the drawn boundary and the enforced boundary can
 * never drift apart.
 *
 * The straight-line distance between two coordinates is computed with the
 * Haversine formula using the Earth's mean radius (6,371,000 m).
 *
 * Store address : 21 5th Street, corner 6th Avenue, Grace Park East, Caloocan
 * Verification  : BMF Gas Corporation business listing + Philippine DOE LTO
 *                 record for the same address both resolve to the coordinates
 *                 below. Re-confirm on Google Maps before go-live.
 */

// Standalone fallbacks so this class is usable even without config/app.php.
if (!defined('STORE_LATITUDE')) {
    define('STORE_LATITUDE', 14.645962);
}
if (!defined('STORE_LONGITUDE')) {
    define('STORE_LONGITUDE', 120.988134);
}
if (!defined('DELIVERY_RADIUS_METERS')) {
    define('DELIVERY_RADIUS_METERS', 3000);
}

class DeliveryCoverage
{
    /** Earth mean radius in meters (per the Haversine approximation). */
    public const EARTH_RADIUS_METERS = 6371000;

    /** Shown when the customer is inside the delivery zone. */
    public const WITHIN_MESSAGE = 'Great! Your address is within our delivery coverage.';

    /** Required wording for an out-of-coverage selection. */
    public const OUT_OF_RANGE_MESSAGE = "Sorry, we can't deliver to this location. Your selected address is outside our 3 km delivery coverage. Please select another location within our delivery area.";

    /** Shown when no usable coordinates were supplied at all. */
    public const MISSING_LOCATION_MESSAGE = "We couldn't confirm your exact delivery location. Please set your location on the map (or correct your address) before placing an order.";

    /**
     * @return float Verified store latitude (center of the delivery circle).
     */
    public static function storeLatitude(): float
    {
        return (float)STORE_LATITUDE;
    }

    /**
     * @return float Verified store longitude (center of the delivery circle).
     */
    public static function storeLongitude(): float
    {
        return (float)STORE_LONGITUDE;
    }

    /**
     * @return float Delivery radius in meters (3,000 = 3 km).
     */
    public static function radiusMeters(): float
    {
        return (float)DELIVERY_RADIUS_METERS;
    }

    /**
     * Great-circle distance between two coordinates via the Haversine formula.
     *
     * @param float $lat1
     * @param float $lon1
     * @param float $lat2
     * @param float $lon2
     * @return float Distance in meters
     */
    public static function distanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = self::EARTH_RADIUS_METERS;

        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);

        $deltaLat = $lat2 - $lat1;
        $deltaLon = $lon2 - $lon1;

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1) * cos($lat2) * sin($deltaLon / 2) ** 2;

        // Guard against tiny floating-point drift pushing $a outside [0,1].
        $a = min(1, max(0, $a));

        $centralAngle = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $centralAngle;
    }

    /**
     * True when both values are numeric and inside valid geographic ranges.
     *
     * @param mixed $lat
     * @param mixed $lng
     * @return bool
     */
    public static function coordinatesAreValid($lat, $lng): bool
    {
        if ($lat === null || $lng === null || $lat === '' || $lng === '') {
            return false;
        }
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }

        $lat = (float)$lat;
        $lng = (float)$lng;

        return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
    }

    /**
     * Evaluate a customer's selected coordinates against the delivery zone.
     *
     * A coordinate exactly on the 3,000 m boundary counts as deliverable
     * (<= radius is allowed).
     *
     * @param mixed $lat
     * @param mixed $lng
     * @return array{
     *   valid: bool,
     *   within: bool,
     *   distance_meters: float|null,
     *   distance_label: string|null,
     *   message: string,
     *   store_latitude: float,
     *   store_longitude: float,
     *   radius_meters: float,
     *   limit_km: float
     * }
     */
    public static function evaluate($lat, $lng): array
    {
        $base = [
            'store_latitude'  => self::storeLatitude(),
            'store_longitude' => self::storeLongitude(),
            'radius_meters'   => self::radiusMeters(),
            'limit_km'        => round(self::radiusMeters() / 1000, 2),
        ];

        if (!self::coordinatesAreValid($lat, $lng)) {
            return $base + [
                'valid'           => false,
                'within'          => false,
                'distance_meters' => null,
                'distance_label'  => null,
                'message'         => self::MISSING_LOCATION_MESSAGE,
            ];
        }

        $distance = self::distanceMeters(
            self::storeLatitude(),
            self::storeLongitude(),
            (float)$lat,
            (float)$lng
        );

        $within = $distance <= self::radiusMeters();

        return $base + [
            'valid'           => true,
            'within'          => $within,
            'distance_meters' => $distance,
            'distance_label'  => self::formatDistance($distance),
            'message'         => $within ? self::WITHIN_MESSAGE : self::OUT_OF_RANGE_MESSAGE,
        ];
    }

    /**
     * Human-readable distance: meters for short hops, km for longer ones.
     *
     * @param float $meters
     * @return string e.g. "850 m" or "2.94 km"
     */
    public static function formatDistance(float $meters): string
    {
        if ($meters >= 1000) {
            return number_format($meters / 1000, 2) . ' km';
        }
        return (string)round($meters) . ' m';
    }

    /**
     * Whether order placement must enforce the delivery zone right now.
     *
     * Production always enforces. The isolated automated test harness sets
     * $GLOBALS['TEST_MODE'], which bypasses enforcement so legacy suites can
     * keep placing synthetic orders at arbitrary coordinates; a suite opts
     * back in with $GLOBALS['ENFORCE_DELIVERY_COVERAGE'] = true. This mirrors
     * the kind of guard the Mailer already uses for TEST_MODE.
     *
     * @return bool
     */
    public static function isEnforced(): bool
    {
        if (!empty($GLOBALS['TEST_MODE'])) {
            return !empty($GLOBALS['ENFORCE_DELIVERY_COVERAGE']);
        }
        return true;
    }
}
