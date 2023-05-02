<?php

include_once __DIR__ . '/cli.php';

const _POOL_STATUS_FORK_ERROR = 0x103;
const _POOL_TASK = 0x106;
const _POOL_RESULT = 0x108;

const _POOL_STATUSES = [
    _POOL_TASK => '_POOL_TASK',
    _POOL_RESULT => '_POOL_RESULT',
];

function _pool_pipe_create()
{
    return stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
}

function _pool_parent_stream(array $sockets)
{
    [$for_read, $for_write] = $sockets;

    fclose($for_write);

    if (!stream_set_blocking($for_read, false)) {
        cli_error('unable to set read stream to non-blocking');
        exit(ERROR);
    }

    return $for_read;
}

function _pool_child_stream(array $sockets)
{
    [$for_read, $for_write] = $sockets;

    fclose($for_read);

    return $for_write;
}

function _pool_prepare_tasks(iterable $task_iterator, array $pid_list): array
{
    $data = [];
    $size = count($pid_list);
    foreach ($task_iterator as $i => $task) {
        $data[$pid_list[$i % $size]][] = $task;
    }

    return $data;
}

function _pool_encode($data): string
{
    $encoded = base64_encode(igbinary_serialize($data));

    return sprintf('%s%s', $encoded, "\n");
}

function _pool_decode($data)
{
    return igbinary_unserialize(base64_decode($data));
}

$_pool_parent_read_streams = [];
$_pool_memory = 0;
$_pool_delay = 0;

function pool_run(array $params, Closure $work, Closure $job, Closure $on_success, Closure $on_error)
{
    global $_pool_child_pid_list, $_pool_parent_read_streams, $_pool_memory, $_pool_delay;

    $size = $params['size'];
    $_pool_delay = $params['delay'];
    $_pool_memory = $params['memory'];

    $is_parent = false;
    $sockets = [];
    for ($proc_id = 0; $proc_id < $size; ++$proc_id) {
        $sockets = _pool_pipe_create();

        if (!$sockets) {
            cli_error('unable to create stream socket pair');
            exit(ERROR);
        }

        if (($pid = pcntl_fork()) < 0) {
            cli_error(posix_strerror(posix_get_last_error()));
            exit(ERROR);
        }

        if ($pid > 0) {
            $is_parent = true;
            $_pool_child_pid_list[] = $pid;
            $for_read = _pool_parent_stream($sockets);
            $_pool_parent_read_streams[$pid] = $for_read;

            continue;
        }

        if ($pid === 0) {
            cli_info('child process created');
            $is_parent = false;
            break;
        }
    }

    if (!$is_parent) {
        $write_stream = _pool_child_stream($sockets);

        _pool_run_child_process($write_stream, $job);

        cli_warning('child close');

        @fclose($write_stream);

        exit(OK);
    }

    foreach ([SIGTERM, SIGINT, SIGHUP] as $signal) {
        pcntl_signal($signal, '_pool_kill');
    }

    foreach ($work() as $tasks) {
        _pool_sent_tasks_to_children($tasks);

        foreach (_pool_wait(count($tasks), $on_error) as $results) {
            $on_success($results);

            gc_collect_cycles();
        }
    }

    cli_debug('work is done');

    _pool_kill();
}

function _pool_run_child_process($write_stream, Closure $job)
{
    global $_pool_memory, $_pool_delay;

    while (cli_run($_pool_memory, $_pool_delay) && !feof($write_stream)) {
        $message = null;

        try {
            $data = @fgets($write_stream);
            if (!$data) {
                cli_warning('no data');
                continue;
            }

            $message = _pool_decode($data);
            if (!$message) {
                cli_warning('no message');
                continue;
            }

            [$status, $payload] = $message;

            if ($status === _POOL_TASK) {
                $result = $job($payload);
                $message = _pool_encode([_POOL_RESULT, $result]);
            }
        } catch (Throwable $t) {
            $result = get_class($t) . ' ' . $t->getMessage() . "\n" .
                "Emitted in " . $t->getFile() . ":" . $t->getLine() . "\n" .
                "Stack trace in the forked worker:\n" .
                $t->getTraceAsString();
            $message = _pool_encode([_POOL_STATUS_FORK_ERROR, [$result, $payload ?? null]]);
        }

        if ($message) {
            _pool_write_stream($write_stream, $message);
        }
    }
}

