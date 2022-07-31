<?php
declare(strict_types=1);

namespace PhpWeather\HttpProvider;

use Http\Client\HttpClient;
use JsonException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PhpWeather\Common\Weather;
use PhpWeather\Common\WeatherQuery;
use PhpWeather\Exception;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AbstractProviderTest extends TestCase
{
    private MockObject|HttpClient $client;
    private MockObject|RequestFactoryInterface $requestFactory;
    private AbstractHttpProvider|MockObject $provider;

    public function setUp(): void
    {
        $this->client = $this->createMock(HttpClient::class);
        $this->requestFactory = $this->createMock(RequestFactoryInterface::class);

        $this->provider = $this->getMockForAbstractClass(AbstractHttpProvider::class, [$this->client, $this->requestFactory]);
    }

    /**
     * @throws Exception
     * @throws JsonException
     */
    public function testCurrentWeather(): void
    {
        $latitude = 47.8739259;
        $longitude = 8.0043961;
        $testQuery = WeatherQuery::create($latitude, $longitude);
        $testString = 'https://example.com/';

        $testWeather = (new Weather())
            ->setLatitude($latitude)
            ->setLongitude($longitude);

        $request = $this->createMock(RequestInterface::class);

        $this->requestFactory->expects(self::once())->method('createRequest')->with('GET', $testString)->willReturn($request);

        $responseBody = [];
        $responseBodyString = json_encode($responseBody, JSON_THROW_ON_ERROR);
        $response = $this->createMock(ResponseInterface::class);
        $response->expects(self::once())->method('getBody')->willReturn($responseBodyString);
        $this->client->expects(self::once())->method('sendRequest')->with($request)->willReturn($response);

        $this->provider->expects(self::once())->method('getCurrentWeatherQueryString')->with($testQuery)->willReturn($testString);
        $this->provider->expects(self::once())->method('mapRawData')->with($latitude, $longitude, $responseBody)->willReturn($testWeather);

        $currentWeather = $this->provider->getCurrentWeather($testQuery);
        self::assertSame($testWeather, $currentWeather);
    }
}