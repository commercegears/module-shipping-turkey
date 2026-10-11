<?php
/**
 * @copyright Copyright (c) CommerceGears (https://www.commercegearscommerce.co.uk/)
 */
declare(strict_types=1);

namespace CommerceGears\ShippingTurkey\Model\Adapter;

use CommerceGears\ShippingCore\Api\Data\ConnectionInterface;
use CommerceGears\ShippingCore\Exception\CarrierException;
use CommerceGears\ShippingCore\Model\Carrier\AbstractAdapter;
use CommerceGears\ShippingCore\Model\Carrier\Data\Address;
use CommerceGears\ShippingCore\Model\Carrier\Data\Label;
use CommerceGears\ShippingCore\Model\Carrier\Data\ShipmentRequest;
use CommerceGears\ShippingCore\Model\Carrier\Data\ShipmentResult;
use CommerceGears\ShippingCore\Model\Carrier\Data\TrackingEvent;
use CommerceGears\ShippingCore\Model\Carrier\Data\TrackingResult;
use CommerceGears\ShippingCore\Api\CredentialsHelpInterface;

/**
 * Aras Kargo (Türkiye): order web service (ASMX SOAP, namespace http://tempuri.org/) for SetOrder, GetArasBarcode
 * and CancelDispatch; tracking through the separate "ArasCargoIntegrationService" query service (needs an XML
 * service user and the customer code from esasweb.araskargo.com.tr).
 *
 * Labels: GetArasBarcode returns one JPEG per piece (merged into a PDF by the module) plus Zebra EPL.
 */
class Aras extends AbstractAdapter implements CredentialsHelpInterface
{
    public const CODE = 'aras';
    private const TEST_URL = 'https://customerservicestest.araskargo.com.tr/arascargoservice/arascargoservice.asmx';
    private const LIVE_URL = 'https://customerws.araskargo.com.tr/arascargoservice.asmx';
    private const QUERY_URL = 'https://customerservices.araskargo.com.tr/ArasCargoCustomerIntegrationService/ArasCargoIntegrationService.svc';

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getTitle(): string
    {
        return 'Aras Kargo';
    }

    public function isBeta(): bool
    {
        // Order, label and cancel are tested against the Aras test system; tracking is beta.
        return false;
    }

    public function getCapabilities(): array
    {
        // Multi-piece orders are accepted by SetOrder, but GetArasBarcode returned no label for them on the test
        // system, so one parcel per label for now.
        return [self::CAP_SHIPMENT, self::CAP_CANCEL, self::CAP_TRACKING, self::CAP_COD, self::CAP_DESI];
    }

    public function getCredentialFields(): array
    {
        return [
            ['code' => 'username', 'label' => (string)__('Web Service User Name'), 'type' => 'text', 'required' => true,
                'notice' => (string)__('SetOrder user from your Aras branch.'),
                'sandbox' => (string)__('the integration test user from Aras')],
            ['code' => 'password', 'label' => (string)__('Web Service Password'), 'type' => 'password', 'required' => true],
            ['code' => 'customer_code', 'label' => (string)__('Customer Code (Tracking)'), 'type' => 'text',
                'notice' => (string)__('Your Aras customer number; needed for tracking.')],
            ['code' => 'query_username', 'label' => (string)__('XML Service User (Tracking)'), 'type' => 'text',
                'notice' => (string)__('Created in esasweb.araskargo.com.tr > Entegrasyon > XML Servisleri.')],
            ['code' => 'query_password', 'label' => (string)__('XML Service Password'), 'type' => 'password'],
        ];
    }

    public function getCredentialsHelp(): string
    {
        return (string)__('Web service user, password and customer code come from your Aras Kargo branch; tracking needs an XML service user created in esasweb.araskargo.com.tr > Entegrasyon > XML Servisleri.');
    }

    public function getSandboxHelp(): string
    {
        return (string)__('On: the Aras test web service with the integration test user from Aras; test shipments are not collected. Off: your live web service user.');
    }

    public function getOptionFields(): array
    {
        return [
            ['code' => 'payor', 'label' => (string)__('Shipping Paid By'), 'type' => 'select',
                'options' => ['1' => (string)__('Sender (contract)'), '2' => (string)__('Recipient')], 'default' => '1'],
            ['code' => 'cod_collection', 'label' => (string)__('Cash on Delivery Collected As'), 'type' => 'select',
                'options' => ['0' => (string)__('Cash'), '1' => (string)__('Card')], 'default' => '0'],
        ];
    }

    public function getServices(): array
    {
        return ['standard' => 'Aras Standart Kargo'];
    }

