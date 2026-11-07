<?php
/**
 * Public web addresses for vehicles.
 *
 * `/showroom/view.php?id=290` tells a search engine nothing and a person less.
 * `/range-rover-evoque` carries the words somebody actually typed into Google,
 * survives being pasted into WhatsApp, and does not leak how many cars are in
 * the database or invite anybody to count through them.
 *
 * The slug is the model, which is what the car is called and what gets
 * searched. The make is added only when a second car would claim the same
 * address, then the year, then a number -- so the common case stays short and
 * the uncommon case stays correct.
 *
 * Hyphens and lower case, not CamelCase: search engines treat a hyphen as a
 * space and read `range-rover-evoque` as three words, while `RangeRoverEvoque`
 * is one word nobody searches for. Both forms resolve, because links get typed
 * by hand and shared in whatever case they were written, but only one is
 * canonical and the other redirects to it.
 *
 * A slug never changes once it is set. That is the whole point of it: a URL
 * that moves loses every link pointing at it and every position it had earned.
 * Correcting a typo in a model name therefore leaves the address alone, and the
 * way to deliberately re-issue one is to clear the column and let it rebuild.
 */

if (!function_exists('carSlugMigrate')) {

/**
 * Addresses that already belong to something else.
 *
 * The pretty URL sits at the site root, so a slug of "login" or "showroom"
 * would shadow a real page. Apache is told to serve real files first, which
 * covers most of it, but a directory that does not exist yet today might
 * tomorrow -- so these are refused outright.
 */
function carSlugReserved(): array
{
    return [
        'index', 'login', 'logout', 'profile', 'showroom', 'modules', 'api',
        'client', 'portal', 'assets', 'uploads', 'includes', 'config',
        'database', 'migrations', 'scripts', 'storage', 'sessions', 'tests',
        'visitorbook', 'iclock', 'scratch', 'sitemap', 'robots', 'manifest',
        'offline', 'sw', 'favicon', 'admin', 'vehicles', 'contact', 'compare',
        'inquiry', 'viewing', 'in-shipment', 'book-service', 'car', 'cars',
    ];
}

/** The slug column, added on first use like the rest of the schema. */
function carSlugMigrate(PDO $db): bool
{
    static $done = null;
    if ($done !== null) return $done;
    foreach ([
        "ALTER TABLE cars ADD COLUMN slug VARCHAR(190) NULL",
        // Unique so two cars can never claim one address, and indexed because
        // every pretty URL is a lookup on it.
        "ALTER TABLE cars ADD UNIQUE INDEX idx_cars_slug (slug)",
        // The Product ID (MSC-1042). Unique so two cars can never share one.
        "ALTER TABLE cars ADD COLUMN product_id VARCHAR(20) NULL",
        "ALTER TABLE cars ADD UNIQUE INDEX idx_cars_product_id (product_id)",
    ] as $alter) {
        try { $db->exec($alter); } catch (\Throwable $_) { /* already there */ }
    }
    $done = true;
    // Ensure any car still missing a Product ID (existing stock or rows inserted
    // via imports, trade-ins, or intake) receives one in id order.
    try {
        if ($db->query("SELECT 1 FROM cars WHERE product_id IS NULL OR product_id = '' LIMIT 1")->fetchColumn()) {
            carProductIdBackfill($db);
        }
    } catch (\Throwable $_) {}
    return true;
}

/**
 * Will a pretty address actually resolve on this host?
 *
 * The rewrite lives in .htaccess, and a host with AllowOverride off ignores
 * that file without telling anybody. Emitting /range-rover-evoque there would
 * put a dead link on every listing, and redirecting the old address to it would
 * take every vehicle page down. So .htaccess sets a variable, and until that
 * variable appears the site keeps using the addresses that are known to work.
 *
 * Apache renames the variable REDIRECT_MASCARDI_PRETTY once a rewrite rule has
 * fired, and can prefix it again for each further pass, so anything ending in
 * the name counts.
 */
function carPrettyUrls(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    foreach ($_SERVER as $k => $v) {
        if ($v === '1' && ($k === 'MASCARDI_PRETTY' || str_ends_with($k, '_MASCARDI_PRETTY'))) {
            return $ok = true;
        }
    }
    return $ok = false;
}

/** Words to a URL segment: lower case, hyphens, nothing else. */
function carSlugify(string $text): string
{
    $s = $text;
    // Transliterate what we can, so "Citroën" becomes "citroen" rather than
    // losing the letter altogether.
    if (function_exists('iconv')) {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
        if ($t !== false) $s = $t;
    }
    // TRANSLIT spells an umlaut as a quote mark beside the letter, so
    // "Citroen" arrives as Citro"en. Dropped rather than treated as a word
    // break, or the slug reads citro-en.
    $s = str_replace(['"', "'", '`', '^', '~'], '', $s);
    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim((string)$s, '-');
    return (string)preg_replace('/-{2,}/', '-', $s);
}

/**
 * The same text reduced to letters and digits, for matching forgivingly.
 *
 * `/RangeRoverEvoque`, `/range-rover-evoque` and `/Range_Rover_Evoque` all
 * reduce to the same thing, which is how a hand-typed link still finds the car.
 */
function carSlugLoose(string $text): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($text)) ?? '';
}

