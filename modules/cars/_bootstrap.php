<?php
/**
 * Cars — service billing.
 *
 * The problem
 * -----------
 * A car has two quite different relationships to a client, and the schema only
 * had room for one:
 *
 *   OWNERSHIP  — cars.client_id + cars.car_type. Whose vehicle is this?
 *                'inventory' is ours to sell; 'client' belongs to a customer.
 *   BILLING    — who pays for work done on it?
 *
 * For a customer's car those are the same person, so the single field held up.
 * They come apart the moment we service our own stock: the work is a real
 * expense that has to be quoted and invoiced to Mascardi, but the vehicle is
 * still ours and still for sale. With only cars.client_id to hand, the only way
 * to attach the car to a client was to edit it and set car_type = 'client' —
 * which is precisely what drops it out of the inventory list.
 *
 * So billing gets its own field. Ownership stays exactly as it was, the car
 * stays in stock, and the paperwork can still be raised against whoever is
 * actually paying.
 *
 * Worth knowing: servicing a car never removed it from inventory by itself.
 * Job cards only move cars.status to in_workshop and back, and the inventory
 * list counts those. Only 'delivered' and 'sold' leave. Editing car_type by
 * hand was the thing doing the damage.
 */

if (!function_exists('carsEnsureServiceBilling')) {

/** The internal client every in-house expense is billed to. */
if (!defined('CAR_INTERNAL_CLIENT')) define('CAR_INTERNAL_CLIENT', 'Mascardi Luxury Cars');

/**
 * Adds the billing column once. Follows the inline-migration habit used across
 * the system: run on page load, cheap after the first time, no deploy step.
 */
function carsEnsureServiceBilling(PDO $db): bool
{
    static $done = null;
    if ($done !== null) return $done;

    if (getSetting('cars_service_billing_version', '') === '1') return $done = true;

    try {
        $cols = $db->query("SHOW COLUMNS FROM cars LIKE 'service_client_id'")->fetchAll();
        if (!$cols) {
            $db->exec("ALTER TABLE cars ADD COLUMN service_client_id INT NULL AFTER client_id");
            $db->exec("CREATE INDEX idx_cars_service_client ON cars (service_client_id)");
        }
        $db->prepare("INSERT INTO settings (setting_key, setting_value)
                      VALUES ('cars_service_billing_version', '1')
                      ON DUPLICATE KEY UPDATE setting_value = '1'")->execute();
        return $done = true;
    } catch (\Throwable $e) {
        error_log('carsEnsureServiceBilling: ' . $e->getMessage());
        return $done = false;
    }
}

/**
 * The internal client, created on first use.
 *
 * Matched loosely on name because the company is likely already on file under
 * some spelling, and a second "Mascardi" in the client list would be worse than
 * reusing the one that is there.
 */
function carInternalClient(PDO $db): ?array
{
    try {
        $st = $db->prepare("SELECT * FROM clients
                             WHERE LOWER(name) LIKE '%mascardi%'
                          ORDER BY id ASC LIMIT 1");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;

        $db->prepare("INSERT INTO clients (name, email, phone, status, notes, created_at)
                      VALUES (?, ?, ?, 'active', ?, NOW())")
           ->execute([
               CAR_INTERNAL_CLIENT, '', '',
               'Internal account. Work on our own stock is billed here so it is '
             . 'quoted, invoiced and reported as the expense it is.',
           ]);
        $id = (int)$db->lastInsertId();
        $st = $db->prepare("SELECT * FROM clients WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        error_log('carInternalClient: ' . $e->getMessage());
        return null;
    }
}

/**
 * Who work on this car is billed to.
 *
 * Falls back to the owner, because for a customer's car the two are the same
 * and making every caller work that out invites them to get it wrong.
 */
function carBillingClient(PDO $db, array $car): ?array
{
    $id = (int)($car['service_client_id'] ?? 0) ?: (int)($car['client_id'] ?? 0);
    if ($id <= 0) return null;
    try {
        $st = $db->prepare("SELECT * FROM clients WHERE id = ?");
        $st->execute([$id]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) { return null; }
}

/**
 * Points a car's service billing at a client. Pass null to clear it.
 *
 * Deliberately touches nothing but service_client_id — not car_type, not
 * client_id, not status. Billing our own stock to ourselves must leave the
 * vehicle exactly where it was, still for sale.
 */
function carSetBillingClient(PDO $db, int $carId, ?int $clientId): bool
{
    if ($carId <= 0) return false;
    if (!carsEnsureServiceBilling($db)) return false;
    try {
        $db->prepare("UPDATE cars SET service_client_id = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$clientId ?: null, $carId]);
        return true;
    } catch (\Throwable $e) {
        error_log('carSetBillingClient: ' . $e->getMessage());
        return false;
    }
}

/**
 * Hands service billing to the buyer once a vehicle is sold.
 *
 * Called from the delivery handover. Until now the sale moved ownership but
 * left billing pointing at us, so the first post-sale service would have been
 * quoted to Mascardi rather than to the person who now owns the car.
 */
function carTransferBillingToBuyer(PDO $db, int $carId, int $buyerClientId): bool
{
    if ($carId <= 0 || $buyerClientId <= 0) return false;
    if (!carsEnsureServiceBilling($db)) return false;
    try {
        $db->prepare("UPDATE cars SET service_client_id = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$buyerClientId, $carId]);
        return true;
    } catch (\Throwable $e) {
        error_log('carTransferBillingToBuyer: ' . $e->getMessage());
        return false;
    }
}

} // function_exists('carsEnsureServiceBilling')
