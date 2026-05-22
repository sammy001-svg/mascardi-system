<?php
/**
 * Notification helper functions.
 * All functions silently swallow exceptions so they never break the main request.
 */

function createNotification(int $userId, string $type, string $title, string $message = '', string $link = ''): void {
    try {
        getDB()->prepare(
            "INSERT INTO notifications (user_id, type, title, message, link) VALUES (?,?,?,?,?)"
        )->execute([$userId, $type, $title ?: 'Notification', $message, $link]);
    } catch (\Throwable $e) {}
}

function notifyRoles(array $roles, string $type, string $title, string $message = '', string $link = ''): void {
    try {
        $db     = getDB();
        $ph     = implode(',', array_fill(0, count($roles), '?'));
        $stmt   = $db->prepare("SELECT id FROM users WHERE role IN ({$ph}) AND status='active'");
        $stmt->execute($roles);
        foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $uid) {
            createNotification((int)$uid, $type, $title, $message, $link);
        }
    } catch (\Throwable $e) {}
}

function getUnreadNotificationCount(int $userId): int {
    try {
        $s = getDB()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
        $s->execute([$userId]);
        return (int)$s->fetchColumn();
    } catch (\Throwable $e) { return 0; }
}

function getRecentNotifications(int $userId, int $limit = 20): array {
    try {
        $s = getDB()->prepare("SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT ?");
        $s->execute([$userId, $limit]);
        return $s->fetchAll();
    } catch (\Throwable $e) { return []; }
}
