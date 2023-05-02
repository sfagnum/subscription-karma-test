<?php

include_once __DIR__ . '/lib/os.php';
include_once __DIR__ . '/lib/cli.php';
include_once __DIR__ . '/lib/db.php';
include_once __DIR__ . '/lib/pool.php';
include_once __DIR__ . '/fixtures/mock.php';

$threads = max(1, os_cpu_num() - 1);

$batch = min(1000, $threads * 100);
$delay = 300;
$memory_limit = '4MB';
$period = 7;

$options = getopt('d::b::m::p::t::', ['delay::', 'batch::', 'memory::', 'period::', 'threads::']);

$delay = (int)($options['d'] ?? $options['delay'] ?? getenv('EMAIL_SEND_DELAY') ?: $delay) * 1000;
$batch = (int)($options['b'] ?? $options['batch'] ?? getenv('EMAIL_SEND_BATCH') ?: $batch);
$memory_limit = $options['m'] ?? $options['memory'] ?? getenv('EMAIL_SEND_MEMORY_LIMIT') ?: $memory_limit;
$period = (int)($options['p'] ?? $options['period'] ?? getenv('EMAIL_SEND_PERIOD') ?: $period) * 24 * 60 * 60;
$threads = (int)($options['t'] ?? $options['threads'] ?? getenv('EMAIL_SEND_THREADS') ?: $threads);

cli_info('Run sending emails: batch[%d], memory_limit[%s], delay[%d], period[%d]', $batch, $memory_limit, $delay, $period);
$memory_limit = cli_convert_to_bytes($memory_limit);

$work = static function () use ($batch, $period) {
    foreach (get_users($batch, $period) as $users) {
        $valid_emails = db_select(
            'emails',
            ['email'],
            'email IN (:emails) AND valid = 1',
            [':emails' => array_column($users, 'email')]
        )->fetchAll(PDO::FETCH_COLUMN);

        $valid_emails = array_flip($valid_emails);

        cli_info('send to (%d) valid emails', count($valid_emails));

        yield array_filter($users, static function ($user) use ($valid_emails) {
            return isset($valid_emails[$user['email']]);
        });
    }

    pool_close();
};

$template = static fn (array $user): string => sprintf(
    '%s, your subscription is expiring soon',
    mb_convert_case($user['username'], MB_CASE_TITLE, 'UTF-8')
);

$job = static function($user) use ($template) {
    send_email(
        $user['email'],
        'notify@karma.test',
        'subscription is expiring soon',
        $template($user)
    );

    return $user['email'];
};

$on_success = static function($sent) {
    cli_info('emails sent (%d)', count($sent));
};

$on_error = static function($message, $payload) {
    cli_error($message);
    cli_warning('payload: %s', var_export($payload, true));
};

$options = [
    'size' => $threads,
    'memory' => $memory_limit,
    'delay' => $delay,
];

pool_run($options, $work, $job, $on_success, $on_error);

cli_info('Done');

function get_users(int $batch, int $period): iterable
{
    $sql = <<<SQL
SELECT id, email, username FROM users
WHERE id > :id
  AND confirmed = 1
  AND (UNIX_TIMESTAMP() - validts) < $period
ORDER BY id ASC
LIMIT $batch
SQL;

    $last_id = 0;
    $stmt = db()->prepare($sql);

    while (true) {
        $cnt = 0;
        $stmt->bindValue(':id', $last_id);

        cli_debug('execute: "%s"', $sql);
        $stmt->execute();
        cli_debug('done');

        $users = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $last_id = $row['id'];
            $cnt++;
            $users[] = $row;
        }

        if ($cnt === 0) {
            break;
        }

        yield $users;
    }
}