<?php

if (!extension_loaded('pcntl')
    || !extension_loaded('posix')
    || !function_exists('pcntl_signal')
    || !function_exists('pcntl_signal_dispatch')
) {
    error_log('pcntl or posix extension not installed');
    exit(ERROR);
}

const OK = 0;
const ERROR = 1;

const _CLI_DEBUG = 0x90;
const _CLI_INFO = 0x91;
const _CLI_WARNING = 0x92;
const _CLI_ERROR = 0x93;

$_cli_run = true;
foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
    pcntl_signal($signal, static function () use (&$_cli_run) {
        cli_warning('Start gracefully exit process');
        $_cli_run = false;
    });
}


$_cli_options = getopt('v::', ['verbose::']);

$_verbose = $_cli_options['v'] ?? $_cli_options['verbose'] ?? getenv('VERBOSE') ?? null;

function _cli_backtrace(string $function = null): string
{
    $trace = [];

    foreach (debug_backtrace() as $item) {
        if ($function !== null) {
            if ($function === $item['function']) {
                $trace[] = sprintf('[%s:%d]', $item['file'], $item['line']);
                break;
            }
        } else {
            $trace[] = sprintf('[%s:%d]', $item['file'], $item['line']);
        }
    }

    if (count($trace) === 1) {
        return $trace[0];
    }

    return implode(PHP_EOL, $trace);
}

function _cli_output($message, int $level, ...$args)
{
    $output = STDOUT;

    $trace = '';

    switch ($level) {
        case _CLI_ERROR:
            $status = "\e[91merror\e[0m";
            $output = STDERR;
            $trace = _cli_backtrace('cli_error');
            break;
        case _CLI_WARNING:
            $status = "\e[33mwarning\e[0m";
            $trace = _cli_backtrace('cli_warning');
            break;
        case _CLI_INFO: $status = "\e[92minfo\e[0m"; break;
        default: $status = "\e[96mdebug\e[0m"; break;
    }

    $message = sprintf(
        "%s [\e[37m%s\e[0m][%s]%s %s%s",
        date('Y-m-d H:i:s'),
        getmypid(),
        $status,
        $trace,
        sprintf($message, ...$args),
        PHP_EOL
    );
    fwrite($output, $message);
}

function cli_debug(string $message, ...$args)
{
    global $_verbose;

    if ($_verbose !== null) {
        _cli_output($message, _CLI_DEBUG, ...$args);
    }
}

function cli_info(string $message, ...$args)
{
    _cli_output($message, _CLI_INFO, ...$args);
}

function cli_warning(string $message, ...$args)
{
    _cli_output($message, _CLI_WARNING, ...$args);
}

function cli_error(string $message, ...$args)
{
    _cli_output($message, _CLI_ERROR, ...$args);
}

function cli_convert_to_bytes(string $memory_limit): int
{
    $memory_limit = strtolower($memory_limit);
    $max = strtolower(ltrim($memory_limit, '+'));
    if (strpos($max, '0x') === 0) {
        $max = \intval($max, 16);
    } elseif (strpos($max, '0') === 0) {
        $max = \intval($max, 8);
    } else {
        $max = (int) $max;
    }

    switch (substr(rtrim($memory_limit, 'b'), -1)) {
        case 't': $max *= 1024;
        case 'g': $max *= 1024;
        case 'm': $max *= 1024;
        case 'k': $max *= 1024;
    }

    return $max;
}

function cli_run(int $memory, int $delay = 0): bool
{
    global $_cli_run;

    if ($memory < ($used_memory = memory_get_usage(true))) {
        cli_error(
            'Worker stopped due to memory limit of %d bytes exceeded (%d bytes used)',
            $memory,
            $used_memory
        );

        return false;
    }

    usleep($delay);

    pcntl_signal_dispatch();

    return $_cli_run;
}