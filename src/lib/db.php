<?php

include_once __DIR__ . '/cli.php';

$db = null;

function db(bool $reconnect = false, string $dsn = null, string $user = null, string $pass = null): PDO
{
    global $db;

    if (!$reconnect && $db !== null) {
        return $db;
    }

    $dsn = (string)($dsn ?? $_ENV['DB_DSN'] ?? getenv('DB_DSN'));
    $user = (string)($user ?? $_ENV['DB_USER'] ?? getenv('DB_USER'));
    $pass = (string)($pass ?? $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD'));

    try {
        $db = new PDO($dsn, $user, $pass, [
            PDO::ATTR_PERSISTENT => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (PDOException $exception) {
        cli_error($exception->getMessage());
        exit(ERROR);
    }

    return $db;
}

function db_close(): void
{
    global $db;

    $db = null;
}

function db_insert(string $table, array $columns, array $values): int
{
    $db = db();

    $data = [];
    foreach ($values as $valueRow) {
        foreach ($valueRow as &$value) {
            $type = PDO::PARAM_STR;
            if (is_int($value)) {
                $type = PDO::PARAM_INT;
            }
            $value = $db->quote($value, $type);
        }
        unset($value);
        $valueRow = implode(', ', $valueRow);
        $data[] = '(' . $valueRow . ')';
    }
    $data = implode(',', $data);

    $columns = array_map(static fn (string $col) => "`$col`", $columns);
    $columns = implode(',', $columns);

    $sql = sprintf('INSERT INTO %s (%s) VALUES %s', $table, $columns, $data);

    return db_exec($sql);
}

function db_exec(string $sql): int
{
    $db = db();

    $status = $db->exec($sql);

    if ($status === false) {
        cli_error(print_r($db->errorInfo(), true));
        exit(ERROR);
    }

    return $status;
}

function _db_prepare_condition(string $condition, array $params): array
{
    $bind_params = [];

    $addParam = static function ($value, string $namedParam = null) use (&$bind_params) {
        if (is_int($value)) {
            $value = [PDO::PARAM_INT, $value];
        } elseif (is_bool($value)) {
            $value = [PDO::PARAM_BOOL, $value];
        } elseif (is_null($value)) {
            $value = [PDO::PARAM_NULL, $value];
        } else {
            $value = [PDO::PARAM_STR, $value];
        }

        if ($namedParam) {
            $bind_params[$namedParam] = $value;
        } else {
            $bind_params[count($bind_params) + 1] = $value;
        }
    };

    foreach ($params as $param => $value) {
        if (is_array($value)) {
            $condition = str_replace($param, implode(',', array_fill(0, count($value), '?')), $condition);
            foreach ($value as $datum) {
                $addParam($datum);
            }
        } else {
            $addParam($value, $param);
        }
    }

    return [$condition, $bind_params];
}

function _db_bind_params(PDOStatement $stmt, array $bind_params): void
{
    $params_by_reference = [];
    foreach ($bind_params as $param => &$value) {
        if ($value[0] === PDO::PARAM_STR) {
            $params_by_reference[$param] = $value[1];
            $stmt->bindParam($param, $params_by_reference[$param], $value[0]);
        } else {
            $stmt->bindParam($param, $value[1], $value[0]);
        }
    }
    unset($value);
}

function db_update(string $table, array $values, string $condition, array $params = [])
{
    $db = db();

    $sets = [];

    foreach ($values as $column => $value) {
        $sets[] = sprintf('`%s` = %s', $column, is_callable($value) ? $value() : $db->quote($value));
    }

    [$condition, $bind_params] = _db_prepare_condition($condition, $params);

    $sql = sprintf(
        'UPDATE %s SET %s WHERE %s',
        $table,
        implode(',', $sets),
        $condition
    );

    $stmt = $db->prepare($sql);

    _db_bind_params($stmt, $bind_params);

    cli_debug('execute "%s"', $sql);

    $status = $stmt->execute();

    if ($status === false) {
        cli_error(print_r($db->errorInfo(), true));
        exit(ERROR);
    }
}

function db_select(string $table, array $columns, string $condition, array $params = []): PDOStatement
{
    [$condition, $bind_params] = _db_prepare_condition($condition, $params);

    $sql = sprintf(
        'SELECT %s FROM %s WHERE %s',
        implode(', ', $columns),
        $table,
        $condition
    );

    $stmt = db()->prepare($sql);

    _db_bind_params($stmt, $bind_params);

    cli_debug('execute "%s"', $sql);

    $stmt->execute();

    return $stmt;
}

function db_transactional(Closure $func)
{
    $db = db();

    $db->beginTransaction();
    try {
        $res = $func($db);
        $db->commit();

        return $res;
    } catch (Throwable $e) {
        $db->rollBack();

        throw $e;
    }
}