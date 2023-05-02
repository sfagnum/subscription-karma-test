<?php

function os_cpu_num(): int
{
    $num = 1;

    if (ini_get('pcre.jit') === '1'
        && PHP_OS === 'Darwin'
        && version_compare(PHP_VERSION, '7.3.0') >= 0
        && version_compare(PHP_VERSION, '7.4.0') < 0
    ) {
        return $num;
    }

    if (!extension_loaded('pcntl') || !function_exists('shell_exec')) {
        return $num;
    }

    $has_nproc = trim((string) @shell_exec('command -v nproc'));
    if ($has_nproc) {
        $ret = @shell_exec('nproc');
        if (is_string($ret)) {
            $ret = trim($ret);
            $tmp = filter_var($ret, FILTER_VALIDATE_INT);
            if (is_int($tmp)) {
                return $tmp;
            }
        }
    }

    $ret = @shell_exec('sysctl -n hw.ncpu');
    if (is_string($ret)) {
        $ret = trim($ret);
        $tmp = filter_var($ret, FILTER_VALIDATE_INT);
        if (is_int($tmp)) {
            return $tmp;
        }
    }

    if (is_readable('/proc/cpuinfo')) {
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        $count = substr_count($cpuinfo, 'processor');
        if ($count > 0) {
            return $count;
        }
    }

    return $num;
}