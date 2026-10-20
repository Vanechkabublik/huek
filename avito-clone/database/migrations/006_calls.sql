-- Звонки: записи о звонках хранятся в переписке как особые сообщения (kind = 'call').
ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS kind VARCHAR(10) NOT NULL DEFAULT 'text';   -- text | call
ALTER TABLE chat_messages ADD COLUMN IF NOT EXISTS meta JSONB;                                  -- для звонка: {state, duration, video}
CREATE INDEX IF NOT EXISTS idx_chat_messages_kind ON chat_messages (conversation_id) WHERE kind = 'text';

-- В шаблон политики добавляем упоминание звонков — только если администратор ещё не правил текст сам
UPDATE legal_documents
SET body = replace(
        body,
        '- Переписка в чате между покупателями и продавцами.',
        E'- Переписка в чате между покупателями и продавцами.\n- Звонки через Сайт (голос и видео): сами аудио и видео передаются напрямую между участниками либо через сервер-ретранслятор и нами не записываются и не сохраняются; в переписке остаётся только запись о звонке (время, длительность, итог). При прямом соединении собеседник может увидеть ваш IP-адрес.'),
    version = version + 1,
    updated_at = NOW()
WHERE slug = 'privacy'
  AND updated_by IS NULL
  AND body LIKE '%- Переписка в чате между покупателями и продавцами.%'
  AND body NOT LIKE '%Звонки через Сайт%';
