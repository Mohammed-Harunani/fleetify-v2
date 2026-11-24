<?php
declare(strict_types=1);

namespace Fleetify\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $config = require dirname(__DIR__, 2)
            . '/config/database.php';

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'],
            $config['database'],
            $config['charset']
        );

        try {
            self::$connection = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                $config['options']
            );

            return self::$connection;

        } catch (PDOException $exception) {
            error_log(
                '[Fleetify Database] ' . $exception->getMessage()
            );

            throw new RuntimeException(
                'Unable to connect to the database.',
                0,
                $exception
            );
        }
    }
}
