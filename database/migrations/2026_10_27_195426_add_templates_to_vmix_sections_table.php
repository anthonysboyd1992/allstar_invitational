<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sections become templates (structure + default values) and run-of-show items made from them.
     * Every existing section is converted into a template with one item that keeps its current values.
     */
    public function up(): void
    {
        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->after('id')->constrained('vmix_sections')->cascadeOnDelete();
        });

        Schema::table('vmix_groups', function (Blueprint $table) {
            $table->foreignId('template_group_id')->nullable()->after('vmix_section_id')->constrained('vmix_groups')->cascadeOnDelete();
        });

        Schema::table('vmix_fields', function (Blueprint $table) {
            $table->foreignId('template_field_id')->nullable()->after('vmix_group_id')->constrained('vmix_fields')->cascadeOnDelete();
        });

        $now = now();

        foreach (DB::table('vmix_sections')->orderBy('sort_order')->orderBy('id')->get() as $template) {
            $itemId = DB::table('vmix_sections')->insertGetId([
                'template_id' => $template->id,
                'name' => $this->unique('name', $template->name.' 1'),
                'key' => $this->unique('key', $template->key.'_1'),
                'on_air' => $template->on_air,
                'sort_order' => $template->sort_order,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('vmix_sections')->where('id', $template->id)->update(['on_air' => false]);

            foreach (DB::table('vmix_groups')->where('vmix_section_id', $template->id)->get() as $group) {
                $groupId = DB::table('vmix_groups')->insertGetId([
                    'vmix_section_id' => $itemId,
                    'template_group_id' => $group->id,
                    'name' => $group->name,
                    'sort_order' => $group->sort_order,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach (DB::table('vmix_fields')->where('vmix_group_id', $group->id)->get() as $field) {
                    DB::table('vmix_fields')->insert([
                        ...collect((array) $field)->except(['id', 'vmix_group_id', 'template_field_id', 'created_at', 'updated_at'])->all(),
                        'vmix_group_id' => $groupId,
                        'template_field_id' => $field->id,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    if ($field->type === 'image') {
                        DB::table('vmix_fields')->where('id', $field->id)->update(['value' => null, 'live_value' => null, 'original_path' => null]);
                    }
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('vmix_sections')->whereNotNull('template_id')->delete();

        Schema::table('vmix_fields', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_field_id');
        });

        Schema::table('vmix_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_group_id');
        });

        Schema::table('vmix_sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('template_id');
        });
    }

    private function unique(string $column, string $value): string
    {
        $separator = $column === 'key' ? '_' : ' ';
        $candidate = $value;
        $suffix = 2;

        while (DB::table('vmix_sections')->where($column, $candidate)->exists()) {
            $candidate = $value.$separator.$suffix++;
        }

        return $candidate;
    }
};
