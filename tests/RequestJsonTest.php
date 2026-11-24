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
        foreach (['[1]', '[]'] as $tagLimits) {
            try {
                ArrayCodec::pack(RequestJson::decode(self::request($tagLimits)));
                self::assertTrue(false, "a list ({$tagLimits}) is not a tag_limits object");
            } catch (InvalidRequestException $error) {
                self::assertSame('wrong_type', $error->reason(), $tagLimits);
                self::assertSame('/containers/0/tag_limits', $error->field(), $tagLimits);
            }
        }
    }

    public static function testAMalformedRateTableOrHullVertexObjectIsRefusedCleanly(): void
    {
        $request = RequestJson::decode(self::request('{}'));
        $request['containers'][0]['rate_table'] = ['weight_brackets_g' => [1000]];
        self::assertThrows(InvalidRequestException::class, static fn() => ArrayCodec::pack($request));

        $scalars = RequestJson::decode(self::request('{}'));
        $scalars['items'][0] += ['shape_type' => 'convex_hull', 'hull_vertices' => [7, 7, 7, 7]];
        self::assertThrows(InvalidRequestException::class, static fn() => ArrayCodec::pack($scalars));

        $hull = RequestJson::decode(
            '{"items":[{"id":"cube","quantity":1,"weight":"10","shape_type":"convex_hull",'
            . '"hull_vertices":{"0":{"x":"0","y":"0","z":"0"}},'
            . '"dimensions":{"length":"100","width":"100","height":"100"}}],'
            . '"containers":[{"id":"box","quantity":1,"inner_dimensions":{"length":"300","width":"100","height":"100"}}],'
            . '"configuration":{"solver_profile":"fast"}}'
        );
        try {
            ArrayCodec::pack($hull);
            self::assertTrue(false, 'an object keyed from 0 is not a hull_vertices list');
        } catch (InvalidRequestException $error) {
            self::assertSame('wrong_type', $error->reason());
            self::assertSame('/items/0/hull_vertices', $error->field());
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
