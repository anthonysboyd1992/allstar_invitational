<?php

namespace Database\Seeders;

use App\Models\Competitor;
use Illuminate\Database\Seeder;

class CompetitorSeeder extends Seeder
{
    /**
     * Sample invitational drivers for local heat sheets. Existing names are updated in place; headshots are left alone.
     *
     * @var list<array{pill_number: int, car_number: string, first_name: string, last_name: string, hometown: string, weight: float, accolades: string|null, transponder: string}>
     */
    public const Samples = [
        ['pill_number' => 101, 'car_number' => '47', 'first_name' => 'Collin', 'last_name' => 'Thirlby', 'hometown' => 'Traverse City, MI', 'weight' => 210.0, 'accolades' => '2025 WoodTic Champion', 'transponder' => '2000101'],
        ['pill_number' => 102, 'car_number' => '45', 'first_name' => 'Conner', 'last_name' => 'Zbozien', 'hometown' => 'St. Clair Shores, MI', 'weight' => 160.0, 'accolades' => '2024 Flat Rock Speedway Track Champion', 'transponder' => '2000102'],
        ['pill_number' => 103, 'car_number' => '8', 'first_name' => 'Kyle', 'last_name' => 'Strickler', 'hometown' => 'Mooresville, NC', 'weight' => 195.0, 'accolades' => 'World of Outlaws Late Model winner', 'transponder' => '2000103'],
        ['pill_number' => 104, 'car_number' => '2', 'first_name' => 'Nick', 'last_name' => 'Hoffman', 'hometown' => 'Mooresville, NC', 'weight' => 185.0, 'accolades' => 'Castrol FloRacing Night in America champion', 'transponder' => '2000104'],
        ['pill_number' => 105, 'car_number' => '1', 'first_name' => 'Tyler', 'last_name' => 'Erb', 'hometown' => 'New Waverly, TX', 'weight' => 175.0, 'accolades' => 'Lucas Oil Late Model Dirt Series winner', 'transponder' => '2000105'],
        ['pill_number' => 106, 'car_number' => '1s', 'first_name' => 'Brandon', 'last_name' => 'Sheppard', 'hometown' => 'New Berlin, IL', 'weight' => 190.0, 'accolades' => 'World of Outlaws Late Model champion', 'transponder' => '2000106'],
        ['pill_number' => 107, 'car_number' => '20RT', 'first_name' => 'Ricky', 'last_name' => 'Thornton', 'hometown' => 'Adele, IA', 'weight' => 205.0, 'accolades' => 'Lucas Oil Late Model Dirt Series champion', 'transponder' => '2000107'],
        ['pill_number' => 108, 'car_number' => '32', 'first_name' => 'Bobby', 'last_name' => 'Pierce', 'hometown' => 'Oakwood, IL', 'weight' => 188.0, 'accolades' => 'World 100 winner', 'transponder' => '2000108'],
        ['pill_number' => 109, 'car_number' => '44', 'first_name' => 'Chris', 'last_name' => 'Madden', 'hometown' => 'Gray Court, SC', 'weight' => 200.0, 'accolades' => 'World 100 winner', 'transponder' => '2000109'],
        ['pill_number' => 110, 'car_number' => '25', 'first_name' => 'Shane', 'last_name' => 'Clanton', 'hometown' => 'Zebulon, GA', 'weight' => 215.0, 'accolades' => 'Lucas Oil Late Model Dirt Series champion', 'transponder' => '2000110'],
        ['pill_number' => 111, 'car_number' => '157', 'first_name' => 'Mike', 'last_name' => 'Marlar', 'hometown' => 'Winfield, TN', 'weight' => 198.0, 'accolades' => 'Dream winner', 'transponder' => '2000111'],
        ['pill_number' => 112, 'car_number' => '49', 'first_name' => 'Jonathan', 'last_name' => 'Davenport', 'hometown' => 'Blairsville, GA', 'weight' => 192.0, 'accolades' => 'Lucas Oil Late Model Dirt Series champion', 'transponder' => '2000112'],
        ['pill_number' => 113, 'car_number' => '76B', 'first_name' => 'Brandon', 'last_name' => 'Overton', 'hometown' => 'Evans, GA', 'weight' => 180.0, 'accolades' => 'World 100 winner', 'transponder' => '2000113'],
        ['pill_number' => 114, 'car_number' => '39', 'first_name' => 'Tim', 'last_name' => 'McCreadie', 'hometown' => 'Watertown, NY', 'weight' => 202.0, 'accolades' => 'Lucas Oil Late Model Dirt Series champion', 'transponder' => '2000114'],
        ['pill_number' => 115, 'car_number' => '19R', 'first_name' => 'Ryan', 'last_name' => 'Gustin', 'hometown' => 'Marshalltown, IA', 'weight' => 178.0, 'accolades' => 'USMTS champion', 'transponder' => '2000115'],
        ['pill_number' => 116, 'car_number' => '71k', 'first_name' => 'Hudson', 'last_name' => "O'Neal", 'hometown' => 'Martinsville, IN', 'weight' => 170.0, 'accolades' => 'Jackson 100 winner', 'transponder' => '2000116'],
        ['pill_number' => 117, 'car_number' => '99m', 'first_name' => 'Devin', 'last_name' => 'Moran', 'hometown' => 'Dresden, OH', 'weight' => 186.0, 'accolades' => null, 'transponder' => '2000117'],
        ['pill_number' => 118, 'car_number' => '17m', 'first_name' => 'Dale', 'last_name' => 'McDowell', 'hometown' => 'Chickamauga, GA', 'weight' => 194.0, 'accolades' => 'World 100 winner', 'transponder' => '2000118'],
        ['pill_number' => 119, 'car_number' => '0', 'first_name' => 'Scott', 'last_name' => 'Bloomquist', 'hometown' => 'Mooresburg, TN', 'weight' => 220.0, 'accolades' => 'Lucas Oil Late Model Dirt Series champion', 'transponder' => '2000119'],
        ['pill_number' => 120, 'car_number' => '20', 'first_name' => 'Jimmy', 'last_name' => 'Owens', 'hometown' => 'Newport, TN', 'weight' => 189.0, 'accolades' => 'World 100 winner', 'transponder' => '2000120'],
        ['pill_number' => 121, 'car_number' => '28', 'first_name' => 'Dennis', 'last_name' => 'Erb', 'hometown' => 'Carpentersville, IL', 'weight' => 183.0, 'accolades' => null, 'transponder' => '2000121'],
        ['pill_number' => 122, 'car_number' => '29', 'first_name' => 'Darrell', 'last_name' => 'Lanigan', 'hometown' => 'Union, KY', 'weight' => 197.0, 'accolades' => 'World 100 winner', 'transponder' => '2000122'],
        ['pill_number' => 123, 'car_number' => '1P', 'first_name' => 'Earl', 'last_name' => 'Pearson', 'hometown' => 'Jacksonville, FL', 'weight' => 191.0, 'accolades' => 'Lucas Oil Late Model Dirt Series champion', 'transponder' => '2000123'],
        ['pill_number' => 124, 'car_number' => '2s', 'first_name' => 'Stormy', 'last_name' => 'Scott', 'hometown' => 'Las Cruces, NM', 'weight' => 176.0, 'accolades' => null, 'transponder' => '2000124'],
        ['pill_number' => 125, 'car_number' => '96', 'first_name' => 'Tanner', 'last_name' => 'English', 'hometown' => 'Benton, KY', 'weight' => 172.0, 'accolades' => 'Lucas Oil Rookie of the Year', 'transponder' => '2000125'],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        foreach (self::Samples as $driver) {
            Competitor::query()->updateOrCreate(
                [
                    'first_name' => $driver['first_name'],
                    'last_name' => $driver['last_name'],
                ],
                [
                    'pill_number' => $driver['pill_number'],
                    'car_number' => $driver['car_number'],
                    'hometown' => $driver['hometown'],
                    'weight' => $driver['weight'],
                    'accolades' => $driver['accolades'],
                    'transponder' => $driver['transponder'],
                ],
            );
        }
    }
}