/**
 * Build an address for this car that nothing else is using.
 *
 * Tried in order of how well each reads: the model, then with the make, then
 * with the year, then numbered. A car being re-slugged keeps its own address
 * rather than colliding with itself.
 */
function carSlugBuild(PDO $db, array $car, int $ignoreId = 0): string
{
    carSlugMigrate($db);

    $model = trim((string)($car['model'] ?? ''));
    $make  = trim((string)($car['make']  ?? ''));
    $year  = trim((string)($car['year']  ?? ''));

    $candidates = [];
    if ($model !== '') $candidates[] = $model;
    if ($make !== '' && $model !== '') $candidates[] = $make . ' ' . $model;
    if ($year !== '' && $make !== '')  $candidates[] = $year . ' ' . $make . ' ' . $model;
    if (!$candidates) $candidates[] = 'vehicle';

    $taken = static function (string $slug) use ($db, $ignoreId): bool {
        if (in_array($slug, carSlugReserved(), true)) return true;
        // Looks like a Product ID (msc-1042): that address belongs to the
        // Product ID, so a word-based slug must never claim it.
        if (preg_match('/^msc-?\d+$/', $slug)) return true;
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM cars WHERE slug = ? AND id <> ?");
            $st->execute([$slug, $ignoreId]);
            return (int)$st->fetchColumn() > 0;
        } catch (\Throwable $e) {
            error_log('carSlugBuild: ' . $e->getMessage());
            return false;
        }
    };

    foreach ($candidates as $c) {
        $slug = carSlugify($c);
        if ($slug !== '' && !$taken($slug)) return $slug;
    }

    // Everything readable is gone, so number the last and best of them.
    $base = carSlugify(end($candidates)) ?: 'vehicle';
    for ($n = 2; $n < 500; $n++) {
        if (!$taken($base . '-' . $n)) return $base . '-' . $n;
    }
    // Beyond that, something is wrong; fall back to something certainly unique.
    return $base . '-' . (int)($car['id'] ?? 0);
}

/**
 * The car's slug, creating one if it has none.
 *
 * Only fills an empty column. A car that already has an address keeps it, for
 * the reason in this file's header.
 */
