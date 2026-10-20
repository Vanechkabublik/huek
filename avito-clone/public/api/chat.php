<?php
declare(strict_types=1);

/**
 * Эндпоинт чата (нужен вход).
 *   GET  ?action=list                       — диалоги + число непрочитанных
 *   GET  ?action=thread  c=ID | ad=ID, before? — сообщения диалога (или «черновик» диалога по объявлению)
 *   POST ?action=send    conversation_id | ad_id, body
 *   POST ?action=read    conversation_id    — пометить входящие прочитанными
 *   GET  ?action=ticket                     — тикет для подключения к WebSocket
 *   GET  ?action=unread                     — общее число непрочитанных
 *   GET  ?action=ice                        — серверы STUN/TURN для звонков (временные логин и пароль TURN)
 */

require __DIR__ . '/../../src/bootstrap.php';

use App\Auth;
use App\Calls;
use App\Chat;
use App\HttpException;
use App\Realtime;
use App\Request;
use App\Response;

Response::handleExceptions();
$me = (int) Auth::requireLogin(json: true)['id'];

switch (Request::action('list')) {
    case 'list':
        Response::ok(['conversations' => Chat::conversations($me), 'unread' => Chat::unreadTotal($me)]);

    case 'thread':
        $convId = Request::int('c');
        if ($convId === 0 && Request::int('ad') > 0) {
            $convId = Chat::conversationIdForAd(Request::int('ad'), $me) ?? 0;
            if ($convId === 0) {
                Response::ok(['conversation' => Chat::draftForAd(Request::int('ad'), $me), 'messages' => [], 'has_more' => false]);
            }
        }
        $before = Request::int('before') ?: null;
        $thread = Chat::messages($convId, $me, $before);
        Response::ok(['conversation' => Chat::summary($convId, $me)] + $thread);

    case 'send':
        Request::requirePost();
        $convId = Request::int('conversation_id');
        Response::ok(Chat::send(
            $me,
            $convId > 0 ? $convId : null,
            Request::int('ad_id') > 0 ? Request::int('ad_id') : null,
            (string) (Request::input()['body'] ?? '')
        ), 201);

    case 'read':
        Request::requirePost();
        Response::ok(['marked' => Chat::markRead(Request::int('conversation_id'), $me), 'unread' => Chat::unreadTotal($me)]);

    case 'ticket':
        Response::ok(['ticket' => Realtime::ticket($me), 'url' => Realtime::publicUrl(), 'port' => Realtime::publicPort()]);

    case 'unread':
        Response::ok(['unread' => Chat::unreadTotal($me)]);

    case 'ice':
        Response::ok(Calls::iceConfig($me));

    default:
        throw new HttpException('Неизвестное действие', 404);
}
