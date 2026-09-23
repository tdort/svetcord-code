<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/models/QuestModel.php';

Auth::bootSession();
$user = Auth::requireLogin();
$myId = (int) $user['id'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'status': {
        json_ok(['quest' => QuestModel::status($myId)]);
        break;
    }

    /** Step 1: client is about to show a simulated ad. */
    case 'start_ad': {
        require_method('POST');
        $result = QuestModel::startAd($myId);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok(['watch_seconds' => $result['watch_seconds']]);
        break;
    }

    /** Step 2: the ad "finished" — verified server-side, then rewarded. */
    case 'claim_ad': {
        require_method('POST');
        $result = QuestModel::claimAd($myId);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok(['reward' => $result['reward'], 'points' => $result['points']]);
        break;
    }

    default:
        json_error('Unknown action.', 404);
}
