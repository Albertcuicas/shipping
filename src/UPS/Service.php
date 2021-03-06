<?php
/**
 * Created by PhpStorm.
 * User: johan
 * Date: 2017-03-01
 * Time: 14:36
 */
declare(strict_types = 1);

namespace Vinnia\Shipping\UPS;

use DateTimeImmutable;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\FulfilledPromise;
use function GuzzleHttp\Promise\promise_for;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use LogicException;
use Money\Currency;
use Money\Money;
use Psr\Http\Message\ResponseInterface;
use Vinnia\Shipping\Address;
use Vinnia\Shipping\CancelPickupRequest;
use Vinnia\Shipping\Parcel;
use Vinnia\Shipping\PickupRequest;
use Vinnia\Shipping\Quote;
use Vinnia\Shipping\QuoteRequest;
use Vinnia\Shipping\ErrorFormatterInterface;
use Vinnia\Shipping\ServiceInterface;
use Vinnia\Shipping\ShipmentRequest;
use Vinnia\Shipping\ExactErrorFormatter;
use Vinnia\Shipping\Tracking;
use Vinnia\Shipping\TrackingActivity;
use Vinnia\Shipping\TrackingResult;
use Vinnia\Util\Arrays;
use Vinnia\Util\Collection;
use Vinnia\Util\Measurement\Unit;
use Vinnia\Util\Text\Xml;

class Service implements ServiceInterface
{

    const URL_TEST = 'https://wwwcie.ups.com/rest';
    const URL_PRODUCTION = 'https://onlinetools.ups.com/rest';
    const NON_SI_COUNTRIES = ['US'];

    /**
     * @var ClientInterface
     */
    private $guzzle;

    /**
     * @var Credentials
     */
    private $credentials;

    /**
     * @var string
     */
    private $baseUrl;

    /**
     * @var null|ErrorFormatterInterface
     */
    private $errorFormatter;

    /**
     * Service constructor.
     * @param ClientInterface $guzzle
     * @param Credentials $credentials
     * @param string $baseUrl
     */
    function __construct(
        ClientInterface $guzzle,
        Credentials $credentials,
        string $baseUrl = self::URL_PRODUCTION,
        ?ErrorFormatterInterface $responseFormatter = null
    )
    {
        $this->guzzle = $guzzle;
        $this->credentials = $credentials;
        $this->baseUrl = $baseUrl;
        $this->errorFormatter = $responseFormatter === null ?
            new ExactErrorFormatter() :
            $responseFormatter;
    }

