<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/models/AdminModel.php';

Auth::bootSession();
$admin = Auth::requireAdmin();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'overview': {
        json_ok(['stats' => AdminModel::getStats()]);
        break;
    }

    case 'users': {
        $search = sanitize_text($_GET['q'] ?? '');
        json_ok(['users' => AdminModel::listUsers($search)]);
        break;
    }

    case 'ban': {
        require_method('POST');
        $body = request_body();
        $targetId = (int) ($body['user_id'] ?? 0);
        $reason = sanitize_text($body['reason'] ?? '');

        if (mb_strlen($reason) > 255) {
            json_error('Reason must be 255 characters or fewer.');
        }

        $result = AdminModel::banUser($targetId, $reason, (int) $admin['id']);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    case 'unban': {
        require_method('POST');
        $body = request_body();
        $targetId = (int) ($body['user_id'] ?? 0);

        $result = AdminModel::unban($targetId, (int) $admin['id']);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    case 'warn': {
        require_method('POST');
        $body = request_body();
        $targetId = (int) ($body['user_id'] ?? 0);
        $reason = sanitize_text($body['reason'] ?? '');

        if (mb_strlen($reason) > 255) {
            json_error('Reason must be 255 characters or fewer.');
        }

        $result = AdminModel::warnUser($targetId, $reason, (int) $admin['id']);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    case 'warnings': {
        json_ok(['warnings' => AdminModel::listWarnings()]);
        break;
    }

    case 'user_warnings': {
        $targetId = (int) ($_GET['user_id'] ?? 0);
        json_ok(['warnings' => AdminModel::listWarningsForUser($targetId)]);
        break;
    }

    case 'revoke_warning': {
        require_method('POST');
        $body = request_body();
        $warningId = (int) ($body['warning_id'] ?? 0);

        $result = AdminModel::revokeWarning($warningId, (int) $admin['id']);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    case 'bans': {
        json_ok(['bans' => AdminModel::listBans()]);
        break;
    }

    case 'servers': {
        $search = sanitize_text($_GET['q'] ?? '');
        json_ok(['servers' => AdminModel::listServers($search)]);
        break;
    }

    case 'disable_server': {
        require_method('POST');
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);
        $reason = sanitize_text($body['reason'] ?? '');

        if (mb_strlen($reason) > 255) {
            json_error('Reason must be 255 characters or fewer.');
        }

        $result = AdminModel::disableServer($serverId, $reason, (int) $admin['id']);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    case 'enable_server': {
        require_method('POST');
        $body = request_body();
        $serverId = (int) ($body['server_id'] ?? 0);

        $result = AdminModel::enableServer($serverId);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    case 'reports': {
        require_once __DIR__ . '/../includes/models/ReportModel.php';
        $status = $_GET['status'] ?? '';
        $status = in_array($status, ['open', 'resolved', 'dismissed'], true) ? $status : null;
        json_ok(['reports' => ReportModel::listAll($status)]);
        break;
    }

    case 'resolve_report': {
        require_method('POST');
        require_once __DIR__ . '/../includes/models/ReportModel.php';
        $body = request_body();
        $reportId = (int) ($body['report_id'] ?? 0);
        $status = (string) ($body['status'] ?? '');
        $result = ReportModel::resolve($reportId, (int) $admin['id'], $status);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    case 'set_badge': {
        require_method('POST');
        $body = request_body();
        $targetId = (int) ($body['user_id'] ?? 0);
        $badge = (string) ($body['badge'] ?? 'none');

        $result = AdminModel::setBadge($targetId, $badge, (int) $admin['id']);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    /** ---------------- Custom badges ---------------- */

    case 'custom_badges': {
        require_once __DIR__ . '/../includes/models/CustomBadgeModel.php';
        json_ok(['badges' => CustomBadgeModel::listAll()]);
        break;
    }

    case 'custom_badge_delete': {
        require_method('POST');
        require_once __DIR__ . '/../includes/models/CustomBadgeModel.php';
        $body = request_body();
        CustomBadgeModel::delete((int) ($body['badge_id'] ?? 0));
        json_ok();
        break;
    }

    case 'user_badges': {
        require_once __DIR__ . '/../includes/models/CustomBadgeModel.php';
        $targetId = (int) ($_GET['user_id'] ?? 0);
        json_ok([
            'all_badges' => CustomBadgeModel::listAll(),
            'assigned_ids' => CustomBadgeModel::badgeIdsForUser($targetId),
        ]);
        break;
    }

    case 'assign_badge': {
        require_method('POST');
        require_once __DIR__ . '/../includes/models/CustomBadgeModel.php';
        $body = request_body();
        CustomBadgeModel::assign((int) ($body['user_id'] ?? 0), (int) ($body['badge_id'] ?? 0));
        json_ok();
        break;
    }

    case 'unassign_badge': {
        require_method('POST');
        require_once __DIR__ . '/../includes/models/CustomBadgeModel.php';
        $body = request_body();
        CustomBadgeModel::unassign((int) ($body['user_id'] ?? 0), (int) ($body['badge_id'] ?? 0));
        json_ok();
        break;
    }

    default:
        json_error('Unknown action', 404);
}