function pool_close()
{
    _pool_kill();
}

function _pool_kill()
{
    global $_pool_child_pid_list, $_pool_parent_read_streams;

    foreach ($_pool_child_pid_list as $pid) {
        $process_lookup = posix_kill($pid, 0);
        $status = 0;

        if ($process_lookup) {
            posix_kill($pid, SIGALRM);

            if (pcntl_waitpid($pid, $status) < 0) {
                cli_error(posix_strerror(posix_get_last_error()));
            }
        }

        if (pcntl_wifsignaled($status)) {
            $return_code = pcntl_wexitstatus($status);
            $term_sig = pcntl_wtermsig($status);

            if ($term_sig !== SIGALRM) {
                cli_error("Child terminated with return code $return_code and signal $term_sig");
            }
        }

        @fclose($_pool_parent_read_streams[$pid]);
        unset($_pool_parent_read_streams[$pid]);
    }
}

function _pool_sent_tasks_to_children(iterable $tasks)
{
    global $_pool_parent_read_streams, $_pool_child_pid_list;

    $tasks = _pool_prepare_tasks($tasks, $_pool_child_pid_list);

    foreach ($tasks as $pid => $pid_tasks) {
        $stream = $_pool_parent_read_streams[$pid];
        cli_debug('parent: send to PID %d', $pid);

        foreach ($pid_tasks as $task) {
            $message = _pool_encode([_POOL_TASK, $task]);
            _pool_write_stream($stream, $message);
        }
    }
}

$_pool_buf = '';
function _pool_write_stream($stream, string $message)
{
    global $_pool_buf;

    $message = $_pool_buf.$message;

    $bytes_to_write = strlen($message);
    $bytes_sent = 0;

    while ($bytes_sent < $bytes_to_write && !feof($stream)) {
        $bytes_sent += @fwrite($stream, substr($message, $bytes_sent));

        if ($bytes_sent < $bytes_to_write) {
            usleep(500000);
        }
    }
}

function _pool_wait(int $count, Closure $on_error): Generator
{
    global $_pool_child_pid_list;

    $results = [];
    foreach (_pool_read_results_from_children() as $pid => $message) {
        $message = _pool_decode($message);

        if (!$message) {
            cli_debug('no message from child %d', $pid);
            continue;
        }

        [$status, $payload] = $message;

        cli_debug('parent: receive message from PID %d %d(%s)', $pid, $status, _POOL_STATUSES[$status] ?? 'none');

        if ($status === _POOL_STATUS_FORK_ERROR) {
            cli_error($payload);

            foreach ($_pool_child_pid_list as $child_pid) {
                posix_kill($child_pid, SIGTERM);
            }

            [$error, $result] = $payload;
            $on_error($error, $result);
            break;
        }

        if ($status === _POOL_RESULT) {
            $results[] = $payload;
        }

        if ($count === count($results)) {
            return yield $results;
        }
    }
}

function _pool_read_results_from_children(int $timeout = 1000): iterable
{
    global $_pool_parent_read_streams, $_pool_memory, $_pool_delay;

    $streams = [];
    $pids = [];
    foreach ($_pool_parent_read_streams as $pid => &$stream) {
        $id = (int)$stream;
        $streams[$id] = $stream;
        $pids[$id] = $pid;
    }
    unset($stream);

    $write = null;
    $except = null;

    while (cli_run($_pool_memory, $_pool_delay) && count($streams) > 0) {
        $read = array_values($streams);

        $num = @stream_select($read, $write, $except, $timeout);
        if ($num === false) {
            cli_debug('stream_select: false');
            $err = error_get_last();

            if (isset($err['message']) && stripos($err['message'], 'interrupted system call') === false) {
                cli_warning('Unable to select on read stream: %s', $err['message']);
                _pool_kill();
                exit(OK);
            }

            break;
        }

        foreach ($read as $stream) {
            if (feof($stream)) {
                cli_debug('stream_select: feof');
                fclose($stream);
                unset($streams[(int)$stream]);
            } else {
                yield $pids[(int)$stream] => fgets($stream);
            }
        }
    }
}