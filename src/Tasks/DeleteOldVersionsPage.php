<?php

declare(strict_types=1);

namespace Sunnysideup\VersionsTablePruner\Tasks;

use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;

class DeleteOldVersionsPage extends BuildTask
{
    protected $title = 'Version Cleanup';

    protected $description = 'Keeps recent versions and gradually removes older ones based on retention periods.';

    private static string $segment = 'delete-old-versions-page';

    /**
     * RULES MUST BE IN ORDER FROM NEWEST → OLDEST
     */
    private static array $retention_rules = [
        'FirstHour' => [
            'From'    => null,
            'To'      => '1 HOUR',
            'GroupBy' => 'MINUTE(LastEdited)'
        ],
        'FirstDay' => [
            'From'    => '1 HOUR',
            'To'      => '1 DAY',
            'GroupBy' => 'HOUR(LastEdited)'
        ],
        'FirstThreeWeeks' => [
            'From'    => '1 DAY',
            'To'      => '3 WEEK',
            'GroupBy' => 'DATE(LastEdited)'
        ],
        'FirstTwelveWeeks' => [
            'From'    => '3 WEEK',
            'To'      => '12 WEEK',
            'GroupBy' => 'YEARWEEK(LastEdited)'
        ],
        'TwelveWeeksToThreeYears' => [
            'From'    => '12 WEEK',
            'To'      => '36 MONTH',
            'GroupBy' => 'YEAR(LastEdited), QUARTER(LastEdited)'
        ],
        'ThreeToSevenYears' => [
            'From'    => '36 MONTH',
            'To'      => '84 MONTH',
            'GroupBy' => 'YEAR(LastEdited), FLOOR((MONTH(LastEdited)-1)/6)'
        ],
        'SevenYearsPlus' => [
            'From'    => '84 MONTH',
            'To'      => null,
            'GroupBy' => 'YEAR(LastEdited)'
        ],
    ];

    /**
     * Per-table change tracking.
     */
    private static array $change_tracking = [
        // Product::class => [
        //     'fields' => ['Price'],
        //     'upTo'   => '12 MONTH'
        // ],
    ];

    public function run($request): void
    {
        $this->makeTable();
        $this->ensureIndexes();
        $this->applyRetentionRules();
        $this->applyChangeTracking();
        $this->buildDeletionKeyTable();
        $this->deleteOldVersions();

        DB::alteration_message('Version cleanup completed.');
    }

