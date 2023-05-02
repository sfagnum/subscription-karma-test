<?php

include_once __DIR__. '/../lib/db.php';
include_once __DIR__. '/../lib/cli.php';

$options = getopt('d::e::', ['delete::', 'with-checked-emails::']);

$delete = ($options['d'] ?? $options['delete'] ?? null) !== null;
$with_checked_emails = ($options['e'] ?? $options['with-checked-emails'] ?? null) !== null;

if ($delete) {
    cli_info('DROP TABLES');
    db_exec('drop table if exists emails');
    db_exec('drop table if exists users');
}

$sql = <<<SQL
create table users
(
    id        int auto_increment,
    username  varchar(255)         not null,
    email     varchar(255)         not null,
    validts   int                  not null,
    confirmed tinyint(1) default 0 not null,
    constraint users_pk
        primary key (id),
    constraint uniq_email
        unique (email)
);

create index users__index_email_validts_confirmed
    on users (email, validts, confirmed);

create table emails
(
    id          int auto_increment,
    email       varchar(255)         not null,
    checked     tinyint(1) default 0 null,
    valid       tinyint(1) default 0 not null,
    processts   int                  null,
    constraint emails_pk
        primary key (id),
    constraint emails_users_email_fk
        foreign key (email) references users (email)
            on update cascade on delete cascade
);

create index emails_email_index
    on emails (email);
create index emails_checked_processts
    on emails (checked, processts);
SQL;

cli_info('CREATE TABLES');
db_exec($sql);

$rows = 1000000;
$cnt = 0;

cli_info('INSERT %d rows', $rows);

$email = static function(int $i): string {
    $hosts = ['mail.ru', 'google.com', 'yandex.ru', 'ya.ru', 'protonmail.com', 'mail.bk'];
    return sprintf('user%d@%s', $i, $hosts[array_rand($hosts)]);
};

$username = static function (): string {
    $alpha = array_merge(range(0,9), range('a', 'z'), ['.', '-']);
    shuffle($alpha);
    return implode('', array_slice($alpha, 0, random_int(5, 12)));
};

$batch = 2000;

do {
    cli_debug('batch %d', $batch);

    $users = $emails = [];
    for ($i = 0; $i < $batch; $i++) {
        $e = $email(++$cnt);

        $users[] = [
            $username(),
            $e,
            strtotime(sprintf('+%d days', random_int(2, 5))),
            (int)(random_int(0, 3) !== 0)
        ];

        if ($with_checked_emails) {
            $emails[] = [
                $e,
                1,
                (int)(random_int(0, 3) !== 0),
                time()
            ];
        } else {
            $emails[] = [
                $e,
            ];
        }

        --$rows;
    }

    db_insert('users', ['username', 'email', 'validts', 'confirmed'], $users);

    if ($with_checked_emails) {
        db_insert('emails', ['email', 'checked', 'valid', 'processts'], $emails);
    } else {
        db_insert('emails', ['email'], $emails);
    }

} while (cli_run(67108864) && $rows > 0);

cli_info('DONE');
