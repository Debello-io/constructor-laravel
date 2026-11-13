<?php

namespace ConstructorIO\Laravel\Tests\Unit;

use ConstructorIO\Laravel\Constructor;
use ConstructorIO\Laravel\Facades\Constructor as ConstructorFacade;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The facade's @method list is what IDEs and static analysis see. Keep it in
 * sync with the public methods of the class it proxies.
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

        sort($documented);
        sort($public);

        $this->assertSame($public, $documented, 'Update the @method list in src/Facades/Constructor.php');
    }
}
