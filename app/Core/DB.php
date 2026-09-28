<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/** Thin PDO wrapper. Always use bound parameters; never interpolate user input into SQL. */
final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $c = Config::get('db');
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int)$c['port'], $c['database'], $c['charset'] ?? 'utf8mb4');
            self::$pdo = new PDO($dsn, $c['username'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            self::$pdo->exec("SET time_zone = '+00:00'");
        }
        return self::$pdo;
    }

    public static function run(string $sql, array $params = []): \PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function all(string $sql, array $params = []): array { return self::run($sql, $params)->fetchAll(); }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::run($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table,
            implode(',', array_map(fn($c) => "`$c`", $cols)),
            implode(',', array_map(fn($c) => ":$c", $cols)));
        self::run($sql, $data);
        return (int)self::pdo()->lastInsertId();
    }

    /** Update rows matching $where (column => value, ANDed). */
    public static function update(string $table, array $data, array $where): int
    {
        $set = implode(',', array_map(fn($c) => "`$c` = :s_$c", array_keys($data)));
        $cond = implode(' AND ', array_map(fn($c) => "`$c` = :w_$c", array_keys($where)));
        $params = [];
        foreach ($data as $k => $v) $params["s_$k"] = $v;
        foreach ($where as $k => $v) $params["w_$k"] = $v;
        return self::run("UPDATE `$table` SET $set WHERE $cond", $params)->rowCount();
    }

    public static function delete(string $table, array $where): int
    {
        $cond = implode(' AND ', array_map(fn($c) => "`$c` = :$c", array_keys($where)));
        return self::run("DELETE FROM `$table` WHERE $cond", $where)->rowCount();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try { $r = $fn(); $pdo->commit(); return $r; }
        catch (\Throwable $e) { $pdo->rollBack(); throw $e; }
    }

    /** Build "IN (:p0,:p1)" safely. Returns [sqlFragment, params]. */
    public static function in(array $values, string $prefix = 'in'): array
    {
        if (!$values) return ['(NULL)', []];
        $ph = []; $params = [];
        foreach (array_values($values) as $i => $v) { $ph[] = ":{$prefix}{$i}"; $params["{$prefix}{$i}"] = $v; }
        return ['(' . implode(',', $ph) . ')', $params];
    }
}
