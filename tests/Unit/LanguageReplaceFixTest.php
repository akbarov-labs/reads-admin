<?php

namespace Tests\Unit;

use App\Support\LanguageReplaceFix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Undoing the uz/ru/en → O'zbekcha/Ruscha/Inglizcha replace, on values from the live catalogue. */
class LanguageReplaceFixTest extends TestCase
{
    /** @return array<string, array{0: string, 1: ?string, 2?: bool}> */
    public static function values(): array
    {
        return [
            'a title' => ["O'zbekchauklar hukmdori: Ikki minora", 'Uzuklar hukmdori: Ikki minora'],
            'inside a word' => ['Baqaloq sargO\'zbekchaashtlari yoxud sirli qotillik qissasi', 'Baqaloq sarguzashtlari yoxud sirli qotillik qissasi'],
            'an author' => ['StivInglizcha King', 'Stiven King'],
            'two in one name' => ["DilfO'zbekchaa XudaybergInglizchaova", 'Dilfuza Xudaybergenova'],
            'after initials' => ['H.K.AndersInglizcha', 'H.K.Andersen'],
            'a publisher' => ["HO'zbekchaur kitoblari", 'Huzur kitoblari'],
            'after a capital' => ['TRuschast and support', 'Trust and support'],
            'a short word' => ['SInglizcha borsanki', 'Sen borsanki'],
            'in capitals' => ["ZEHN TO'zbekchaOQLARI", 'ZEHN TUZOQLARI'],
            'a real word made worse' => ["O'zbekcha-Ruschascha-inglizcha lug'at", "O'zbekcha-Ruscha-inglizcha lug'at"],
            'only the stuck ones' => ["Inglizchaglish-O'zbekchabek dictionary, Inglizcha-O'zbekcha lug'at", "English-Uzbek dictionary, Inglizcha-O'zbekcha lug'at"],
            'a real word with an ending waits for a person' => ["Mahsulot ta'rifi Inglizchadan ona tilimizga", "Mahsulot ta'rifi Endan ona tilimizga", false],
            'a word on its own' => ['Inglizcha? No Problem', null],
            'a language' => ["O'zbekcha", null],
            'nothing to undo' => ['Oʻtkan kunlar', null],
        ];
    }

    #[DataProvider('values')]
    public function test_it_undoes_only_language_names_stuck_to_letters(string $value, ?string $expected, bool $certain = true): void
    {
        $fix = LanguageReplaceFix::fix($value);

        if ($expected === null) {
            $this->assertNull($fix);

            return;
        }

        $this->assertSame(['text' => $expected, 'certain' => $certain], $fix);
    }
}
