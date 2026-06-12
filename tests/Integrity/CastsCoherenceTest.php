<?php

namespace Tests\Integrity;

use App\Models\Agent;
use App\Runtime\Models\KbDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use Tests\TestCase;

/**
 * Category D (Integrity) — model casts/fillable ↔ schema coherence.
 *
 * Every $fillable key and every cast key must exist as a real column.
 * This is the test that would have caught the dropped-voiceflow-columns
 * bug: the migration dropped columns the models (and seeder) still wrote.
 */
class CastsCoherenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_fillable_and_cast_key_is_a_schema_column(): void
    {
        $models = $this->modelClasses();

        $this->assertContains(Agent::class, $models, 'Model discovery looks broken');
        $this->assertContains(KbDocument::class, $models, 'Runtime model discovery looks broken');

        $failures = [];

        foreach ($models as $class) {
            /** @var Model $model */
            $model = new $class;
            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                $failures[] = "{$class} → table '{$table}' does not exist";

                continue;
            }

            $columns = array_flip(Schema::getColumnListing($table));

            foreach ($model->getFillable() as $attribute) {
                if (! isset($columns[$attribute])) {
                    $failures[] = "{$class} → \$fillable '{$attribute}' is not a column on '{$table}'";
                }
            }

            foreach (array_keys($model->getCasts()) as $attribute) {
                if (! isset($columns[$attribute])) {
                    $failures[] = "{$class} → cast '{$attribute}' is not a column on '{$table}'";
                }
            }
        }

        $this->assertSame([], $failures, "Model attributes without backing columns:\n".implode("\n", $failures));
    }

    /**
     * Concrete Eloquent models in app/Models + app/Runtime/Models.
     *
     * @return list<class-string<Model>>
     */
    private function modelClasses(): array
    {
        $map = [
            'App\\Models' => app_path('Models'),
            'App\\Runtime\\Models' => app_path('Runtime/Models'),
        ];

        $classes = [];

        foreach ($map as $namespace => $path) {
            foreach (File::allFiles($path) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $class = $namespace.'\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());

                if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                    continue;
                }

                if ((new ReflectionClass($class))->isAbstract()) {
                    continue;
                }

                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }
}
