<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Egypt's 27 governorates, the divisions of the Live page. Reference data: safe to run on every deploy.
 * A row is found by its slug and updated in place, never deleted and re-inserted, so the ids that
 * live_streams.governorate_id points at stay valid. The model is fully guarded, hence the query builder.
 */
class GovernorateSeeder extends Seeder
{
    /** [slug, English name, Arabic name] in the order the Live page lists them (sort_order 1 to 27). */
    private const GOVERNORATES = [
        ['cairo', 'Cairo', 'القاهرة'],
        ['giza', 'Giza', 'الجيزة'],
        ['alexandria', 'Alexandria', 'الإسكندرية'],
        ['qalyubia', 'Qalyubia', 'القليوبية'],
        ['port-said', 'Port Said', 'بورسعيد'],
        ['suez', 'Suez', 'السويس'],
        ['dakahlia', 'Dakahlia', 'الدقهلية'],
        ['sharqia', 'Sharqia', 'الشرقية'],
        ['gharbia', 'Gharbia', 'الغربية'],
        ['monufia', 'Monufia', 'المنوفية'],
        ['beheira', 'Beheira', 'البحيرة'],
        ['kafr-el-sheikh', 'Kafr El Sheikh', 'كفر الشيخ'],
        ['damietta', 'Damietta', 'دمياط'],
        ['ismailia', 'Ismailia', 'الإسماعيلية'],
        ['fayoum', 'Fayoum', 'الفيوم'],
        ['beni-suef', 'Beni Suef', 'بني سويف'],
        ['minya', 'Minya', 'المنيا'],
        ['asyut', 'Asyut', 'أسيوط'],
        ['sohag', 'Sohag', 'سوهاج'],
        ['qena', 'Qena', 'قنا'],
        ['luxor', 'Luxor', 'الأقصر'],
        ['aswan', 'Aswan', 'أسوان'],
        ['red-sea', 'Red Sea', 'البحر الأحمر'],
        ['new-valley', 'New Valley', 'الوادي الجديد'],
        ['matrouh', 'Matrouh', 'مطروح'],
        ['north-sinai', 'North Sinai', 'شمال سيناء'],
        ['south-sinai', 'South Sinai', 'جنوب سيناء'],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            foreach (self::GOVERNORATES as $index => [$slug, $english, $arabic]) {
                DB::table('governorates')->updateOrInsert(
                    ['slug' => $slug],
                    ['name_en' => $english, 'name_ar' => $arabic, 'sort_order' => $index + 1],
                );
            }
        });

        $this->command?->info('Governorates: '.count(self::GOVERNORATES).' present and up to date.');
    }
}
