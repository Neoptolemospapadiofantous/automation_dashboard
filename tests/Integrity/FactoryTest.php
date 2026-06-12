<?php

namespace Tests\Integrity;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use Throwable;

/**
 * Category D (Integrity) — every factory produces a persistable model.
 *
 * Factories drift when migrations add NOT NULL columns or constraints;
 * this catches the drift at the factory, before it cascades into every
 * test (and seeder) that builds fixtures from it.
 */
class FactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_factory_creates_a_persistable_model(): void
    {
        $factories = $this->factoryClasses();

        $this->assertContains(UserFactory::class, $factories, 'Factory discovery looks broken');
        $this->assertGreaterThanOrEqual(7, count($factories));

        $failures = [];
        foreach ($factories as $factoryClass) {
            try {
                $model = $factoryClass::new()->create();
                $this->assertTrue($model->exists, "{$factoryClass} did not persist its model");
            } catch (Throwable $e) {
                $failures[] = sprintf('%s → %s: %s', $factoryClass, $e::class, $e->getMessage());
            }
        }

        $this->assertSame([], $failures, "Factories that cannot persist:\n".implode("\n", $failures));
    }

    /**
     * @return list<class-string<Factory<Model>>>
     */
    private function factoryClasses(): array
    {
        $classes = [];

        foreach (File::allFiles(database_path('factories')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = 'Database\\Factories\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

            if (class_exists($class) && is_subclass_of($class, Factory::class)) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
