<?php
/**
 * @license MIT
 */
namespace Mews\Pos\Gateways;

use GuzzleHttp\Client;
use Mews\Pos\DataMapper\AbstractRequestDataMapper;
use Mews\Pos\Entity\Account\AbstractPosAccount;
use Mews\Pos\Entity\Account\EstPosAccount;
use Mews\Pos\Entity\Card\AbstractCreditCard;
use Symfony\Component\HttpFoundation\Request;

/**
 * todo cardType verisi dokumantasyona gore kontrol edilmesi gerekiyor.
 * cardType gondermeden de su an calisiyor.
 * Class EstPos
 */
class EstPos extends AbstractGateway
{
    /**
     * hash algorithm ver3
     *
     * @const string
     */
    protected const HASH_ALGORITHM = 'sha512';

    /**
     * hash separator
     *
     * @const string
     */
    protected const HASH_SEPARATOR = '|';

    /**
     * @const string
     */
    public const NAME = 'EstPos';

    /**
     * Response Codes
     *
     * @var array
     */
    protected $codes = [
        '00' => 'approved',
        '01' => 'bank_call',
        '02' => 'bank_call',
        '05' => 'reject',
        '09' => 'try_again',
        '12' => 'invalid_transaction',
        '28' => 'reject',
        '51' => 'insufficient_balance',
        '54' => 'expired_card',
        '57' => 'does_not_allow_card_holder',
        '62' => 'restricted_card',
        '77' => 'request_rejected',
        '99' => 'general_error',
    ];

    /**
     * @var EstPosAccount
     */
    protected $account;

    /**
     * @var AbstractCreditCard|null
     */
    protected $card;

    /**
     * EstPos constructor.
     * @inheritdoc
     *
     * @param EstPosAccount             $account
     */
    public function __construct(array $config, AbstractPosAccount $account, AbstractRequestDataMapper $requestDataMapper)
    {
        parent::__construct($config, $account, $requestDataMapper);
    }

    /**
     * @inheritDoc
     */
    public function createXML(array $nodes, string $encoding = 'ISO-8859-9', bool $ignorePiNode = false): string
    {
        return parent::createXML(['CC5Request' => $nodes], $encoding, $ignorePiNode);
    }

    /**
     * Check 3D Hash
     *
     * @param array $data
     *
     * @return bool
     */
    public function check3DHash(array $data): bool
    {
        $return = false;

        $postParams = array_keys($data);
        natcasesort($postParams);

        $hashData = [];
        foreach($postParams as $param){
            if(!in_array(strtolower($param), ['hash', 'encoding'])){
                $hashData[] = str_replace("|", "\\|", str_replace("\\", "\\\\", $data[$param]));
            }
        }

        $hashData[] = str_replace("|", "\\|", str_replace("\\", "\\\\", $this->account->getStoreKey()));
        $hashVal = implode(static::HASH_SEPARATOR, $hashData);

        $hash = $this->hashString($hashVal);
        if($hash == $data['HASH']){
            $return = true;
        }

        return $return;
    }

