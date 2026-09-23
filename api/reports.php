<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/models/ReportModel.php';

Auth::bootSession();
$user = Auth::requireLogin();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'create': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $reportedUserId = (int) ($body['reported_user_id'] ?? 0);
        $reason = sanitize_text($body['reason'] ?? '');
        $messageId = !empty($body['message_id']) ? (int) $body['message_id'] : null;

        if ($reason === '' || mb_strlen($reason) > 500) {
            json_error('Please describe the issue in 1-500 characters.');
        }

        $result = ReportModel::create((int) $user['id'], $reportedUserId, $reason, $messageId);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    default:
        json_error('Unknown action', 404);
}
