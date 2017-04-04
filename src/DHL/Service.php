<?php
/**
 * Created by PhpStorm.
 * User: johan
 * Date: 2017-03-02
 * Time: 13:01
 */
declare(strict_types = 1);

namespace Vinnia\Shipping\DHL;

use DateTimeImmutable;
use DateTimeZone;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\RejectedPromise;
use Money\Currency;
use Money\Money;
use Psr\Http\Message\ResponseInterface;
use SimpleXMLElement;
use Vinnia\Shipping\Address;
use Vinnia\Shipping\Package;
use Vinnia\Shipping\Quote;
use Vinnia\Shipping\ServiceInterface;
use Vinnia\Shipping\Tracking;
use Vinnia\Shipping\TrackingActivity;
use Vinnia\Util\Collection;
use Vinnia\Util\Measurement\Unit;

class Service implements ServiceInterface
{

    const URL_TEST = 'https://xmlpitest-ea.dhl.com/XMLShippingServlet';
    const URL_PRODUCTION = 'https://xmlpi-ea.dhl.com/XMLShippingServlet';

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
     * DHL constructor.
     * @param ClientInterface $guzzle
     * @param Credentials $credentials
     * @param string $baseUrl
     */
    function __construct(ClientInterface $guzzle, Credentials $credentials, string $baseUrl = self::URL_PRODUCTION)
    {
        $this->guzzle = $guzzle;
        $this->credentials = $credentials;
        $this->baseUrl = $baseUrl;
    }

    /**
     * @param Address $sender
     * @param Address $recipient
     * @param Package $package
     * @param array $options
     * @return PromiseInterface
     */
    public function getQuotes(Address $sender, Address $recipient, Package $package, array $options = []): PromiseInterface
    {
        $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $package = $package->convertTo(Unit::CENTIMETER, Unit::KILOGRAM);

        // after value conversions we might get lots of decimals. deal with that
        $length = number_format($package->getLength()->getValue(), 2, '.', '');
        $width = number_format($package->getWidth()->getValue(), 2, '.', '');
        $height = number_format($package->getHeight()->getValue(), 2, '.', '');
        $weight = number_format($package->getWeight()->getValue(), 2, '.', '');

        $body = <<<EOD
<?xml version="1.0" encoding="UTF-8"?>
<p:DCTRequest xmlns:p="http://www.dhl.com"
    xmlns:p1="http://www.dhl.com/datatypes"
    xmlns:p2="http://www.dhl.com/DCTRequestdatatypes"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="http://www.dhl.com DCT-req.xsd ">
   <GetQuote>
      <Request>
         <ServiceHeader>
            <MessageTime>{$dt->format('c')}</MessageTime>
            <SiteID>{$this->credentials->getSiteID()}</SiteID>
            <Password>{$this->credentials->getPassword()}</Password>
         </ServiceHeader>
      </Request>
      <From>
         <CountryCode>{$sender->getCountry()}</CountryCode>
         <Postalcode>{$sender->getZip()}</Postalcode>
         <City>{$sender->getCountry()}</City>
      </From>
      <BkgDetails>
         <PaymentCountryCode>{$sender->getCountry()}</PaymentCountryCode>
         <Date>{$dt->format('Y-m-d')}</Date>
         <ReadyTime>PT00H00M</ReadyTime>
         <DimensionUnit>CM</DimensionUnit>
         <WeightUnit>KG</WeightUnit>
         <Pieces>
            <Piece>
               <PieceID>1</PieceID>
               <Height>{$height}</Height>
               <Depth>{$length}</Depth>
               <Width>{$width}</Width>
               <Weight>{$weight}</Weight>
            </Piece>
         </Pieces>
         <PaymentAccountNumber>{$this->credentials->getAccountNumber()}</PaymentAccountNumber>
      </BkgDetails>
      <To>
         <CountryCode>{$recipient->getCountry()}</CountryCode>
         <Postalcode>{$recipient->getZip()}</Postalcode>
         <City>{$recipient->getCity()}</City>
      </To>
   </GetQuote>
</p:DCTRequest>
EOD;

        return $this->guzzle->requestAsync('POST', $this->baseUrl, [
            'query' => [
                'isUTF8Support' => true,
            ],
            'headers' => [
                'Accept' => 'text/xml',
                'Content-Type' => 'text/xml',
            ],
            'body' => $body,
        ])->then(function (ResponseInterface $response) {
            $body = (string)$response->getBody();

            $xml = new SimpleXMLElement($body, LIBXML_PARSEHUGE);
            $qtdShip = $xml->xpath('/res:DCTResponse/GetQuoteResponse/BkgDetails/QtdShp');

            if (count($qtdShip) === 0) {
                return new RejectedPromise($body);
            }

            $qtdShip =  new Collection($qtdShip);

            // somestimes the DHL api responds with a correct response
            // without ShippingCharge values which is strange.
            return $qtdShip->filter(function (SimpleXMLElement $element): bool {
                $charge = (string) $element->{'ShippingCharge'};
                return $charge !== '';
            })->map(function (SimpleXMLElement $element): Quote {
                $amountString = (string) $element->{'ShippingCharge'};

                // the amount is a decimal string, deal with that
                $amount = (int) round(((float)$amountString) * pow(10, 2));

                $product = (string) $element->{'ProductShortName'};

                return new Quote('DHL', $product, new Money($amount, new Currency((string) $element->{'CurrencyCode'})));
            })->value();
        });
    }

