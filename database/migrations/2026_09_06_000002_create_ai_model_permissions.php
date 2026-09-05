<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    /**
     * Create the `ViewAny:AiModel` and `Update:AiModel` permissions gating the
     * admin `Models` page — viewing the list, toggling a flair badge, and
     * editing a duration all require them, and running the Sync action
     * requires the update permission since it writes new rows.
     *
     * @return void
     */
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'ViewAny:AiModel', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'Update:AiModel', 'guard_name' => 'web']);
    }

    /**
     * Remove the permission rows. Role assignments referencing them are
     * cascaded away by the `role_has_permissions` foreign key.
     *
     * @return void
     */
    public function down(): void
    {
        Permission::whereIn('name', ['ViewAny:AiModel', 'Update:AiModel'])
            ->where('guard_name', 'web')
            ->delete();
    }
};
