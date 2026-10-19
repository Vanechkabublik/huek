<?php
declare(strict_types=1);

namespace App;

/**
 * Журнал действий администраторов: кто, что и над чем сделал.
 */
final class Audit
{
    public const LABELS = [
        'ad.approve'      => 'Одобрил объявление',
        'ad.reject'       => 'Отклонил объявление',
        'ad.block'        => 'Заблокировал объявление',
        'ad.delete'       => 'Удалил объявление',
        'user.block'      => 'Заблокировал пользователя',
        'user.unblock'    => 'Разблокировал пользователя',
        'ip.ban'          => 'Заблокировал IP-адрес',
        'ip.unban'        => 'Снял блокировку IP-адреса',
        'legal.save'      => 'Изменил юридический документ',
        'settings.save'   => 'Изменил настройки сайта',
        'user.password'   => 'Сменил пароль пользователю',
        'user.delete'     => 'Удалил пользователя',
        'user.role'       => 'Изменил роль пользователя',
        'category.create' => 'Создал категорию',
        'category.update' => 'Изменил категорию',
        'category.delete' => 'Удалил категорию',
        'attribute.save'  => 'Сохранил характеристику категории',
        'attribute.delete'=> 'Удалил характеристику категории',
        'review.delete'   => 'Удалил отзыв',
        'bulk'            => 'Массовое действие',
    ];

    /** @param array<string,mixed> $details */
    public static function log(array $actor, string $action, ?string $targetType = null, ?int $targetId = null, array $details = []): void
    {
        try {
            Database::run(
                'INSERT INTO audit_log (actor_id, actor_name, action, target_type, target_id, details) VALUES (?, ?, ?, ?, ?, ?::jsonb)',
                [(int) $actor['id'], $actor['name'], $action, $targetType, $targetId, json_encode($details, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT)]
            );
        } catch (\Throwable $e) {
            error_log('Audit log failed: ' . $e->getMessage());   // журнал не должен ломать само действие
        }
    }

    /**
     * @return array{items:array,total:int,has_more:bool}
     */
    public static function list(string $filter = '', int $page = 1, ?string $targetType = null, ?int $targetId = null, int $perPage = 30): array
    {
        $where = ['1 = 1'];
        $params = [];
        if ($filter !== '') {
            $where[] = 'action LIKE ?';
            $params[] = addcslashes($filter, '%_\\') . '%';
        }
        if ($targetType !== null) {
            $where[] = 'target_type = ?';
            $params[] = $targetType;
            if ($targetId !== null) {
                $where[] = 'target_id = ?';
                $params[] = $targetId;
            }
        }
        $w = implode(' AND ', $where);
        $total = (int) Database::value("SELECT COUNT(*) FROM audit_log WHERE $w", $params);
        $offset = (max(1, $page) - 1) * $perPage;
        $rows = Database::all(
            "SELECT id, actor_id, actor_name, action, target_type, target_id, details, created_at
             FROM audit_log WHERE $w ORDER BY id DESC LIMIT $perPage OFFSET $offset",
            $params
        );
        return [
            'items' => array_map(static function (array $r) {
                $d = is_string($r['details']) ? json_decode($r['details'], true) : $r['details'];
                return [
                    'id'          => (int) $r['id'],
                    'actor_id'    => $r['actor_id'] !== null ? (int) $r['actor_id'] : null,
                    'actor_name'  => $r['actor_name'] ?? '—',
                    'action'      => $r['action'],
                    'label'       => self::LABELS[$r['action']] ?? $r['action'],
                    'target_type' => $r['target_type'],
                    'target_id'   => $r['target_id'] !== null ? (int) $r['target_id'] : null,
                    'details'     => is_array($d) ? $d : [],
                    'date_text'   => Format::dateFull($r['created_at']),
                ];
            }, $rows),
            'total'    => $total,
            'has_more' => $offset + count($rows) < $total,
        ];
    }
}
