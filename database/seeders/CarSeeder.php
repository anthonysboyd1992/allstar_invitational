<?php

namespace Database\Seeders;

use App\Models\Car;
use Illuminate\Database\Seeder;

class CarSeeder extends Seeder
{
    /**
     * Sample invitational cars. Null time means no lap in yet.
     *
     * @var list<array{0: string, 1: string|null}>
     */
    public const Samples = [
        ['01', '12.890'],
        ['1', '12.345'],
        ['2', '12.401'],
        ['4', '13.112'],
        ['4A', '12.556'],
        ['5G', '12.778'],
        ['7', '12.634'],
        ['7BC', '13.001'],
        ['11', '12.210'],
        ['13', '12.987'],
        ['21', '13.445'],
        ['26', null],
        ['39', '12.812'],
        ['48', '12.499'],
        ['55', '13.220'],
        ['71', '12.301'],
        ['87', '12.950'],
        ['91', null],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        foreach (self::Samples as [$number, $time]) {
            Car::query()->updateOrCreate(
                ['number' => $number],
                [
                    'sort_order' => 0,
                    'time_ms' => Car::parseTime($time),
                ],
            );
        }

        Car::applyNumberOrder();
    }
}