    public function getLabelFormats(): array
    {
        return [Label::FORMAT_PDF => (string)__('PDF (from the Aras label image)')];
    }

    public function getTrackingUrl(string $trackingNumber, string $countryId = '', string $locale = ''): string
    {
        return 'https://kargotakip.araskargo.com.tr/mainpage.aspx?code=' . rawurlencode($trackingNumber);
    }

    public function testConnection(ConnectionInterface $connection): string
    {
        // GetArasBarcode with an unknown code: 1001 (not found) = credentials valid, 999 = wrong user/password.
        $body = '<GetArasBarcode xmlns="http://tempuri.org/"><Username>' . $this->xml($this->credential($connection, 'username', 'User name'))
            . '</Username><Password>' . $this->xml($this->credential($connection, 'password', 'Password'))
            . '</Password><integrationCode>SWTEST' . time() . '</integrationCode></GetArasBarcode>';
        $response = $this->soap($this->call('test_connection', $connection), $this->url($connection), $body, '',
            'http://tempuri.org/GetArasBarcode');
        $code = (string)($response->xpath('.//GetArasBarcodeResult/ResultCode')[0] ?? '');
        if ($code !== '1001' && $code !== '0') {
            throw new CarrierException(__('Aras: %1', trim((string)($response->xpath('.//GetArasBarcodeResult/Message')[0] ?? $code))));
        }
        return (string)__('Aras %1 web service accepted the credentials.', $connection->isSandbox() ? 'test' : 'live');
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $connection = $request->connection;
        $user = $this->credential($connection, 'username', 'User name');
        $password = $this->credential($connection, 'password', 'Password');
        $code = $this->integrationCode($request->reference);
        $recipient = $request->recipient;
        [$cityName, $townName] = $this->cityTown($recipient);
        $pieces = '';
        $totalDesi = 0.0;
        foreach ($request->parcels as $i => $parcel) {
            $desi = $parcel->getDesi() ?: $parcel->weightKg;
            $totalDesi += $desi;
            $pieces .= '<PieceDetail><VolumetricWeight>' . $this->num($desi) . '</VolumetricWeight><Weight>' . $this->num($parcel->weightKg)
                . '</Weight><BarcodeNumber>' . $this->xml($code . ($i + 1)) . '</BarcodeNumber><ProductNumber>'
                . $this->xml($this->ascii($request->reference, 20)) . '</ProductNumber><Description>'
                . $this->xml($this->clean($request->contentsDescription, 50)) . '</Description></PieceDetail>';
        }
        $cod = $request->codAmount !== null && $request->codAmount > 0;
        $order = '<UserName>' . $this->xml($user) . '</UserName><Password>' . $this->xml($password) . '</Password>'
            . '<TradingWaybillNumber>' . $this->xml($code) . '</TradingWaybillNumber>'
            . '<InvoiceNumber>' . $this->xml($code) . '</InvoiceNumber>'
            . '<IntegrationCode>' . $this->xml($code) . '</IntegrationCode>'
            . '<ReceiverName>' . $this->xml($this->clean(trim($recipient->name . ($recipient->company !== '' ? ' - ' . $recipient->company : '')), 100)) . '</ReceiverName>'
            . '<ReceiverAddress>' . $this->xml($this->clean($recipient->getStreetFull(), 250)) . '</ReceiverAddress>'
            . '<ReceiverPhone1>' . $this->xml($this->phone($recipient->phone)) . '</ReceiverPhone1>'
            . '<ReceiverCityName>' . $this->xml($cityName) . '</ReceiverCityName>'
            . '<ReceiverTownName>' . $this->xml($townName) . '</ReceiverTownName>'
            . '<VolumetricWeight>' . $this->num($totalDesi) . '</VolumetricWeight>'
            . '<Weight>' . $this->num($request->getTotalWeight()) . '</Weight>'
            . '<PieceCount>' . count($request->parcels) . '</PieceCount>'
            . '<IsCod>' . ($cod ? '1' : '0') . '</IsCod>'
            . ($cod ? '<CodAmount>' . $this->num((float)$request->codAmount) . '</CodAmount><CodCollectionType>'
                . $this->xml($connection->getOption('cod_collection', '0')) . '</CodCollectionType><CodBillingType>0</CodBillingType>' : '')
            . '<PayorTypeCode>' . $this->xml($connection->getOption('payor', '1')) . '</PayorTypeCode>'
            . '<IsWorldWide>0</IsWorldWide>'
            . '<PieceDetails>' . $pieces . '</PieceDetails>';
        $body = '<SetOrder xmlns="http://tempuri.org/"><orderInfo><Order>' . $order . '</Order></orderInfo><userName>'
            . $this->xml($user) . '</userName><password>' . $this->xml($password) . '</password></SetOrder>';
        $response = $this->soap($this->call('create_shipment', $connection, $request->reference), $this->url($connection), $body, '',
            'http://tempuri.org/SetOrder', '', ['retries' => 0]);
        $result = $response->xpath('.//OrderResultInfo')[0] ?? null;
        if (!$result || (string)$result->ResultCode !== '0') {
            throw new CarrierException(__('Aras rejected the order: %1 (%2)', (string)($result->ResultMessage ?? ''), (string)($result->ResultCode ?? '?')));
        }
        try {
            return $this->fetchLabel($connection, $code);
        } catch (CarrierException $e) {
            // Do not leave an order without a label at Aras.
            try {
                $this->cancelShipment($connection, $code, []);
            } catch (\Exception) {
                // reported below
            }
            throw $e;
        }
    }

