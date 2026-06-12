<?php

namespace Tests\Wiring;

use App\Http\Controllers\ChatController;
use App\Runtime\AgentRuntime;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;
use Throwable;

/**
 * Category E (Wiring) — E2 constructor graph resolution.
 *
 * Force-resolves every concrete injectable class in the layers that rely
 * on implicit autowiring. If app()->make() succeeds for all of them, the
 * dependency graph is constructible — a renamed dependency or a missing
 * binding becomes a CI failure instead of a runtime fatal.
 */
class InjectableGraphTest extends TestCase
{
    /** @var list<string> directories under app/ scanned recursively */
    private const DIRECTORIES = [
        'Http/Controllers',
        'Console/Commands',
        'Runtime',
    ];

    public function test_every_injectable_class_constructs_through_the_container(): void
    {
        $classes = $this->injectables();

        // Discovery-rot guard: if the PSR-4 mapping below ever breaks the
        // loop would pass vacuously, so pin two classes we know must appear.
        $this->assertContains(ChatController::class, $classes);
        $this->assertContains(AgentRuntime::class, $classes);

        $failures = [];
        foreach ($classes as $class) {
            try {
                $this->app->make($class);
            } catch (Throwable $e) {
                $failures[] = sprintf('%s → %s: %s', $class, $e::class, $e->getMessage());
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Injectable classes that fail to construct:\n".implode("\n", $failures)
        );
    }

    /**
     * Concrete, autowirable classes discovered from the scanned directories.
     *
     * @return list<class-string>
     */
    private function injectables(): array
    {
        $classes = [];

        foreach (self::DIRECTORIES as $dir) {
            foreach (File::allFiles(app_path($dir)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
                $class = 'App\\'.str_replace('/', '\\', $dir).'\\'.$relative;

                // class_exists() is false for interfaces and traits; enums
                // and abstracts are filtered via reflection below.
                if (! class_exists($class)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isEnum()) {
                    continue;
                }

                if (! $this->isAutowirable($reflection)) {
                    continue; // DTO / value object needing scalar constructor args
                }

                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * A class is autowirable when every required constructor parameter is
     * a single class-typed parameter the container can build. Required
     * scalar / array / untyped / union parameters mean the class is a DTO
     * or value object constructed by hand — skip it.
     */
    private function isAutowirable(ReflectionClass $reflection): bool
    {
        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return true;
        }

        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isOptional()) {
                continue;
            }

            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                return false;
            }
        }

        return true;
    }
}
