<?php

namespace Database\Seeders;

use App\Models\Option;
use App\Models\Review;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Semua data di sini FIKTIF, hanya untuk demo.
        $admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'admin@terserah.test', 'password' => 'password', 'role' => 'admin']);
        $amy   = User::create(['name' => 'Amy', 'username' => 'amy', 'email' => 'amy@terserah.test', 'password' => 'password', 'role' => 'user']);
        $budi  = User::create(['name' => 'Budi', 'username' => 'budi', 'email' => 'budi@terserah.test', 'password' => 'password', 'role' => 'user']);
        $citra = User::create(['name' => 'Citra', 'username' => 'citra', 'email' => 'citra@terserah.test', 'password' => 'password', 'role' => 'user']);

        $data = [
            ['Warung Nasi Bu Tini (fiktif)', 'makanan', 'Jl. Contoh No. 1', -6.2000, 106.8167, 1, ['murah', 'nasi', 'halal', 'santai']],
            ['Kedai Kopi Senja (fiktif)', 'makanan', 'Jl. Contoh No. 2', -6.2100, 106.8200, 2, ['kopi', 'nyaman', 'wifi', 'halal']],
            ['Sate Pak Joko (fiktif)', 'makanan', 'Jl. Contoh No. 3', -6.1900, 106.8300, 2, ['sate', 'halal', 'ramai']],
            ['Ramen Kita (fiktif)', 'makanan', 'Jl. Contoh No. 4', -6.2200, 106.8100, 3, ['ramen', 'jepang', 'nyaman']],
            ['Resto Vegan Hijau (fiktif)', 'makanan', 'Jl. Contoh No. 5', -6.2300, 106.8400, 3, ['vegan', 'sehat', 'nyaman']],
            ['Film: Petualangan Bintang (fiktif)', 'film', null, null, null, 2, ['petualangan', 'keluarga']],
            ['Film: Malam Sunyi (fiktif)', 'film', null, null, null, 2, ['horor', 'tegang']],
            ['Film: Tawa Sore (fiktif)', 'film', null, null, null, 2, ['komedi', 'ringan']],
            ['Liburan ke Pantai Biru (fiktif)', 'liburan', null, null, null, 3, ['pantai', 'santai', 'alam']],
            ['Liburan ke Gunung Hijau (fiktif)', 'liburan', null, null, null, 2, ['gunung', 'alam', 'hemat']],
            ['Hadiah: Buku Catatan (fiktif)', 'hadiah', null, null, null, 1, ['murah', 'praktis']],
            ['Hadiah: Headset (fiktif)', 'hadiah', null, null, null, 3, ['teknologi', 'musik']],
        ];

        $options = [];
        foreach ($data as [$title, $cat, $addr, $lat, $lng, $price, $tags]) {
            $options[] = Option::create([
                'created_by' => $admin->id, 'title' => $title, 'description' => 'Data contoh untuk demo.',
                'category' => $cat, 'address' => $addr, 'lat' => $lat, 'lng' => $lng,
                'price_level' => $price, 'tags' => $tags,
            ]);
        }

        foreach ($options as $i => $opt) {
            foreach ([$amy, $budi, $citra] as $j => $u) {
                Review::create([
                    'option_id' => $opt->id, 'user_id' => $u->id,
                    'rating' => 3 + (($i + $j) % 3), 'comment' => 'Ulasan contoh.',
                ]);
            }
        }

        $room = Room::create([
            'owner_id' => $amy->id, 'name' => 'Makan siang tim', 'category' => 'makanan',
            'code' => 'TRSRH1', 'use_location' => true, 'status' => 'open',
            'weights' => ['preference' => 0.3, 'rating' => 0.25, 'price' => 0.2, 'distance' => 0.25],
        ]);

        $room->members()->attach($amy->id, ['lat' => -6.2050, 'lng' => 106.8170, 'budget_max' => 40000, 'pref_text' => 'pengen yang halal dan murah']);
        $room->members()->attach($budi->id, ['lat' => -6.2150, 'lng' => 106.8250, 'budget_max' => 60000, 'pref_text' => 'suka kopi dan tempat nyaman']);
        $room->members()->attach($citra->id, ['lat' => -6.1950, 'lng' => 106.8300, 'budget_max' => 80000, 'pref_text' => 'bebas, asal nyaman']);
        $room->options()->attach(collect($options)->take(5)->pluck('id'));
    }
}
