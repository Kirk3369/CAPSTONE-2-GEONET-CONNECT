<?php
require_once __DIR__ . '/geonet.php';

function geonet_map_settings($input)
{
    $values = [];
    foreach (['lat' => [-90, 90], 'lng' => [-180, 180], 'zoom' => [3, 19]] as $field => $range) {
        $raw = $input[$field] ?? null;
        if ((!is_string($raw) && !is_int($raw) && !is_float($raw)) || !is_numeric($raw)) {
            throw new InvalidArgumentException('Enter valid map coordinates and zoom.');
        }
        $value = (float) $raw;
        if (!is_finite($value) || $value < $range[0] || $value > $range[1] || ($field === 'zoom' && floor($value) !== $value)) {
            throw new InvalidArgumentException('Latitude must be -90 to 90, longitude -180 to 180, and zoom a whole number from 3 to 19.');
        }
        $values[$field] = $value;
    }
    return $values;
}
