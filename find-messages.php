<?php

use Alfred\Workflows\Workflow;
use Carbon\Carbon;

date_default_timezone_set('America/Denver');

require __DIR__ . '/vendor/autoload.php';

$workflow = new Workflow;

$dbPath = $_SERVER['HOME'] . '/Library/Messages/chat.db';

$db = new PDO('sqlite:' . $dbPath);
$query = $db->query("
    select
        message.rowid,
        ifnull(handle.uncanonicalized_id, chat.chat_identifier) AS sender,
        message.service,
        datetime(message.date / 1000000000 + 978307200, 'unixepoch', 'localtime') AS message_date,
        message.text
    from
        message
            left join chat_message_join
                    on chat_message_join.message_id = message.ROWID
            left join chat
                    on chat.ROWID = chat_message_join.chat_id
            left join handle
                    on message.handle_id = handle.ROWID
    where
        is_from_me = 0
        and text is not null
        and length(text) > 0
        and (
            text glob '*[0-9][0-9][0-9][0-9][0-9]*'
            or text glob '*[0-9][0-9][0-9][0-9][0-9][0-9]*'
            or text glob '*[0-9][0-9][0-9][0-9][0-9][0-9][0-9]*'
            or text glob '*[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]*'
        )
    order by
        message.date desc
    limit 100
");

$found = 0;
$max = 8;

while ($message = $query->fetch(PDO::FETCH_ASSOC)) {
    if (preg_match('/(^|\s|\R|\t)(\d{5,8})($|\s|\R|\t)/', $message['text'], $matches)) {
        $found++;
        $code = $matches[2];
        $date = Carbon::parse($message['message_date']);
        $text = formatText($message['text']);
        $sender = formatSender($message['sender']);

        $workflow->result()
                 ->title($code)
                 ->subtitle($date->shortRelativeToNowDiffForHumans() . " from $sender: $text")
                 ->arg($code)
                 ->valid(true);

        if ($found >= $max) {
            break;
        }
    }
}

if (! $found) {
    $workflow->result()
             ->title('No 2FA Codes Found')
             ->subtitle('No two-factor authentication codes were found in your recent iMessage messages')
             ->arg('')
             ->valid(true);
}

echo $workflow->output();

/**
 * Format the text of the message
 *
 * @param string $text
 *
 * @return string
 */
function formatText($text)
{
    return str_replace(
        ["\n", ':;'],
        ['; ', ':'],
        trim($text)
    );
}

/**
 * Format a sender number
 *
 * @param string $sender
 *
 * @return string
 */
function formatSender($sender)
{
    $sender = trim($sender, '+');

    if (strlen($sender) === 11 && substr($sender, 0, 1) === '1') {
        $sender = substr($sender, 1);
    }

    if (strlen($sender) === 10) {
        return substr($sender, 0, 3) . '-' . substr($sender, 3, 3) . '-' . substr($sender, 6, 4);
    }

    return $sender;
}
