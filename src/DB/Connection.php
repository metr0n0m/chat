<?php
declare(strict_types=1);

namespace Chat\DB;

class Connection
{
    private static ?self $instance = null;
    private \PDO $pdo;
    private string $dsn;

    private function __construct()
    {
        $this->dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME
        );
        $this->connect();
    }

    private function connect(): void
    {
        $this->pdo = new \PDO($this->dsn, DB_USER, DB_PASS, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::MYSQL_ATTR_FOUND_ROWS   => true,
        ]);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function prepare(string $sql): \PDOStatement
    {
        return $this->pdo->prepare($sql);
    }

    public function execute(string $sql, array $params = []): \PDOStatement
    {
        return $this->executeWithRetry($sql, $params, true);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->execute($sql, $params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->execute($sql, $params)->fetchAll();
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        $this->pdo->commit();
    }

    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }

    private function executeWithRetry(string $sql, array $params, bool $canRetry): \PDOStatement
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            if (!$canRetry || !$this->isLostConnection($e)) {
                throw $e;
            }

            try {
                $this->connect();
            } catch (\Throwable $connectEx) {
                error_log('[DB] Lost connection (' . $e->getCode() . '): ' . $e->getMessage() . ' | Reconnect failed: ' . $connectEx->getMessage());
                throw $e;
            }
            return $this->executeWithRetry($sql, $params, false);
        }
    }

    private function isLostConnection(\PDOException $e): bool
    {
        $info = $e->errorInfo;
        $driverCode = isset($info[1]) ? (int) $info[1] : 0;

        return $driverCode === 2006 || $driverCode === 2013;
    }
}
