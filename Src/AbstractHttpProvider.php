<?php
/** @noinspection PhpPluralMixedCanBeReplacedWithArrayInspection */
declare(strict_types=1);

namespace PhpWeather\HttpProvider;

use Http\Client\HttpClient;
use Http\Discovery\Psr17FactoryDiscovery;
use JsonException;
use PhpWeather\Exception\ClientException;
use PhpWeather\Exception\InvalidCredentials;
use PhpWeather\Exception\NoWeatherData;
use PhpWeather\Exception\QuotaExceeded;
use PhpWeather\Exception\ServerException;
use PhpWeather\Exception\WeatherException;
use PhpWeather\Provider;
use PhpWeather\Weather;
use PhpWeather\WeatherCollection;
use PhpWeather\WeatherQuery;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;

abstract class AbstractHttpProvider implements Provider
{
    protected HttpClient $client;
    protected RequestFactoryInterface $requestFactory;

    /**
     * @param  HttpClient  $client
     * @param  RequestFactoryInterface|null  $requestFactory
     */
    public function __construct(HttpClient $client, ?RequestFactoryInterface $requestFactory = null)
    {
        $this->client = $client;
        $this->requestFactory = $requestFactory ?: Psr17FactoryDiscovery::findRequestFactory();
    }

    public function getCurrentWeather(WeatherQuery $query): Weather
    {
        $queryString = $this->getCurrentWeatherQueryString($query);
        $rawResponse = $this->getRawResponse($queryString);

        $mappedRawdata = $this->mapRawData(
            $query->getLatitude(),
            $query->getLongitude(),
            $rawResponse,
            Weather::CURRENT
        );
        $currentWeather = null;
        if ($mappedRawdata instanceof WeatherCollection) {
            $currentWeather = $mappedRawdata->getCurrentWeather();
        }
        if ($mappedRawdata instanceof Weather) {
            $currentWeather = $mappedRawdata;
        }
        if ($currentWeather === null) {
            throw new NoWeatherData();
        }

        return $currentWeather;
    }

    public function getForecast(WeatherQuery $query): WeatherCollection
    {
        $queryString = $this->getForecastWeatherQueryString($query);
        $rawResponse = $this->getRawResponse($queryString);

        $weatherData = $this->mapRawData(
            $query->getLatitude(),
            $query->getLongitude(),
            $rawResponse,
            Weather::FORECAST
        );

        return $this->ensureWeatherCollection($weatherData);
    }

    public function getHistorical(WeatherQuery $query): Weather
    {
        $queryString = $this->getHistoricalWeatherQueryString($query);
        $rawResponse = $this->getRawResponse($queryString);

        $mappedRawdata = $this->mapRawData(
            $query->getLatitude(),
            $query->getLongitude(),
            $rawResponse,
            Weather::HISTORICAL
        );

        $historicalWeather = null;
        if ($mappedRawdata instanceof WeatherCollection) {
            $dateTime = $query->getDateTime();
            if ($dateTime !== null) {
                $historicalWeather = $mappedRawdata->getClosest($dateTime);
            }
        }
        if ($mappedRawdata instanceof Weather) {
            $historicalWeather = $mappedRawdata;
        }
        if ($historicalWeather === null) {
            throw new NoWeatherData();
        }

        return $historicalWeather;

    }

    public function getHistoricalTimeLine(WeatherQuery $query): WeatherCollection
    {
        $queryString = $this->getHistoricalTimeLineWeatherQueryString($query);
        $rawResponse = $this->getRawResponse($queryString);

        $historicalData = $this->mapRawData(
            $query->getLatitude(),
            $query->getLongitude(),
            $rawResponse,
            Weather::HISTORICAL
        );

        return $this->ensureWeatherCollection($historicalData);
    }

    abstract protected function mapUnits(string $units): string;

    abstract protected function getCurrentWeatherQueryString(WeatherQuery $query): string;

    abstract protected function getForecastWeatherQueryString(WeatherQuery $query): string;

    abstract protected function getHistoricalWeatherQueryString(WeatherQuery $query): string;

    abstract protected function getHistoricalTimeLineWeatherQueryString(WeatherQuery $query): string;

    /**
     * @param  float  $latitude
     * @param  float  $longitude
     * @param  array<mixed>  $rawData
     * @param  string|null  $type
     * @return Weather|WeatherCollection
     */
    abstract protected function mapRawData(float $latitude, float $longitude, array $rawData, ?string $type = null): Weather|WeatherCollection;

    /**
     * @param  string  $queryString
     * @return array<mixed>
     * @throws ClientException
     * @throws InvalidCredentials
     * @throws QuotaExceeded
     * @throws ServerException
     * @throws WeatherException
     */
    private function getRawResponse(string $queryString): array
    {
        $request = $this->getRequest('GET', $queryString);
        $response = $this->getParsedResponse($request);
        try {
            return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new WeatherException($e->getMessage(), $e->getCode(), $e);
        }

    }

    protected function getRequest(string $method, string $url): RequestInterface
    {
        return $this->requestFactory->createRequest($method, $url);
    }

    /**
     * @param  RequestInterface  $request
     * @return string
     * @throws ClientException
     * @throws InvalidCredentials
     * @throws QuotaExceeded
     * @throws ServerException
     */
    private function getParsedResponse(RequestInterface $request): string
    {
        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new ClientException($e->getMessage(), $e->getCode(), $e);
        }

        $statusCode = $response->getStatusCode();
        if (401 === $statusCode || 403 === $statusCode) {
            throw new InvalidCredentials();
        }

        if (429 === $statusCode) {
            throw new QuotaExceeded();
        }

        if ($statusCode >= 300) {
            throw new ServerException();
        }

        $body = (string)$response->getBody();
        if ('' === $body) {
            throw new ServerException();
        }

        return $body;
    }

    /**
     * @param  WeatherCollection|Weather  $weatherData
     * @return WeatherCollection
     */
    private function ensureWeatherCollection(WeatherCollection|Weather $weatherData): WeatherCollection
    {
        if ($weatherData instanceof Weather) {
            $weatherCollection = new \PhpWeather\Common\WeatherCollection();
            $weatherCollection->add($weatherData);

            return $weatherCollection;
        }

        return $weatherData;
    }

}