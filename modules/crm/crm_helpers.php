<?php
if (!defined('BASE_URL')) exit;

/**
 * CRM Shared Helper Functions
 * ----------------------------
 * Utility functions used across the CRM module.
 * Not a page — include this file where needed.
 */

// ── Lead Scoring ─────────────────────────────────────────────────────────────

/**
 * Calculate a lead score from 0–100 based on stage, budget, activity recency,
 * activity count, and contact completeness.
 */
function calculateLeadScore(array $lead, PDO $db): int
{
    $score = 0;

    // ── Stage (0–25 pts) ──────────────────────────────────────────────────────
    $stageScores = [
        'hot'       => 25,
        'lukewarm'  => 20,
        'cold'      => 10,
        'reserved'  => 25,
        'lost'      => 0,
        'delivered' => 0,
    ];
    $score += $stageScores[$lead['stage']] ?? 0;

    // ── Budget (0–20 pts) ─────────────────────────────────────────────────────
    $budget = (float)($lead['budget'] ?? 0);
    if ($budget >= 5_000_000)     $score += 20;
    elseif ($budget >= 2_000_000) $score += 15;
    elseif ($budget >= 1_000_000) $score += 10;
    elseif ($budget >= 500_000)   $score += 5;
    elseif ($budget > 0)          $score += 2;

    // ── Activity recency & count (0–25 pts + 0–15 pts) ───────────────────────
    try {
        $stmt = $db->prepare(
            'SELECT MAX(created_at) AS last_at, COUNT(*) AS act_count
             FROM crm_activities
             WHERE lead_id = ?'
        );
        $stmt->execute([(int)$lead['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // Recency
        if (!empty($row['last_at'])) {
            $daysAgo = (int)floor((time() - strtotime($row['last_at'])) / 86400);
            if ($daysAgo <= 7)       $score += 25;
            elseif ($daysAgo <= 14)  $score += 15;
            elseif ($daysAgo <= 30)  $score += 10;
            else                     $score += 5;
        }
        // no activity → +0

        // Count
        $actCount = (int)($row['act_count'] ?? 0);
        if ($actCount >= 10)     $score += 15;
        elseif ($actCount >= 5)  $score += 10;
        elseif ($actCount >= 2)  $score += 5;
        elseif ($actCount >= 1)  $score += 2;

    } catch (\Throwable $e) {
        // DB error — skip activity components
    }

    // ── Contact completeness (0–15 pts) ──────────────────────────────────────
    if (!empty($lead['phone']))         $score += 5;
    if (!empty($lead['email']))         $score += 5;
    if (!empty($lead['interested_in'])) $score += 5;

    return min(100, max(0, $score));
}

// ── Score Colour ─────────────────────────────────────────────────────────────

/**
 * Return a Bootstrap contextual colour name (no "bg-" prefix) for a lead score.
 *
 * Usage examples:
 *   "bg-" . scoreColor($score)   → Bootstrap background class
 *   "text-" . scoreColor($score) → Bootstrap text class
 */
function scoreColor(int $score): string
{
    if ($score >= 80) return 'success';
    if ($score >= 60) return 'primary';
    if ($score >= 40) return 'warning';
    if ($score >= 20) return 'danger';
    return 'secondary';
}

// ── WhatsApp Helpers ──────────────────────────────────────────────────────────

/**
 * Normalise a phone number to E.164 format for WhatsApp.
 *
 * Rules (Kenya-centric defaults):
 *   - Leading 0  → replace with +254
 *   - 254 prefix (no +) → prepend +
 *   - Already starts with + → unchanged
 * Non-digit/non-+ characters are stripped first.
 */
function formatWhatsAppNumber(string $phone): string
{
    // Strip everything except digits and a leading +
    $clean = preg_replace('/[^0-9+]/', '', $phone);

    if (str_starts_with($clean, '0')) {
        // Local Kenyan format: 07xx… → +25407xx…
        $clean = '+254' . substr($clean, 1);
    } elseif (str_starts_with($clean, '254') && !str_starts_with($clean, '+')) {
        // International digits without the +
        $clean = '+' . $clean;
    }
    // Already E.164 (starts with +) — leave as-is

    return $clean;
}

/**
 * Build a wa.me click-to-chat URL with a pre-filled introductory message.
 *
 * @param string $phone        Raw phone number (will be normalised)
 * @param string $leadName     Customer's name
 * @param string $interestedIn Vehicle the lead is interested in (may be empty)
 * @param string $agentName    CRM agent's name
 * @param string $company      Company/dealership name
 * @return string              Full WhatsApp URL
 */
function buildWhatsAppUrl(
    string $phone,
    string $leadName,
    string $interestedIn,
    string $agentName,
    string $company
): string {
    // Normalise then strip the leading + — wa.me needs plain digits
    $e164   = formatWhatsAppNumber($phone);
    $digits = ltrim($e164, '+');

    $inCar  = $interestedIn !== '' ? " in the {$interestedIn}" : '';

    $message = "Hello {$leadName}! I'm {$agentName} from {$company}. "
             . "I'm following up on your interest{$inCar}. "
             . "When would be a good time to connect or visit our showroom?";

    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
}

/**
 * Hand a delivered lead over to the client book.
 *
 * Once a vehicle is delivered the buyer stops being a prospect and becomes a
 * customer, so the record has to exist in `clients` — otherwise the next time
 * they come in for a service the front desk has to re-register both the person
 * and the car from scratch, and the vehicle's history starts over.
 *
 * This does three things:
 *   1. resolves (or creates) the client record for the lead
 *   2. links the lead to it
 *   3. hands the linked vehicle to that client so it shows under Client Cars
 *
 * Safe to call repeatedly — an already-converted lead resolves to its existing
 * client, and a buyer who returns for a second vehicle is matched on phone or
 * email rather than duplicated.
 *
 * Returns the client id, or 0 if there was nothing usable to convert.
 */
function crmDeliverLeadToClient(PDO $db, array $lead): int
{
    $leadId = (int)($lead['id'] ?? 0);
    $name   = trim((string)($lead['name'] ?? ''));
    if (!$leadId || $name === '') return 0;

    $phone = trim((string)($lead['phone'] ?? '')) ?: null;
    $email = trim((string)($lead['email'] ?? '')) ?: null;

    // Columns added by inline migrations elsewhere; tolerate their absence.
    try { $db->exec("ALTER TABLE clients ADD COLUMN kra_pin VARCHAR(20) NULL"); } catch (\Throwable $_) {}

    // Some installs still carry the original schema where clients.email/phone are
    // NOT NULL. A buyer legitimately may have only a phone number (or only an
    // email), so inserting NULL there raised
    //   "Integrity constraint violation: 1048 Column 'email' cannot be null"
    // and every such conversion failed. Relax the columns to match reality.
    // NULL is used rather than '' on purpose: if an install has a UNIQUE index on
    // email, repeated empty strings would collide whereas multiple NULLs do not.
    try { $db->exec("ALTER TABLE clients MODIFY COLUMN email VARCHAR(150) NULL"); } catch (\Throwable $_) {}
    try { $db->exec("ALTER TABLE clients MODIFY COLUMN phone VARCHAR(50)  NULL"); } catch (\Throwable $_) {}

    $clientId = (int)($lead['client_id'] ?? 0);

    try {
        // Already converted — reuse, never duplicate.
        if (!$clientId && ($phone || $email)) {
            $s = $db->prepare("SELECT id FROM clients
                               WHERE (phone IS NOT NULL AND phone <> '' AND phone = ?)
                                  OR (email IS NOT NULL AND email <> '' AND email = ?)
                               ORDER BY id ASC LIMIT 1");
            $s->execute([$phone ?? '', $email ?? '']);
            $clientId = (int)($s->fetchColumn() ?: 0);
        }

        if (!$clientId) {
            $db->prepare("INSERT INTO clients (name, phone, email, id_number, status, notes)
                          VALUES (?,?,?,?, 'active', ?)")
               ->execute([
                   $name, $phone, $email,
                   trim((string)($lead['id_number'] ?? '')) ?: null,
                   'Created automatically on delivery of CRM lead #' . $leadId,
               ]);
            $clientId = (int)$db->lastInsertId();

            // Optional column — only set when it exists and the lead carries one.
            if (!empty($lead['kra_pin'])) {
                try { $db->prepare("UPDATE clients SET kra_pin=? WHERE id=?")->execute([$lead['kra_pin'], $clientId]); }
                catch (\Throwable $_) {}
            }
            logActivity('create', 'clients', $clientId, "Client created automatically from delivered lead #{$leadId}");
        }

        $db->prepare("UPDATE crm_leads SET client_id=?, updated_at=NOW() WHERE id=? AND (client_id IS NULL OR client_id=0)")
           ->execute([$clientId, $leadId]);

        // Hand the vehicle over. car_type is only switched for stock we owned —
        // consignment vehicles (trade_in / sale_on_behalf) keep their type so the
        // Trade-In module can still find and settle them.
        $carId = (int)($lead['pinned_car_id'] ?? 0);
        if ($carId) {
            // Service billing follows ownership. Without this the vehicle changes
            // hands while its work keeps being quoted to whoever paid before — for
            // ex-stock that means invoicing ourselves for a car we no longer own.
            require_once __DIR__ . '/../cars/_bootstrap.php';
            carsEnsureServiceBilling($db);
            $db->prepare("UPDATE cars
                          SET client_id = ?,
                              service_client_id = ?,
                              car_type  = CASE WHEN car_type = 'inventory' THEN 'client' ELSE car_type END,
                              owner_name  = COALESCE(NULLIF(owner_name,''), ?),
                              owner_phone = COALESCE(NULLIF(owner_phone,''), ?),
                              updated_at  = NOW()
                          WHERE id = ?")
               ->execute([$clientId, $clientId, $name, $phone, $carId]);
            logActivity('update', 'cars', $carId, "Vehicle assigned to client #{$clientId} on delivery of lead #{$leadId}");
        }

        return $clientId;
    } catch (\Throwable $e) {
        // Record the reason so callers can show it. Previously this returned 0
        // silently, so a failing conversion looked exactly like "nothing to do"
        // and the problem was invisible on screen.
        crmLastConvertError($e->getMessage());
        error_log('crmDeliverLeadToClient: ' . $e->getMessage());
        return 0;
    }
}

/** Last conversion failure reason — set by crmDeliverLeadToClient(), read by callers. */
function crmLastConvertError(?string $set = null): string
{
    static $err = '';
    if ($set !== null) $err = $set;
    return $err;
}

/**
 * Settle a consignment when its vehicle is delivered through a CRM lead.
 *
 * Sale on Behalf / Trade-In vehicles belong to a customer, not to us. Selling
 * one through the Trade-In module marks the deal sold and works out the
 * commission and the owner's payout. Selling the same vehicle through a CRM
 * lead did none of that — the car went out of the yard while its consignment
 * stayed "active" and no payout was ever raised, so the owner would not be paid.
 *
 * Idempotent: a deal that is already settled is left alone.
 * Returns the consignment reference that was settled, or '' if there was none.
 */
function crmSettleConsignmentOnDelivery(PDO $db, array $lead): string
{
    $carId = (int)($lead['pinned_car_id'] ?? 0);
    if (!$carId) return '';

    try {
        // consignments only exists once the Trade-In module has been used.
        $has = $db->query("SHOW TABLES LIKE 'consignments'")->fetchColumn();
        if (!$has) return '';

        $s = $db->prepare("SELECT * FROM consignments WHERE car_id = ? AND status = 'active' LIMIT 1");
        $s->execute([$carId]);
        $c = $s->fetch(PDO::FETCH_ASSOC);
        if (!$c) return '';   // not consignment stock, or already settled

        // Prefer the price actually agreed with the buyer; fall back to what the
        // vehicle was listed at so a payout is never based on zero.
        $price = (float)($lead['agreed_sale_price'] ?? 0);
        if ($price <= 0) $price = (float)($c['listing_price'] ?? 0);
        if ($price <= 0) {
            $cs = $db->prepare("SELECT COALESCE(NULLIF(offer_price,0), asking_price) FROM cars WHERE id = ?");
            $cs->execute([$carId]);
            $price = (float)$cs->fetchColumn();
        }
        if ($price <= 0) return '';   // nothing sane to settle against

        require_once __DIR__ . '/../trade_in/_bootstrap.php';
        $commission = consignmentCommission($c, $price);
        $payout     = round(max(0, $price - $commission), 2);

        $db->prepare("UPDATE consignments
                      SET status='sold', sold_price=?, sold_date=CURDATE(),
                          commission_amount=?, payout_amount=?, payout_status='pending'
                      WHERE id=? AND status='active'")
           ->execute([$price, $commission, $payout, (int)$c['id']]);

        logActivity('update', 'trade_in', (int)$c['id'],
            "Consignment {$c['reference']} settled on delivery of lead #" . (int)($lead['id'] ?? 0)
            . " — sold " . number_format($price, 2)
            . ", commission " . number_format($commission, 2)
            . ", owner payout " . number_format($payout, 2) . " (pending)");

        try {
            require_once __DIR__ . '/../../includes/notifications.php';
            notifyRoles(['admin','super_admin','general_manager','finance_manager','finance_officer'], 'sale',
                "Consignment Sold: {$c['reference']}",
                "Sold through a CRM lead. Owner payout of " . number_format($payout, 2) . " is pending.",
                BASE_URL . '/modules/trade_in/view.php?id=' . (int)$c['id']);
        } catch (\Throwable $_) {}

        return (string)$c['reference'];
    } catch (\Throwable $e) {
        error_log('crmSettleConsignmentOnDelivery: ' . $e->getMessage());
        return '';
    }
}
