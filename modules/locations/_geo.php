<?php
/**
 * Where a location actually is, as opposed to what it is called.
 *
 * `locations` has always carried an address as free text. That is enough to
 * print at the top of a document and no use at all for drawing a map, so two
 * columns are added here. They are nullable, and most rows will not have them
 * until somebody fills them in, which means everything reading them has to cope
 * with their absence rather than drawing an empty map over the sea.
 *
 * Coordinates rather than a geocoder, deliberately. The addresses in this table
 * are written by hand and read like "off Mombasa Road, opposite the Shell" --
 * which no geocoding service resolves reliably -- and a service that could would
 * want an API key and a billing account for a figure that changes about once a
 * year. Somebody drops a pin once, in Settings, and it is right from then on.
 *
 * A note on 0,0: it is a real coordinate in the Gulf of Guinea, and it is also
 * what an empty number field submits. Treating it as unset is the difference
 * between "we have not recorded this yard" and telling a customer the car is
 * in the Atlantic.
 */

if (!function_exists('locationGeoMigrate')) {

/** The two columns, added on first use like the rest of the schema. */
function locationGeoMigrate(PDO $db): bool
{
    static $done = null;
    if ($done !== null) return $done;
    foreach ([
        "ALTER TABLE locations ADD COLUMN latitude DECIMAL(10,7) NULL",
        "ALTER TABLE locations ADD COLUMN longitude DECIMAL(10,7) NULL",
    ] as $alter) {
        try { $db->exec($alter); } catch (\Throwable $_) { /* already there */ }
    }
    return $done = true;
}

/**
 * Is this pair of numbers somewhere on Earth that somebody meant?
 *
 * Range alone is not enough: 0,0 passes every bounds check and is what an
 * empty form field becomes.
 */
function locationGeoValid($lat, $lng): bool
{
    if ($lat === null || $lng === null || $lat === '' || $lng === '') return false;
    if (!is_numeric($lat) || !is_numeric($lng)) return false;
    $lat = (float)$lat;
    $lng = (float)$lng;
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return false;
    return !(abs($lat) < 0.00001 && abs($lng) < 0.00001);
}

/**
 * A location as something that can be put on a map.
 *
 * Falls back to the parent's pin when the location itself has none: a bay
 * inside a showroom compound is at the compound's gate as far as a customer
 * driving there is concerned, and that is the same reasoning by which the
 * public page already shows the parent's name.
 *
 * Returns null only when there is no location at all. A location with no pin
 * still comes back, with mappable => false, because its name and address are
 * worth showing even when the map cannot be drawn.
 *
 * @return array{id:int,name:string,address:?string,phone:?string,
 *               lat:?float,lng:?float,mappable:bool,from_parent:bool}|null
 */
function locationMap(PDO $db, ?int $locationId): ?array
{
    if (!$locationId) return null;
    locationGeoMigrate($db);
    try {
        $st = $db->prepare(
            "SELECT l.id, l.name, l.type, l.address, l.phone, l.latitude, l.longitude,
                    p.name AS parent_name, p.address AS parent_address,
                    p.phone AS parent_phone, p.type AS parent_type,
                    p.latitude AS parent_lat, p.longitude AS parent_lng
               FROM locations l
          LEFT JOIN locations p ON p.id = l.parent_id
              WHERE l.id = ?"
        );
        $st->execute([$locationId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('locationMap: ' . $e->getMessage());
        return null;
    }
    if (!$r) return null;

    $own    = locationGeoValid($r['latitude'], $r['longitude']);
    $parent = !$own && locationGeoValid($r['parent_lat'], $r['parent_lng']);

    return [
        'id'          => (int)$r['id'],
        // Everything that reads this is a page or a message a customer sees, so
        // the name carries the company. The row's own name is kept beside it
        // for anything that needs to match back to the record.
        'name'        => locationPublicName((string)$r['name'], (string)($r['type'] ?? '')),
        'name_raw'    => (string)$r['name'],
        'type'        => (string)($r['type'] ?? ''),
        // The address shown is the one belonging to whichever pin is used, so
        // the words and the marker never describe two different places.
        'address'     => $parent ? ($r['parent_address'] ?: $r['address']) : $r['address'],
        'phone'       => $r['phone'] ?: $r['parent_phone'],
        'lat'         => $own ? (float)$r['latitude']  : ($parent ? (float)$r['parent_lat'] : null),
        'lng'         => $own ? (float)$r['longitude'] : ($parent ? (float)$r['parent_lng'] : null),
        'mappable'    => $own || $parent,
        'from_parent' => $parent,
    ];
}

/**
 * The name a customer should see for one of our own places.
 *
 * The rows are named for the district they sit in -- "Lavington", "Spring
 * Valley" -- which is how the yard is referred to inside the company and is
 * nowhere near enough outside it. "Available at Lavington" names a suburb of
 * about thirty thousand people, and a directions link built from it opens the
 * suburb rather than the gate. Carrying the company name does both jobs at
 * once: it reads as a place of ours, and it is a thing a map can find.
 *
 * A port is not ours, so it is left alone; every other type -- storage,
 * showroom, office -- is a Mascardi address. A name that already says so is
 * left as it is, so nothing is ever branded twice.
 *
 * The prefix is the first word of the company name, which is the brand rather
 * than the registered name: "Mascardi Car Yard" gives "Mascardi Lavington",
 * not "Mascardi Car Yard Lavington".
 */
function locationPublicName(?string $name, ?string $type = null): string
{
    $name = trim((string)$name);
    if ($name === '' || $type === 'port') {
        return $name;
    }
    $brand = trim((string)(function_exists('getSetting')
        ? getSetting('company_name', 'Mascardi')
        : 'Mascardi'));
    $brand = trim((string)(explode(' ', $brand)[0] ?? ''));
    if ($brand === '' || stripos($name, $brand) !== false) {
        return $name;
    }
    return $brand . ' ' . $name;
}

/**
 * A link that opens the place in whatever maps app the visitor has.
 *
 * A plain maps URL, not the embedded JavaScript API: this needs no key, no
 * billing account and no third-party script on the page.
 *
 * With a pin it points at the exact spot and there is nothing to interpret.
 * Without one it asks for directions to the place by name and address --
 * "Mascardi Lavington, James Gichuru Road" -- rather than searching the
 * address alone, which for a row named after its district used to open the
 * district. Directions rather than a search either way, because that is the
 * question the button is answering.
 */
function locationDirectionsUrl(array $loc): string
{
    if (!empty($loc['mappable'])) {
        return 'https://www.google.com/maps/dir/?api=1&destination='
             . rawurlencode($loc['lat'] . ',' . $loc['lng']);
    }
    // Name first: it is the part that identifies the place, and the address
    // narrows it down. Either alone is weaker than the two together.
    $parts = array_filter([
        trim((string)($loc['name'] ?? '')),
        trim((string)($loc['address'] ?? '')),
    ], static fn ($s) => $s !== '');
    if ($parts === []) return '';
    return 'https://www.google.com/maps/dir/?api=1&destination='
         . rawurlencode(implode(', ', $parts));
}

} // function_exists('locationGeoMigrate')