    /**
     * Label and tracking numbers for an integration code (public for tests with recorded responses).
     */
    public function parseBarcode(\SimpleXMLElement $body, string $code): ShipmentResult
    {
        $result = $body->xpath('.//GetArasBarcodeResult')[0] ?? null;
        if (!$result || (string)$result->ResultCode !== '0') {
            throw new CarrierException(__('Aras did not return a label: %1', trim((string)($result->Message ?? ''))));
        }
        $numbers = [];
        foreach ($result->xpath('.//BarcodeModel/TrackingNumber') ?: [] as $node) {
            $numbers[] = (string)$node;
        }
        $labels = [];
        foreach ($result->xpath('.//Images/base64Binary') ?: [] as $image) {
            $content = base64_decode((string)$image, true);
            if ($content !== false && $content !== '') {
                $labels[] = new Label($content, Label::FORMAT_PNG);
            }
        }
        if (!$labels || !$numbers) {
            throw new CarrierException(__('Aras did not return a label image.'));
        }
        return new ShipmentResult($code, array_values(array_unique($numbers)), $labels, ['integration_code' => $code]);
    }

    public function cancelShipment(ConnectionInterface $connection, string $carrierReference, array $trackingNumbers): void
    {
        $body = '<CancelDispatch xmlns="http://tempuri.org/"><userName>' . $this->xml($this->credential($connection, 'username', 'User name'))
            . '</userName><password>' . $this->xml($this->credential($connection, 'password', 'Password'))
            . '</password><integrationCode>' . $this->xml($carrierReference) . '</integrationCode></CancelDispatch>';
        $response = $this->soap($this->call('cancel', $connection, $carrierReference), $this->url($connection), $body, '',
            'http://tempuri.org/CancelDispatch');
        $code = (string)($response->xpath('.//CancelDispatchResult/ResultCode')[0] ?? '');
        if ($code !== '0') {
            throw new CarrierException(__('Aras did not cancel the order: %1', (string)($response->xpath('.//CancelDispatchResult/ResultMessage')[0] ?? $code)));
        }
    }

    public function track(ConnectionInterface $connection, array $trackingNumbers, array $references = []): array
    {
        $user = $this->credential($connection, 'query_username', 'XML service user');
        $login = '<LoginInfo><UserName>' . $this->xml($user) . '</UserName><Password>'
            . $this->xml($this->credential($connection, 'query_password', 'XML service password')) . '</Password><CustomerCode>'
            . $this->xml($this->credential($connection, 'customer_code', 'Customer code')) . '</CustomerCode></LoginInfo>';
        $results = [];
        foreach ($trackingNumbers as $number) {
            $query = '<QueryInfo><QueryType>1</QueryType><TrackingNumber>' . $this->xml((string)$number) . '</TrackingNumber></QueryInfo>';
            $body = '<GetQueryJSON xmlns="http://tempuri.org/"><loginInfo>' . $this->xml($login) . '</loginInfo><queryInfo>'
                . $this->xml($query) . '</queryInfo></GetQueryJSON>';
            $response = $this->soap($this->call('track', $connection, (string)$number), self::QUERY_URL, $body, '',
                'http://tempuri.org/IArasCargoIntegrationService/GetQueryJSON');
            $result = $this->parseTracking((string)$number, (string)($response->xpath('.//GetQueryJSONResult')[0] ?? ''));
            if ($result) {
                $results[(string)$number] = $result;
            }
        }
        return $results;
    }

