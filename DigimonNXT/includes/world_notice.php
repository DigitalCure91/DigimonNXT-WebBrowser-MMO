<?php
declare(strict_types=1);
// Public broadcast data only. Administrative commands are CLI-only.
function dn_world_public_notice(): ?array {
    try {
        $db = pv_db();
        $statement = $db->prepare("SELECT value_json FROM nxt_world_settings WHERE setting_key='announcement' LIMIT 1");
        $statement->execute();
        $row = $statement->get_result()->fetch_assoc();
        $statement->close();
        $value = json_decode((string)($row['value_json'] ?? ''), true);
        if (!is_array($value) || (int)($value['expires_at'] ?? 0) <= time()) return null;
        $message = trim((string)($value['message'] ?? ''));
        if ($message === '') return null;
        return ['message' => (function_exists('mb_substr') ? mb_substr($message, 0, 1000) : substr($message, 0, 1000)), 'expires_at' => (int)$value['expires_at']];
    } catch (Throwable) { return null; }
}
function dn_world_notice_banner(): void {
    if (!pv_is_logged_in()) return;
    $notice = dn_world_public_notice();
    echo '<aside class="nxt-world-notice" data-world-notice role="status" aria-live="polite"'.($notice ? '' : ' hidden').'><strong>SERVER NOTICE</strong><span data-world-notice-text>'.pv_h((string)($notice['message'] ?? '')).'</span></aside>';
}