    /**
     * @param string $trackingNumber
     * @param array $options
     * @return PromiseInterface
     */
    public function getTrackingStatus(string $trackingNumber, array $options = []): PromiseInterface
    {
        $body = <<<EOD
<?xml version="1.0" encoding="UTF-8"?>
<req:KnownTrackingRequest xmlns:req="http://www.dhl.com"
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:schemaLocation="http://www.dhl.com TrackingRequestKnown.xsd">
   <Request>
      <ServiceHeader>
         <SiteID>{$this->credentials->getSiteID()}</SiteID>
         <Password>{$this->credentials->getPassword()}</Password>
      </ServiceHeader>
   </Request>
   <LanguageCode>en</LanguageCode>
   <AWBNumber>{$trackingNumber}</AWBNumber>
   <LevelOfDetails>ALL_CHECK_POINTS</LevelOfDetails>
   <PiecesEnabled>S</PiecesEnabled>
</req:KnownTrackingRequest>
EOD;

        return $this->guzzle->requestAsync('POST', $this->baseUrl, [
            'query' => [
                'isUTF8Support' => true,
            ],
            'headers' => [
                'Accept' => 'text/xml',
                'Content-Type' => 'text/xml',
            ],
            'body' => $body,
        ])->then(function (ResponseInterface $response) {
            $body = (string)$response->getBody();
            $xml = new SimpleXMLElement($body, LIBXML_PARSEHUGE);

            $info = $xml->xpath('/req:TrackingResponse/AWBInfo/ShipmentInfo');

            if (!$info) {
                return new RejectedPromise($body);
            }

            $activities = (new Collection($info[0]->xpath('ShipmentEvent')))->map(function (SimpleXMLElement $element) {
                $dtString = ((string) $element->{'Date'}) . ' ' . ((string) $element->{'Time'});
                $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $dtString);

                // ServiceArea.Description is a string of format {CITY} - {COUNTRY}
                $addressParts = explode(' - ', (string) $element->{'ServiceArea'}->{'Description'});

                $address = new Address([], '', $addressParts[0] ?? '', '', $addressParts[1] ?? '');

                // the description will sometimes include the location too.
                $description = (string) $element->{'ServiceEvent'}->{'Description'};

                $status = $this->getStatusFromEventCode((string) $element->{'ServiceEvent'}->{'EventCode'});

                return new TrackingActivity($status, $description, $dt, $address);
            })->reverse()->value(); // DHL orders the events in ascending order, we want the most recent first.

            return new Tracking('DHL', (string) $info[0]->{'GlobalProductCode'}, $activities);
        });
    }

    /**
     * @param string $code
     * @return int
     */
    private function getStatusFromEventCode(string $code): int
    {
        $code = mb_strtoupper($code, 'utf-8');

        // status mappings stolen from keeptracker.
        // DHL doesn't really provide any documentation for the
        // meaning of these so we'll just have to wing it for now.
        $codeMap = [
            TrackingActivity::STATUS_DELIVERED => [
                'CC', 'BR', 'TP', 'DD', 'OK', 'DL', 'DM',
            ],
            TrackingActivity::STATUS_EXCEPTION => [
                'BL', 'HI', 'HO', 'AD', 'SP', 'IA', 'SI', 'ST', 'NA',
                'CI', 'CU', 'LX', 'DI', 'SF', 'LV', 'UV', 'HN', 'DP',
                'PY', 'PM', 'BA', 'CD', 'UD', 'HX', 'TD', 'CA', 'NH',
                'MX', 'SS', 'CS', 'CM', 'RD', 'RR', 'MS', 'MC', 'OH',
                'SC', 'WX',

                // returned to shipper
                'RT',
            ],
        ];

        foreach ($codeMap as $status => $codes) {
            if (in_array($code, $codes)) {
                return $status;
            }
        }

        return TrackingActivity::STATUS_IN_TRANSIT;
    }

    /**
     * @param Address $sender
     * @param Address $recipient
     * @param Package $package
     * @param array $options
     * @return PromiseInterface
     */
    public function createLabel(Address $sender, Address $recipient, Package $package, array $options = []): PromiseInterface
    {
        $dt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $body = <<<EOD
<?xml version="1.0" encoding="UTF-8"?>
<req:ShipmentRequest xmlns:req="http://www.dhl.com" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.dhl.com ship-val-global-req.xsd" schemaVersion="5.0">
   <Request>
      <ServiceHeader>
         <MessageTime>2002-08-20T11:28:56.000-08:00</MessageTime>
         <MessageReference>1234567890123456789012345678901</MessageReference>
         <SiteID>DServiceVal</SiteID>
         <Password>testServVal</Password>
      </ServiceHeader>
   </Request>
   <RegionCode>EU</RegionCode>
   <NewShipper>N</NewShipper>
   <LanguageCode>en</LanguageCode>
   <PiecesEnabled>Y</PiecesEnabled>
   <Billing>
      <ShipperAccountNumber>950000002</ShipperAccountNumber>
      <ShippingPaymentType>S</ShippingPaymentType>
      <BillingAccountNumber>950000002</BillingAccountNumber>
      <DutyPaymentType>R</DutyPaymentType>
   </Billing>
   <Consignee>
      <CompanyName>ABM Life Centre</CompanyName>
      <AddressLine>Central 1</AddressLine>
      <AddressLine>Changi Business Park</AddressLine>
      <City>Singapore</City>
      <Division>sg</Division>
      <PostalCode>486048</PostalCode>
      <CountryCode>SG</CountryCode>
      <CountryName>Singapore</CountryName>
      <Contact>
         <PersonName>raobeert bere</PersonName>
         <PhoneNumber>11234-325423</PhoneNumber>
         <PhoneExtension>45232</PhoneExtension>
         <FaxNumber>11234325423</FaxNumber>
         <Telex>454586</Telex>
         <Email>nl@email.com</Email>
      </Contact>
   </Consignee>
   <Commodity>
      <CommodityCode>cc</CommodityCode>
      <CommodityName>cn</CommodityName>
   </Commodity>
   <Dutiable>
      <DeclaredValue>150.00</DeclaredValue>
      <DeclaredCurrency>EUR</DeclaredCurrency>
      <ScheduleB>3002905110</ScheduleB>
      <ExportLicense>D123456</ExportLicense>
      <ShipperEIN>112233445566</ShipperEIN>
      <ShipperIDType>S</ShipperIDType>
      <ImportLicense>ImportLic</ImportLicense>
      <ConsigneeEIN>ConEIN2123</ConsigneeEIN>
      <TermsOfTrade>DAP</TermsOfTrade>
   </Dutiable>
   <ShipmentDetails>
      <NumberOfPieces>2</NumberOfPieces>
      <Pieces>
         <Piece>
            <PieceID>1</PieceID>
            <PackageType>EE</PackageType>
            <Weight>20</Weight>
            <DimWeight>1200.0</DimWeight>
            <Width>2102</Width>
            <Height>220</Height>
            <Depth>200</Depth>
         </Piece>
         <Piece>
            <PieceID>2</PieceID>
            <PackageType>EE</PackageType>
            <Weight>35</Weight>
            <DimWeight>1200.0</DimWeight>
            <Width>120</Width>
            <Height>130</Height>
            <Depth>100</Depth>
         </Piece>
      </Pieces>
      <Weight>55</Weight>
      <WeightUnit>K</WeightUnit>
      <GlobalProductCode>P</GlobalProductCode>
      <LocalProductCode>P</LocalProductCode>
      <Date>{$dt->format('Y-m-d')}</Date>
      <Contents>For testing purpose only. Please do not ship</Contents>
      <DoorTo>DD</DoorTo>
      <DimensionUnit>C</DimensionUnit>
      <InsuredAmount>50.00</InsuredAmount>
      <PackageType>EE</PackageType>
      <IsDutiable>Y</IsDutiable>
      <CurrencyCode>EUR</CurrencyCode>
   </ShipmentDetails>
   <Shipper>
      <ShipperID>190083500</ShipperID>
      <CompanyName>BP Europa SE - BP Nederland</CompanyName>
      <RegisteredAccount>272317228</RegisteredAccount>
      <AddressLine>Anchoragelaan 8</AddressLine>
      <AddressLine>LD Schiol lane</AddressLine>
      <City>Schiphol</City>
      <Division>ld</Division>
      <PostalCode>1118</PostalCode>
      <CountryCode>NL</CountryCode>
      <CountryName>Netherlands</CountryName>
      <Contact>
         <PersonName>enquiry sing</PersonName>
         <PhoneNumber>11234-325423</PhoneNumber>
         <PhoneExtension>45232</PhoneExtension>
         <FaxNumber>11234325423</FaxNumber>
         <Telex>454586</Telex>
         <Email>test@anc.com</Email>
      </Contact>
   </Shipper>
   <SpecialService>
      <SpecialServiceType>A</SpecialServiceType>
   </SpecialService>
   <SpecialService>
      <SpecialServiceType>I</SpecialServiceType>
   </SpecialService>
   <Place>
      <ResidenceOrBusiness>B</ResidenceOrBusiness>
      <CompanyName>BP Europa SE - BP Nederland</CompanyName>
      <AddressLine>Anchoragelaan 8</AddressLine>
      <AddressLine>LD Schiol lane</AddressLine>
      <City>Schiphol</City>
      <CountryCode>NL</CountryCode>
      <DivisionCode>nl</DivisionCode>
      <Division>nl</Division>
      <PostalCode>1118</PostalCode>
      <PackageLocation>reception</PackageLocation>
   </Place>
   <EProcShip>N</EProcShip>
   <LabelImageFormat>PDF</LabelImageFormat>
</req:ShipmentRequest>
EOD;

        return $this->guzzle->requestAsync('POST', $this->baseUrl, [
            'query' => [
                'isUTF8Support' => true,
            ],
            'headers' => [
                'Accept' => 'text/xml',
                'Content-Type' => 'text/xml',
            ],
            'body' => $body,
        ]);
    }
}
