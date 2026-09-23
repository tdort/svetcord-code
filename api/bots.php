<?php
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/Permissions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/models/BotModel.php';

Auth::bootSession();
$user = Auth::requireLogin();
$myId = (int) $user['id'];
$pdo = Database::get();
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list': {
        json_ok(['bots' => BotModel::listForOwner($myId)]);
        break;
    }

    case 'create': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $name = sanitize_text($body['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 32) {
            json_error('Bot name must be 1-32 characters.');
        }
        $bot = BotModel::create($myId, $name);
        json_ok(['bot' => $bot]); // includes the raw token — only time it's ever returned
        break;
    }

    case 'regenerate_token': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $botId = (int) ($body['bot_id'] ?? 0);
        $token = BotModel::regenerateToken($botId, $myId);
        if ($token === null) {
            json_error('Bot not found.', 404);
        }
        json_ok(['token' => $token]);
        break;
    }

    case 'delete': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $botId = (int) ($body['bot_id'] ?? 0);
        if (!BotModel::delete($botId, $myId)) {
            json_error('Bot not found.', 404);
        }
        json_ok();
        break;
    }

    /** ---------------- No-code automation rules ---------------- */

    case 'rules_list': {
        $botId = (int) ($_GET['bot_id'] ?? 0);
        $bot = BotModel::findById($botId);
        if (!$bot || (int) $bot['owner_id'] !== $myId) {
            json_error('Bot not found.', 404);
        }
        require_once __DIR__ . '/../includes/models/BotRuleModel.php';
        json_ok(['rules' => BotRuleModel::listForBot($botId)]);
        break;
    }

    case 'rule_create': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $botId = (int) ($body['bot_id'] ?? 0);
        $bot = BotModel::findById($botId);
        if (!$bot || (int) $bot['owner_id'] !== $myId) {
            json_error('Bot not found.', 404);
        }
        require_once __DIR__ . '/../includes/models/BotRuleModel.php';
        $triggerType = in_array($body['trigger_type'] ?? '', BotRuleModel::TRIGGER_TYPES, true) ? $body['trigger_type'] : 'contains';
        $triggerValue = sanitize_text($body['trigger_value'] ?? '');
        $responseText = sanitize_text($body['response_text'] ?? '');
        if ($triggerValue === '' || mb_strlen($triggerValue) > 200) {
            json_error('Trigger text must be 1-200 characters.');
        }
        if ($responseText === '' || mb_strlen($responseText) > 2000) {
            json_error('Response text must be 1-2000 characters.');
        }
        $rule = BotRuleModel::create($botId, $triggerType, $triggerValue, $responseText);
        json_ok(['rule' => $rule]);
        break;
    }

    case 'rule_set_enabled': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $ruleId = (int) ($body['rule_id'] ?? 0);
        require_once __DIR__ . '/../includes/models/BotRuleModel.php';
        $rule = BotRuleModel::findById($ruleId);
        if (!$rule) {
            json_error('Rule not found.', 404);
        }
        $bot = BotModel::findById((int) $rule['bot_id']);
        if (!$bot || (int) $bot['owner_id'] !== $myId) {
            json_error('Rule not found.', 404);
        }
        BotRuleModel::setEnabled($ruleId, !empty($body['enabled']));
        json_ok();
        break;
    }

    case 'rule_delete': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $ruleId = (int) ($body['rule_id'] ?? 0);
        require_once __DIR__ . '/../includes/models/BotRuleModel.php';
        $rule = BotRuleModel::findById($ruleId);
        if (!$rule) {
            json_error('Rule not found.', 404);
        }
        $bot = BotModel::findById((int) $rule['bot_id']);
        if (!$bot || (int) $bot['owner_id'] !== $myId) {
            json_error('Rule not found.', 404);
        }
        BotRuleModel::delete($ruleId);
        json_ok();
        break;
    }

    /** ---------------- Code editor draft (never executed server-side — see migration note) ---------------- */

    case 'get_script': {
        $botId = (int) ($_GET['bot_id'] ?? 0);
        $bot = BotModel::findById($botId);
        if (!$bot || (int) $bot['owner_id'] !== $myId) {
            json_error('Bot not found.', 404);
        }
        json_ok(['script' => $bot['script']]);
        break;
    }

    case 'save_script': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $botId = (int) ($body['bot_id'] ?? 0);
        $bot = BotModel::findById($botId);
        if (!$bot || (int) $bot['owner_id'] !== $myId) {
            json_error('Bot not found.', 404);
        }
        $script = (string) ($body['script'] ?? '');
        if (mb_strlen($script) > 500000) {
            json_error('Script is too large.');
        }
        $pdo->prepare('UPDATE bots SET script = ? WHERE id = ?')->execute([$script, $botId]);
        json_ok();
        break;
    }

    /** ---------------- Adding a bot to a server (no bot ownership required — server permission required instead) ---------------- */

    case 'public_info': {
        $botId = (int) ($_GET['bot_id'] ?? 0);
        $info = BotModel::publicInfo($botId);
        if (!$info) {
            json_error('Bot not found.', 404);
        }
        json_ok(['bot' => $info]);
        break;
    }

    case 'addable_servers': {
        $botId = (int) ($_GET['bot_id'] ?? 0);
        if (!BotModel::publicInfo($botId)) {
            json_error('Bot not found.', 404);
        }
        json_ok(['servers' => BotModel::serversAddableBy($botId, $myId)]);
        break;
    }

    case 'add_to_server': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $botId = (int) ($body['bot_id'] ?? 0);
        $serverId = (int) ($body['server_id'] ?? 0);
        if (!BotModel::publicInfo($botId)) {
            json_error('Bot not found.', 404);
        }
        $perms = Permissions::effectiveFor($pdo, $serverId, $myId);
        if (!Permissions::has($perms, Permissions::MANAGE_SERVER)) {
            json_error("You need Manage Server permission there to add a bot.", 403);
        }
        $result = BotModel::addToServer($botId, $serverId);
        if (!$result['success']) {
            json_error($result['error']);
        }
        json_ok();
        break;
    }

    /** ---------------- Managing where YOUR bot is (ownership required) ---------------- */

    case 'bot_servers': {
        $botId = (int) ($_GET['bot_id'] ?? 0);
        $bot = BotModel::findById($botId);
        if (!$bot || (int) $bot['owner_id'] !== $myId) {
            json_error('Bot not found.', 404);
        }
        json_ok(['servers' => BotModel::serversForBot($botId)]);
        break;
    }

    case 'remove_from_server': {
        require_method('POST');
        Auth::requireNotBanned($user);
        $body = request_body();
        $botId = (int) ($body['bot_id'] ?? 0);
        $serverId = (int) ($body['server_id'] ?? 0);
        $bot = BotModel::findById($botId);
        if (!$bot || (int) $bot['owner_id'] !== $myId) {
            json_error('Bot not found.', 404);
        }
        BotModel::removeFromServer($botId, $serverId);
        json_ok();
        break;
    }

    default:
        json_error('Unknown action', 404);
}