    /**
     * Parses the JSON string returned by GetQueryJSON (QueryType 1).
     */
    public function parseTracking(string $number, string $json): ?TrackingResult
    {
        $data = json_decode($json, true);
        $cargo = $data['QueryResult']['Cargo'] ?? null;
        if (!is_array($cargo) || !$cargo) {
            return null;
        }
        if (array_is_list($cargo)) {
            $cargo = $cargo[0];
        }
        $code = (string)($cargo['DURUM_KODU'] ?? '');
        $text = (string)($cargo['DURUMU'] ?? '');
        $status = match ($code) {
            '6' => TrackingResult::STATUS_DELIVERED,
            '4' => TrackingResult::STATUS_OUT_FOR_DELIVERY,
            '1', '2', '3', '5', '7' => TrackingResult::STATUS_IN_TRANSIT,
            default => TrackingResult::STATUS_PENDING,
        };
        if (preg_match('/[İI]ADE/u', mb_strtoupper($text))) {
            $status = TrackingResult::STATUS_RETURNED;
        }
        $deliveredAt = '';
        if ($status === TrackingResult::STATUS_DELIVERED) {
            $deliveredAt = $this->parseTrDate((string)($cargo['TESLIM_TARIHI'] ?? '') . ' ' . (string)($cargo['TESLIM_SAATI'] ?? ''));
        }
        $events = [new TrackingEvent(
            $deliveredAt !== '' ? $deliveredAt : $this->parseTrDate((string)($cargo['ISLEM_TARIHI'] ?? '')),
            $status,
            $text,
            $code,
            trim((string)($cargo['VARIS_BIRIM'] ?? $cargo['CIKIS_BIRIM'] ?? ''))
        )];
        return new TrackingResult($number, $status, $events, $deliveredAt);
    }

    private function fetchLabel(ConnectionInterface $connection, string $code): ShipmentResult
    {
        $body = '<GetArasBarcode xmlns="http://tempuri.org/"><Username>' . $this->xml($this->credential($connection, 'username', 'User name'))
            . '</Username><Password>' . $this->xml($this->credential($connection, 'password', 'Password'))
            . '</Password><integrationCode>' . $this->xml($code) . '</integrationCode></GetArasBarcode>';
        // Aras prepares the label a moment after SetOrder: an empty result is asked again (up to ~6 s).
        for ($attempt = 1; ; $attempt++) {
            $response = $this->soap($this->call('get_label', $connection, $code), $this->url($connection), $body, '',
                'http://tempuri.org/GetArasBarcode');
            $ready = (bool)($response->xpath('.//GetArasBarcodeResult/Images/base64Binary') ?: []);
            if ($ready || $attempt >= 4) {
                return $this->parseBarcode($response, $code);
            }
            usleep($attempt * 1000000);
        }
    }

    private function integrationCode(string $reference): string
    {
        $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '');
        // Aras allows 16 characters; 14 leaves room for the piece number on piece barcodes.
        return substr('SW' . substr($ref, -6) . strtoupper(base_convert((string)(int)(microtime(true) * 100), 10, 36)), 0, 14);
    }

    /**
     * Turkish addresses: region = il (province), city = ilçe (district). Upper-case Turkish letters for Aras.
     *
     * @return array{0: string, 1: string}
     */
    private function cityTown(Address $address): array
    {
        $upper = static fn (string $v): string => mb_strtoupper(str_replace(['i', 'ı'], ['İ', 'I'], trim($v)), 'UTF-8');
        if ($address->region !== '') {
            return [$upper($address->region), $upper($address->city)];
        }
        $parts = preg_split('/\s*[\/,]\s*/', $address->city) ?: [];
        return count($parts) >= 2 ? [$upper($parts[1]), $upper($parts[0])] : [$upper($address->city), ''];
    }

    private function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $digits = '0' . substr($digits, 2);
        }
        return substr($digits, 0, 11);
    }

    private function num(float $value): string
    {
        return rtrim(rtrim(number_format(max(0.01, $value), 2, '.', ''), '0'), '.');
    }

    private function parseTrDate(string $value): string
    {
        $value = trim($value);
        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2}))?/', $value, $m)) {
            return $this->toUtc(sprintf('%s-%s-%s %s:%s:00', $m[3], $m[2], $m[1], $m[4] ?? '12', $m[5] ?? '00'), 'Europe/Istanbul');
        }
        return $this->toUtc($value, 'Europe/Istanbul');
    }

    private function url(ConnectionInterface $connection): string
    {
        return $connection->isSandbox() ? self::TEST_URL : self::LIVE_URL;
    }
}
