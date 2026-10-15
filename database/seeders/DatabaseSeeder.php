<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Safe to run in production: reference data only, never users or demo content.
 * Demo/dev content lives in DemoContentSeeder and must be invoked explicitly.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(CategorySeeder::class);
        // Creates a static page only when its slug is new, so an editor's changes survive every deploy.
        $this->call(PageSeeder::class);
        // The Live page's divisions; matched by slug and updated in place, so ids and the broadcasts using them survive.
        $this->call(GovernorateSeeder::class);
    }
}