function carEnsureSlug(PDO $db, $car): string
{
    carSlugMigrate($db);

    $read = static function (PDO $db, int $id): ?array {
        try {
            $st = $db->prepare("SELECT id, make, model, year, slug FROM cars WHERE id = ?");
            $st->execute([$id]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (\Throwable $e) {
            error_log('carEnsureSlug read: ' . $e->getMessage());
            return null;
        }
    };

    if (!is_array($car)) {
        $car = $read($db, (int)$car);
    } elseif (!array_key_exists('slug', $car)) {
        /* A row selected without the slug column is not evidence that the car
           has no slug, and treating it as such would re-issue an address the
           world already has links to. The sitemap used to pass exactly such a
           row -- id and updated_at only -- which would have renamed the stock.
           So a partial row is re-read rather than trusted. */
        $car = $read($db, (int)($car['id'] ?? 0));
    }
    if (!is_array($car) || empty($car['id'])) return '';

    $slug = trim((string)($car['slug'] ?? ''));
    if ($slug !== '') return $slug;

    // Building needs the words; a row that came in without them has to be read.
    if (!array_key_exists('model', $car) || !array_key_exists('make', $car)) {
        $full = $read($db, (int)$car['id']);
        if ($full) $car = $full;
    }

    $slug = carSlugBuild($db, $car, (int)$car['id']);
    try {
        $db->prepare("UPDATE cars SET slug = ? WHERE id = ?")->execute([$slug, (int)$car['id']]);
    } catch (\Throwable $e) {
        // A race on the unique index: somebody else just took it. Re-read.
        error_log('carEnsureSlug store: ' . $e->getMessage());
        try {
            $st = $db->prepare("SELECT slug FROM cars WHERE id = ?");
            $st->execute([(int)$car['id']]);
            $slug = (string)$st->fetchColumn();
        } catch (\Throwable $_) { return ''; }
    }
    return $slug;
}

/** Give every car on the website an address. Returns how many were filled. */
function carSlugBackfill(PDO $db): int
{
    carSlugMigrate($db);
    try {
        $rows = $db->query("SELECT id, make, model, year, slug FROM cars
                             WHERE slug IS NULL OR slug = ''
                          ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('carSlugBackfill: ' . $e->getMessage());
        return 0;
    }
    $n = 0;
    foreach ($rows as $r) {
        if (carEnsureSlug($db, $r) !== '') $n++;
    }
    return $n;
}

/* ── Product ID ──────────────────────────────────────────────────────────────
 *
 * The public address of a car is its Product ID: mascardi.co.ke/MSC-1042.
 *
 * Unlike the word-based slug, it is short, easy to read out over the phone or
 * quote on WhatsApp, and never depends on how the model happens to be spelled.
 * It is issued once, in sequence from MSC-1001, and never changes or gets
 * reused. The old word-based addresses keep working: they redirect (301) here.
 */

/** Prefix of every Product ID. */
if (!defined('CAR_PRODUCT_ID_PREFIX')) define('CAR_PRODUCT_ID_PREFIX', 'MSC-');

/**
 * Reduce whatever somebody typed to canonical form, or null if it is not a
 * Product ID at all. `msc1042`, `MSC 1042` and `msc-1042` all become MSC-1042.
 */
function carProductIdNormalize(string $text): ?string
{
    if (preg_match('/^\s*msc[-_\s]?0*(\d{1,9})\s*\/?$/i', $text, $m)) {
        return CAR_PRODUCT_ID_PREFIX . (int)$m[1];
    }
    return null;
}

/** The number the next Product ID will carry. Reserves nothing. */
function carProductIdNextNumber(PDO $db): int
{
    carSlugMigrate($db);
    try {
        $max = (int)$db->query(
            "SELECT MAX(CAST(SUBSTRING(product_id, " . (strlen(CAR_PRODUCT_ID_PREFIX) + 1) . ") AS UNSIGNED))
               FROM cars WHERE product_id LIKE '" . CAR_PRODUCT_ID_PREFIX . "%'"
        )->fetchColumn();
    } catch (\Throwable $e) {
        error_log('carProductIdNextNumber: ' . $e->getMessage());
        $max = 0;
    }
    return max(1000, $max) + 1;
}

/**
 * The car's Product ID, issuing one if it has none.
 *
 * Only fills an empty column, so an issued ID is never changed. Accepts a car
 * row or a bare id. Results are cached for the request, because a listing page
 * asks for the same car's address several times.
 */
function carEnsureProductId(PDO $db, $car): string
{
    static $cache = [];
    carSlugMigrate($db);

    $id = is_array($car) ? (int)($car['id'] ?? 0) : (int)$car;
    if ($id <= 0) return '';
    if (isset($cache[$id])) return $cache[$id];

    // A row that already carries the ID is trusted. One selected without the
    // column is re-read, as with the slug, rather than assumed to have none.
    if (is_array($car) && !empty($car['product_id'])) {
        return $cache[$id] = (string)$car['product_id'];
    }

    $read = static function (PDO $db, int $id): ?string {
        try {
            $st = $db->prepare("SELECT product_id FROM cars WHERE id = ?");
            $st->execute([$id]);
            $v = $st->fetchColumn();
            return $v === false ? null : trim((string)$v);
        } catch (\Throwable $e) {
            error_log('carEnsureProductId read: ' . $e->getMessage());
            return null;
        }
    };

    $existing = $read($db, $id);
    if ($existing === null) return '';           // no such car
    if ($existing !== '') return $cache[$id] = $existing;

    // Another request may take the same number between reading and writing;
    // the unique index rejects the loser, which simply tries the next one.
    for ($try = 0; $try < 8; $try++) {
        $pid = CAR_PRODUCT_ID_PREFIX . (carProductIdNextNumber($db) + $try);
        try {
            $up = $db->prepare("UPDATE cars SET product_id = ?
                                 WHERE id = ? AND (product_id IS NULL OR product_id = '')");
            $up->execute([$pid, $id]);
            $now = $read($db, $id);
            if ($now !== null && $now !== '') return $cache[$id] = $now;
        } catch (\Throwable $e) {
            // Duplicate: loop and take the next number.
        }
    }
    error_log('carEnsureProductId: could not issue an ID for car ' . $id);
    return '';
}

/** Give every car a Product ID, oldest first. Returns how many were issued. */
function carProductIdBackfill(PDO $db): int
{
    carSlugMigrate($db);
    try {
        $ids = $db->query("SELECT id FROM cars WHERE product_id IS NULL OR product_id = ''
                           ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        error_log('carProductIdBackfill: ' . $e->getMessage());
        return 0;
    }
    $n = 0;
    foreach ($ids as $id) {
        if (carEnsureProductId($db, (int)$id) !== '') $n++;
    }
    return $n;
}

/**
 * Find the car a Product ID is asking for.
 *
 * @return array{id:int,product_id:string}|null
 */
function carProductIdResolve(PDO $db, string $text): ?array
{
    $pid = carProductIdNormalize($text);
    if ($pid === null) return null;
    carSlugMigrate($db);
    try {
        $st = $db->prepare("SELECT id, product_id FROM cars WHERE product_id = ? LIMIT 1");
        $st->execute([$pid]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id' => (int)$r['id'], 'product_id' => (string)$r['product_id']] : null;
    } catch (\Throwable $e) {
        error_log('carProductIdResolve: ' . $e->getMessage());
        return null;
    }
}

/**
 * The public address of a car: the site root plus its Product ID.
 *
 * One place builds this, so the address arrives everywhere at once. Falls back
 * to the query-string form when pretty addresses cannot be served on this host
 * (or no ID could be issued), which keeps a link working rather than handing
 * somebody a URL to the site root.
 */
function carUrl(PDO $db, $car): string
{
    $base = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $id   = is_array($car) ? (int)($car['id'] ?? 0) : (int)$car;
    $pid  = $id > 0 ? carEnsureProductId($db, $car) : '';

    if ($pid !== '' && carPrettyUrls()) return $base . '/' . $pid;
    return $base . '/showroom/view.php?id=' . $id;
}

/**
 * Find the car a pretty URL is asking for.
 *
 * Exact match first, then the forgiving one, so a link typed as
 * `/RangeRoverEvoque` still lands -- the page then redirects it to the
 * canonical address so only one form is ever indexed.
 *
 * @return array{id:int,slug:string}|null
 */
function carSlugResolve(PDO $db, string $slug): ?array
{
    $slug = trim($slug, " \t\n\r\0\x0B/");
    if ($slug === '' || strlen($slug) > 190) return null;
    carSlugMigrate($db);

    try {
        $st = $db->prepare("SELECT id, slug FROM cars WHERE slug = ? LIMIT 1");
        $st->execute([carSlugify($slug)]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return ['id' => (int)$r['id'], 'slug' => (string)$r['slug']];

        $loose = carSlugLoose($slug);
        if ($loose === '') return null;
        $st = $db->prepare("SELECT id, slug FROM cars
                             WHERE REPLACE(slug, '-', '') = ? LIMIT 1");
        $st->execute([$loose]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id' => (int)$r['id'], 'slug' => (string)$r['slug']] : null;
    } catch (\Throwable $e) {
        error_log('carSlugResolve: ' . $e->getMessage());
        return null;
    }
}

} // function_exists('carSlugMigrate')
