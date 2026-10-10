<?php
/**
 * @copyright Copyright (c) Softaware Commerce (https://www.softawarecommerce.co.uk/)
 */
declare(strict_types=1);

namespace Softaware\ShippingTurkey\Model\Adapter;

use Softaware\ShippingCore\Api\Data\ConnectionInterface;
use Softaware\ShippingCore\Exception\CarrierException;
use Softaware\ShippingCore\Model\Carrier\AbstractAdapter;
use Softaware\ShippingCore\Model\Carrier\AdapterContext;
use Softaware\ShippingCore\Model\Carrier\Data\Address;
use Softaware\ShippingCore\Model\Carrier\Data\Label;
use Softaware\ShippingCore\Model\Carrier\Data\ShipmentRequest;
use Softaware\ShippingCore\Model\Carrier\Data\ShipmentResult;
use Softaware\ShippingCore\Model\Carrier\Data\TrackingEvent;
use Softaware\ShippingCore\Model\Carrier\Data\TrackingResult;
use Softaware\ShippingCore\Model\Shipping\OwnLabelRenderer;
use Softaware\ShippingCore\Api\CredentialsHelpInterface;

/**
 * Yurtiçi Kargo ShippingOrderDispatcherServices (SOAP): createShipment, queryShipment, cancelShipment.
 *
 * createShipment registers the shipment under the merchant's cargo key and returns no label: the module prints
 * its own label with the cargo key as a Code 128 barcode, which the branch scans. Yurtiçi web services only answer
 * from Turkish IP addresses (or whitelisted ones).
 */
class Yurtici extends AbstractAdapter implements CredentialsHelpInterface
{
    public const CODE = 'yurtici';
    private const NS = 'http://yurticikargo.com.tr/ShippingOrderDispatcherServices';
    private const TEST_URL = 'https://testws.yurticikargo.com/KOPSWebServices/ShippingOrderDispatcherServices';
    private const LIVE_URL = 'https://ws.yurticikargo.com/KOPSWebServices/ShippingOrderDispatcherServices';

