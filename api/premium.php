<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/models/NitroModel.php';

Auth::bootSession();
$user = Auth::requireLogin();
$myId = (int) $user['id'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'status': {
        json_ok(['nitro' => NitroModel::status($myId)]);
        break;
    }

    case 'purchase': {
        require_method('POST');
        if (!empty($user['is_banned'])) {
            json_error('Purchases are disabled while your account is suspended.');
        }
        $result = NitroModel::purchase($myId);
        if (!$result['success']) {
            json_error($result['error']);
        }
        unset($result['success']);
        json_ok(['nitro' => $result]);
        break;
    }

    default:
        json_error('Unknown action.', 404);
}