    /**
     * @param QuoteRequest $request
     * @return PromiseInterface
     */
    public function getQuotes(QuoteRequest $request): PromiseInterface
    {
        $sender = $request->sender;
        $recipient = $request->recipient;

        // UPS doesn't allow us to use SI units inside some countries
        $nonSi = in_array(mb_strtoupper($sender->countryCode, 'utf-8'), self::NON_SI_COUNTRIES);
        $lengthUnit = $nonSi ? 'IN' : 'CM';
        $weightUnit = $nonSi ? 'LBS' : 'KGS';

        $parcels = array_map(function (Parcel $parcel) use ($nonSi): Parcel {
            return $parcel->convertTo(
                $nonSi ? Unit::INCH : Unit::CENTIMETER,
                $nonSi ? Unit::POUND : Unit::KILOGRAM
            );
        }, $request->parcels);

        $body = [
            'UPSSecurity' => [
                'UsernameToken' => [
                    'Username' => $this->credentials->getUsername(),
                    'Password' => $this->credentials->getPassword(),
                ],
                'ServiceAccessToken' => [
                    'AccessLicenseNumber' => $this->credentials->getAccessLicense(),
                ],
            ],
            'RateRequest' => [
                'Request' => [
                    'RequestOption' => 'Shop',
                    //'TransactionReference' => [
                    //    'CustomerContext' => '',
                    //],
                ],
                'Shipment' => [
                    'Shipper' => [
                        'Name' => '',
                        'ShipperNumber' =>  $this->credentials->getShipperNumber(),
                        'Address' => [
                            'AddressLine' => array_filter($sender->lines),
                            'City' => $sender->city,
                            'StateProvinceCode' => '',
                            'PostalCode' => $sender->zip,
                            'CountryCode' => $sender->countryCode,
                        ],
                    ],
                    'ShipTo' => [
                        'Name' => '',
                        'Address' => [
                            'AddressLine' => array_filter($recipient->lines),
                            'City' => $recipient->city,
                            'StateProvinceCode' => $recipient->state,
                            'PostalCode' => $recipient->zip,
                            'CountryCode' => $recipient->countryCode,
                        ],
                    ],
                    'ShipFrom' => [
                        'Name' => '',
                        'Address' => [
                            'AddressLine' => array_filter($sender->lines),
                            'City' => $sender->city,
                            'StateProvinceCode' => $sender->state,
                            'PostalCode' => $sender->zip,
                            'CountryCode' => $sender->countryCode,
                        ],
                    ],
                    'Package' => array_map(function (Parcel $parcel) use ($lengthUnit, $weightUnit): array {
                        return [
                            'PackagingType' => [
                                'Code' => '02',
                            ],
                            'Dimensions' => [
                                'UnitOfMeasurement' => [
                                    'Code' => $lengthUnit,
                                ],
                                'Length' => $parcel->length->format(2, '.', ''),
                                'Width' => $parcel->width->format(2, '.', ''),
                                'Height' => $parcel->height->format(2, '.', ''),
                            ],
                            'PackageWeight' => [
                                'UnitOfMeasurement' => [
                                    'Code' => $weightUnit,
                                ],
                                'Weight' => $parcel->weight->format(2, '.', ''),
                            ],
                        ];
                    }, $parcels),
                ],
            ],
        ];
        if ($this->credentials->getShipperNumber()) { 
            $body['RateRequest']['Shipment']['ShipmentRatingOptions']['NegotiatedRatesIndicator'] = '';
        }
        return $this->guzzle->requestAsync('POST', $this->baseUrl . '/Rate', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ])->then(function (ResponseInterface $response) {
            $body = json_decode((string) $response->getBody(), true);

            if (Arrays::get($body, 'RateResponse.RatedShipment') === null) {
                return new RejectedPromise($body);
            }

            $shipments = $body['RateResponse']['RatedShipment'];

            // sometimes UPS likes to return a single rate response.
            // this causes the json to appear as an object instead
            // of an array.
            $shipments = Arrays::isNumericKeyArray($shipments) ? $shipments : [$shipments];

            return array_map(function (array $shipment): Quote {
                $charges = ($this->credentials->getShipperNumber()) ? $shipment['NegotiatedRateCharges']['TotalCharge'] : $shipment['TotalCharges'];
                $amount = (int) round(((float) $charges['MonetaryValue']) * pow(10, 2));

                return new Quote(
                    'UPS',
                    (string) Arrays::get($shipment, 'Service.Code'),
                    new Money($amount, new Currency($charges['CurrencyCode']))
                );
            }, $shipments);
        });
    }

    /**
     * @inheritdoc
     */
    public function getTrackingStatus(array $trackingNumbers, array $options = []): PromiseInterface
    {
        if (count($trackingNumbers) > 1) {
            throw new LogicException("UPS only allows tracking of 1 shipment at a time.");
        }

        $trackingNo = $trackingNumbers[0] ?? '';
        $body = [
            'UPSSecurity' => [
                'UsernameToken' => [
                    'Username' => $this->credentials->getUsername(),
                    'Password' => $this->credentials->getPassword(),
                ],
                'ServiceAccessToken' => [
                    'AccessLicenseNumber' => $this->credentials->getAccessLicense(),
                ],
            ],
            'TrackRequest' => [
                'Request' => [
                    // All activities
                    'RequestOption' => '1',
                ],
                'InquiryNumber' => $trackingNo,
            ],
        ];

        return $this->guzzle->requestAsync('POST', $this->baseUrl . '/Track', [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ])->then(function (ResponseInterface $response) use ($trackingNo) {
            $body = (string) $response->getBody();
            $json = json_decode($body, true);

            if (Arrays::get($json, 'TrackResponse.Shipment') === null) {
                return [
                    new TrackingResult(TrackingResult::STATUS_ERROR, $trackingNo, $this->errorFormatter->format($body)),
                ];
            }

            $estimatedDelivery = null;
            $deliveryDetail = $json['TrackResponse']['Shipment']['DeliveryDetail'] ?? [];
            if (!empty($deliveryDetail['Type']) && 'Scheduled Delivery' === $deliveryDetail['Type']['Description']) {
                //They only supply the date so let's set time to 12 to cover most of the world
                $estimatedDelivery = DateTimeImmutable::createFromFormat(
                    'Ymd H:i:s',
                    $deliveryDetail['Date'].' 12:00:00', new \DateTimeZone('UTC')
                );
            }

            // if we're tracking a multi-piece shipment
            // we assume that the first package is the
            // master package.
            $packages = $json['TrackResponse']['Shipment']['Package'] ?? [];
            $activities = [];
            $parcels = [];
            if (!empty($packages)) {
                $package = Arrays::isNumericKeyArray($packages) ? $packages[0] : $packages;

                $activities = $package['Activity'] ?? [];

                // if there is only one activity UPS decides to not return
                // an array of activities and instead they only list one.
                // probably because they're converting from XML.
                $activities = Arrays::isNumericKeyArray($activities) ? $activities : [$activities];

                $activities = (new Collection($activities))->map(function (array $row): TrackingActivity {
                    $address = new Address(
                        '',
                        [],
                        $row['ActivityLocation']['Address']['PostalCode'] ?? '',
                        $row['ActivityLocation']['Address']['City'] ?? '',
                        '',
                        $row['ActivityLocation']['Address']['CountryCode'] ?? ''
                    );
                    $date = \DateTimeImmutable::createFromFormat('YmdHis', $row['Date'] . $row['Time']);
                    $status = $this->getStatusFromType($row['Status']['Type']);
                    $description = $row['Status']['Description'] ?? '';
                    return new TrackingActivity($status, $description, $date, $address);
                })->value();

                $parcels[] = Parcel::make(
                    // UPS does not provide any dimension information in the tracking response.
                    0.00,
                    0.00,
                    0.00,
                    (float) $package['PackageWeight']['Weight'],
                    Unit::CENTIMETER,
                    $package['PackageWeight']['UnitOfMeasurement']['Code'] === 'LBS' ?
                        Unit::POUND :
                        Unit::KILOGRAM
                );
            }

            $tracking = new Tracking('UPS', $json['TrackResponse']['Shipment']['Service']['Description'], $activities);
            $tracking->estimatedDeliveryDate = $estimatedDelivery;
            $tracking->parcels = $parcels;

            return [
                new TrackingResult(TrackingResult::STATUS_SUCCESS, $trackingNo, $body, $tracking),
            ];
        });
    }

    /**
     * @param string $type
     * @return int
     */
    private function getStatusFromType(string $type): int
    {
        $type = mb_strtoupper($type, 'utf-8');
        switch ($type) {
            case 'D':
                return TrackingActivity::STATUS_DELIVERED;
            case 'I':
            case 'P':
            case 'M':
                return TrackingActivity::STATUS_IN_TRANSIT;
            case 'X':
                return TrackingActivity::STATUS_EXCEPTION;
        }
        return TrackingActivity::STATUS_IN_TRANSIT;
    }

    /**
     * @param ShipmentRequest $request
     * @return PromiseInterface
     */
    public function createShipment(ShipmentRequest $request): PromiseInterface
    {
        // TODO: Implement createLabel() method.
    }

    /**
     * @param string $id
     * @param array $data
     * @return PromiseInterface
     */
    public function cancelShipment(string $id, array $data = []): PromiseInterface
    {
        return new FulfilledPromise(true);
    }

    /**
     * @param QuoteRequest $request
     * @return PromiseInterface promise resolved with an array of strings
     */
    public function getAvailableServices(QuoteRequest $request): PromiseInterface
    {
        return promise_for([]);
    }

    /**
     * @param string $trackingNumber
     * @return PromiseInterface
     * @throws \Exception
     */
    public function getProofOfDelivery(string $trackingNumber): PromiseInterface
    {
        throw new \Exception('Not implemented');
    }

    /**
     * @param PickupRequest $request
     * @return PromiseInterface
     * @throws \Exception
     */
    public function createPickup(PickupRequest $request): PromiseInterface
    {
        throw new \Exception('Not implemented');
    }

    /**
     * @param CancelPickupRequest $request
     * @return PromiseInterface
     * @throws \Exception
     */
    public function cancelPickup(CancelPickupRequest $request): PromiseInterface
    {
        throw new \Exception('Not implemented');
    }
}
