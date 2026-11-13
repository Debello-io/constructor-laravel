<?php

namespace ConstructorIO\Laravel\Tests\Unit;

use ConstructorIO\Laravel\Constructor;
use ConstructorIO\Laravel\Facades\Constructor as ConstructorFacade;
use ConstructorIO\Laravel\Testing\ConstructorFake;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The facade's @method list is what IDEs and static analysis see. Keep it in
 * sync with the public methods of the class it proxies, and of the fake it
 * proxies after Constructor::fake().
 */
class FacadeDocblockTest extends TestCase
{
    public function test_every_public_method_is_documented_on_the_facade(): void
    {
        $docblock = (string) (new ReflectionClass(ConstructorFacade::class))->getDocComment();
        // The return type may contain spaces (array<string, mixed>), so match lazily up to "name(".
        preg_match_all('/@method\s+static\s+.+?\s(\w+)\(/', $docblock, $matches);
        $documented = $matches[1];

        $public = array_map(
            fn (ReflectionMethod $method) => $method->getName(),
            array_filter(
                (new ReflectionClass(Constructor::class))->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $method) => ! $method->isConstructor(),
            ),
        );

        // After Constructor::fake(), the facade also proxies the fake's own
        // instance methods (stub, calls and the assert* methods).
        foreach ((new ReflectionClass(ConstructorFake::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (! $method->isStatic() && ! method_exists(Constructor::class, $method->getName())) {
                $public[] = $method->getName();
            }
        }

        sort($documented);
        sort($public);

        $this->assertSame($public, $documented, 'Update the @method list in src/Facades/Constructor.php');
    }
}
