<?php

namespace App\Models;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * https://www.post.japanpost.jp/zipcode/dl/readme.html
 *
 * @property string $jis
 * @property string $postcode5
 * @property string $postcode
 * @property string $prefectureKana
 * @property string $cityKana
 * @property string $townAreaKana
 * @property string $prefecture
 * @property string $city
 * @property string $townArea
 * @property string $isOneTownByMultiPostcode
 * @property string $isNeedSmallAreaAddress
 * @property string $isChome
 * @property string $isMultiTownByOnePostcode
 * @property string $updated
 * @property string $updateReason
 *
 */
class Postcode implements Arrayable
{
    private Collection $data;

    public const CSV_FIELDS = [
        'jis',
        'postcode5',
        'postcode',
        'prefectureKana',
        'cityKana',
        'townAreaKana',
        'prefecture',
        'city',
        'townArea',
        'isOneTownByMultiPostcode',
        'isNeedSmallAreaAddress',
        'isChome',
        'isMultiTownByOnePostcode',
        'updated',
        'updateReason',
    ];

    private const SHOULD_ENCLOSURE = [
        'postcode5',
        'postcode',
        'prefectureKana',
        'cityKana',
        'townAreaKana',
        'prefecture',
        'city',
        'townArea',
    ];

    public const REGEX_UN_CLOSED_PARENTHESES = '/（.*[^）]\z/u';
    public const REGEX_UN_CLOSED_PARENTHESES_KANA = '/\(.*[^\)]\z/';
    public const REGEX_IGNORE = '/以下に掲載がない場合|[市|町|村]の次に.*がくる場合|[市|町|村]一円|^甲、乙/u';
    public const REGEX_FLOOR = '/（([０-９]+階|地階・階層不明)）/u';
    public const IGNORE_FLOOR = '　地階・階層不明';
    public const REGEX_FLOOR_KANA = '/\(([0-9]+ｶｲ|ﾁｶｲ･ｶｲｿｳﾌﾒｲ)\)/u';
    public const IGNORE_FLOOR_KANA = 'ﾁｶｲ･ｶｲｿｳﾌﾒｲ';
    public const REGEX_JIWARI = '/^([^０-９第（]+)第*[０-９]+地割[、|～].*/u';
    public const REGEX_JIWARI_KANA = '/^([^0-9(]+)[0-9]+ﾁﾜﾘ[､|-].*/u';
    public const REGEX_PARENTHESES = '/（(.*)）/u';
    public const REGEX_PARENTHESES_KANA = '/\((.*)\)/u';
    public const REGEX_USE_IN_PARENTHESES = '/^[^～]*[０-９]+区/u';
    public const REGEX_USE_IN_PARENTHESES_KANA = '/^[^-]*[0-9]+ｸ/u';
    public const REGEX_IGNORE_IN_PARENTHESES = [
        '[０-９]',
        'その他',
        '^丁目$',
        '^番地$',
        '成田国際空港内',
        '次のビルを除く',
        '^全域$',
        '地区$',
        '無番地',
        '.*・.*',
        '住宅$',
        '^[ヲクチマワ]$',
        'がくる場合$',
        '甲、乙',
        '○○屋敷',
        'バッカイ',
        '[東西]余川',
        '足助高等学校',
        'キョウワマチ',
        '^奥津川$',
        '乙を除く',
        'を含む',
        '番地のみ$',
        '^小川$',
    ];

    public function __construct(array $csvLine)
    {
        $this->data = collect($csvLine);
    }

    public function __set($name, $value)
    {
        $order = $this->getCsvOrder($name);
        if ($order !== null) {
            return $this->data->put($order, $value);
        }

        throw new RuntimeException('A property "' . $name . '" does not exists.');
    }

    public function __get($name)
    {
        $order = $this->getCsvOrder($name);
        if ($order !== null) {
            return $this->data->get($order);
        }

        throw new RuntimeException('A property "' . $name . '" does not exists.');
    }

    private function getCsvOrder(string $name): ?int
    {
        return Arr::get(array_flip(self::CSV_FIELDS), $name);
    }

    public function isSame(self $postcode): bool
    {
        return collect(self::CSV_FIELDS)
            ->every(fn(string $field) => $this->{$field} === $postcode->{$field});
    }

    public function mergeTownArea(self $postcode): self
    {
        $this->townArea .= $postcode->townArea;

        // カナだけカッコなしのケースがあるため確認する
        if ($this->isUnClosedTownAreaKana()) {
            $this->townAreaKana .= $postcode->townAreaKana;
        }

        return $this;
    }

    public function isUnClosedTownArea(): bool
    {
        return Str::of($this->townArea)->test(self::REGEX_UN_CLOSED_PARENTHESES);
    }

    public function isUnClosedTownAreaKana(): bool
    {
        return Str::of($this->townAreaKana)->test(self::REGEX_UN_CLOSED_PARENTHESES_KANA);
    }

