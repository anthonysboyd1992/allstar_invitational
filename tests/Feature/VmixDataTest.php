<?php

namespace Tests\Feature;

use App\Models\Competitor;
use App\Models\Setting;
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

    public function test_building_a_template_with_groups_and_fields(): void
    {
        $this->actingAs(User::factory()->create());

        $component = Livewire::test('pages::vmix-data')
            ->set('newSectionName', 'Track Facts')
            ->call('addTemplate')
            ->set('newGroupName', 'Heading')
            ->call('addGroup');

        $section = VmixSection::query()->sole();
        $heading = $section->groups()->sole();
        $this->assertSame('track_facts', $section->key);
        $this->assertTrue($section->isTemplate());
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

    public function test_toggling_an_item_on_and_off_air(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create();
        $item = VmixSection::factory()->itemOf($template)->create();

        $component = Livewire::test('pages::vmix-data')->call('toggleOnAir', $item->id);
        $this->assertTrue($item->refresh()->on_air);
        $this->assertSame((string) $item->id, Setting::get(Setting::ShowCurrentItem));

        $component->call('toggleOnAir', $item->id);
        $this->assertFalse($item->refresh()->on_air);

        $component->call('toggleOnAir', $template->id)->assertNotFound();
    }

    public function test_editing_a_value_updates_the_master_file_immediately(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create(['key' => 'track_facts']);
        VmixField::factory()
            ->for(VmixGroup::factory()->for($template, 'section'), 'group')
            ->create(['key' => 'track_name', 'value' => 'Old']);
        $section = $this->onAirItemOf($template);
        $field = $this->itemField($section, 'track_name');

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

    public function test_adding_a_template_or_group_with_an_existing_name_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());
        $section = VmixSection::factory()->create(['name' => 'Track Facts']);
        VmixGroup::factory()->for($section, 'section')->create(['name' => 'Heading']);

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id])
            ->set('newSectionName', 'Track Facts ')
            ->call('addTemplate')
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
        $template = VmixSection::factory()->template()->create(['key' => 'ringers']);
        $group = VmixGroup::factory()->for($template, 'section')->create();

        $templateField = Livewire::test('pages::vmix-data', ['selectedSectionId' => $template->id])
            ->set("newField.{$group->id}.key", 'logo')
            ->set("newField.{$group->id}.type", 'image')
            ->set("newField.{$group->id}.width", '400')
            ->set("newField.{$group->id}.height", '200')
            ->call('addField', $group->id)
            ->assertHasNoErrors()
            ->call('toggleNeedsUpdate', $group->fields()->sole()->id)
            ->instance()->section->groups->sole()->fields->sole();

        $this->assertTrue($templateField->isImage());
        $this->assertSame([400, 200], [$templateField->width, $templateField->height]);

        $section = $this->onAirItemOf($template);
        $field = $this->itemField($section, 'logo');
        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id]);

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
            ->assertExactJson(['ringers' => [['logo' => VmixFeed::clearUrl(400, 200)]]]);
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
        $template = VmixSection::factory()->template()->create(['key' => 'ringers']);
        $group = VmixGroup::factory()->for($template, 'section')->create();
        $driver = Competitor::factory()->create([
            'car_number' => '71H', 'first_name' => 'Max', 'last_name' => 'Stambaugh', 'hometown' => 'Elida, OH', 'accolades' => 'Champ',
        ]);
        $other = Competitor::factory()->create(['first_name' => 'Tim', 'last_name' => 'Ward']);

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $template->id])
            ->set("newField.{$group->id}.key", 'ringer_1')
            ->set("newField.{$group->id}.type", 'driver')
            ->set("newField.{$group->id}.value", (string) $driver->id)
            ->call('addField', $group->id)
            ->assertHasNoErrors();

        $templateField = $group->fields()->sole();
        $this->assertTrue($templateField->isDriver());
        $this->assertTrue($templateField->needs_update);

        $section = $this->onAirItemOf($template);
        $field = $this->itemField($section, 'ringer_1');
        $field->update(['live_value' => null]);
        $this->assertSame('', $this->getJson(route('feeds.data'))->json('ringers.0')['ringer_1_name']);

        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id])->call('pushField', $field->id);
        $field->update(['needs_update' => false]);

        $row = $this->getJson(route('feeds.data'))->json('ringers.0');
        $this->assertSame([
            'ringer_1_number' => '71H',
            'ringer_1_name' => 'Max Stambaugh',
            'ringer_1_first_name' => 'Max',
            'ringer_1_last_name' => 'Stambaugh',
            'ringer_1_hometown' => 'Elida, OH',
            'ringer_1_accolades' => 'Champ',
            'ringer_1_headshot' => app(VmixFeed::class)->headshotFor($driver),
            'ringer_1_assigned_car' => '',
        ], $row);

        $component->set("fieldValues.{$field->id}", (string) $other->id);
        $this->assertSame('Tim Ward', $this->getJson(route('feeds.data'))->json('ringers.0')['ringer_1_name']);

        $component->set("fieldValues.{$field->id}", '999999')->assertSet("fieldValues.{$field->id}", (string) $other->id);

        $component->set("fieldValues.{$field->id}", '');
        $row = $this->getJson(route('feeds.data'))->json('ringers.0');
        $this->assertSame('', $row['ringer_1_name']);
        $this->assertSame(VmixFeed::clearUrl(256, 256), $row['ringer_1_headshot']);
    }

    public function test_driver_lookup_and_feed_include_car_number_zero(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        $group = VmixGroup::factory()->for($template, 'section')->create();
        $driver = Competitor::factory()->create([
            'car_number' => '0',
            'first_name' => 'Zero',
            'last_name' => 'Car',
        ]);

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $template->id])
            ->set("newField.{$group->id}.type", 'driver')
            ->assertSee('#0 Zero Car')
            ->set("newField.{$group->id}.key", 'guest')
            ->set("newField.{$group->id}.value", (string) $driver->id)
            ->call('addField', $group->id);

        $section = $this->onAirItemOf($template);
        $this->itemField($section, 'guest')->update([
            'value' => (string) $driver->id,
            'live_value' => (string) $driver->id,
        ]);

        $this->assertSame('0', $this->getJson(route('feeds.data'))->json('interview.0')['guest_number']);

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id])
            ->call('toggleSection', $section->id)
            ->assertSee('#0 Zero Car');
    }

    public function test_manual_fields_wait_for_update_and_show_what_is_on_air(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create(['key' => 'ringers']);
        $first = VmixGroup::factory()->for($template, 'section')->create(['sort_order' => 1]);
        $second = VmixGroup::factory()->for($template, 'section')->create(['sort_order' => 2]);
        $driver = Competitor::factory()->create(['first_name' => 'Max', 'last_name' => 'Stambaugh']);
        $replacement = Competitor::factory()->create(['first_name' => 'Tim', 'last_name' => 'Ward']);
        VmixField::factory()->for($first, 'group')->needsUpdate()->create(['key' => 'ringer', 'type' => 'driver', 'value' => (string) $driver->id]);
        VmixField::factory()->for($first, 'group')->needsUpdate()->create(['key' => 'title', 'value' => 'Ringers']);
        VmixField::factory()->for($second, 'group')->needsUpdate()->create(['key' => 'note', 'value' => 'Old']);
        VmixField::factory()->for($second, 'group')->create(['key' => 'auto', 'value' => 'A']);

        $section = $this->onAirItemOf($template);
        [$ringer, $title, $note, $auto] = array_map(fn (string $key) => $this->itemField($section, $key), ['ringer', 'title', 'note', 'auto']);

        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id])
            ->call('toggleSection', $section->id)
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

        $component->call('pushGroup', $ringer->vmix_group_id);
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

    public function test_master_file_has_the_on_air_item_of_each_template_under_the_template_key(): void
    {
        $facts = VmixSection::factory()->template()->create(['key' => 'track_facts', 'sort_order' => 1]);
        $heading = VmixGroup::factory()->for($facts, 'section')->create(['sort_order' => 1]);
        $data = VmixGroup::factory()->for($facts, 'section')->create(['sort_order' => 2]);
        VmixField::factory()->for($heading, 'group')->create(['key' => 'track_name', 'value' => 'Knoxville Raceway']);
        VmixField::factory()->for($data, 'group')->create(['key' => 'track_record', 'value' => '14.592']);
        VmixField::factory()->for($data, 'group')->create(['key' => 'notes', 'value' => null]);

        $sponsors = VmixSection::factory()->template()->create(['key' => 'sponsors', 'sort_order' => 2]);
        VmixField::factory()
            ->for(VmixGroup::factory()->for($sponsors, 'section'), 'group')
            ->create(['key' => 'sponsor_name', 'value' => 'Casey’s']);

        $offAir = VmixSection::factory()->template()->create(['key' => 'weather']);
        VmixField::factory()
            ->for(VmixGroup::factory()->for($offAir, 'section'), 'group')
            ->create(['key' => 'temp', 'value' => '72']);

        $this->onAirItemOf($facts);
        $this->itemField($this->onAirItemOf($sponsors, 'Sponsors 1'), 'sponsor_name')->update(['value' => 'Old', 'live_value' => 'Old']);
        $this->itemField($this->onAirItemOf($sponsors, 'Sponsors 2'), 'sponsor_name');
        $offAir->createItem('Weather 1');

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

    public function test_run_of_show_items_and_templates_can_be_dragged_into_a_new_order(): void
    {
        $this->actingAs(User::factory()->create());
        [$first, $second, $third] = collect(['first', 'second', 'third'])
            ->map(fn (string $key, int $index) => VmixSection::factory()->template()->create(['key' => $key, 'sort_order' => $index]));
        $items = collect([$first, $second, $third])->map(fn (VmixSection $template) => $this->onAirItemOf($template));

        $component = Livewire::test('pages::vmix-data')->call('sortItems', $items[2]->id, 0);

        $this->assertSame([$items[2]->id, $items[0]->id, $items[1]->id], VmixSection::query()->runOfShow()->pluck('id')->all());
        $this->assertSame(['third', 'first', 'second'], array_keys($this->getJson(route('feeds.data'))->json()));

        $component->call('sortTemplates', $first->id, 2);
        $this->assertSame([$second->id, $third->id, $first->id], VmixSection::query()->templates()->pluck('id')->all());
    }

    public function test_groups_can_be_dragged_into_a_new_order_within_their_section(): void
    {
        $this->actingAs(User::factory()->create());
        $section = VmixSection::factory()->create();
        [$heading, $data, $footer] = collect(['Heading', 'Data', 'Footer'])
            ->map(fn (string $name, int $index) => VmixGroup::factory()->for($section, 'section')->create(['name' => $name, 'sort_order' => $index]));
        $otherGroup = VmixGroup::factory()->create();

        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $section->id])
            ->call('sortGroups', $heading->id, 2);

        $this->assertSame([$data->id, $footer->id, $heading->id], $section->groups()->pluck('id')->all());

        $component->call('sortGroups', $otherGroup->id, 0)->assertNotFound();
    }

    public function test_color_fields_save_hex_values_live(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create(['key' => 'theme']);
        $group = VmixGroup::factory()->for($template, 'section')->create();

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $template->id])
            ->set("newField.{$group->id}.key", 'accent')
            ->set("newField.{$group->id}.type", 'color')
            ->set("newField.{$group->id}.value", 'not-a-color')
            ->call('addField', $group->id)
            ->assertHasErrors("newField.{$group->id}.key")
            ->set("newField.{$group->id}.value", '#ff6600')
            ->call('addField', $group->id)
            ->assertHasNoErrors();

        $field = $group->fields()->sole();
        $this->assertSame([VmixField::TypeColor, '#FF6600', '#FF6600', false], [$field->type, $field->value, $field->live_value, $field->needs_update]);

        $item = $this->onAirItemOf($template);
        $field = $this->itemField($item, 'accent');

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $item->id])
            ->set("fieldValues.{$field->id}", '#00aa33')
            ->assertSet("fieldValues.{$field->id}", '#00AA33')
            ->set("fieldValues.{$field->id}", 'red')
            ->assertSet("fieldValues.{$field->id}", '#00AA33')
            ->assertDispatched('notify');

        $this->getJson(route('feeds.data'))->assertExactJson(['theme' => [['accent' => '#00AA33']]]);
    }

    public function test_new_color_fields_default_to_white(): void
    {
        $this->actingAs(User::factory()->create());
        $group = VmixGroup::factory()->create();

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $group->vmix_section_id])
            ->set("newField.{$group->id}.key", 'background')
            ->set("newField.{$group->id}.type", 'color')
            ->call('addField', $group->id)
            ->assertHasNoErrors();

        $this->assertSame(VmixField::DefaultColor, $group->fields()->sole()->value);
    }

    public function test_adding_an_item_copies_the_template_structure_and_default_values(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create(['name' => 'Interview', 'key' => 'interview']);
        $group = VmixGroup::factory()->for($template, 'section')->create(['name' => 'Guest']);
        $driver = VmixField::factory()->for($group, 'group')->needsUpdate()->create(['key' => 'guest', 'type' => 'driver', 'value' => null]);
        VmixField::factory()->for($group, 'group')->create(['key' => 'topic', 'value' => 'Heat 1 recap']);
        $logoPath = UploadedFile::fake()->image('logo.png')->store('vmix', 'public');
        VmixField::factory()->for($group, 'group')->image($logoPath)->create(['key' => 'logo']);

        Livewire::test('pages::vmix-data')
            ->set('newItemTemplateId', (string) $template->id)
            ->call('addItem')
            ->assertHasNoErrors()
            ->set('newItemTemplateId', (string) $template->id)
            ->set('newItemName', 'Feature Winner')
            ->call('addItem')
            ->set('newItemTemplateId', (string) $template->id)
            ->set('newItemName', 'Interview')
            ->call('addItem')
            ->assertHasErrors('newItemName');

        [$first, $second] = $template->items()->get();
        $this->assertSame(['Interview 1', 'Feature Winner'], [$first->name, $second->name]);
        $this->assertFalse($first->on_air);

        $copy = $first->groups()->sole();
        $this->assertSame(['Guest', $group->id], [$copy->name, $copy->template_group_id]);
        $this->assertSame(['guest', 'topic', 'logo'], $copy->fields->pluck('key')->all());
        $this->assertSame($driver->id, $copy->fields[0]->template_field_id);
        $this->assertTrue($copy->fields[0]->needs_update);
        $this->assertSame('Heat 1 recap', $copy->fields[1]->value);

        $copiedLogo = $copy->fields[2]->value;
        $this->assertNotSame($logoPath, $copiedLogo);
        Storage::disk('public')->assertExists([$logoPath, $copiedLogo]);

        $first->deleteWithFiles();
        Storage::disk('public')->assertMissing($copiedLogo);
        Storage::disk('public')->assertExists($logoPath);
    }

    public function test_template_structure_changes_apply_to_every_item(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        $group = VmixGroup::factory()->for($template, 'section')->create(['name' => 'Guest']);
        $topic = VmixField::factory()->for($group, 'group')->create(['key' => 'topic']);
        $item = $this->onAirItemOf($template);
        $this->itemField($item, 'topic')->update(['value' => 'Kept', 'live_value' => 'Kept']);

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $template->id])
            ->set("groupNames.{$group->id}", 'Driver')
            ->set("fieldKeys.{$topic->id}", 'question')
            ->call('toggleNeedsUpdate', $topic->id)
            ->set("newField.{$group->id}.key", 'car')
            ->set("newField.{$group->id}.value", '71H')
            ->call('addField', $group->id)
            ->set('newGroupName', 'Footer')
            ->call('addGroup')
            ->set('sectionKey', 'driver_interview');

        $copy = $item->groups()->where('name', 'Driver')->sole();
        $question = $this->itemField($item, 'question');
        $this->assertSame('Kept', $question->value);
        $this->assertTrue($question->needs_update);
        $this->assertSame('71H', $this->itemField($item, 'car')->value);
        $this->assertSame(['Driver', 'Footer'], $item->groups()->pluck('name')->all());
        $this->assertSame(['question', 'car'], $copy->fields()->pluck('key')->all());

        $this->getJson(route('feeds.data'))
            ->assertExactJson(['driver_interview' => [['question' => 'Kept', 'car' => '71H']]]);

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $template->id])
            ->call('deleteField', $topic->id)
            ->call('deleteGroup', $group->id);

        $this->assertSame(['Footer'], $item->groups()->pluck('name')->all());
        $this->assertSame(0, VmixField::query()->count());
    }

    public function test_item_structure_cannot_be_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create();
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'topic']);
        $item = $template->createItem('Interview 1');
        $field = $this->itemField($item, 'topic');

        $component = fn () => Livewire::test('pages::vmix-data', ['selectedSectionId' => $item->id]);

        $open = $component();
        $open->assertDontSee('From template');
        $open->call('toggleSection', $item->id)->assertSee('From template')->assertDontSee('Add group');
        $open->call('toggleSection', $item->id)->assertDontSee('From template');
        $open->call('selectSection', $item->id)->assertDontSee('From template');

        $component()->set('newGroupName', 'Extra')->call('addGroup')->assertForbidden();
        $component()->call('deleteField', $field->id)->assertForbidden();
        $component()->call('deleteGroup', $field->vmix_group_id)->assertForbidden();
        $component()->call('toggleNeedsUpdate', $field->id)->assertForbidden();

        $this->assertModelExists($field);
        $this->assertSame(1, $item->groups()->count());
    }

    public function test_only_one_item_per_template_is_on_air(): void
    {
        $this->actingAs(User::factory()->create());
        $interview = VmixSection::factory()->template()->create(['key' => 'interview']);
        $facts = VmixSection::factory()->template()->create(['key' => 'facts']);
        $first = VmixSection::factory()->itemOf($interview)->onAir()->create();
        $second = VmixSection::factory()->itemOf($interview)->create();
        $fact = VmixSection::factory()->itemOf($facts)->onAir()->create();

        Livewire::test('pages::vmix-data')->call('toggleOnAir', $second->id);

        $this->assertFalse($first->refresh()->on_air);
        $this->assertTrue($second->refresh()->on_air);
        $this->assertTrue($fact->refresh()->on_air);
    }

    public function test_next_and_previous_step_through_the_run_of_show(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest']);
        [$first, $second, $third] = collect(['Max', 'Tim', 'Kyle'])->map(function (string $guest, int $index) use ($template) {
            $item = $template->createItem('Interview '.($index + 1));
            $this->itemField($item, 'guest')->update(['value' => $guest, 'live_value' => $guest]);

            return $item;
        });

        $component = Livewire::test('pages::vmix-data');
        $this->assertSame($first->id, $component->instance()->upNext->id);

        $component->call('showNext');
        $this->assertSame([$first->id], VmixSection::query()->where('on_air', true)->pluck('id')->all());
        $component->assertSet('selectedSectionId', $first->id)->assertSeeInOrder(['Now', 'Interview 1', 'Up next', 'Interview 2']);
        $this->assertSame($second->id, $component->instance()->upNext->id);

        $component->call('showNext')->call('showNext');
        $this->assertSame([$third->id], VmixSection::query()->where('on_air', true)->pluck('id')->all());
        $this->assertSame('Kyle', $this->getJson(route('feeds.data'))->json('interview.0.guest'));

        $component->call('showNext')->assertDispatched('notify');
        $this->assertTrue($third->refresh()->on_air);

        $component->call('showPrevious');
        $this->assertSame([$second->id], VmixSection::query()->where('on_air', true)->pluck('id')->all());
        $this->assertSame('Tim', $this->getJson(route('feeds.data'))->json('interview.0.guest'));
    }

    public function test_deleting_a_template_deletes_its_items(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create();
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create();
        $item = $this->onAirItemOf($template);

        Livewire::test('pages::vmix-data', ['selectedSectionId' => $template->id])->call('deleteSection');

        $this->assertModelMissing($item);
        $this->assertSame(0, VmixField::query()->count());
        $this->assertSame('{}', $this->get(route('feeds.data'))->getContent());
    }

    public function test_standalone_sections_have_their_own_structure_values_and_key(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest', 'value' => 'Max']);
        $this->onAirItemOf($template);

        $component = Livewire::test('pages::vmix-data')
            ->set('newStandaloneName', 'Weather')
            ->call('addSection')
            ->assertHasNoErrors()
            ->set('newGroupName', 'Now')
            ->call('addGroup');

        $weather = VmixSection::query()->standalone()->sole();
        $group = $weather->groups()->sole();
        $this->assertSame(['weather', false, null], [$weather->key, $weather->is_template, $weather->template_id]);
        $component->assertSet('selectedSectionId', $weather->id)->assertSee('Section name');

        $component
            ->set("newField.{$group->id}.key", 'temp')
            ->set("newField.{$group->id}.value", '72')
            ->call('addField', $group->id)
            ->call('toggleOnAir', $weather->id);

        $this->assertTrue($weather->refresh()->on_air);
        $this->assertTrue($template->items()->sole()->on_air);
        $this->assertNull(Setting::get(Setting::ShowCurrentItem));

        $this->getJson(route('feeds.data'))->assertExactJson([
            'weather' => [['temp' => '72']],
            'interview' => [['guest' => 'Max']],
        ]);

        $component->set('newStandaloneName', 'Weather')->call('addSection')->assertHasErrors('newStandaloneName');
        $component->call('toggleOnAir', $template->id)->assertNotFound();
    }

    public function test_standalone_sections_can_be_dragged_into_a_new_order(): void
    {
        $this->actingAs(User::factory()->create());
        [$first, $second] = collect(['first', 'second'])
            ->map(fn (string $key, int $index) => VmixSection::factory()->onAir()->create(['key' => $key, 'sort_order' => $index]));
        foreach ([$first, $second] as $section) {
            VmixField::factory()->for(VmixGroup::factory()->for($section, 'section'), 'group')->create(['key' => 'title']);
        }

        Livewire::test('pages::vmix-data')->call('sortStandalone', $second->id, 0);

        $this->assertSame([$second->id, $first->id], VmixSection::query()->standalone()->pluck('id')->all());
        $this->assertSame(['second', 'first'], array_keys($this->getJson(route('feeds.data'))->json()));
    }

    public function test_sections_in_the_run_of_show_stay_live_until_turned_off(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest']);
        $first = $template->createItem('Interview 1');
        $weather = VmixSection::factory()->create(['key' => 'weather']);
        VmixField::factory()->for(VmixGroup::factory()->for($weather, 'section'), 'group')->create(['key' => 'temp', 'value' => '72']);

        $component = Livewire::test('pages::vmix-data')
            ->set('newItemTemplateId', "section:{$weather->id}")
            ->call('addItem')
            ->assertSee('Section · stays live');
        $second = $template->createItem('Interview 2');

        $this->assertSame([$first->id, $weather->id, $second->id], VmixSection::query()->runOfShow()->pluck('id')->all());

        $component->call('showNext')->call('showNext');
        $this->assertTrue($weather->refresh()->on_air);
        $this->assertFalse($first->refresh()->on_air);

        $component->call('showNext');
        $this->assertTrue($weather->refresh()->on_air);
        $this->assertTrue($second->refresh()->on_air);
        $this->assertSame(['weather', 'interview'], array_keys($this->getJson(route('feeds.data'))->json()));

        $component->call('sortItems', $weather->id, 0)->call('removeFromRunOfShow', $weather->id);
        $this->assertSame([$first->id, $second->id], VmixSection::query()->runOfShow()->pluck('id')->all());
        $this->assertTrue($weather->refresh()->on_air);
        $this->assertModelExists($weather);

        $component->call('removeFromRunOfShow', $first->id)->assertNotFound();
    }

    public function test_breaks_count_down_in_the_run_of_show_and_stay_out_of_the_master_file(): void
    {
        $this->actingAs(User::factory()->create());
        $this->freezeSecond();
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest']);
        $first = $template->createItem('Interview 1');

        $component = Livewire::test('pages::vmix-data')
            ->set('newBreakMinutes', '0')
            ->set('newBreakExtraSeconds', '0')
            ->call('addBreak')
            ->assertHasErrors('newBreakMinutes')
            ->set('newBreakMinutes', '5')
            ->set('newBreakExtraSeconds', '30')
            ->call('addBreak')
            ->assertHasNoErrors()
            ->assertSee('Idle');

        $break = VmixSection::query()->whereNotNull('break_seconds')->sole();
        $this->assertSame(['Break 1', 330], [$break->name, $break->break_seconds]);
        $this->assertSame('5:30', VmixSection::formatSeconds($break->break_seconds));
        $this->assertFalse(VmixSection::query()->standalone()->whereKey($break->id)->exists());
        $component->assertSet('selectedSectionId', $break->id)
            ->set('allottedMinutes', '1')
            ->set('allottedExtraSeconds', '30');
        $this->assertSame(90, $break->refresh()->break_seconds);

        $second = $template->createItem('Interview 2');
        $this->assertSame([$first->id, $break->id, $second->id], VmixSection::query()->runOfShow()->pluck('id')->all());

        $component->call('showNext')->call('showNext');
        $break->refresh();
        $this->assertTrue($break->on_air);
        $this->assertFalse($first->refresh()->on_air);
        $this->assertTrue(now()->addSeconds(90)->equalTo($break->breakEndsAt()));
        $component->assertSee('Break 1 · 1:30');
        $this->assertSame('{}', $this->get(route('feeds.data'))->getContent());

        $component->call('showNext');
        $this->assertFalse($break->refresh()->on_air);
        $this->assertNull($break->breakEndsAt());
        $component->assertSee('Interview 2 · 1:00');

        $component->call('selectSection', $break->id)->set('newGroupName', 'Nope')->call('addGroup')->assertForbidden();
        Livewire::test('pages::vmix-data')->call('removeFromRunOfShow', $break->id);
        $this->assertModelMissing($break);
    }

    public function test_a_finished_break_puts_the_next_entry_on_air(): void
    {
        $this->actingAs(User::factory()->create());
        $this->freezeSecond();
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest', 'value' => 'Max']);
        $break = VmixSection::createBreak(60);
        $next = $template->createItem('Interview 1');
        $break->putOnAir();

        $this->travel(59)->seconds();
        $this->assertSame('{}', $this->get(route('feeds.data'))->getContent());
        $this->assertTrue($break->refresh()->on_air);

        $this->travel(1)->seconds();
        $this->getJson(route('feeds.data'))->assertExactJson(['interview' => [['guest' => 'Max']]]);
        $this->assertFalse($break->refresh()->on_air);
        $this->assertTrue($next->refresh()->on_air);
        $this->assertSame((string) $next->id, Setting::get(Setting::ShowCurrentItem));

        $next->update(['on_air' => false]);
        $lastBreak = VmixSection::createBreak(5);
        $standalone = VmixSection::factory()->onAir()->create(['show_order' => VmixSection::nextShowOrder()]);
        $lastBreak->putOnAir();
        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $lastBreak->id]);
        $this->travel(5)->seconds();

        $component->call('finishBreak')->assertSet('selectedSectionId', $standalone->id);

        $this->assertFalse($lastBreak->refresh()->on_air);
        $this->assertTrue($standalone->refresh()->on_air);
        $this->assertFalse($next->refresh()->on_air);
        $this->assertSame((string) $standalone->id, Setting::get(Setting::ShowCurrentItem));
    }

    public function test_dragging_a_section_into_the_run_of_show_adds_it_at_the_drop_position(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create();
        $first = $template->createItem('Interview 1');
        $second = $template->createItem('Interview 2');
        $weather = VmixSection::factory()->create();

        $component = Livewire::test('pages::vmix-data')->call('sortItems', $weather->id, 1);

        $this->assertSame([$first->id, $weather->id, $second->id], VmixSection::query()->runOfShow()->pluck('id')->all());
        $this->assertSame(1, VmixSection::query()->standalone()->count());

        $component->call('sortItems', $template->id, 0)->assertNotFound();
    }

    public function test_placeholders_are_in_the_run_of_show_with_no_fields_and_stay_out_of_the_master_file(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest', 'value' => 'Max']);
        $item = $this->onAirItemOf($template);

        $component = Livewire::test('pages::vmix-data')
            ->call('addPlaceholder')
            ->assertHasNoErrors()
            ->assertSee('Placeholder 1')
            ->assertDontSee('no data fields');

        $placeholder = VmixSection::query()->where('is_placeholder', true)->sole();
        $component->call('toggleSection', $placeholder->id)->assertSee('no data fields');
        $this->assertTrue($placeholder->isInRunOfShow());
        $this->assertFalse(VmixSection::query()->standalone()->whereKey($placeholder->id)->exists());
        $this->getJson(route('feeds.data'))->assertExactJson(['interview' => [['guest' => 'Max']]]);

        $component->set('newPlaceholderName', 'Feature winner')->call('addPlaceholder')->assertSee('Feature winner');
        $component->call('addGroup')->assertForbidden();

        Livewire::test('pages::vmix-data')->call('removeFromRunOfShow', $placeholder->id);
        $this->assertModelMissing($placeholder);
        $this->assertModelExists($item);
    }

    public function test_double_clicking_a_run_of_show_entry_puts_it_on_air(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest', 'value' => 'Max']);
        $first = $this->onAirItemOf($template, 'Interview 1');
        $second = $template->createItem('Interview 2');
        $this->itemField($second, 'guest')->update(['value' => 'Tim', 'live_value' => 'Tim']);
        $placeholder = VmixSection::createPlaceholder('Winner TBD');

        $component = Livewire::test('pages::vmix-data')->call('goLive', $second->id);

        $this->assertFalse($first->refresh()->on_air);
        $this->assertTrue($second->refresh()->on_air);
        $this->assertSame((string) $second->id, Setting::get(Setting::ShowCurrentItem));
        $component->assertSet('selectedSectionId', $second->id);
        $this->getJson(route('feeds.data'))->assertExactJson(['interview' => [['guest' => 'Tim']]]);

        $component->call('goLive', $placeholder->id);
        $this->assertFalse($second->refresh()->on_air);
        $this->assertFalse($placeholder->refresh()->on_air);
        $this->assertSame((string) $placeholder->id, Setting::get(Setting::ShowCurrentItem));
        $this->assertSame('{}', $this->get(route('feeds.data'))->getContent());

        $component->call('goLive', $template->id)->assertNotFound();
    }

    public function test_allotted_seconds_save_on_a_run_of_show_item(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create(['name' => 'Interview']);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest']);

        Livewire::test('pages::vmix-data')
            ->set('newItemTemplateId', (string) $template->id)
            ->set('newItemName', 'Interview 1')
            ->set('newItemMinutes', '1441')
            ->call('addItem')
            ->assertHasErrors('newItemMinutes')
            ->set('newItemMinutes', '1')
            ->set('newItemExtraSeconds', '30')
            ->call('addItem')
            ->assertHasNoErrors()
            ->assertSee('Interview · 1:30')
            ->set('allottedMinutes', '0')
            ->set('allottedExtraSeconds', '45')
            ->assertSee('Interview · 0:45');

        $item = VmixSection::query()->where('name', 'Interview 1')->sole();
        $this->assertSame(45, $item->allotted_seconds);
        $this->assertSame('0:45', VmixSection::formatSeconds($item->allottedSeconds()));
    }

    public function test_an_allotted_item_starts_a_timer_and_advances_when_it_ends(): void
    {
        $this->actingAs(User::factory()->create());
        $this->freezeSecond();
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest', 'value' => 'Max']);
        $first = $template->createItem('Interview 1');
        $first->update(['allotted_seconds' => 30]);
        $second = $template->createItem('Interview 2');
        $this->itemField($second, 'guest')->update(['value' => 'Tim', 'live_value' => 'Tim']);

        $component = Livewire::test('pages::vmix-data')->call('showNext');

        $first->refresh();
        $this->assertTrue($first->on_air);
        $this->assertTrue(now()->addSeconds(30)->equalTo($first->timerEndsAt()));
        $component->assertSee('Interview 1 · 0:30');
        $this->getJson(route('feeds.data'))->assertExactJson(['interview' => [['guest' => 'Max']]]);

        $this->travel(29)->seconds();
        $this->assertTrue($first->refresh()->on_air);
        $this->getJson(route('feeds.data'))->assertExactJson(['interview' => [['guest' => 'Max']]]);

        $this->travel(1)->seconds();
        $this->getJson(route('feeds.data'))->assertExactJson(['interview' => [['guest' => 'Tim']]]);
        $this->assertFalse($first->refresh()->on_air);
        $this->assertTrue($second->refresh()->on_air);
        $this->assertSame((string) $second->id, Setting::get(Setting::ShowCurrentItem));
    }

    public function test_an_item_without_allotted_time_runs_for_sixty_seconds(): void
    {
        $this->actingAs(User::factory()->create());
        $this->freezeSecond();
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest', 'value' => 'Max']);
        $first = $template->createItem('Interview 1');
        $second = $template->createItem('Interview 2');
        $this->itemField($second, 'guest')->update(['value' => 'Tim', 'live_value' => 'Tim']);

        $component = Livewire::test('pages::vmix-data')->call('showNext');

        $this->assertTrue(now()->addSeconds(60)->equalTo($first->refresh()->timerEndsAt()));
        $component->assertSee('Interview 1 · 1:00');

        $this->travel(59)->seconds();
        $this->getJson(route('feeds.data'))->assertExactJson(['interview' => [['guest' => 'Max']]]);
        $this->assertTrue($first->refresh()->on_air);

        $this->travel(1)->seconds();
        $this->getJson(route('feeds.data'))->assertExactJson(['interview' => [['guest' => 'Tim']]]);
        $this->assertFalse($first->refresh()->on_air);
        $this->assertTrue($second->refresh()->on_air);
        $this->assertSame((string) $second->id, Setting::get(Setting::ShowCurrentItem));
    }

    public function test_a_placeholder_timer_advances_the_run_of_show_when_it_ends(): void
    {
        $this->actingAs(User::factory()->create());
        $this->freezeSecond();
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest', 'value' => 'Max']);

        $component = Livewire::test('pages::vmix-data')
            ->set('newPlaceholderMinutes', '0')
            ->set('newPlaceholderExtraSeconds', '30')
            ->call('addPlaceholder')
            ->assertHasNoErrors()
            ->assertSee('Placeholder · 0:30');

        $placeholder = VmixSection::query()->where('is_placeholder', true)->sole();
        $this->assertSame(30, $placeholder->allotted_seconds);
        $next = $template->createItem('Interview 1');

        $component->call('goLive', $placeholder->id);
        $this->assertFalse($placeholder->refresh()->on_air);
        $this->assertSame((string) $placeholder->id, Setting::get(Setting::ShowCurrentItem));
        $component->assertSee('Placeholder 1 · 0:30');
        $this->assertSame('{}', $this->get(route('feeds.data'))->getContent());

        $this->travel(29)->seconds();
        $this->assertSame((string) $placeholder->id, Setting::get(Setting::ShowCurrentItem));
        $this->assertFalse($next->refresh()->on_air);

        $this->travel(1)->seconds();
        $this->getJson(route('feeds.data'))->assertExactJson(['interview' => [['guest' => 'Max']]]);
        $this->assertTrue($next->refresh()->on_air);
        $this->assertSame((string) $next->id, Setting::get(Setting::ShowCurrentItem));
    }

    public function test_end_of_show_remaining_is_the_sum_of_allotted_times_when_idle(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-29 15:00:00');
        $template = VmixSection::factory()->template()->create();
        $first = $template->createItem('Interview 1');
        $first->update(['allotted_seconds' => 480]);
        $second = $template->createItem('Interview 2');
        $second->update(['allotted_seconds' => 180]);

        $this->assertSame(660, VmixSection::remainingUntilEndOfShow(null, VmixSection::query()->runOfShow()->get()));

        Livewire::test('pages::vmix-data')
            ->assertSee('End of show')
            ->assertSee('11:00')
            ->assertSee('Done at')
            ->assertSee('3:11:00 PM')
            ->assertDontSee('-1m')
            ->assertDontSee('+1m');
    }

    public function test_end_of_show_remaining_includes_current_leftover_and_later_items(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-29 15:00:00');
        $template = VmixSection::factory()->template()->create();
        $first = $template->createItem('Interview 1');
        $first->update(['allotted_seconds' => 480]);
        $second = $template->createItem('Interview 2');
        $second->update(['allotted_seconds' => 180]);

        $component = Livewire::test('pages::vmix-data')->call('showNext');

        $this->assertSame(660, VmixSection::remainingUntilEndOfShow($first->refresh(), VmixSection::query()->runOfShow()->get()));
        $component
            ->assertSee('11:00')
            ->assertSee('Done at')
            ->assertSee('3:11:00 PM')
            ->assertSee('-30s')
            ->assertSee('+30s')
            ->assertSee('-1m')
            ->assertSee('+1m')
            ->assertSee('-5m')
            ->assertSee('+5m')
            ->assertSee('Add 30 seconds')
            ->assertSee('Subtract 1 minute')
            ->assertSee('Add 5 minutes');

        $this->travel(60)->seconds();
        $component->call('$refresh');

        $this->assertSame(600, VmixSection::remainingUntilEndOfShow($first->refresh(), VmixSection::query()->runOfShow()->get()));
        $component->assertSee('10:00')->assertSee('3:11:00 PM');
    }

    public function test_bumping_the_current_timer_adds_a_minute_without_changing_allotted_time(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-29 15:00:00');
        $template = VmixSection::factory()->template()->create();
        $first = $template->createItem('Interview 1');
        $first->update(['allotted_seconds' => 120]);
        $second = $template->createItem('Interview 2');
        $second->update(['allotted_seconds' => 60]);

        $component = Livewire::test('pages::vmix-data')->call('showNext')->call('bumpCurrentTimer', 60);

        $first->refresh();
        $this->assertSame(120, $first->allotted_seconds);
        $this->assertTrue(now()->addMinute()->equalTo($first->on_air_at));
        $this->assertTrue(now()->addSeconds(180)->equalTo($first->timerEndsAt()));
        $this->assertSame(240, VmixSection::remainingUntilEndOfShow($first, VmixSection::query()->runOfShow()->get()));
        $component->assertSee('Interview 1 · 2:00')->assertSee('4:00')->assertSee('3:04:00 PM');
    }

    public function test_bumping_the_current_timer_subtracts_a_minute(): void
    {
        $this->actingAs(User::factory()->create());
        $this->freezeSecond();
        $template = VmixSection::factory()->template()->create();
        $first = $template->createItem('Interview 1');
        $first->update(['allotted_seconds' => 180]);
        $template->createItem('Interview 2')->update(['allotted_seconds' => 60]);

        Livewire::test('pages::vmix-data')->call('showNext')->call('bumpCurrentTimer', -60);

        $first->refresh();
        $this->assertSame(180, $first->allotted_seconds);
        $this->assertTrue(now()->subMinute()->equalTo($first->on_air_at));
        $this->assertTrue(now()->addSeconds(120)->equalTo($first->timerEndsAt()));
        $this->assertSame(180, VmixSection::remainingUntilEndOfShow($first, VmixSection::query()->runOfShow()->get()));
    }

    public function test_subtracting_a_minute_with_under_a_minute_left_advances_the_run_of_show(): void
    {
        $this->actingAs(User::factory()->create());
        $this->freezeSecond();
        $template = VmixSection::factory()->template()->create(['key' => 'interview']);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest', 'value' => 'Max']);
        $first = $template->createItem('Interview 1');
        $first->update(['allotted_seconds' => 30]);
        $second = $template->createItem('Interview 2');
        $this->itemField($second, 'guest')->update(['value' => 'Tim', 'live_value' => 'Tim']);

        Livewire::test('pages::vmix-data')->call('showNext')->call('bumpCurrentTimer', -60);

        $this->assertFalse($first->refresh()->on_air);
        $this->assertTrue($second->refresh()->on_air);
        $this->assertSame((string) $second->id, Setting::get(Setting::ShowCurrentItem));
        $this->getJson(route('feeds.data'))->assertExactJson(['interview' => [['guest' => 'Tim']]]);
    }

    public function test_bumping_does_nothing_without_a_current_timer_or_with_invalid_seconds(): void
    {
        $this->actingAs(User::factory()->create());
        $this->freezeSecond();
        $template = VmixSection::factory()->template()->create();
        $first = $template->createItem('Interview 1');
        $first->update(['allotted_seconds' => 120]);

        Livewire::test('pages::vmix-data')->call('bumpCurrentTimer', 60);

        $this->assertNull($first->refresh()->on_air_at);

        $component = Livewire::test('pages::vmix-data')->call('showNext');
        $startedAt = $first->refresh()->on_air_at;

        $component->call('bumpCurrentTimer', 1)->call('bumpCurrentTimer', 45)->call('bumpCurrentTimer', 0);

        $this->assertTrue($startedAt->equalTo($first->refresh()->on_air_at));
        $this->assertSame(120, $first->allotted_seconds);
    }

    public function test_bumping_the_current_timer_accepts_thirty_seconds_and_five_minutes(): void
    {
        $this->actingAs(User::factory()->create());
        $this->travelTo('2026-10-29 15:00:00');
        $template = VmixSection::factory()->template()->create();
        $first = $template->createItem('Interview 1');
        $first->update(['allotted_seconds' => 120]);
        $template->createItem('Interview 2')->update(['allotted_seconds' => 60]);

        $component = Livewire::test('pages::vmix-data')->call('showNext')->call('bumpCurrentTimer', 30);

        $first->refresh();
        $this->assertSame(120, $first->allotted_seconds);
        $this->assertTrue(now()->addSeconds(30)->equalTo($first->on_air_at));
        $this->assertSame(210, VmixSection::remainingUntilEndOfShow($first, VmixSection::query()->runOfShow()->get()));
        $component->assertSee('3:03:30 PM');

        $component->call('bumpCurrentTimer', 300);
        $first->refresh();
        $this->assertTrue(now()->addSeconds(330)->equalTo($first->on_air_at));
        $this->assertSame(510, VmixSection::remainingUntilEndOfShow($first, VmixSection::query()->runOfShow()->get()));
        $component->assertSee('3:08:30 PM');
    }

    public function test_descriptions_save_as_a_bulleted_list_and_stay_out_of_the_master_file(): void
    {
        $this->actingAs(User::factory()->create());
        $template = VmixSection::factory()->template()->create([
            'name' => 'Interview',
            'key' => 'interview',
            'description' => "Guest bumper\nAsk hometown",
        ]);
        VmixField::factory()->for(VmixGroup::factory()->for($template, 'section'), 'group')->create(['key' => 'guest', 'value' => 'Max']);
        $this->onAirItemOf($template, 'Interview 1');
        VmixSection::factory()->create([
            'name' => 'Weather',
            'key' => 'weather',
            'description' => 'Track temp and wind',
        ]);
        VmixSection::createBreak(300)->update(['description' => "After heat 3\nHold for feature"]);
        VmixSection::createPlaceholder('Feature winner')->update(['description' => 'Hold until the trophy is ready']);

        $component = Livewire::test('pages::vmix-data', ['selectedSectionId' => $template->id])
            ->assertSee('Guest bumper')
            ->assertSee('Ask hometown')
            ->assertSee('Track temp and wind')
            ->assertSee('After heat 3')
            ->assertSee('Hold for feature')
            ->assertSee('Hold until the trophy is ready')
            ->assertSet('sectionDescriptionLines', ['Guest bumper', 'Ask hometown', ''])
            ->set('sectionDescriptionLines.0', '  One guest  ')
            ->assertSet('sectionDescriptionLines.0', 'One guest')
            ->set('sectionDescriptionLines.2', 'Hold for winner');

        $this->assertSame("One guest\nAsk hometown\nHold for winner", $template->refresh()->description);
        $this->assertSame(['One guest', 'Ask hometown', 'Hold for winner'], $template->descriptionLines());
        $this->getJson(route('feeds.data'))->assertExactJson(['interview' => [['guest' => 'Max']]]);

        $placeholder = VmixSection::query()->where('is_placeholder', true)->sole();
        $component
            ->call('toggleSection', $placeholder->id)
            ->assertSee('Hold until the trophy is ready')
            ->assertSet('expandedSectionId', $placeholder->id)
            ->assertSet('sectionDescriptionLines', ['Hold until the trophy is ready', ''])
            ->set('sectionDescriptionLines.0', str_repeat('a', VmixSection::DescriptionLineMax + 1))
            ->assertSet('sectionDescriptionLines.0', 'Hold until the trophy is ready')
            ->call('removeDescriptionLine', 0)
            ->assertSet('sectionDescriptionLines', ['']);

        $this->assertNull($placeholder->refresh()->description);
    }

    /**
     * Copy a template into a new run-of-show item and put it on air.
     */
    private function onAirItemOf(VmixSection $template, ?string $name = null): VmixSection
    {
        $item = $template->createItem($name ?? $template->nextItemName());
        $item->putOnAir();

        return $item;
    }

    private function itemField(VmixSection $item, string $key): VmixField
    {
        return VmixField::query()
            ->where('key', $key)
            ->whereHas('group', fn ($query) => $query->where('vmix_section_id', $item->id))
            ->sole();
    }
}
