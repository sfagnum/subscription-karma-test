<?php

include_once __DIR__ . '/lib/cli.php';
include_once __DIR__ . '/lib/db.php';
include_once __DIR__ . '/fixtures/mock.php';

$batch = 1;
$delay = 300;
$memory_limit = '128MB';

$options = getopt('d::b::m::', ['delay::', 'batch::', 'memory::']);

$delay = (int)($options['d'] ?? $options['delay'] ?? getenv('DELAY') ?: $delay) * 1000;
$batch = (int)($options['b'] ?? $options['batch'] ?? getenv('BATCH') ?: $batch);
$memory_limit = $options['m'] ?? $options['memory'] ?? getenv('MEMORY') ?: $memory_limit;

$sql = <<<SQL
SELECT email FROM emails
WHERE checked = 0
AND (processts IS NULL OR ((processts IS NOT NULL) AND (UNIX_TIMESTAMP() - processts) > 3601))
ORDER BY id ASC
LIMIT $batch
FOR UPDATE
SQL;

cli_info('Run checking emails: batch[%d], memory_limit[%s], delay[%d]', $batch, $memory_limit, $delay);
$memory_limit = cli_convert_to_bytes($memory_limit);

$sel_stmt = db()->prepare($sql);
$upd_stmt = db()->prepare('UPDATE emails SET checked = :checked, valid = :valid WHERE email = :email');

while(cli_run($memory_limit, $delay)) {
    $emails = db_transactional(static function() use ($sel_stmt) {
        $sel_stmt->execute();

        $emails = $sel_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($emails)) {
            db_update(
                'emails',
                [
                    'processts' => fn() => 'UNIX_TIMESTAMP()'
                ],
                'email in (:emails)',
                [':emails' => $emails]
            );
        }

        return $emails;
    });

    foreach ($emails as $email) {
        $status = check_email($email);

        $upd_stmt->bindValue(':checked', 1, PDO::PARAM_INT);
        $upd_stmt->bindValue(':valid', $status, PDO::PARAM_INT);
        $upd_stmt->bindValue(':email', $email);

        $upd_stmt->execute();

        cli_debug('update %s [%d]', $email, $status);
    }
}

cli_info('DONE');
exit(OK);