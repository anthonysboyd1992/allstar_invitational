<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('users')->orderBy('id')->each(function (object $user): void {
            $permissions = json_decode((string) $user->permissions, true);

            if (! is_array($permissions) || in_array('results', $permissions, true)) {
                return;
            }

            if (! in_array('lineups', $permissions, true) && ! in_array('users', $permissions, true)) {
                return;
            }

            $insertAt = array_search('timing', $permissions, true);
            if ($insertAt === false) {
                $lineupsAt = array_search('lineups', $permissions, true);
                $insertAt = $lineupsAt === false ? array_search('users', $permissions, true) : $lineupsAt + 1;
            }

            if ($insertAt === false) {
                $permissions[] = 'results';
            } else {
                array_splice($permissions, $insertAt, 0, ['results']);
            }

            DB::table('users')->where('id', $user->id)->update([
                'permissions' => json_encode(array_values($permissions)),
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')->orderBy('id')->each(function (object $user): void {
            $permissions = json_decode((string) $user->permissions, true);

            if (! is_array($permissions)) {
                return;
            }

            DB::table('users')->where('id', $user->id)->update([
                'permissions' => json_encode(array_values(array_filter(
                    $permissions,
                    fn (mixed $area): bool => $area !== 'results',
                ))),
            ]);
        });
    }
};
