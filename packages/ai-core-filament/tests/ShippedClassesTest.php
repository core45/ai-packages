<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Tests;

use Core45\AiCore\Filament\Actions\AskAIAction;
use Core45\AiCore\Filament\Actions\ImproveSeoAction;
use Core45\AiCore\Filament\Actions\RunSeoAnalysisAction;
use Core45\AiCore\Filament\Actions\TranslateFromAction;
use Core45\AiCore\Filament\AiCorePlugin;
use Filament\Panel;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

class ShippedClassesTest extends TestCase
{
    /**
     * Every PHP file under src/ declares the class its path implies, and that
     * class loads.
     *
     * A merge across two forks copies files by path. A file that fails to load
     * — because it references a class that was never copied, or declares a
     * method signature its interface rejects — fails when PHP loads it, which
     * in production means the first request that touches it and in CI means
     * nothing at all unless something forces the load. This forces it.
     */
    #[Test]
    public function every_shipped_class_loads(): void
    {
        $root = __DIR__.'/../src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        $checked = 0;

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);
            $class = 'Core45\\AiCore\\Filament\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

            $this->assertTrue(
                class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class),
                "{$class} does not load (expected from {$relative})."
            );

            $checked++;
        }

        // A guard on the guard: if the walk found nothing, the assertion above
        // vacuously passed and this test proved nothing at all.
        $this->assertGreaterThan(40, $checked, 'The src/ walk found suspiciously few classes.');
    }

    /**
     * The plugin registers the four resources for models ai-core owns.
     */
    #[Test]
    public function the_plugin_registers_the_knowledge_and_prompt_resources(): void
    {
        $panel = Panel::make()->id('testing');

        AiCorePlugin::make()->register($panel);

        $this->assertCount(4, $panel->getResources());
    }

    /**
     * The generic actions are static factories, not resources: they are the
     * building blocks a host attaches to its OWN resource for a model no
     * package can know about.
     */
    #[Test]
    public function the_generic_actions_expose_a_static_factory(): void
    {
        foreach ([
            AskAIAction::class,
            ImproveSeoAction::class,
            RunSeoAnalysisAction::class,
            TranslateFromAction::class,
        ] as $class) {
            $reflection = new ReflectionClass($class);

            $this->assertTrue(
                $reflection->hasMethod('make') || $reflection->hasMethod('makeSection'),
                "{$class} exposes neither make() nor makeSection(); a host cannot attach it."
            );
        }
    }
}
