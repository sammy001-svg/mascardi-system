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
