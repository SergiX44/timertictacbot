<?php

use Carbon\Carbon;
use Zanzara\Zanzara;
use Zanzara\Context;

require __DIR__.'/vendor/autoload.php';

$config = require 'config.php';
$bot = new Zanzara($config['telegram_token']);

$loopingTimers = [];
$pendingTimers = [];

function unsetTimer($userId, $inlineMessageId)
{
    global $loopingTimers;
    unset($loopingTimers[$userId][$inlineMessageId]);
    if (empty($loopingTimers[$userId])) {
        unset($loopingTimers[$userId]);
    }
}

$bot->onCommand('start', function (Context $ctx) {
    $ctx->sendMessage('Only in inline mode!');
});

$bot->onInlineQuery(function (Context $ctx) use (&$pendingTimers) {
    $now = Carbon::now();
    $query = $ctx->getInlineQuery()->getQuery();
    if ($query === '' || ($timestamp = strtotime($query, $now->timestamp)) === -1) {
        return;
    }

    $when = Carbon::createFromTimestamp(++$timestamp);
    if ($when->isBefore($now) || $when->diffInHours($now) > 48) {
        return;
    }
    $pendingTimers[$ctx->getInlineQuery()->getFrom()->getId()] = $when;

    $ctx->answerInlineQuery([
        [
            'type' => 'article',
            'id' => sha1($when->timestamp),
            'title' => $when->diffForHumans($now, null, false, 3),
            'input_message_content' => ['message_text' => $when->diff($now)->format('%H:%I:%S')],
            'reply_markup' => [
                'inline_keyboard' => [[['text' => 'Stop Timer', 'callback_data' => 'stop_timer']]]
            ]
        ]
    ], [
        'cache_time' => 0
    ]);
});

$bot->onChosenInlineResult(function (Context $ctx) use (&$loopingTimers, &$pendingTimers) {
    $result = $ctx->getChosenInlineResult();
    $userId = $result->getFrom()->getId();
    $loopingTimers[$userId][$result->getInlineMessageId()] = $pendingTimers[$userId];
});

$bot->onCbQueryData(['stop_timer'], function (Context $ctx) use (&$loopingTimers) {
    unsetTimer($ctx->getCallbackQuery()->getFrom()->getId(), $ctx->getCallbackQuery()->getInlineMessageId());
    $ctx->editMessageText('Canceled', [
        'inline_message_id' => $ctx->getCallbackQuery()->getInlineMessageId()
    ]);
});

$bot->getLoop()->addPeriodicTimer(5, function () use (&$loopingTimers, &$bot) {
    $now = Carbon::now();
    foreach ($loopingTimers as $userId => $timers) {
        /**
         * @var string $inlineMessageId
         * @var Carbon $expire
         */
        foreach ($timers as $inlineMessageId => $expire) {
            if ($expire->isBefore($now)) {
                unsetTimer($userId, $inlineMessageId);
                $bot->getTelegram()->editMessageText('Expired', [
                    'inline_message_id' => $inlineMessageId,
                ]);
                continue;
            }

            $bot->getTelegram()->editMessageText($expire->diff($now)->format('%H:%I:%S'), [
                'inline_message_id' => $inlineMessageId,
                'reply_markup' => [
                    'inline_keyboard' => [[['text' => 'Stop Timer', 'callback_data' => 'stop_timer']]]
                ]
            ]);
        }
    }
});

$bot->run();