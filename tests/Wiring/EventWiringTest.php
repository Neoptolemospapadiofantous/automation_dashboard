<?php

namespace Tests\Wiring;

use App\Listeners\SendWelcomeEmail;
use Illuminate\Auth\Events\Registered;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Category E (Wiring) — E3 event ↔ listener map.
 *
 * Listeners are auto-discovered by method signature; renaming a listener
 * class or its handle() type-hint silently detaches it. Both directions
 * are asserted: every registered listener class exists, and the critical
 * event pins still have their listeners attached.
 */
class EventWiringTest extends TestCase
{
    public function test_every_registered_listener_class_exists(): void
    {
        $dispatcher = $this->app->make(Dispatcher::class);

        $failures = [];
        $checked = 0;

        foreach ($dispatcher->getRawListeners() as $event => $listeners) {
            foreach ($listeners as $listener) {
                $class = $this->listenerClass($listener);
                if ($class === null) {
                    continue; // Closure listener — nothing to verify statically
                }

                $checked++;
                if (! class_exists($class)) {
                    $failures[] = "Listener {$class} for event {$event} does not exist";
                }
            }
        }

        $this->assertGreaterThan(0, $checked, 'No string listeners found — discovery looks broken');
        $this->assertSame([], $failures, "Missing listener classes:\n".implode("\n", $failures));
    }

    public function test_registered_event_has_the_send_welcome_email_listener(): void
    {
        $this->assertTrue(
            Event::hasListeners(Registered::class),
            'No listeners attached to the Registered event'
        );

        $dispatcher = $this->app->make(Dispatcher::class);
        $raw = $dispatcher->getRawListeners()[Registered::class] ?? [];

        $attached = false;
        foreach ($raw as $listener) {
            if ($this->listenerClass($listener) === SendWelcomeEmail::class) {
                $attached = true;
                break;
            }
        }

        $this->assertTrue(
            $attached,
            SendWelcomeEmail::class.' is no longer attached to '.Registered::class
            .' — auto-discovery requires the handle(Registered $event) signature'
        );
    }

    private function listenerClass(mixed $listener): ?string
    {
        if (is_string($listener)) {
            return Str::parseCallback($listener)[0];
        }

        if (is_array($listener) && isset($listener[0]) && is_string($listener[0])) {
            return $listener[0];
        }

        return null;
    }
}
