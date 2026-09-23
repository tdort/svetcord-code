<?php
require_once __DIR__ . '/../Database.php';

class NitroModel
{
    public const COST_POINTS = 3000;
    public const DURATION_DAYS = 30;

    public static function status(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT points, nitro_until FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();

        $active = $row['nitro_until'] !== null && strtotime($row['nitro_until'] . ' UTC') > time();

        return [
            'points' => (int) $row['points'],
            'cost' => self::COST_POINTS,
            'duration_days' => self::DURATION_DAYS,
            'active' => $active,
            'nitro_until' => $row['nitro_until'],
        ];
    }

    /** Buy (or extend) Nitro with points. Stacks on top of remaining time if already active. */
    public static function purchase(int $userId): array
    {
        $pdo = Database::get();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT points, nitro_until FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $row = $stmt->fetch();

            if ((int) $row['points'] < self::COST_POINTS) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Not enough points yet.'];
            }

            $base = ($row['nitro_until'] !== null && strtotime($row['nitro_until'] . ' UTC') > time())
                ? $row['nitro_until']
                : gmdate('Y-m-d H:i:s');

            $stmt = $pdo->prepare(
                'UPDATE users
                 SET points = points - ?, nitro_until = DATE_ADD(?, INTERVAL ? DAY)
                 WHERE id = ?'
            );
            $stmt->execute([self::COST_POINTS, $base, self::DURATION_DAYS, $userId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Something went wrong — try again.'];
        }

        return ['success' => true] + self::status($userId);
    }
}
