<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Database\DatabaseException;
use PDO;

final class InvestigationRepository
{
    private Database $database;

    public function __construct(Database $database)
    {
        $this->database = $database;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function save(array $data): int
    {
        $pdo = $this->database->pdo();

        $row = InvestigationMapper::toRow($data);
        $findings = InvestigationMapper::toFindingRows($data);

        $pdo->beginTransaction();

        try {
            $columns = array_keys($row);
            $quotedColumns = array_map(static fn (string $c): string => '`' . $c . '`', $columns);
            $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

            $sql = 'INSERT INTO `investigations` ('
                . implode(', ', $quotedColumns)
                . ') VALUES ('
                . implode(', ', $placeholders)
                . ')';

            $stmt = $pdo->prepare($sql);

            foreach ($row as $column => $value) {
                $type = PDO::PARAM_STR;

                if ($value === null) {
                    $type = PDO::PARAM_NULL;
                } elseif (is_int($value)) {
                    $type = PDO::PARAM_INT;
                }

                $stmt->bindValue(':' . $column, $value, $type);
            }

            $stmt->execute();

            $investigationId = (int) $pdo->lastInsertId();

            if ($investigationId <= 0) {
                throw new DatabaseException('database_insert_failed', 'Could not store the investigation.');
            }

            if ($findings !== []) {
                $findingSql = 'INSERT INTO `investigation_findings` '
                    . '(`investigation_id`, `code`, `severity`, `title`, `detail`, `origin`, `evidence_json`, `position`) '
                    . 'VALUES (:investigation_id, :code, :severity, :title, :detail, :origin, :evidence_json, :position)';

                $findingStmt = $pdo->prepare($findingSql);

                foreach ($findings as $finding) {
                    $findingStmt->bindValue(':investigation_id', $investigationId, PDO::PARAM_INT);
                    $findingStmt->bindValue(':code', $finding['code'], $finding['code'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $findingStmt->bindValue(':severity', $finding['severity'], $finding['severity'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $findingStmt->bindValue(':title', $finding['title'], $finding['title'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $findingStmt->bindValue(':detail', $finding['detail'], $finding['detail'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $findingStmt->bindValue(':origin', $finding['origin'], $finding['origin'] === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $findingStmt->bindValue(':evidence_json', $finding['evidence_json'], PDO::PARAM_STR);
                    $findingStmt->bindValue(':position', $finding['position'], PDO::PARAM_INT);
                    $findingStmt->execute();
                }
            }

            $pdo->commit();

            return $investigationId;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($exception instanceof DatabaseException) {
                throw $exception;
            }

            // Surface the real PDO error locally. Insert/prepare errors contain
            // SQLSTATE + table/column names but never credentials or bound values.
            throw new DatabaseException(
                'database_save_failed',
                'Could not store the investigation. Cause: ' . $exception->getMessage()
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 25): array
    {
        $limit = max(1, min(100, $limit));

        $pdo = $this->database->pdo();

        $stmt = $pdo->prepare(
            'SELECT `id`, `reference`, `order_status`, `amount_value`, `amount_currency`, '
            . '`ai_origin`, `ai_available`, `report_state`, `created_timestamp` '
            . 'FROM `investigations` ORDER BY `created_timestamp` DESC, `id` DESC LIMIT :limit'
        );

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!is_array($rows)) {
            return [];
        }

        return $rows;
    }

    /**
     * @return array{investigation: array<string, mixed>, findings: array<int, array<string, mixed>>}|null
     */
    public function find(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $pdo = $this->database->pdo();

        $stmt = $pdo->prepare('SELECT * FROM `investigations` WHERE `id` = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row)) {
            return null;
        }

        $findingStmt = $pdo->prepare(
            'SELECT `code`, `severity`, `title`, `detail`, `origin`, `evidence_json`, `position` '
            . 'FROM `investigation_findings` WHERE `investigation_id` = :id ORDER BY `position` ASC, `id` ASC'
        );
        $findingStmt->bindValue(':id', $id, PDO::PARAM_INT);
        $findingStmt->execute();

        $findings = $findingStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'investigation' => $row,
            'findings' => is_array($findings) ? $findings : [],
        ];
    }

    public function delete(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $pdo = $this->database->pdo();

        $stmt = $pdo->prepare('DELETE FROM `investigations` WHERE `id` = :id');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }
}