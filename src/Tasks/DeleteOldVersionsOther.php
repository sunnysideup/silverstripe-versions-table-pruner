<?php

declare(strict_types=1);

namespace Sunnysideup\VersionsTablePruner\Tasks;

use Symfony\Component\Console\Input\InputInterface;
use SilverStripe\PolyExecution\PolyOutput;
use Symfony\Component\Console\Command\Command;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;

class DeleteOldVersionsOther extends BuildTask
{
    protected string $title = 'Cleanup old ChangeSet records';

    protected static string $description = 'Deletes old ChangeSet and orphaned ChangeSetItems.';

    protected static string $commandName = 'delete-old-change-sets';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $this->deleteOldChangeSets($output);
        $this->deleteOrphanChangeSetItems($output);
        $this->deleteOrphanChangeSetItemReferencedBy($output);
        $output->writeln('----------------------------------');
        return Command::SUCCESS;
    }

    private function deleteOldChangeSets(PolyOutput $output): void
    {
        $output->writeln('Deleting ChangeSets older than 3 months');
        DB::query(
            'DELETE FROM ChangeSet
             WHERE LastEdited < DATE_SUB(NOW(), INTERVAL 3 MONTH)'
        );
    }

    private function deleteOrphanChangeSetItems(PolyOutput $output): void
    {
        $output->writeln('Deleting orphaned ChangeSetItems');
        DB::query(
            'DELETE CSI
             FROM ChangeSetItem CSI
             LEFT JOIN ChangeSet CS ON CS.ID = CSI.ChangeSetID
             WHERE CS.ID IS NULL'
        );
    }

    private function deleteOrphanChangeSetItemReferencedBy(PolyOutput $output): void
    {
        $output->writeln('Deleting orphaned ChangeSetItem_ReferencedBy records');
        DB::query(
            'DELETE CSRB
             FROM ChangeSetItem_ReferencedBy CSRB
             LEFT JOIN ChangeSetItem CSI ON CSI.ID = CSRB.ChangeSetItemID
             WHERE CSI.ID IS NULL'
        );
    }
}
