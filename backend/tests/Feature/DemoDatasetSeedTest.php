<?php

namespace Tests\Feature;

use App\Models\Section;
use App\Models\Subject;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The demo dataset as the README documents it.
 *
 * The documented sequence is `php artisan migrate --seed` followed by
 * `php artisan db:seed --class=DemoDataSeeder`, and nothing else. That second
 * command creates the demo catalogue and the BIT sections, so it has to finish
 * the same pass `DatabaseSeeder` finishes with — classification, access grants
 * and the section claim — or every demo subject is left `unclassified`.
 *
 * An unclassified subject is schedulable nowhere, so the failure this pins down
 * is not cosmetic: the preflight fails closed and generation answers 422 for
 * every demo section, i.e. the documented flow produces a demo that cannot
 * demonstrate anything. The assertions run against the preflight verdict rather
 * than the seeder's internals, because that verdict is the gate the deployment
 * actually consults.
 */
class DemoDatasetSeedTest extends TestCase
{
    use RefreshDatabase;

    /** The sequence the README documents must end READY, not fail closed. */
    #[Test]
    public function it_leaves_the_documented_demo_flow_ready(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $exitCode = Artisan::call('departments:preflight');
        $output = Artisan::output();

        $this->assertSame(
            0,
            $exitCode,
            "The documented demo flow should leave the database ready to generate from.\n\n".$output
        );
        $this->assertStringNotContainsString('NOT READY', $output);
        $this->assertStringContainsString('READY', $output);
    }

    /** Every subject the demo creates carries a review outcome. */
    #[Test]
    public function it_leaves_no_demo_subject_unclassified(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $unclassified = Subject::query()
            ->where('classification', Subject::CLASSIFICATION_UNCLASSIFIED)
            ->pluck('code');

        $this->assertSame(
            [],
            $unclassified->all(),
            'The demo catalogue is the reviewed curriculum, so nothing it creates may stay unreviewed.'
        );
    }

    /** The demo sections belong to the department whose prefix they carry. */
    #[Test]
    public function it_claims_every_demo_section(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $unclaimed = Section::query()
            ->whereNull('department_id')
            ->pluck('name');

        $this->assertSame(
            [],
            $unclaimed->all(),
            'A section with no owning department cannot hold a major subject of that department.'
        );
    }
}
