<?php

use Alfred\Workflows\Workflow;

require __DIR__ . '/vendor/autoload.php';

$workflow = new Workflow;

$dbPath = $_SERVER['HOME'] . '/Library/Messages/chat.db';

if (! is_readable($dbPath)) {
    $workflow->result()
             ->title('ERROR: Unable to Access Your Messages')
             ->subtitle('We were unable to access the file that contains your text messages')
             ->arg('')
             ->valid(true);
    echo $workflow->output();
    exit;
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    $workflow->result()
             ->title('ERROR: Unable to Access Your Messages')
             ->subtitle('We were unable to access the file that contains your text messages')
             ->arg('')
             ->valid(true);
    $workflow->result()
             ->title('Error Message:')
             ->subtitle($e->getMessage())
             ->arg('')
             ->valid(true);
    echo $workflow->output();
    exit;
}

try {
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
                text glob '*[0-9][0-9][0-9][0-9]*'
                or text glob '*[0-9][0-9][0-9][0-9][0-9]*'
                or text glob '*[0-9][0-9][0-9][0-9][0-9][0-9]*'
                or text glob '*[0-9][0-9][0-9]-[0-9][0-9][0-9]*'
                or text glob '*[0-9][0-9][0-9][0-9][0-9][0-9][0-9]*'
                or text glob '*[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]*'
            )
        order by
            message.date desc
        limit 100
    ");
} catch (PDOException $e) {
    $workflow->result()
             ->title('ERROR: Unable to Query Your Messages')
             ->subtitle('We were unable to run the query that reads your text messages')
             ->arg('')
             ->valid(true);
    $workflow->result()
             ->title('Error Message:')
             ->subtitle($e->getMessage())
             ->arg('')
             ->valid(true);
    echo $workflow->output();
    exit;
}

$found = 0;
$max = 8;

while ($message = $query->fetch(PDO::FETCH_ASSOC)) {
    $code = null;

    if (preg_match('/(^|\s|\R|\t|G-|:)(\d{5,8})($|\s|\R|\t|\.)/', $message['text'], $matches)) {
        // 5-8 consecutive digits
        $code = $matches[2];
    } elseif (preg_match('/(code:|is:)\s*(\d{4,8})($|\s|\R|\t|\.)/i', $message['text'], $matches)) {
        // "code:" or "is:", optional whitespace, then 4-8 consecutive digits
        // examples:
        //   "Your Airbnb verification code is: 1234."
        //   "Here is your authorization code:9384"
        $code = $matches[2];
    } elseif (preg_match('/(^|code:|is:)\s*(\d{3})-(\d{3})($|\s|\R|\t|\.)/', $message['text'], $matches)) {
        // line beginning or "code:" or "is:", optional whitespace, 3 consecutive digits, a hyphen, 3 consecutive digits
        // examples:
        //   "123-456"
        //   "Your Stripe verification code is: 719-839."
        $code = $matches[2] . $matches[3];
    }

    if ($code) {
        $found++;
        $date = formatDate($message['message_date']);
        $text = formatText($message['text']);
        $sender = formatSender($message['sender']);

        $workflow->result()
                 ->title($code)
                 ->subtitle("$date from $sender: $text")
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
             ->subtitle('No two-factor authentication codes were found in your recent text messages')
             ->arg('')
             ->valid(true);
}

echo $workflow->output();

/**
 * Format the date of the message
 *
 * @param string $date
 *
 * @return string
 */
function formatDate($date)
{
    $time = strtotime($date);

    if (date('m/d/Y', $time) === date('m/d/Y')) {
        return 'Today @ ' . date('g:ia', $time);
    }

    return date('M j @ g:ia', $time);
}

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
