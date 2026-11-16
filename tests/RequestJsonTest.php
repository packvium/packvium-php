<?php
declare(strict_types=1);

namespace Packvium\Tests;

use Packvium\Serialization\ArrayCodec;
use Packvium\Serialization\InvalidRequestException;
use Packvium\Support\RequestJson;

/**
 * C-14: a container's `tag_limits` keyed by consecutive integers from 0 is a JSON object,
 * which `json_decode(..., true)` alone would turn into a list.
 */
final class RequestJsonTest extends TestCase
{
    public static function testAnObjectKeyedFromZeroIsAcceptedAsTheObjectItIs(): void
    {
        foreach (['{"0":1}' => 1, '{"0":1,"1":2}' => 1, '{"1":1,"0":2}' => 2] as $limits => $placed) {
            $result = ArrayCodec::pack(RequestJson::decode(self::request((string)$limits)));
            self::assertSame($placed, count($result['containers'][0]['placements']), (string)$limits);
        }
    }

    public static function testAJsonArrayIsStillRefusedAsNotAnObject(): void
    {
        try {
            ArrayCodec::pack(RequestJson::decode(self::request('[1]')));
            self::assertTrue(false, 'a list is not a tag_limits object');
        } catch (InvalidRequestException $error) {
            self::assertSame('wrong_type', $error->reason());
            self::assertSame('/containers/0/tag_limits', $error->field());
        }
    }

    public static function testARequestWithoutTagLimitsDecodesAsBefore(): void
    {
        $json = self::request('{}');
        self::assertSame(\json_decode($json, true), RequestJson::decode($json));
    }

    public static function testContainersThatAreNotAListAreLeftForTheCodecToRefuse(): void
    {
        foreach (['{"containers":{"box":{"tag_limits":{"0":1}}}}', '{"containers":7}', '[]', '7'] as $json) {
            self::assertSame(\json_decode($json, true), RequestJson::decode($json), $json);
        }
    }

    private static function request(string $tagLimits): string
    {
        return '{"items":[{"id":"cube","quantity":3,"weight":"10","tags":["0"],'
            . '"dimensions":{"length":"100","width":"100","height":"100"}}],'
            . '"containers":[{"id":"box","quantity":1,"tag_limits":' . $tagLimits . ','
            . '"inner_dimensions":{"length":"300","width":"100","height":"100"}}],'
            . '"configuration":{"solver_profile":"fast"}}';
    }
}
