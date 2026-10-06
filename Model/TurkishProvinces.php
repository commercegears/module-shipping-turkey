<?php
/**
 * @copyright Copyright (c) Softaware Commerce (https://www.softawarecommerce.co.uk/)
 */
declare(strict_types=1);

namespace Softaware\ShippingTurkey\Model;

/**
 * Turkish provinces (il) and their plate codes, for carriers that want a city code.
 */
class TurkishProvinces
{
    private const PROVINCES = ['adana', 'adiyaman', 'afyonkarahisar', 'agri', 'amasya', 'ankara', 'antalya', 'artvin', 'aydin',
        'balikesir', 'bilecik', 'bingol', 'bitlis', 'bolu', 'burdur', 'bursa', 'canakkale', 'cankiri', 'corum', 'denizli',
        'diyarbakir', 'edirne', 'elazig', 'erzincan', 'erzurum', 'eskisehir', 'gaziantep', 'giresun', 'gumushane', 'hakkari',
        'hatay', 'isparta', 'mersin', 'istanbul', 'izmir', 'kars', 'kastamonu', 'kayseri', 'kirklareli', 'kirsehir', 'kocaeli',
        'konya', 'kutahya', 'malatya', 'manisa', 'kahramanmaras', 'mardin', 'mugla', 'mus', 'nevsehir', 'nigde', 'ordu', 'rize',
        'sakarya', 'samsun', 'siirt', 'sinop', 'sivas', 'tekirdag', 'tokat', 'trabzon', 'tunceli', 'sanliurfa', 'usak', 'van',
        'yozgat', 'zonguldak', 'aksaray', 'bayburt', 'karaman', 'kirikkale', 'batman', 'sirnak', 'bartin', 'ardahan', 'igdir',
        'yalova', 'karabuk', 'kilis', 'osmaniye', 'duzce'];

    /**
     * Plate code ("34" for İstanbul) or '' when unknown.
     */
    public static function code(string $province): string
    {
        $key = self::normalise($province);
        if ($key === 'icel') {
            $key = 'mersin';
        }
        $index = array_search($key, self::PROVINCES, true);
        return $index === false ? '' : str_pad((string)($index + 1), 2, '0', STR_PAD_LEFT);
    }

    public static function normalise(string $value): string
    {
        $value = str_replace(['İ', 'I', 'ı', 'Ş', 'ş', 'Ğ', 'ğ', 'Ü', 'ü', 'Ö', 'ö', 'Ç', 'ç'],
            ['i', 'i', 'i', 's', 's', 'g', 'g', 'u', 'u', 'o', 'o', 'c', 'c'], trim($value));
        return preg_replace('/[^a-z]/', '', strtolower($value)) ?? '';
    }
}
