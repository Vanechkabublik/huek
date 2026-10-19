<?php
declare(strict_types=1);

namespace App;

/**
 * Юридические документы сайта: политика обработки персональных данных и пользовательское соглашение.
 * Тексты хранятся в БД и редактируются в админке. Разметка: «# Заголовок», «- пункт списка», пустая строка — новый абзац.
 * Подстановки: {{site}}, {{operator}}, {{email}}, {{date}}.
 */
final class Legal
{
    public const SLUGS = ['privacy' => 'Политика обработки персональных данных', 'terms' => 'Пользовательское соглашение'];

    /** @return array{slug:string,title:string,body:string,version:int,updated_at:string}|null */
    public static function get(string $slug): ?array
    {
        if (!isset(self::SLUGS[$slug])) {
            return null;
        }
        return self::all()[$slug] ?? null;
    }

    /** @var array<string,array<string,mixed>>|null */
    private static ?array $all = null;

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        if (self::$all === null) {
            self::$all = [];
            foreach (array_keys(self::SLUGS) as $slug) {
                self::$all[$slug] = null;
            }
            foreach (Database::all('SELECT slug, title, body, version, updated_at FROM legal_documents') as $r) {
                if (isset(self::SLUGS[$r['slug']])) {
                    self::$all[$r['slug']] = $r;
                }
            }
            self::$all = array_filter(self::$all);
        }
        return self::$all;
    }

    public static function save(string $slug, string $title, string $body, array $actor): void
    {
        $title = trim($title);
        $body = trim(str_replace("\r\n", "\n", $body));
        $errors = [];
        if (mb_strlen($title) < 3 || mb_strlen($title) > 200) {
            $errors['title'] = 'Заголовок — от 3 до 200 символов';
        }
        if (mb_strlen($body) < 50) {
            $errors['body'] = 'Текст слишком короткий (минимум 50 символов)';
        }
        if (mb_strlen($body) > 60000) {
            $errors['body'] = 'Текст слишком длинный (максимум 60 000 символов)';
        }
        if (!isset(self::SLUGS[$slug]) || $errors) {
            throw new HttpException('Проверьте правильность заполнения полей', 422, $errors);
        }
        $cur = self::get($slug);
        if ($cur !== null && $cur['title'] === $title && trim($cur['body']) === $body) {
            return;   // ничего не изменилось — версию не повышаем
        }
        Database::run(
            'UPDATE legal_documents SET title = ?, body = ?, version = version + 1, updated_at = NOW(), updated_by = ? WHERE slug = ?',
            [$title, $body, (int) $actor['id'], $slug]
        );
        self::$all = null;
        Audit::log($actor, 'legal.save', null, null, ['title' => $title]);
    }

    /** Версия согласия: меняется, когда админ правит любой документ. Пример: «p3-t1». */
    public static function consentVersion(): string
    {
        try {
            $parts = [];
            foreach (self::all() as $slug => $doc) {
                $parts[] = $slug[0] . $doc['version'];
            }
            return implode('-', $parts);
        } catch (\Throwable) {
            return '0';       // таблица документов ещё не создана миграцией
        }
    }

    /** Запоминает, что пользователь принял документы (время, версия) — вызывать при регистрации и входе с галочкой. */
    public static function recordConsent(int $userId): void
    {
        Database::run('UPDATE users SET consent_at = NOW(), consent_version = ? WHERE id = ?', [self::consentVersion(), $userId]);
    }

    /** Текст документа → безопасный HTML. */
    public static function html(array $doc): string
    {
        $vars = [
            '{{site}}'     => Settings::siteName(),
            '{{operator}}' => Settings::get('operator') !== '' ? Settings::get('operator') : 'владелец сайта',
            '{{email}}'    => Settings::get('contact_email') !== '' ? Settings::get('contact_email') : 'адрес, указанный на сайте',
            '{{date}}'     => date('d.m.Y', (int) strtotime($doc['updated_at'])),
        ];
        $html = '';
        $list = [];
        $flush = static function () use (&$html, &$list): void {
            if ($list) {
                $html .= '<ul>' . implode('', array_map(static fn(string $li) => '<li>' . $li . '</li>', $list)) . '</ul>';
                $list = [];
            }
        };
        $esc = static fn(string $s): string => e(strtr($s, $vars));
        foreach (preg_split('/\n{2,}/', trim($doc['body'])) ?: [] as $block) {
            foreach (explode("\n", $block) as $line) {
                $line = rtrim($line);
                if ($line === '') {
                    continue;
                }
                if (str_starts_with($line, '- ')) {
                    $list[] = $esc(substr($line, 2));
                    continue;
                }
                $flush();
                if (str_starts_with($line, '# ')) {
                    $html .= '<h2>' . $esc(substr($line, 2)) . '</h2>';
                } else {
                    $html .= '<p>' . $esc($line) . '</p>';
                }
            }
            $flush();
        }
        return $html;
    }
}
