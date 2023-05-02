<?php

include_once __DIR__. '/../lib/cli.php';

function send_email($email, $from, $subject, $body)
{
    cli_debug('START send_email to: [%s] body: "%s"', $email, $body);
    sleep(random_int(1, 10));
    cli_debug('DONE send_mail to [%s]', $email);
}

function check_email($email): int
{
    cli_debug('START check_email [%s]', $email);
    sleep(random_int(1, 60));
    cli_debug('DONE check_email [%s]', $email);

    return random_int(0, 1);
}