    /**
     * @inheritDoc
     */
    public function make3DPayment(Request $request)
    {
        $provisionResponse = null;
        if ($this->check3DHash($request->request->all())) {
            if ($request->request->get('mdStatus') !== '1') {
                /**
                 * TODO hata durumu ele alinmasi gerekiyor
                 * ornegin soyle bir hata donebilir
                 * ["ProcReturnCode" => "99", "mdStatus" => "7", "mdErrorMsg" => "Isyeri kullanim tipi desteklenmiyor.",
                 * "ErrMsg" => "Isyeri kullanim tipi desteklenmiyor.", "Response" => "Error", "ErrCode" => "3D-1007", ...]
                 */
            } else {
                $contents = $this->create3DPaymentXML($request->request->all());
                $provisionResponse = $this->send($contents);
            }
        }

        $this->response = (object) $this->map3DPaymentData($request->request->all(), $provisionResponse);

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function make3DPayPayment(Request $request)
    {
        $this->response = (object) $this->map3DPayResponseData($request->request->all());

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function make3DHostPayment(Request $request)
    {
        $this->response = (object) $this->map3DHostResponseData($request->request->all());

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function get3DFormData(): array
    {
        if (!$this->order) {
            return [];
        }

        return $this->requestDataMapper->create3DFormData($this->account, $this->order, $this->type, $this->get3DGatewayURL(), $this->card);
    }

    /**
     * @inheritDoc
     */
    public function send($contents, ?string $url = null)
    {
        $client = new Client();

        $response = $client->request('POST', $this->getApiURL(), [
            'body' => $contents,
        ]);

        $this->data = $this->XMLStringToObject($response->getBody()->getContents());

        return $this->data;
    }

    /**
     * @inheritDoc
     */
    public function history(array $meta)
    {
        $xml = $this->createHistoryXML($meta);

        $bankResponse = $this->send($xml);

        $this->response = $this->mapHistoryResponse($bankResponse);

        return $this;
    }

    /**
     * @return EstPosAccount
     */
    public function getAccount()
    {
        return $this->account;
    }

    /**
     * @inheritDoc
     */
    public function createRegularPaymentXML()
    {
        $requestData = $this->requestDataMapper->createNonSecurePaymentRequestData($this->account, $this->order, $this->type, $this->card);

        return $this->createXML($requestData);
    }

    /**
     * @inheritDoc
     */
    public function createRegularPostXML()
    {
        $requestData = $this->requestDataMapper->createNonSecurePostAuthPaymentRequestData($this->account, $this->order);

        return $this->createXML($requestData);
    }

    /**
     * @inheritDoc
     */
    public function create3DPaymentXML($responseData)
    {
        $requestData = $this->requestDataMapper->create3DPaymentRequestData($this->account, $this->order, $this->type, $responseData);

        return $this->createXML($requestData);
    }

    /**
     * @inheritDoc
     */
    public function createStatusXML()
    {
        $requestData = $this->requestDataMapper->createStatusRequestData($this->account, $this->order);

        return $this->createXML($requestData);
    }

    /**
     * @inheritDoc
     */
    public function createHistoryXML($customQueryData)
    {
        $requestData = $this->requestDataMapper->createHistoryRequestData($this->account, $this->order, $customQueryData);

        return $this->createXML($requestData);
    }

    /**
     * @inheritDoc
     */
    public function createCancelXML()
    {
        $requestData = $this->requestDataMapper->createCancelRequestData($this->account, $this->order);

        return $this->createXML($requestData);
    }

    /**
     * @inheritDoc
     */
    public function createRefundXML()
    {
        $requestData = $this->requestDataMapper->createRefundRequestData($this->account, $this->order);

        return $this->createXML($requestData);
    }

    /**
     * Get ProcReturnCode
     *
     * @param array|object $response
     *
     * @return string|null
     */
    protected function getProcReturnCode($response): ?string
    {
        if (is_array($response)) {
            return $response['ProcReturnCode'] ?? null;
        }

        return $response->ProcReturnCode ?? null;
    }

    /**
     * Get Status Detail Text
     *
     * @param string|null $procReturnCode
     *
     * @return string|null
     */
    protected function getStatusDetail(?string $procReturnCode): ?string
    {
        return $procReturnCode ? (isset($this->codes[$procReturnCode]) ? (string) $this->codes[$procReturnCode] : null) : null;
    }

    /**
     * @inheritDoc
     */
    protected function map3DPaymentData($raw3DAuthResponseData, $rawPaymentResponseData)
    {
        //
        // hashAlgorithm ver3'te tüm parametreler hash doğrulamasında kullanılmasından dolayı iptal edilmiştir.
        //$raw3DAuthResponseData = $this->emptyStringsToNull($raw3DAuthResponseData);
        $paymentResponseData = $this->mapPaymentResponse($rawPaymentResponseData);

        $threeDResponse = [
            'transaction_security' => $this->mapResponseTransactionSecurity($raw3DAuthResponseData['mdStatus']),
            'md_status'            => $raw3DAuthResponseData['mdStatus'],
            'hash'                 => $raw3DAuthResponseData['HASH'],
            'order_id'             => $raw3DAuthResponseData['oid'],
            'rand'                 => $raw3DAuthResponseData['rnd'],
            'hash_params'          => $raw3DAuthResponseData['HASHPARAMS'] ?? null,
            'hash_params_val'      => $raw3DAuthResponseData['HASHPARAMSVAL'] ?? null,
            'masked_number'        => $raw3DAuthResponseData['maskedCreditCard'],
            'month'                => $raw3DAuthResponseData['Ecom_Payment_Card_ExpDate_Month'],
            'year'                 => $raw3DAuthResponseData['Ecom_Payment_Card_ExpDate_Year'],
            'amount'               => $raw3DAuthResponseData['amount'],
            'currency'             => array_search($raw3DAuthResponseData['currency'], $this->requestDataMapper->getCurrencyMappings()),
            'eci'                  => null,
            'tx_status'            => null,
            'cavv'                 => null,
            'xid'                  => $raw3DAuthResponseData['oid'],
            'md_error_message'     => '1' !== $raw3DAuthResponseData['mdStatus'] ? $raw3DAuthResponseData['mdErrorMsg'] : null,
            'name'                 => $raw3DAuthResponseData['firmaadi'],
            '3d_all'               => $raw3DAuthResponseData,
        ];

        if ('1' === $raw3DAuthResponseData['mdStatus']) {
            $threeDResponse['eci'] = $raw3DAuthResponseData['eci'];
            $threeDResponse['cavv'] = $raw3DAuthResponseData['cavv'];
        }

        return $this->mergeArraysPreferNonNullValues($threeDResponse, $paymentResponseData);
    }

    /**
     * @inheritDoc
     */
    protected function map3DPayResponseData($raw3DAuthResponseData)
    {
        $status = 'declined';

        //
        // hashAlgorithm ver3'te tüm parametreler hash doğrulamasında kullanılmasından dolayı iptal edilmiştir.
        //$raw3DAuthResponseData = $this->emptyStringsToNull($raw3DAuthResponseData);

        $procReturnCode = $this->getProcReturnCode($raw3DAuthResponseData);
        if ($this->check3DHash($raw3DAuthResponseData) && '00' === $procReturnCode) {
            if (in_array($raw3DAuthResponseData['mdStatus'], ['1', '2', '3', '4'])) {
                $status = 'approved';
            }
        }


        $defaultResponse = $this->getDefaultPaymentResponse();

        $response = [
            'order_id'             => $raw3DAuthResponseData['oid'],
            'transaction_security' => $this->mapResponseTransactionSecurity($raw3DAuthResponseData['mdStatus']),
            'md_status'            => $raw3DAuthResponseData['mdStatus'],
            'status'               => $status,
            'hash'                 => $raw3DAuthResponseData['HASH'],
            'rand'                 => $raw3DAuthResponseData['rnd'],
            'hash_params'          => $raw3DAuthResponseData['HASHPARAMS'] ?? null,
            'hash_params_val'      => $raw3DAuthResponseData['HASHPARAMSVAL'] ?? null,
            'masked_number'        => $raw3DAuthResponseData['maskedCreditCard'],
            'month'                => $raw3DAuthResponseData['Ecom_Payment_Card_ExpDate_Month'],
            'year'                 => $raw3DAuthResponseData['Ecom_Payment_Card_ExpDate_Year'],
            'amount'               => $raw3DAuthResponseData['amount'],
            'currency'             => array_search($raw3DAuthResponseData['currency'], $this->requestDataMapper->getCurrencyMappings()),
            'tx_status'            => null,
            'eci'                  => $raw3DAuthResponseData['eci'],
            'cavv'                 => $raw3DAuthResponseData['cavv'],
            'xid'                  => $raw3DAuthResponseData['oid'],
            'md_error_message'     => $raw3DAuthResponseData['mdErrorMsg'],
            'name'                 => $raw3DAuthResponseData['firmaadi'],
            'email'                => $raw3DAuthResponseData['Email'],
            'campaign_url'         => null,
            'all'                  => $raw3DAuthResponseData,
        ];

        if ('1' === $raw3DAuthResponseData['mdStatus']) {
            $response['id'] = $raw3DAuthResponseData['AuthCode'];
            $response['auth_code'] = $raw3DAuthResponseData['AuthCode'];
            $response['trans_id'] = $raw3DAuthResponseData['TransId'];
            $response['host_ref_num'] = $raw3DAuthResponseData['HostRefNum'];
            $response['response'] = $raw3DAuthResponseData['Response'];
            $response['code'] = $procReturnCode;
            $response['status_detail'] = $this->getStatusDetail($procReturnCode);
            $response['error_message'] = $raw3DAuthResponseData['ErrMsg'];
            $response['error_code'] = isset($raw3DAuthResponseData['ErrMsg']) ? $procReturnCode : null;
        }

        return $this->mergeArraysPreferNonNullValues($defaultResponse, $response);
    }

    /**
     * @param array $raw3DAuthResponseData
     *
     * @return array
     */
    protected function map3DHostResponseData(array $raw3DAuthResponseData): array
    {
        //
        // hashAlgorithm ver3'te tüm parametreler hash doğrulamasında kullanılmasından dolayı iptal edilmiştir.
        // $raw3DAuthResponseData = $this->emptyStringsToNull($raw3DAuthResponseData);
        $status = 'declined';

        if ($this->check3DHash($raw3DAuthResponseData)) {
            if (in_array($raw3DAuthResponseData['mdStatus'], [1, 2, 3, 4])) {
                $status = 'approved';
            }
        }

        $defaultResponse = $this->getDefaultPaymentResponse();

        $response = [
            'order_id'             => $raw3DAuthResponseData['oid'],
            'transaction_security' => $this->mapResponseTransactionSecurity($raw3DAuthResponseData['mdStatus']),
            'md_status'            => $raw3DAuthResponseData['mdStatus'],
            'status'               => $status,
            'hash'                 => $raw3DAuthResponseData['HASH'],
            'rand'                 => $raw3DAuthResponseData['rnd'],
            'hash_params'          => $raw3DAuthResponseData['HASHPARAMS'] ?? null,
            'hash_params_val'      => $raw3DAuthResponseData['HASHPARAMSVAL'] ?? null,
            'masked_number'        => $raw3DAuthResponseData['maskedCreditCard'],
            'month'                => $raw3DAuthResponseData['Ecom_Payment_Card_ExpDate_Month'],
            'year'                 => $raw3DAuthResponseData['Ecom_Payment_Card_ExpDate_Year'],
            'amount'               => $raw3DAuthResponseData['amount'],
            'currency'             => array_search($raw3DAuthResponseData['currency'], $this->requestDataMapper->getCurrencyMappings()),
            'tx_status'            => null,
            'eci'                  => $raw3DAuthResponseData['eci'],
            'cavv'                 => $raw3DAuthResponseData['cavv'],
            'xid'                  => $raw3DAuthResponseData['oid'],
            'md_error_message'     => 'approved' !== $status ? $raw3DAuthResponseData['mdErrorMsg'] : null,
            'name'                 => $raw3DAuthResponseData['firmaadi'],
            'email'                => $raw3DAuthResponseData['Email'],
            'campaign_url'         => null,
            'all'                  => $raw3DAuthResponseData,
        ];

        return $this->mergeArraysPreferNonNullValues($defaultResponse, $response);
    }

    /**
     * @inheritDoc
     */
    protected function mapRefundResponse($rawResponseData)
    {
        $rawResponseData = $this->emptyStringsToNull($rawResponseData);
        $procReturnCode = $this->getProcReturnCode($rawResponseData);
        $status = 'declined';
        if ('00' === $procReturnCode) {
            $status = 'approved';
        }

        return (object) [
            'order_id'         => $rawResponseData['OrderId'],
            'group_id'         => $rawResponseData['GroupId'],
            'response'         => $rawResponseData['Response'],
            'auth_code'        => $rawResponseData['AuthCode'],
            'host_ref_num'     => $rawResponseData['HostRefNum'],
            'proc_return_code' => $procReturnCode,
            'trans_id'         => $rawResponseData['TransId'],
            'num_code'         => $rawResponseData['Extra']['NUMCODE'],
            'error_code'       => $rawResponseData['Extra']['ERRORCODE'],
            'error_message'    => $rawResponseData['ErrMsg'],
            'status'           => $status,
            'status_detail'    => $this->getStatusDetail($procReturnCode),
            'all'              => $rawResponseData,
        ];
    }

    /**
     * @inheritDoc
     */
    protected function mapCancelResponse($rawResponseData)
    {
        $rawResponseData = $this->emptyStringsToNull($rawResponseData);
        $procReturnCode = $this->getProcReturnCode($rawResponseData);
        $status = 'declined';
        if ('00' === $procReturnCode) {
            $status = 'approved';
        }

        $result = [
            'order_id'         => $rawResponseData['OrderId'],
            'group_id'         => $rawResponseData['GroupId'],
            'response'         => $rawResponseData['Response'],
            'auth_code'        => $rawResponseData['AuthCode'],
            'host_ref_num'     => $rawResponseData['HostRefNum'],
            'proc_return_code' => $procReturnCode,
            'trans_id'         => $rawResponseData['TransId'],
            'error_code'       => $rawResponseData['Extra']['ERRORCODE'],
            'num_code'         => $rawResponseData['Extra']['NUMCODE'],
            'error_message'    => $rawResponseData['ErrMsg'],
            'status'           => $status,
            'status_detail'    => $this->getStatusDetail($procReturnCode),
            'all'              => $rawResponseData,
        ];

        return (object) $result;
    }

    /**
     * @inheritDoc
     */
    protected function mapStatusResponse($rawResponseData)
    {
        $rawResponseData = $this->emptyStringsToNull($rawResponseData);
        $procReturnCode = $this->getProcReturnCode($rawResponseData);
        $status = 'declined';
        if ('00' === $procReturnCode) {
            $status = 'approved';
        }

        $result = [
            'order_id'         => $rawResponseData['OrderId'],
            'auth_code'        => null,
            'response'         => $rawResponseData['Response'],
            'proc_return_code' => $procReturnCode,
            'trans_id'         => $rawResponseData['TransId'],
            'error_message'    => $rawResponseData['ErrMsg'],
            'host_ref_num'     => null,
            'order_status'     => $rawResponseData['Extra']['ORDERSTATUS'],
            'process_type'     => null,
            'masked_number'    => null,
            'num_code'         => null,
            'first_amount'     => null,
            'capture_amount'   => null,
            'status'           => $status,
            'error_code'       => null,
            'status_detail'    => $this->getStatusDetail($procReturnCode),
            'capture'          => false,
            'all'              => $rawResponseData,
        ];
        if ('approved' === $status) {
            $result['auth_code']      = $rawResponseData['Extra']['AUTH_CODE'];
            $result['host_ref_num']   = $rawResponseData['Extra']['HOST_REF_NUM'];
            $result['process_type']   = $rawResponseData['Extra']['CHARGE_TYPE_CD'];
            $result['first_amount']   = $rawResponseData['Extra']['ORIG_TRANS_AMT'];
            $result['capture_amount'] = $rawResponseData['Extra']['CAPTURE_AMT'];
            $result['masked_number']  = $rawResponseData['Extra']['PAN'];
            $result['num_code']       = $rawResponseData['Extra']['NUMCODE'];
            $result['capture']        = $result['first_amount'] === $result['capture_amount'];
        }

        return (object) $result;
    }

    /**
     * @inheritDoc
     */
    protected function mapPaymentResponse($responseData): array
    {
        if (empty($responseData)) {
            return $this->getDefaultPaymentResponse();
        }
        $responseData = $this->emptyStringsToNull($responseData);

        $procReturnCode = $this->getProcReturnCode($responseData);
        $status = 'declined';
        if ('00' === $procReturnCode) {
            $status = 'approved';
        }

        return [
            'id'               => $responseData['AuthCode'],
            'order_id'         => $responseData['OrderId'],
            'group_id'         => $responseData['GroupId'],
            'trans_id'         => $responseData['TransId'],
            'response'         => $responseData['Response'],
            'transaction_type' => $this->type,
            'transaction'      => empty($this->type) ? null : $this->requestDataMapper->mapTxType($this->type),
            'auth_code'        => $responseData['AuthCode'],
            'host_ref_num'     => $responseData['HostRefNum'],
            'proc_return_code' => $procReturnCode,
            'code'             => $procReturnCode,
            'status'           => $status,
            'status_detail'    => $this->getStatusDetail($procReturnCode),
            'error_code'       => $responseData['Extra']['ERRORCODE'],
            'error_message'    => $responseData['ErrMsg'],
            'campaign_url'     => null,
            'extra'            => $responseData['Extra'],
            'all'              => $responseData,
        ];
    }

    /**
     * @inheritDoc
     */
    protected function mapHistoryResponse($rawResponseData)
    {
        $rawResponseData = $this->emptyStringsToNull($rawResponseData);
        $procReturnCode = $this->getProcReturnCode($rawResponseData);
        $status = 'declined';
        if ('00' === $procReturnCode) {
            $status = 'approved';
        }

        return (object) [
            'order_id'         => $rawResponseData['OrderId'],
            'response'         => $rawResponseData['Response'],
            'proc_return_code' => $procReturnCode,
            'error_message'    => $rawResponseData['ErrMsg'],
            'num_code'         => $rawResponseData['Extra']['NUMCODE'],
            'trans_count'      => $rawResponseData['Extra']['TRXCOUNT'],
            'status'           => $status,
            'status_detail'    => $this->getStatusDetail($procReturnCode),
            'all'              => $rawResponseData,
        ];
    }

    /**
     * @param string $mdStatus
     *
     * @return string
     */
    protected function mapResponseTransactionSecurity(string $mdStatus): string
    {
        $transactionSecurity = 'MPI fallback';
        if ('1' === $mdStatus) {
            $transactionSecurity = 'Full 3D Secure';
        } elseif (in_array($mdStatus, ['2', '3', '4'])) {
            $transactionSecurity = 'Half 3D Secure';
        }

        return $transactionSecurity;
    }

    /**
     * @inheritDoc
     */
    protected function preparePaymentOrder(array $order)
    {
        if (isset($order['recurringFrequency'])) {
            $order['recurringFrequencyType'] = $this->mapRecurringFrequency($order['recurringFrequencyType']);
        }

        // Order
        return (object) array_merge($order, [
            'installment' => $order['installment'] ?? 0,
            'currency'    => $order['currency'] ?? 'TRY',
        ]);
    }

    /**
     * @inheritDoc
     */
    protected function preparePostPaymentOrder(array $order)
    {
        return (object) [
            'id' => $order['id'],
        ];
    }

    /**
     * @inheritDoc
     */
    protected function prepareStatusOrder(array $order)
    {
        return (object) $order;
    }

    /**
     * @inheritDoc
     */
    protected function prepareHistoryOrder(array $order)
    {
        return (object) $order;
    }

    /**
     * @inheritDoc
     */
    protected function prepareCancelOrder(array $order)
    {
        return (object) $order;
    }

    /**
     * @inheritDoc
     */
    protected function prepareRefundOrder(array $order)
    {
        return (object) [
            'id'       => $order['id'],
            'currency' => $order['currency'] ?? 'TRY',
            'amount'   => $order['amount'],
        ];
    }
}
