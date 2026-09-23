<?php
require_once __DIR__ . '/../Database.php';
require_once __DIR__ . '/BotModel.php';

class BotRuleModel
{
    const TRIGGER_TYPES = ['contains', 'equals', 'starts_with'];

    public static function listForBot(int $botId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM bot_rules WHERE bot_id = ? ORDER BY id ASC');
        $stmt->execute([$botId]);
        return $stmt->fetchAll();
    }

    public static function findById(int $ruleId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM bot_rules WHERE id = ?');
        $stmt->execute([$ruleId]);
        $rule = $stmt->fetch();
        return $rule ?: null;
    }

    public static function create(int $botId, string $triggerType, string $triggerValue, string $responseText): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO bot_rules (bot_id, trigger_type, trigger_value, response_text, enabled, created_at)
             VALUES (?, ?, ?, ?, 1, NOW())'
        );
        $stmt->execute([$botId, $triggerType, $triggerValue, $responseText]);
        return self::findById((int) $pdo->lastInsertId());
    }

    public static function setEnabled(int $ruleId, bool $enabled): void
    {
        $pdo = Database::get();
        $pdo->prepare('UPDATE bot_rules SET enabled = ? WHERE id = ?')->execute([$enabled ? 1 : 0, $ruleId]);
    }

    public static function delete(int $ruleId): void
    {
        $pdo = Database::get();
        $pdo->prepare('DELETE FROM bot_rules WHERE id = ?')->execute([$ruleId]);
    }

    /**
     * Runs every bot that's a member of this channel's server against an
     * incoming (human-sent) message and auto-sends any matching replies.
     * Called right after a normal message is created — no polling, no
     * separate process, it all happens inline on your own server.
     * Bot replies never re-trigger this (only human messages call it), so
     * two bots can't loop on each other forever.
     */
    public static function runTriggersForMessage(int $channelId, int $serverId, string $content, string $senderUsername): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'SELECT b.id AS bot_id, b.user_id AS bot_user_id
             FROM bots b
             JOIN server_members sm ON sm.user_id = b.user_id AND sm.server_id = ?'
        );
        $stmt->execute([$serverId]);
        $bots = $stmt->fetchAll();
        if (empty($bots)) {
            return;
        }

        $lowerContent = mb_strtolower($content);
        $serverName = null; // fetched lazily, only if a matching rule actually uses {server}

        foreach ($bots as $bot) {
            $rules = self::listForBot((int) $bot['bot_id']);
            foreach ($rules as $rule) {
                if (!$rule['enabled']) {
                    continue;
                }
                if (self::matches($rule['trigger_type'], mb_strtolower($rule['trigger_value']), $lowerContent)) {
                    $response = preg_replace_callback('/\{random:([^}]+)\}/', function ($m) {
                        $options = explode('|', $m[1]);
                        return trim($options[array_rand($options)]);
                    }, $rule['response_text']);
                    $response = str_replace('{user}', $senderUsername, $response);
                    if (str_contains($response, '{server}')) {
                        if ($serverName === null) {
                            $s = $pdo->prepare('SELECT name FROM servers WHERE id = ?');
                            $s->execute([$serverId]);
                            $serverName = $s->fetchColumn() ?: 'this server';
                        }
                        $response = str_replace('{server}', $serverName, $response);
                    }
                    require_once __DIR__ . '/MessageModel.php';
                    MessageModel::create($channelId, (int) $bot['bot_user_id'], $response);
                    break; // one reply per bot per message, first matching rule wins
                }
            }
        }
    }

    private static function matches(string $triggerType, string $triggerValue, string $lowerContent): bool
    {
        if ($triggerValue === '') {
            return false;
        }
        switch ($triggerType) {
            case 'equals':
                return $lowerContent === $triggerValue;
            case 'starts_with':
                return str_starts_with($lowerContent, $triggerValue);
            case 'contains':
            default:
                return str_contains($lowerContent, $triggerValue);
        }
    }
}