    public function __construct(AdapterContext $context, private readonly OwnLabelRenderer $labelRenderer)
    {
        parent::__construct($context);
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function getTitle(): string
    {
        return 'Yurtiçi Kargo';
    }

    public function getCapabilities(): array
    {
        return [self::CAP_SHIPMENT, self::CAP_CANCEL, self::CAP_TRACKING, self::CAP_COD, self::CAP_DESI];
    }

    public function getCredentialFields(): array
    {
        return [
            ['code' => 'username', 'label' => (string)__('Web Service User (wsUserName)'), 'type' => 'text', 'required' => true,
                'notice' => (string)__('From your Yurtiçi Kargo contract.'),
                'sandbox' => 'YKTEST'],
            ['code' => 'password', 'label' => (string)__('Web Service Password'), 'type' => 'password', 'required' => true],
        ];
    }

    public function getCredentialsHelp(): string
    {
        return (string)__('Web service user (wsUserName) and password from your Yurtiçi Kargo contract. Yurtiçi only answers requests from Turkish (or whitelisted) IP addresses.');
    }

    public function getSandboxHelp(): string
    {
        return (string)__('On: the Yurtiçi test web service with the test user; shipments are not collected. Off: your live web service user.');
    }

    public function getOptionFields(): array
    {
        return [
            ['code' => 'cod_type', 'label' => (string)__('Cash on Delivery Collected As'), 'type' => 'select', 'default' => '0',
                'options' => ['0' => (string)__('Cash'), '1' => (string)__('Card')]],
        ];
    }

    public function getServices(): array
    {
        return ['standard' => 'Yurtiçi Kargo Standart'];
    }

    public function getTrackingUrl(string $trackingNumber, string $countryId = '', string $locale = ''): string
    {
        return 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=' . rawurlencode($trackingNumber);
    }

    public function testConnection(ConnectionInterface $connection): string
    {
        $this->query($connection, ['SWTEST' . time()]);
        return (string)__('Yurtiçi %1 web service accepted the credentials.', $connection->isSandbox() ? 'test' : 'live');
    }

    public function createShipment(ShipmentRequest $request): ShipmentResult
    {
        $connection = $request->connection;
        $recipient = $request->recipient;
        $key = $this->cargoKey($request->reference);
        $province = $recipient->region !== '' ? $recipient->region : $recipient->city;
        $cod = $request->codAmount !== null && $request->codAmount > 0;
        $desi = $request->getTotalDesi();
        $vo = '<cargoKey>' . $this->xml($key) . '</cargoKey>'
            . '<invoiceKey>' . $this->xml($key) . '</invoiceKey>'
            . '<receiverCustName>' . $this->xml(str_pad($this->clean($recipient->name . ($recipient->company !== '' ? ' ' . $recipient->company : ''), 200), 5, '.')) . '</receiverCustName>'
            . '<receiverAddress>' . $this->xml($this->clean($recipient->getStreetFull() . ' ' . $recipient->postcode, 200)) . '</receiverAddress>'
            . '<cityName>' . $this->xml($this->clean($province, 40)) . '</cityName>'
            . '<townName>' . $this->xml($this->clean($recipient->region !== '' ? $recipient->city : '', 40)) . '</townName>'
            . '<receiverPhone1>' . $this->xml($this->phone($recipient->phone)) . '</receiverPhone1>'
            . ($recipient->email !== '' ? '<emailAddress>' . $this->xml($recipient->email) . '</emailAddress>' : '')
            . ($desi > 0 ? '<desi>' . number_format($desi, 3, '.', '') . '</desi>' : '')
            . '<kg>' . number_format(max(0.1, $request->getTotalWeight()), 3, '.', '') . '</kg>'
            . '<cargoCount>' . count($request->parcels) . '</cargoCount>'
            . '<description>' . $this->xml($this->clean($request->contentsDescription, 200)) . '</description>'
            . '<taxOfficeId>0</taxOfficeId>'
            . ($cod ? '<ttInvoiceAmount>' . number_format((float)$request->codAmount, 2, '.', '') . '</ttInvoiceAmount>'
                . '<ttDocumentId>' . (int)preg_replace('/\D/', '', $request->reference) . '</ttDocumentId>'
                . '<ttCollectionType>' . $this->xml($connection->getOption('cod_type', '0')) . '</ttCollectionType>'
                . '<ttDocumentSaveType>0</ttDocumentSaveType>'
                . '<dcSelectedCredit>' . ($connection->getOption('cod_type', '0') === '1' ? '1' : '0') . '</dcSelectedCredit>'
                . '<dcCreditRule>' . ($connection->getOption('cod_type', '0') === '1' ? '1' : '0') . '</dcCreditRule>'
                : '<ttDocumentId>0</ttDocumentId><dcSelectedCredit>0</dcSelectedCredit><dcCreditRule>0</dcCreditRule>');
        $body = '<ship:createShipment>' . $this->login($connection, 'userLanguage') . '<ShippingOrderVO>' . $vo . '</ShippingOrderVO></ship:createShipment>';
        $response = $this->soap($this->call('create_shipment', $connection, $request->reference), $this->url($connection), $body, '', '',
            'xmlns:ship="' . self::NS . '"', ['retries' => 0]);
        $this->assertResult($response, 'ShippingOrderResultVO', 'shippingOrderDetailVO');
        $facts = [
            (string)__('Order') => $request->reference,
            (string)__('Parcels') => (string)count($request->parcels),
            (string)__('Weight') => number_format($request->getTotalWeight(), 2) . ' kg',
        ];
        if ($desi > 0) {
            $facts[(string)__('Desi')] = number_format($desi, 2);
        }
        if ($cod) {
            $facts[(string)__('Cash on delivery')] = number_format((float)$request->codAmount, 2) . ' ' . $request->currency;
        }
        $pdf = $this->labelRenderer->render('Yurtiçi Kargo', $key, $request->sender, $recipient, $facts);
        return new ShipmentResult($key, [$key], [new Label($pdf, Label::FORMAT_PDF)]);
    }

    public function cancelShipment(ConnectionInterface $connection, string $carrierReference, array $trackingNumbers): void
    {
        $body = '<ship:cancelShipment>' . $this->login($connection, 'userLanguage') . '<cargoKeys>' . $this->xml($carrierReference)
            . '</cargoKeys></ship:cancelShipment>';
        $response = $this->soap($this->call('cancel', $connection, $carrierReference), $this->url($connection), $body, '', '',
            'xmlns:ship="' . self::NS . '"');
        $this->assertResult($response, 'ShippingOrderResultVO', 'shippingCancelDetailVO');
    }

    public function track(ConnectionInterface $connection, array $trackingNumbers, array $references = []): array
    {
        $results = [];
        foreach (array_chunk(array_values($trackingNumbers), 10) as $chunk) {
            $response = $this->query($connection, $chunk);
            foreach ($response->xpath('.//shippingDeliveryDetailVO') ?: [] as $detail) {
                $result = $this->parseDetail($detail);
                if ($result) {
                    $results[$result->trackingNumber] = $result;
                }
            }
        }
        return $results;
    }

    /**
     * One shippingDeliveryDetailVO of a queryShipment response (public for tests with recorded responses).
     */
    public function parseDetail(\SimpleXMLElement $detail): ?TrackingResult
    {
        $key = (string)$detail->cargoKey;
        $code = strtoupper((string)$detail->operationStatus);
        if ($key === '' || (string)$detail->errCode !== '' && (string)$detail->errCode !== '0') {
            return null;
        }
        $item = $detail->shippingDeliveryItemDetailVO;
        $explanation = mb_strtoupper((string)($item->cargoEventExplanation ?? $detail->operationMessage ?? ''));
        $status = match ($code) {
            'DLV' => TrackingResult::STATUS_DELIVERED,
            'CNL' => TrackingResult::STATUS_CANCELLED,
            'NOP' => TrackingResult::STATUS_PENDING,
            'IND', 'ISR', 'ISC' => TrackingResult::STATUS_IN_TRANSIT,
            default => TrackingResult::STATUS_UNKNOWN,
        };
        if (str_contains($explanation, 'İADE') || str_contains($explanation, 'IADE')) {
            $status = TrackingResult::STATUS_RETURNED;
        }
        $events = [];
        foreach ($item->invDocCargoVOArray ?? [] as $event) {
            $events[] = new TrackingEvent(
                $this->date((string)$event->eventDate, (string)$event->eventTime),
                TrackingResult::STATUS_IN_TRANSIT,
                trim((string)$event->eventName),
                (string)$event->eventId,
                trim((string)$event->unitName . ' ' . (string)$event->cityName)
            );
        }
        $deliveredAt = $status === TrackingResult::STATUS_DELIVERED
            ? $this->date((string)($item->deliveryDate ?? ''), (string)($item->deliveryTime ?? '')) : '';
        if ($deliveredAt !== '') {
            $events[] = new TrackingEvent($deliveredAt, TrackingResult::STATUS_DELIVERED, (string)__('Delivered'), 'DLV');
        }
        return new TrackingResult($key, $status, $events, $deliveredAt);
    }

    /**
     * @param string[] $keys
     */
    private function query(ConnectionInterface $connection, array $keys): \SimpleXMLElement
    {
        $body = '<ship:queryShipment>' . $this->login($connection, 'wsLanguage');
        foreach ($keys as $key) {
            $body .= '<keys>' . $this->xml((string)$key) . '</keys>';
        }
        $body .= '<keyType>0</keyType><addHistoricalData>true</addHistoricalData><onlyTracking>false</onlyTracking></ship:queryShipment>';
        $response = $this->soap($this->call('track', $connection, implode(',', $keys)), $this->url($connection), $body, '', '',
            'xmlns:ship="' . self::NS . '"');
        $flag = (string)($response->xpath('.//ShippingDeliveryVO/outFlag')[0] ?? '0');
        if ($flag === '2') {
            throw new CarrierException(__('Yurtiçi: %1', (string)($response->xpath('.//ShippingDeliveryVO/outResult')[0] ?? 'error')));
        }
        return $response;
    }

    private function assertResult(\SimpleXMLElement $response, string $voName, string $detailName): void
    {
        $vo = $response->xpath('.//' . $voName)[0] ?? null;
        if (!$vo) {
            throw new CarrierException(__('Yurtiçi returned an unexpected response.'));
        }
        $errors = [];
        foreach ($vo->{$detailName} ?? [] as $detail) {
            if ((string)$detail->errCode !== '' && (string)$detail->errCode !== '0') {
                $errors[] = (string)$detail->errCode . ' ' . (string)$detail->errMessage;
            }
        }
        if ((string)$vo->outFlag !== '0' || $errors) {
            throw new CarrierException(__('Yurtiçi: %1', implode('; ', $errors) ?: (string)$vo->outResult));
        }
    }

    private function login(ConnectionInterface $connection, string $languageField): string
    {
        return '<wsUserName>' . $this->xml($this->credential($connection, 'username', 'User')) . '</wsUserName>'
            . '<wsPassword>' . $this->xml($this->credential($connection, 'password', 'Password')) . '</wsPassword>'
            . '<' . $languageField . '>TR</' . $languageField . '>';
    }

    private function cargoKey(string $reference): string
    {
        $ref = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $reference) ?? '');
        return substr('SW' . substr($ref, -10) . strtoupper(base_convert((string)time(), 10, 36)), 0, 20);
    }

    private function phone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '90') && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }
        return substr(ltrim($digits, '0'), 0, 10);
    }

    private function date(string $date, string $time): string
    {
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $d)) {
            return '';
        }
        $t = str_pad(preg_replace('/\D/', '', $time) ?? '', 6, '0');
        return $this->toUtc(sprintf('%s-%s-%s %s:%s:%s', $d[1], $d[2], $d[3], substr($t, 0, 2), substr($t, 2, 2), substr($t, 4, 2)), 'Europe/Istanbul');
    }

    private function url(ConnectionInterface $connection): string
    {
        return $connection->isSandbox() ? self::TEST_URL : self::LIVE_URL;
    }
}
