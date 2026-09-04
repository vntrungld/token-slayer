<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    /**
     * Create the per-widget View permission for the tokens-by-model chart.
     * Every analytics widget is gated by its own `View:<Widget>` row so the
     * role editor's Widgets tab can toggle each chart independently; without
     * this row the widget's `canView()` would have nothing to check and the
     * chart would be invisible to everyone but super_admin.
     *
     * @return void
     */
    public function up(): void
    {
        Permission::firstOrCreate(['name' => 'View:TokensByModelChart', 'guard_name' => 'web']);
    }

    /**
     * Remove the permission row. Role assignments referencing it are cascaded
     * away by the `role_has_permissions` foreign key.
     *
     * @return void
     */
    public function down(): void
    {
        Permission::where('name', 'View:TokensByModelChart')->where('guard_name', 'web')->delete();
    }
};
