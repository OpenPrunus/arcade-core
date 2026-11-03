<?php

declare(strict_types=1);

namespace Arcade\Tests\Support;

use Arcade\Support\AtomicFile;
use Arcade\Support\Env;
use Arcade\Support\Nickname;
use Arcade\Support\StdoutLogger;
use Arcade\Support\SystemClock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BehaviourTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function names(): array
    {
        return [
            'simple' => ['Alice', 'Alice'],
            'espaces autour' => ['  Bob  ', 'Bob'],
            'accents conservés' => ['gnié', 'gnié'],
            'caractères permis' => ['a_b-c.d e', 'a_b-c.d e'],
            'html retiré' => ['<img src=x>', 'img srcx'],
            'script retiré' => ['<b>Bob</b>', 'bBobb'],
            'html + troncature' => ['<img src=x onerror=1>', 'img srcx onerro'],
            'html + troncature (serveur)' => ['<img src=x onerror=1>' . 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz', 'img srcx onerro'],
            'emoji retirés' => ['Poule🐔', 'Poule'],
            'tronqué à 15' => [str_repeat('z', 40), str_repeat('z', 15)],
            'tronqué sans espace' => [str_repeat('a', 14) . ' bbb', str_repeat('a', 14)],
            'vide' => ['', ''],
            'que du symbole' => ['<>/\\', ''],
            'unicode multi-octet' => [str_repeat('é', 20), str_repeat('é', 15)],
        ];
    }

    #[DataProvider('names')]
    public function testCleanName(string $raw, string $expected): void
    {
        $this->assertSame($expected, Nickname::clean($raw));
    }

    public function testNicknameKeyIsCaseInsensitiveWithUnicode(): void
    {
        $this->assertSame(Nickname::key('GNIÉ'), Nickname::key('gnié'));
    }

    public function testEnvNumberUsesEnvironmentOnlyWhenPositive(): void
    {
        $k = 'ARCADE_TEST_' . bin2hex(random_bytes(3));
        $this->assertSame(7.0, Env::number($k, 7));
        putenv("$k=2.5");
        $this->assertSame(2.5, Env::number($k, 7));
        putenv("$k=0");
        $this->assertSame(7.0, Env::number($k, 7), 'zéro ignoré');
        putenv("$k=-3");
        $this->assertSame(7.0, Env::number($k, 7), 'négatif ignoré');
        putenv("$k=abc");
        $this->assertSame(7.0, Env::number($k, 7), 'non numérique ignoré');
        putenv("$k=0");
        $this->assertSame(0.0, Env::number($k, 7, true), 'avec allowZero, 0 est une valeur valide');
        putenv("$k=-2");
        $this->assertSame(7.0, Env::number($k, 7, true), 'mais jamais un négatif');
        putenv("$k=abc");
        $this->assertSame(7.0, Env::number($k, 7, true));
        putenv("$k=1.5");
        $this->assertSame(1.5, Env::number($k, 7, true));
        putenv($k);
    }

    public function testAtomicFileWritesAndReadsJson(): void
    {
        $dir = sys_get_temp_dir() . '/arcade-atomic-' . bin2hex(random_bytes(4));
        mkdir($dir);
        try {
            $file = "$dir/data.json";
            $this->assertNull(AtomicFile::readJson($file), 'absent');
            $this->assertTrue(AtomicFile::write($file, '{"a":1}'));
            $this->assertSame(['a' => 1], AtomicFile::readJson($file));
            $this->assertFileDoesNotExist("$file.tmp");
            file_put_contents($file, '{ cassé');
            $this->assertNull(AtomicFile::readJson($file), 'illisible');
            $this->assertFalse(@AtomicFile::write('/chemin/inexistant/f.json', '{}'));
        } finally {
            array_map('unlink', glob("$dir/*"));
            rmdir($dir);
        }
    }

    public function testSystemClockGivesTheCurrentTime(): void
    {
        $clock = new SystemClock();
        $this->assertEqualsWithDelta(microtime(true), SystemClock::seconds($clock), 1.0);
    }

    public function testStdoutLoggerInterpolatesPlaceholders(): void
    {
        $out = fopen('php://memory', 'w+');
        (new StdoutLogger($out))->info('+ {name} (#{id}) {list}', ['name' => 'gnié', 'id' => 4, 'list' => [1, 2]]);
        rewind($out);
        $this->assertMatchesRegularExpression('/^\[\d\d:\d\d:\d\d\] \+ gnié \(#4\) \[1,2\]\n$/', (string) stream_get_contents($out));
    }
}