    private function makeTable(): void
    {
        DB::alteration_message('Creating temporary OldPageVersions table', 'created');
        DB::query("
            CREATE TABLE IF NOT EXISTS OldPageVersions (
                RecordID INT NOT NULL,
                Version  INT NOT NULL,
                PRIMARY KEY (RecordID, Version),
                INDEX (RecordID, Version)
            ) ENGINE=InnoDB
        ");

        DB::query("TRUNCATE OldPageVersions");
    }

    private function ensureIndexes(): void
    {
        DB::alteration_message('Checking composite indexes on SiteTree_Versions', 'created');

        $exists = DB::query("
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'SiteTree_Versions'
              AND index_name = 'idx_lastedited_recordid_version'
        ")->value();

        if (! $exists) {
            DB::alteration_message('Adding composite index idx_lastedited_recordid_version', 'created');

            DB::query("
                ALTER TABLE SiteTree_Versions
                ADD INDEX idx_lastedited_recordid_version (LastEdited, RecordID, Version)
            ");
        }
    }



    /* ============================================================
     * RETENTION RULES
     * ========================================================== */

    private function applyRetentionRules(): void
    {
        $rules = $this->config()->get('retention_rules') ?? self::$retention_rules;
        DB::alteration_message('Applying retention rules…', 'created');
        foreach ($rules as $label => $rule) {
            $from = $rule['From'] ?? null;
            $to   = $rule['To'] ?? null;

            $conditions = [];

            if ($from) {
                $conditions[] = "LastEdited < DATE_SUB(NOW(), INTERVAL {$from})";
            }

            if ($to) {
                $conditions[] = "LastEdited >= DATE_SUB(NOW(), INTERVAL {$to})";
            }

            $where = implode(' AND ', $conditions) ?: null;
            $groupBy = $rule['GroupBy'];

            DB::alteration_message("… Retention: {$label} (GroupBy: {$groupBy})", 'created');
            if (! $where) {
                DB::alteration_message("… …  - No conditions, applying to all records", 'created');
                continue;
            } else {
                DB::alteration_message("… …  - Conditions: {$where}", 'created');
            }
            if (! $groupBy) {
                DB::alteration_message("… …  - No GroupBy specified, skipping", 'created');
                continue;
            } else {
                DB::alteration_message("… …  - GroupBy: {$groupBy}", 'created');
            }
            DB::query("
                INSERT IGNORE INTO OldPageVersions (RecordID, Version)
                SELECT RecordID, MAX(Version)
                FROM SiteTree_Versions FORCE INDEX (idx_lastedited_recordid_version)
                WHERE {$where}
                GROUP BY RecordID, {$groupBy}
            ");
        }
    }



    /* ============================================================
     * CHANGE TRACKING
     * ========================================================== */

    private function applyChangeTracking(): void
    {
        DB::alteration_message('Applying change tracking rules…', 'created');
        $configs = $this->config()->get('change_tracking') ?? self::$change_tracking;

        foreach ($configs as $className => $settings) {
            $table = $this->getVersionsTable($className);

            $this->addChangeKeepsForTable(
                $table,
                (array) ($settings['fields'] ?? []),
                (string) ($settings['upTo'] ?? null)
            );
        }
    }
    private function addChangeKeepsForTable(string $table, array $fields, ?string $upTo): void
    {
        if (! $this->tableExists($table)) {
            DB::alteration_message("… Skipping {$table}: does not exist", 'deleted');
            return;
        }

        if ($fields === []) {
            DB::alteration_message("… Skipping {$table}: no fields configured", 'deleted');
            return;
        }

        DB::alteration_message("… Scanning {$table} for field changes: " . implode(', ', $fields), 'created');

        // WHERE clause
        $where = $upTo
            ? "WHERE stv.LastEdited >= DATE_SUB(NOW(), INTERVAL {$upTo})"
            : '';

        // Select LastEdited depending on table
        $lastEdited = 'stv.LastEdited';
        if ($table === 'SiteTree_Versions') {
            $tableAlias = 'stv';
            $join = ''; // no join needed
        } else {
            $tableAlias = 't';
            $join = '
                INNER JOIN SiteTree_Versions AS stv
                    ON stv.RecordID = t.RecordID
                    AND stv.Version  = t.Version
            ';
        }

        // Field list
        $fieldList = implode(', ', $fields);

        // Final query
        $sql = "
            SELECT
                {$tableAlias}.RecordID,
                {$tableAlias}.Version,
                {$lastEdited},
                {$fieldList}
            FROM {$table} AS {$tableAlias}
                {$join}
                {$where}
            ORDER BY
                {$tableAlias}.RecordID,
                {$lastEdited},
                {$tableAlias}.Version
        ";

        $result = DB::query($sql);

        $prev = [];
        $currentID = null;
        $buffer = [];
        $bufferSize = 0;

        foreach ($result as $row) {
            $id = (int) $row['RecordID'];

            // new record ID!
            if ($id !== $currentID) {
                $currentID = $id;
                $prev = [];
                $this->bufferAdd($buffer, $bufferSize, $id, (int) $row['Version']);
                foreach ($fields as $field) {
                    $prev[$field] = $row[$field] ?? null;
                }
                continue;
            }

            $changed = false;
            foreach ($fields as $field) {
                if (($row[$field] ?? null) !== ($prev[$field] ?? null)) {
                    DB::alteration_message("… … Found change in {$id} for field {$field}", 'created');

                    $changed = true;
                    break;
                }
            }

            if ($changed) {
                $this->bufferAdd($buffer, $bufferSize, $id, (int) $row['Version']);
            }

            foreach ($fields as $f) {
                $prev[$f] = $row[$f] ?? null;
            }
        }

        //final flush
        $this->flushBuffer($buffer, $bufferSize);
    }


    private function bufferAdd(array &$buffer, int &$bufferSize, int $recordId, int $version): void
    {
        $buffer[] = "({$recordId}, {$version})";
        $bufferSize++;

        if ($bufferSize >= 500) {
            $this->flushBuffer($buffer, $bufferSize);
        }
    }

    private function flushBuffer(array &$buffer, int &$bufferSize): void
    {
        if ($bufferSize === 0) {
            return;
        }

        DB::query(
            "
            INSERT IGNORE INTO OldPageVersions (RecordID, Version)
            VALUES " . implode(',', $buffer)
        );

        $buffer = [];
        $bufferSize = 0;
    }

    /* ============================================================
     * DELETE OLD VERSIONS
     * ========================================================== */

    private function deleteOldVersions(): void
    {
        DB::alteration_message('Deleting old versions…', 'deleted');

        $classes = ClassInfo::subclassesFor(SiteTree::class);
        unset($classes[SiteTree::class]);

        foreach ($classes as $class) {
            $table = $this->getVersionsTable($class);

            if (! $table) {
                continue;
            }

            DB::alteration_message("Cleaning {$table}", 'deleted');

            $this->deleteTableUsingKeyset($table);
        }
        DB::query('DROP TABLE IF EXISTS PageVersionsToDelete');
    }

    private function buildDeletionKeyTable(): void
    {
        DB::alteration_message('Preparing permanent deletion keyset table…', 'created');

        // Drop old table if present
        DB::query('DROP TABLE IF EXISTS PageVersionsToDelete');

        // Real table, InnoDB, with PK for fast JOIN
        DB::query("
            CREATE TABLE PageVersionsToDelete (
                RecordID INT NOT NULL,
                Version  INT NOT NULL,
                PRIMARY KEY (RecordID, Version)
            ) ENGINE=InnoDB
        ");

        DB::alteration_message('Populating deletion keyset…', 'created');

        DB::query("
            INSERT INTO PageVersionsToDelete (RecordID, Version)
            SELECT t.RecordID, t.Version
            FROM SiteTree_Versions AS t
            LEFT JOIN OldPageVersions AS keep
                ON keep.RecordID = t.RecordID
            AND keep.Version  = t.Version
            WHERE keep.RecordID IS NULL
        ");

        $count = (int) DB::query("
            SELECT COUNT(*) AS C FROM PageVersionsToDelete
        ")->value();

        DB::alteration_message("Deletion keyset ready: {$count} rows", 'created');
    }

    private function deleteTableUsingKeyset(string $table, int $batchSize = 500000): void
    {
        DB::alteration_message("Cleaning {$table}…", 'deleted');

        $total = 0;

        $sql = "
            DELETE FROM {$table}
            WHERE (RecordID, Version) IN (
                SELECT x.RecordID, x.Version
                FROM (
                    SELECT d.RecordID, d.Version
                    FROM PageVersionsToDelete d
                    LIMIT {$batchSize}
                ) AS x
            );
        ";

        DB::query($sql);
        $deleted = (int) DB::get_conn()->affectedRows();

        $total += $deleted;

        DB::alteration_message(
            "… {$deleted} removed (total {$total}) from {$table}",
            'deleted'
        );

        usleep(20000);

        DB::alteration_message("Finished {$table}: {$total} deleted", 'deleted');
    }


    private function getVersionsTable(string $className): ?string
    {
        $base = $className::config()->get('table_name');
        if (! $base) {
            return null;
        }

        $table = "{$base}_Versions";

        $exists = $this->tableExists($table);

        return $exists ? $table : null;
    }

    private function tableExists(string $tableName): bool
    {
        $exists = DB::query("SHOW TABLES LIKE '{$tableName}'")->value();

        return (bool) $exists;
    }
}
