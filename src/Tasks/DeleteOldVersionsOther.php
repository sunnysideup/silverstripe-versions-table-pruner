<?php

declare(strict_types=1);

namespace Sunnysideup\VersionsTablePruner\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;

class DeleteOldVersionsOther extends BuildTask
{
    protected $title = 'Cleanup old ChangeSet records';
    protected $description = 'Deletes old ChangeSet and orphaned ChangeSetItems.';
    private static $segment = 'delete-old-change-sets';

    public function run($request): void
    {
        $this->deleteOldChangeSets();
        $this->deleteOrphanChangeSetItems();
        $this->deleteOrphanChangeSetItemReferencedBy();

        DB::alteration_message('----------------------------------');
    }
    private function deleteOldChangeSets(): void
    {
        DB::alteration_message('Deleting ChangeSets older than 3 months', 'deleted');
        DB::query(
            'DELETE FROM ChangeSet
             WHERE LastEdited < DATE_SUB(NOW(), INTERVAL 3 MONTH)'
        );
    }

    private function deleteOrphanChangeSetItems(): void
    {
        DB::alteration_message('Deleting orphaned ChangeSetItems', 'deleted');
        DB::query(
            'DELETE CSI
             FROM ChangeSetItem CSI
             LEFT JOIN ChangeSet CS ON CS.ID = CSI.ChangeSetID
             WHERE CS.ID IS NULL'
        );
    }

    private function deleteOrphanChangeSetItemReferencedBy(): void
    {
        DB::alteration_message('Deleting orphaned ChangeSetItem_ReferencedBy records', 'deleted');
        DB::query(
            'DELETE CSRB
             FROM ChangeSetItem_ReferencedBy CSRB
             LEFT JOIN ChangeSetItem CSI ON CSI.ID = CSRB.ChangeSetItemID
             WHERE CSI.ID IS NULL'
        );
    }
}
