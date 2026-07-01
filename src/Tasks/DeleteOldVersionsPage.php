<?php

declare(strict_types=1);

namespace Sunnysideup\VersionsTablePruner\Tasks;

use Symfony\Component\Console\Input\InputInterface;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;

class DeleteOldVersionsPage extends BuildTask
{
    protected string $title = 'Version Cleanup';

    protected static string $description = 'Keeps recent versions and gradually removes older ones based on retention periods.';

    protected static string $commandName = 'delete-old-versions-page';

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

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->makeTable($output);
        $this->ensureIndexes($output);
        $this->applyRetentionRules($output);
        $this->applyChangeTracking($output);
        $this->buildDeletionKeyTable($output);
        $this->deleteOldVersions($output);
        $output->writeln('Version cleanup completed.');
        return Command::SUCCESS;
    }

    private function makeTable(PolyOutput $output): void
    {
        $output->writeln('Creating temporary OldPageVersions table');
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

    private function ensureIndexes(PolyOutput $output): void
    {
        $output->writeln('Checking composite indexes on SiteTree_Versions');

        $exists = DB::query("
            SELECT 1
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = 'SiteTree_Versions'
              AND index_name = 'idx_lastedited_recordid_version'
        ")->value();

        if (! $exists) {
            $output->writeln('Adding composite index idx_lastedited_recordid_version');

            DB::query("
                ALTER TABLE SiteTree_Versions
                ADD INDEX idx_lastedited_recordid_version (LastEdited, RecordID, Version)
            ");
        }
    }



    /* ============================================================
     * RETENTION RULES
     * ========================================================== */

    private function applyRetentionRules(PolyOutput $output): void
    {
        $rules = $this->config()->get('retention_rules') ?? self::$retention_rules;
        $output->writeln('Applying retention rules…');
        foreach ($rules as $label => $rule) {
            $from = $rule['From'] ?? null;
            $to   = $rule['To'] ?? null;

            $conditions = [];

            if ($from) {
                $conditions[] = sprintf('LastEdited < DATE_SUB(NOW(), INTERVAL %s)', $from);
            }

            if ($to) {
                $conditions[] = sprintf('LastEdited >= DATE_SUB(NOW(), INTERVAL %s)', $to);
            }

            $where = implode(' AND ', $conditions) ?: null;
            $groupBy = $rule['GroupBy'];

            $output->writeln(sprintf('… Retention: %s (GroupBy: %s)', $label, $groupBy));
            if (! $where) {
                $output->writeln("… …  - No conditions, applying to all records");
                continue;
            } else {
                $output->writeln('… …  - Conditions: ' . $where);
            }

            if (! $groupBy) {
                $output->writeln("… …  - No GroupBy specified, skipping");
                continue;
            } else {
                $output->writeln('… …  - GroupBy: ' . $groupBy);
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

    private function applyChangeTracking(PolyOutput $output): void
    {
        $output->writeln('Applying change tracking rules…');
        $configs = $this->config()->get('change_tracking') ?? self::$change_tracking;

        foreach ($configs as $className => $settings) {
            $table = $this->getVersionsTable($className);

            $this->addChangeKeepsForTable(
                $output,
                $table,
                (array) ($settings['fields'] ?? []),
                (string) ($settings['upTo'] ?? null)
            );
        }
    }

    private function addChangeKeepsForTable(PolyOutput $output, string $table, array $fields, ?string $upTo): void
    {
        if (! $this->tableExists($table)) {
            $output->writeln(sprintf('… Skipping %s: does not exist', $table));
            return;
        }

        if ($fields === []) {
            $output->writeln(sprintf('… Skipping %s: no fields configured', $table));
            return;
        }

        $output->writeln(sprintf('… Scanning %s for field changes: ', $table) . implode(', ', $fields));

        // WHERE clause
        $where = $upTo
            ? sprintf('WHERE stv.LastEdited >= DATE_SUB(NOW(), INTERVAL %s)', $upTo)
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
                    $output->writeln(sprintf('… … Found change in %d for field %s', $id, $field));

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
        $buffer[] = sprintf('(%d, %d)', $recordId, $version);
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

    private function deleteOldVersions(PolyOutput $output): void
    {
        $output->writeln('Deleting old versions…');

        $classes = ClassInfo::subclassesFor(SiteTree::class);
        unset($classes[SiteTree::class]);

        foreach ($classes as $class) {
            $table = $this->getVersionsTable($class);

            if (! $table) {
                continue;
            }

            $output->writeln('Cleaning ' . $table);

            $this->deleteTableUsingKeyset($output, $table);
        }

        DB::query('DROP TABLE IF EXISTS PageVersionsToDelete');
    }

    private function buildDeletionKeyTable(PolyOutput $output): void
    {
        $output->writeln('Preparing permanent deletion keyset table…');

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

        $output->writeln('Populating deletion keyset…');

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

        $output->writeln(sprintf('Deletion keyset ready: %d rows', $count));
    }

    private function deleteTableUsingKeyset(PolyOutput $output, string $table, int $batchSize = 500000): void
    {
        $output->writeln(sprintf('Cleaning %s…', $table));

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

        $output->writeln(
            sprintf('… %d removed (total %d) from %s', $deleted, $total, $table)
        );

        usleep(20000);

        $output->writeln(sprintf('Finished %s: %d deleted', $table, $total));
    }


    private function getVersionsTable(string $className): ?string
    {
        $base = $className::config()->get('table_name');
        if (! $base) {
            return null;
        }

        $table = $base . '_Versions';

        $exists = $this->tableExists($table);

        return $exists ? $table : null;
    }

    private function tableExists(string $tableName): bool
    {
        $exists = DB::query(sprintf("SHOW TABLES LIKE '%s'", $tableName))->value();

        return (bool) $exists;
    }
}