    public function convertTownArea(): Collection
    {
        $converters = [
            fn() => $this->convertIgnore(),
            fn() => $this->convertFloor(),
            fn() => $this->convertJiwari(),
            fn() => $this->convertParentheses(),
        ];

        $converted = collect($converters)
            ->reduce(function (?Collection $converted, callable $converter) {
                if ($converted) {
                    return $converted;
                }

                return $converter();
            });

        return $converted ?: collect([[$this->townArea, $this->townAreaKana]]);
    }

    private function convertIgnore(): ?Collection
    {
        if (!Str::of($this->townArea)->test(self::REGEX_IGNORE)) {
            return null;
        }

        return collect([['', '']]);
    }

    private function convertFloor(): ?Collection
    {
        if (!Str::of($this->townArea)->test(self::REGEX_FLOOR)) {
            return null;
        }

        return collect([
            [
                str_replace(self::IGNORE_FLOOR,
                    '',
                    preg_replace(
                        self::REGEX_PARENTHESES,
                        '$1',
                        preg_replace(self::REGEX_FLOOR, '　$1', $this->townArea),
                    )
                ),
                str_replace(self::IGNORE_FLOOR_KANA,
                    '',
                    preg_replace(
                        self::REGEX_PARENTHESES_KANA,
                        '$1',
                        preg_replace(self::REGEX_FLOOR_KANA, '$1', $this->townAreaKana),
                    )
                ),
            ],
        ]);
    }

    private function convertJiwari(): ?Collection
    {
        if (!Str::of($this->townArea)->test(self::REGEX_JIWARI)) {
            return null;
        }

        return collect([
            [
                preg_replace(self::REGEX_JIWARI, '$1', $this->townArea),
                //TODO 要正規表現改善。余計なもの除去した後の文字列末尾に `ﾀﾞｲ` が残ってしまう
                preg_replace(
                    '/ﾀﾞｲ$/u',
                    '',
                    preg_replace(self::REGEX_JIWARI_KANA, '$1', $this->townAreaKana),
                ),
            ],
        ]);
    }

    private function convertParentheses(): ?Collection
    {
        if (!Str::of($this->townArea)->test(self::REGEX_PARENTHESES)) {
            return null;
        }

        // 町域のカッコ部分を除去した文字列
        $parenthesesRemovedPair = [
            preg_replace(self::REGEX_PARENTHESES, '', $this->townArea),
            preg_replace(self::REGEX_PARENTHESES_KANA, '', $this->townAreaKana),
        ];

        return collect([$parenthesesRemovedPair])
            ->concat(
                $this->convertInParentheses($parenthesesRemovedPair)
                    ->map(function (Collection $townPair) {
                        [$townArea, $townAreaKana] = $townPair;
                        return [
                            preg_replace(self::REGEX_PARENTHESES, $townArea, $this->townArea),
                            preg_replace(self::REGEX_PARENTHESES_KANA, $townAreaKana, $this->townAreaKana),
                        ];
                    })
            );
    }

    private function convertInParentheses(array $parenthesesRemovedPair): Collection
    {
        // カッコ内文字列を分割
        $splitInParentheses = Str::of($this->townArea)
            ->match(self::REGEX_PARENTHESES)
            ->split('/、/');
        $splitInParenthesesKana = Str::of($this->townAreaKana)
            ->match(self::REGEX_PARENTHESES_KANA)
            ->split('/､/');

        return $splitInParentheses
            ->zip($splitInParenthesesKana)
            ->filter(function (Collection $townPair) use ($parenthesesRemovedPair) {
                [$townArea, $townAreaKana] = $townPair;
                [$baseParenthesesRemoved, $baseParenthesesRemovedKana] = $parenthesesRemovedPair;
                // 町域のカッコ部分を除去した文字列と重複しそうだったら追加しない
                if (Str::of($baseParenthesesRemoved)->length() > 1 && Str::of($townArea)->startsWith($baseParenthesesRemoved)) {
                    return false;
                }
                // 不要な文字列と思われるものをフィルタ
                return Str::of($townArea)->test(self::REGEX_USE_IN_PARENTHESES)
                    || !Str::of($townArea)->test('/' . collect(self::REGEX_IGNORE_IN_PARENTHESES)->join('|') . '/u');
            })
            ->map(function (Collection $townPair) use ($parenthesesRemovedPair) {
                // カナはカッコ内列挙が書けてる場合があるのでその際は元の値を補完する
                [$townArea, $townKana] = $townPair;
                [$baseParenthesesRemoved, $baseParenthesesRemovedKana] = $parenthesesRemovedPair;

                return collect([
                    $townArea,
                    $townKana ?: $baseParenthesesRemovedKana,
                ]);
            });
    }

    public function toArray(): array
    {
        return $this->data->all();
    }

    public function toCsv(): string
    {
        return $this->data
            ->map(function ($value, $index) {
                return in_array(self::CSV_FIELDS[$index], self::SHOULD_ENCLOSURE, true)
                    ? '"'.$value.'"'
                    : $value;
            })
            ->implode(',');
    }
}
