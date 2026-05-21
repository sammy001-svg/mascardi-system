<?php
/**
 * API Bearer token authentication.
 * Tokens are stored in the `api_tokens` column of the `users` table.
 */

function apiAuthenticate(): ?array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($header, 'Bearer ')) return null;

    $token = trim(substr($header, 7));
    if (!$token) return null;

    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT id, name, username, role, status FROM users WHERE api_token = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

function apiError(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function apiResponse(mixed $data, int $code = 200, ?array $meta = null): void {
    http_response_code($code);
    $response = ['success' => true, 'data' => $data];
    if ($meta) $response['meta'] = $meta;
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function apiPaginate(array $rows, int $total, int $page, int $perPage): void {
    apiResponse($rows, 200, [
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $perPage,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}
