<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\User;
use App\Models\VmixField;
use App\Models\VmixGroup;
use App\Models\VmixSection;
use App\Services\VmixFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class VmixDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_requires_login_and_renders(): void
    {
        $this->get(route('vmix-data'))->assertRedirect(route('login'));

        VmixSection::factory()->create(['name' => 'Track Facts']);

        $this->actingAs(User::factory()->create())
            ->get(route('vmix-data'))
            ->assertOk()
            ->assertSee('Track Facts')
            ->assertSee(route('feeds.data'));
    }

    public function test_building_a_section_with_groups_and_fields(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test('pages::vmix-data')
            ->set('newSectionName', 'Track Facts')
            ->call('addSection')
            ->set('newGroupName', 'Heading')
            ->call('addGroup');

        $section = VmixSection::query()->sole();
        $heading = $section->groups()->sole();
        $this->assertSame('track_facts', $section->key);
        $this->assertFalse($section->on_air);
        $component->assertSet('selectedSectionId', $section->id);

        $component
            ->set("newField.{$heading->id}.key", 'track_name')
            ->set("newField.{$heading->id}.value", 'Knoxville Raceway')
            ->call('addField', $heading->id)
            ->assertHasNoErrors()
            ->set('newGroupName', 'Data')
            ->call('addGroup');

        $data = $section->groups()->where('name', 'Data')->sole();

        $this->assertSame('Knoxville Raceway', $heading->fields()->sole()->value);
        $this->assertCount(0, $data->fields);
    }

    public function test_field_names_must_be_unique_across_the_section(): void
    {
        $this->actingAs(User::factory()->create());
        $section = VmixSection::factory()->create();
        [$heading, $data] = VmixGroup::factory()->count(2)->for($section, 'section')->create();
        VmixField::factory()->for($heading, 'group')->create(['key' => 'track_name']);
        VmixField::factory()->for(VmixGroup::factory(), 'group')->create(['key' => 'track_length']);

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id])
            ->set("newField.{$data->id}.key", 'track_name')
            ->call('addField', $data->id)
            ->assertHasErrors("newField.{$data->id}.key")
            ->set("newField.{$data->id}.key", 'bad key!')
            ->call('addField', $data->id)
            ->assertHasErrors("newField.{$data->id}.key")
            ->set("newField.{$data->id}.key", 'track_length')
            ->call('addField', $data->id)
            ->assertHasNoErrors();

        $this->assertSame(['track_length'], $data->fields()->pluck('key')->all());
    }

    public function test_toggling_a_section_on_and_off_air(): void
    {
        $this->actingAs(User::factory()->create());
        $section = VmixSection::factory()->create();

        $component = Livewire::test('pages::vmix-data')->call('toggleOnAir', $section->id);
        $this->assertTrue($section->refresh()->on_air);

        $component->call('toggleOnAir', $section->id);
        $this->assertFalse($section->refresh()->on_air);
    }

    public function test_editing_a_value_updates_the_master_file_immediately(): void
    {
        $this->actingAs(User::factory()->create());
        $section = VmixSection::factory()->onAir()->create(['key' => 'track_facts']);
        $field = VmixField::factory()
            ->for(VmixGroup::factory()->for($section, 'section'), 'group')
            ->create(['key' => 'track_name', 'value' => 'Old']);

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id])
            ->assertSet("fieldValues.{$field->id}", 'Old')
            ->set("fieldValues.{$field->id}", 'Knoxville Raceway')
            ->assertSee('"track_name": "Knoxville Raceway"');

        $this->getJson(route('feeds.data'))
            ->assertExactJson(['track_facts' => [['track_name' => 'Knoxville Raceway']]]);
    }

    public function test_bound_inputs_save_and_reload_from_the_database(): void
    {
        $this->actingAs(User::factory()->create());
        $section = VmixSection::factory()->create(['name' => 'Track Facts', 'key' => 'track_facts']);
        VmixSection::factory()->create(['name' => 'Ringers', 'key' => 'ringers']);
        $group = VmixGroup::factory()->for($section, 'section')->create(['name' => 'Heading']);
        $name = VmixField::factory()->for($group, 'group')->create(['key' => 'track_name']);
        $record = VmixField::factory()->for($group, 'group')->create(['key' => 'track_record']);

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id])
            ->assertSet('sectionName', 'Track Facts')
            ->assertSet("groupNames.{$group->id}", 'Heading')
            ->set('sectionName', '  Facts  ')
            ->assertSet('sectionName', 'Facts')
            ->set('sectionName', 'Ringers')
            ->assertSet('sectionName', 'Facts')
            ->set('sectionKey', 'bad key')
            ->assertSet('sectionKey', 'track_facts')
            ->set("groupNames.{$group->id}", 'Title')
            ->set("fieldKeys.{$record->id}", 'track_name')
            ->assertSet("fieldKeys.{$record->id}", 'track_record')
            ->set("fieldKeys.{$name->id}", 'venue');

        $this->assertSame('Facts', $section->refresh()->name);
        $this->assertSame('Title', $group->refresh()->name);
        $this->assertSame('venue', $name->refresh()->key);
        $this->assertSame('track_record', $record->refresh()->key);
    }

    public function test_adding_a_section_or_group_with_an_existing_name_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $section = VmixSection::factory()->create(['name' => 'Track Facts']);
        VmixGroup::factory()->for($section, 'section')->create(['name' => 'Heading']);

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id])
            ->set('newSectionName', 'Track Facts ')
            ->call('addSection')
            ->assertHasErrors('newSectionName')
            ->set('newGroupName', 'Heading')
            ->call('addGroup')
            ->assertHasErrors('newGroupName');

        $this->assertSame(1, VmixSection::query()->count());
        $this->assertSame(1, VmixGroup::query()->count());
    }

    public function test_uploading_an_image_field(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $section = VmixSection::factory()->onAir()->create(['key' => 'ringers']);
        $group = VmixGroup::factory()->for($section, 'section')->create();

        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id])
            ->set("newField.{$group->id}.key", 'logo')
            ->set("newField.{$group->id}.type", 'image')
            ->set("newField.{$group->id}.width", '400')
            ->set("newField.{$group->id}.height", '200')
            ->call('addField', $group->id)
            ->assertHasNoErrors();

        $field = VmixField::query()->sole();
        $this->assertTrue($field->isImage());
        $this->assertSame([400, 200], [$field->width, $field->height]);
        $component->call('toggleNeedsUpdate', $field->id);

        $component->set("uploads.{$field->id}", UploadedFile::fake()->image('logo.png', 1000, 1000));

        $path = $field->refresh()->value;
        $this->assertStringStartsWith('vmix/', $path);
        Storage::disk('public')->assertExists($path);

        $image = imagecreatefromstring(Storage::disk('public')->get($path));
        $this->assertSame([400, 200], [imagesx($image), imagesy($image)]);

        $this->getJson(route('feeds.data'))
            ->assertExactJson(['ringers' => [['logo' => asset('storage/'.$path)]]]);

        $component->set("uploads.{$field->id}", UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'));
        $this->assertSame($path, $field->refresh()->value);

        $component->call('removeImage', $field->id);
        Storage::disk('public')->assertMissing($path);
        $this->assertNull($field->refresh()->value);

        $this->getJson(route('feeds.data'))
            ->assertExactJson(['ringers' => [['logo' => config('vmix.graphics_url').'clear.png?v='.config('vmix.asset_version')]]]);
    }

    public function test_image_fields_need_a_valid_size_and_default_to_1920_by_1080(): void
    {
        $this->actingAs(User::factory()->create());
        $group = VmixGroup::factory()->create();

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $group->vmix_section_id])
            ->set("newField.{$group->id}.key", 'too_big')
            ->set("newField.{$group->id}.type", 'image')
            ->set("newField.{$group->id}.width", '99999')
            ->call('addField', $group->id)
            ->assertHasErrors("newField.{$group->id}.key")
            ->set("newField.{$group->id}.key", 'background')
            ->set("newField.{$group->id}.type", 'image')
            ->set("newField.{$group->id}.width", '')
            ->call('addField', $group->id)
            ->assertHasNoErrors();

        $field = VmixField::query()->sole();
        $this->assertSame([1920, 1080], [$field->width, $field->height]);
    }

    public function test_changing_the_size_recrops_from_the_original_upload(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $field = VmixField::factory()->image()->create();

        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $field->group->vmix_section_id])
            ->set("uploads.{$field->id}", UploadedFile::fake()->image('logo.png', 1000, 500));

        $field->refresh();
        $firstCrop = $field->value;
        $original = $field->original_path;
        Storage::disk('public')->assertExists($original);

        $component
            ->set("fieldWidths.{$field->id}", '800')
            ->set("fieldHeights.{$field->id}", '100')
            ->assertSet("fieldWidths.{$field->id}", '800');

        $field->refresh();
        $this->assertSame([800, 100], [$field->width, $field->height]);
        Storage::disk('public')->assertMissing($firstCrop);
        Storage::disk('public')->assertExists($original);

        $image = imagecreatefromstring(Storage::disk('public')->get($field->value));
        $this->assertSame([800, 100], [imagesx($image), imagesy($image)]);

        $component->set("fieldWidths.{$field->id}", '0')->assertSet("fieldWidths.{$field->id}", '800');
        $this->assertSame(800, $field->refresh()->width);

        $component->call('removeImage', $field->id);
        Storage::disk('public')->assertMissing($original);
    }

    public function test_driver_fields_expand_to_the_drivers_details(): void
    {
        $this->actingAs(User::factory()->create());
        $section = VmixSection::factory()->onAir()->create(['key' => 'ringers']);
        $group = VmixGroup::factory()->for($section, 'section')->create();
        $driver = Competitor::factory()->create([
            'car_number' => '71H', 'first_name' => 'Max', 'last_name' => 'Stambaugh', 'hometown' => 'Elida, OH', 'accolades' => 'Champ',
        ]);
        $other = Competitor::factory()->create(['first_name' => 'Tim', 'last_name' => 'Ward']);

        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id])
            ->set("newField.{$group->id}.key", 'ringer_1')
            ->set("newField.{$group->id}.type", 'driver')
            ->set("newField.{$group->id}.value", (string) $driver->id)
            ->call('addField', $group->id)
            ->assertHasNoErrors();

        $field = VmixField::query()->sole();
        $this->assertTrue($field->isDriver());
        $this->assertTrue($field->needs_update);
        $this->assertSame('', $this->getJson(route('feeds.data'))->json('ringers.0')['ringer_1_name']);

        $component->call('pushField', $field->id)->call('toggleNeedsUpdate', $field->id);

        $row = $this->getJson(route('feeds.data'))->json('ringers.0');
        $this->assertSame([
            'ringer_1_number' => '71H',
            'ringer_1_name' => 'Max Stambaugh',
            'ringer_1_first_name' => 'Max',
            'ringer_1_last_name' => 'Stambaugh',
            'ringer_1_hometown' => 'Elida, OH',
            'ringer_1_accolades' => 'Champ',
            'ringer_1_headshot' => app(VmixFeed::class)->headshotFor($driver),
        ], $row);

        $component->set("fieldValues.{$field->id}", (string) $other->id);
        $this->assertSame('Tim Ward', $this->getJson(route('feeds.data'))->json('ringers.0')['ringer_1_name']);

        $component->set("fieldValues.{$field->id}", '999999')->assertSet("fieldValues.{$field->id}", (string) $other->id);

        $component->set("fieldValues.{$field->id}", '');
        $row = $this->getJson(route('feeds.data'))->json('ringers.0');
        $this->assertSame('', $row['ringer_1_name']);
        $this->assertStringEndsWith('clear.png?v=0', $row['ringer_1_headshot']);
    }

    public function test_manual_fields_wait_for_update_and_show_what_is_on_air(): void
    {
        $this->actingAs(User::factory()->create());
        $section = VmixSection::factory()->onAir()->create(['key' => 'ringers']);
        [$first, $second] = VmixGroup::factory()->count(2)->for($section, 'section')->create();
        $driver = Competitor::factory()->create(['first_name' => 'Max', 'last_name' => 'Stambaugh']);
        $replacement = Competitor::factory()->create(['first_name' => 'Tim', 'last_name' => 'Ward']);
        $ringer = VmixField::factory()->for($first, 'group')->needsUpdate()->create(['key' => 'ringer', 'type' => 'driver', 'value' => (string) $driver->id]);
        $title = VmixField::factory()->for($first, 'group')->needsUpdate()->create(['key' => 'title', 'value' => 'Ringers']);
        $note = VmixField::factory()->for($second, 'group')->needsUpdate()->create(['key' => 'note', 'value' => 'Old']);
        $auto = VmixField::factory()->for($second, 'group')->create(['key' => 'auto', 'value' => 'A']);

        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id])
            ->set("fieldValues.{$ringer->id}", (string) $replacement->id)
            ->set("fieldValues.{$title->id}", 'Ringer Lineup')
            ->set("fieldValues.{$note->id}", 'New')
            ->set("fieldValues.{$auto->id}", 'B')
            ->assertSee('Update all pending (3)')
            ->assertSee('Update all (2)')
            ->assertSee('Max Stambaugh');

        $row = $this->getJson(route('feeds.data'))->json('ringers.0');
        $this->assertSame(['Max Stambaugh', 'Ringers', 'Old', 'B'], [$row['ringer_name'], $row['title'], $row['note'], $row['auto']]);

        $component->call('pushField', $title->id);
        $this->assertSame('Ringer Lineup', $this->getJson(route('feeds.data'))->json('ringers.0')['title']);

        $component->call('pushGroup', $first->id);
        $row = $this->getJson(route('feeds.data'))->json('ringers.0');
        $this->assertSame(['Tim Ward', 'Old'], [$row['ringer_name'], $row['note']]);

        $component->call('pushSection')->assertDontSee('Update all pending');
        $this->assertSame('New', $this->getJson(route('feeds.data'))->json('ringers.0')['note']);
    }

    public function test_switching_a_field_to_auto_pushes_its_pending_change(): void
    {
        $this->actingAs(User::factory()->create());
        $field = VmixField::factory()->needsUpdate()->create(['value' => 'Draft', 'live_value' => 'Live']);

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $field->group->vmix_section_id])
            ->call('toggleNeedsUpdate', $field->id);

        $field->refresh();
        $this->assertFalse($field->needs_update);
        $this->assertSame('Draft', $field->live_value);
    }

    public function test_a_replaced_image_stays_on_air_until_update(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $field = VmixField::factory()->image()->needsUpdate()->create(['value' => null, 'live_value' => null]);

        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $field->group->vmix_section_id])
            ->set("uploads.{$field->id}", UploadedFile::fake()->image('first.png'))
            ->call('pushField', $field->id);
        $firstLive = $field->refresh()->live_value;

        $component->set("uploads.{$field->id}", UploadedFile::fake()->image('second.png'));
        $field->refresh();

        $this->assertSame($firstLive, $field->live_value);
        $this->assertNotSame($firstLive, $field->value);
        Storage::disk('public')->assertExists([$firstLive, $field->value]);

        $component->call('pushField', $field->id);
        Storage::disk('public')->assertMissing($firstLive);
        Storage::disk('public')->assertExists($field->refresh()->live_value);
    }

    public function test_uploading_a_headshot_for_a_picked_driver_updates_the_competitor(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $driver = Competitor::factory()->create();
        $notPicked = Competitor::factory()->create();
        $field = VmixField::factory()->create(['type' => 'driver', 'value' => (string) $driver->id]);

        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $field->group->vmix_section_id])
            ->set("headshotUploads.{$driver->id}", UploadedFile::fake()->image('max.png', 500, 500));

        $path = $driver->refresh()->headshot_path;
        Storage::disk('public')->assertExists($path);
        $this->assertSame([256, 256], array_slice(getimagesize(Storage::disk('public')->path($path)), 0, 2));

        $component->set("headshotUploads.{$notPicked->id}", UploadedFile::fake()->image('tim.png'))->assertNotFound();
        $this->assertNull($notPicked->refresh()->headshot_path);
    }

    public function test_deleting_an_image_field_deletes_its_file(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $path = UploadedFile::fake()->image('logo.png')->store('vmix', 'public');
        $field = VmixField::factory()->image($path)->create();

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $field->group->vmix_section_id])
            ->call('deleteField', $field->id);

        $this->assertModelMissing($field);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_groups_from_other_sections_cannot_be_changed(): void
    {
        $this->actingAs(User::factory()->create());
        $mine = VmixSection::factory()->create();
        $otherGroup = VmixGroup::factory()->create();

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $mine->id])
            ->call('deleteGroup', $otherGroup->id)
            ->assertNotFound();

        $this->assertModelExists($otherGroup);
    }

    public function test_master_file_has_every_field_of_each_on_air_section(): void
    {
        $facts = VmixSection::factory()->onAir()->create(['key' => 'track_facts', 'sort_order' => 1]);
        $heading = VmixGroup::factory()->for($facts, 'section')->create(['sort_order' => 1]);
        $data = VmixGroup::factory()->for($facts, 'section')->create(['sort_order' => 2]);
        VmixField::factory()->for($heading, 'group')->create(['key' => 'track_name', 'value' => 'Knoxville Raceway']);
        VmixField::factory()->for($data, 'group')->create(['key' => 'track_record', 'value' => '14.592']);
        VmixField::factory()->for($data, 'group')->create(['key' => 'notes', 'value' => null]);

        $sponsors = VmixSection::factory()->onAir()->create(['key' => 'sponsors', 'sort_order' => 2]);
        VmixField::factory()
            ->for(VmixGroup::factory()->for($sponsors, 'section'), 'group')
            ->create(['key' => 'sponsor_name', 'value' => 'Casey’s']);

        $offAir = VmixSection::factory()->create(['key' => 'weather']);
        VmixField::factory()
            ->for(VmixGroup::factory()->for($offAir, 'section'), 'group')
            ->create(['key' => 'temp', 'value' => '72']);

        $this->get(route('feeds.data'))
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertExactJson([
                'track_facts' => [['track_name' => 'Knoxville Raceway', 'track_record' => '14.592', 'notes' => '']],
                'sponsors' => [['sponsor_name' => 'Casey’s']],
            ]);
    }

    public function test_master_file_is_an_empty_object_when_nothing_is_on_air(): void
    {
        VmixSection::factory()->create();

        $this->assertSame('{}', $this->get(route('feeds.data'))->assertOk()->getContent());
    }
}
