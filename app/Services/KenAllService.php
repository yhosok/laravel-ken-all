<?php

namespace App\Services;

use App\Models\Postcode;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use SplFileObject;
use ZipArchive;

class KenAllService
{
    public const KEN_ALL_ZIP_PATH = 'https://www.post.japanpost.jp/zipcode/dl/kogaki/zip/ken_all.zip';

    public function convert(?string $outputPath = null): void
    {
        $csvFile = $this->getKenAllCsvFile();

        $outputFile = new SplFileObject($outputPath ?? storage_path('tmp/ken_all_converted.csv'), 'w+');

        $this->getCsvData($csvFile)
            ->reduce(function ($acc, array $csvLine) use ($outputFile) {

                // 空白行だと0番目にnull値が入ってる
                if (!collect($csvLine)->get(0)) {
                    return $acc;
                }

                /** @var Postcode|null $lastUnClosedPostcode 前行で町域文字列が未完（カッコが閉じていない）場合のデータ */
                /** @var Collection $lastWrittenPostcodes 重複行チェック用の近い郵便番号の出力済みデータ */
                [$lastUnClosedPostcode, $lastWrittenPostcodes] = $acc;

                $current = new Postcode($csvLine);

                // 前行で町域文字列が完結していない場合は現在行の町域をマージする
                $target = $lastUnClosedPostcode
                    ? $lastUnClosedPostcode->mergeTownArea($current)
                    : $current;

                // マージ済みでも町域文字列が完結しない場合は次行に移動
                if ($target->isUnClosedTownArea()) {
                    return [$target, $lastWrittenPostcodes];
                }

                // 近い郵便番号のデータ内で重複行チェックをして新規のものだけを出力対象とする
                $targetsToWrite = $this->postCodeWithTownArea($target)
                    ->filter(fn (Postcode $postcode)
                        => !$lastWrittenPostcodes
                            ->contains(fn (Postcode $lastWrittenPostcode) => $postcode->isSame($lastWrittenPostcode)));

                $this->write($outputFile, $targetsToWrite);

                /** @var Postcode $currentWrittenPostcode */
                $currentWrittenPostcode = $targetsToWrite->first();
                /** @var Postcode $lastWrittenPostcode */
                $lastWrittenPostcode = $lastWrittenPostcodes->first();
                // 愛知県豊橋
                $toyohashi = ['440', '441'];
                // 現在行の郵便番号が前回行と近い場合は現在行のものをマージして次へ
                if (!$currentWrittenPostcode
                    || (($lastWrittenPostcode
                            && (
                            Str::of($currentWrittenPostcode->postcode)->substr(0, 3)->is((string)Str::of($lastWrittenPostcode->postcode)->substr(0, 3))))
                        // 愛知県豊橋だけは郵便番号が前後してしまうのでこういう単位で重複チェックする
                        || (Str::of($currentWrittenPostcode->postcode)->substr(0, 3)->is($toyohashi) && Str::of($lastWrittenPostcode->postcode)->substr(0, 3)->is($toyohashi))
                    )
                ) {
                    return [null, $lastWrittenPostcodes->concat($targetsToWrite)];
                }

                return [null, $targetsToWrite];

            }, [null, collect()]);

        unlink($csvFile);
    }

    private function write(SplFileObject $outputFile, Collection $postcodes): void
    {
        $postcodes
            ->each(function (Postcode $postcode) use ($outputFile) {
                $outputFile->fwrite($postcode->toCsv()."\n");
            });
    }

    private function postCodeWithTownArea(Postcode $original): Collection
    {
        return $original->convertTownArea()
            ->map(function ($townPair) use ($original) {
                [$townArea, $townAreaKana] = $townPair;

                $postcode = new Postcode($original->toArray());

                $postcode->townArea = $townArea;
                $postcode->townAreaKana = $townAreaKana;

                return $postcode;
            });
    }

    public function getCsvData(string $csvFile): LazyCollection
    {
        return LazyCollection::make(function () use ($csvFile) {
            $encoding = 'SJIS-win';
            $file = new SplFileObject($csvFile);
            while (!$file->eof()) {
                $line = $file->fgets();
                yield str_getcsv(preg_replace(
                    '/\r\n|\r|\n/',
                    "\n",
                    mb_convert_encoding($line, 'UTF-8', $encoding)
                ));
            }
        });
    }

    public function getKenAllCsvFile(string $url = self::KEN_ALL_ZIP_PATH): string
    {
        $client = new Client();
        $zipPath = tempnam(sys_get_temp_dir(), 'ken_all') . '.zip';
        $client->get($url, ['sink' => $zipPath]);

        $tmpDir = sys_get_temp_dir() . '/' . uniqid('ken_all', true);
        if (!mkdir($tmpDir) && !is_dir($tmpDir)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $tmpDir));
        }

        $zip = new ZipArchive();

        $zip->open($zipPath);
        $zip->extractTo($tmpDir);
        $fileName = $zip->getNameIndex(0);
        $zip->close();
        unlink($zipPath);

        return "{$tmpDir}/{$fileName}";
    }
}
