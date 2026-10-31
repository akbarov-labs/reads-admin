<?php

namespace App\Support;

/**
 * Where a library is: one of Uzbekistan's fourteen regions, and within
 * Tashkent city one of its twelve districts. The site filters on these, so
 * they are picked from a list rather than typed. Districts elsewhere are
 * free text until there are libraries there to list.
 */
final class Regions
{
    public const TASHKENT_CITY = 'Toshkent shahri';

    /** @var list<string> */
    public const REGIONS = [
        self::TASHKENT_CITY,
        'Toshkent viloyati',
        'Andijon viloyati',
        'Buxoro viloyati',
        "Farg'ona viloyati",
        'Jizzax viloyati',
        'Xorazm viloyati',
        'Namangan viloyati',
        'Navoiy viloyati',
        'Qashqadaryo viloyati',
        "Qoraqalpog'iston Respublikasi",
        'Samarqand viloyati',
        'Sirdaryo viloyati',
        'Surxondaryo viloyati',
    ];

    /** @var list<string> */
    public const TASHKENT_DISTRICTS = [
        'Bektemir tumani',
        'Chilonzor tumani',
        'Mirobod tumani',
        "Mirzo Ulug'bek tumani",
        'Olmazor tumani',
        'Sergeli tumani',
        'Shayxontohur tumani',
        'Uchtepa tumani',
        'Yakkasaroy tumani',
        'Yangihayot tumani',
        'Yashnobod tumani',
        'Yunusobod tumani',
    ];

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(self::REGIONS, self::REGIONS);
    }

    /** @return array<string, string> */
    public static function tashkentDistrictOptions(): array
    {
        return array_combine(self::TASHKENT_DISTRICTS, self::TASHKENT_DISTRICTS);
    }
}
