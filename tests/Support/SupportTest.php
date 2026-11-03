<?php

declare(strict_types=1);

namespace Arcade\Tests\Support;

use Arcade\Support\Env;
use Arcade\Support\Nickname;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testNicknameIsCleanedAndTruncated(): void
    {
        $this->assertSame('Léa_2', Nickname::clean('  Léa_2 <>'));
        $this->assertSame('Bob', Nickname::clean("Bob\n\t"));
        $this->assertSame('', Nickname::clean('<>!?'));
        $this->assertSame(15, mb_strlen(Nickname::clean(str_repeat('é', 40))));
        $this->assertSame('abcdefghijklmn', Nickname::clean('abcdefghijklmn   op'), 'espaces de fin retirés après la troncature');
    }

    public function testNicknameKeyIgnoresCase(): void
    {
        $this->assertSame(Nickname::key('ÉLODIE'), Nickname::key('élodie'));
    }

    public function testEnvFallsBackToDefaults(): void
    {
        $this->assertSame(7.0, Env::number('GLOOP_TEST_UNSET_VAR', 7.0));
        putenv('GLOOP_TEST_VAR=3.5');
        $this->assertSame(3.5, Env::number('GLOOP_TEST_VAR', 1.0));
        putenv('GLOOP_TEST_VAR=-2');
        $this->assertSame(1.0, Env::number('GLOOP_TEST_VAR', 1.0), 'négatif refusé');
        putenv('GLOOP_TEST_VAR=0');
        $this->assertSame(1.0, Env::number('GLOOP_TEST_VAR', 1.0));
        $this->assertSame(0.0, Env::number('GLOOP_TEST_VAR', 1.0, allowZero: true));
        putenv('GLOOP_TEST_VAR=abc');
        $this->assertSame(1.0, Env::number('GLOOP_TEST_VAR', 1.0));
        putenv('GLOOP_TEST_VAR');
        $this->assertSame('x', Env::string('GLOOP_TEST_VAR', 'x'));
    }
}
