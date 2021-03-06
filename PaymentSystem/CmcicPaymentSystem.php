<?php

namespace Kitano\PaymentCmcicBundle\PaymentSystem;

use Kitano\PaymentBundle\PaymentSystem\CreditCardInterface;
use Kitano\PaymentBundle\Entity\Transaction;
use Kitano\PaymentBundle\Entity\AuthorizationTransaction;
use Kitano\PaymentBundle\Entity\CaptureTransaction;
use Kitano\PaymentBundle\KitanoPaymentEvents;
use Kitano\PaymentBundle\Event\PaymentNotificationEvent;
use Kitano\PaymentBundle\Event\PaymentCaptureEvent;
use Kitano\PaymentBundle\PaymentSystem\HandlePaymentResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Templating\EngineInterface;
use Kitano\PaymentBundle\Repository\TransactionRepositoryInterface;
use Symfony\Component\HttpKernel\Log\LoggerInterface;

class CmcicPaymentSystem implements CreditCardInterface
{
    /* @var string */
    protected $lang;

    /* @var string */
    protected $tpe;

    /* @var string */
    protected $key;

    /* @var string */
    protected $version;

    /* @var string */
    protected $email;

    /* @var string */
    protected $companyCode;

    /* @var boolean */
    protected $sandbox = false;

    /* @var array */
    protected $urls = array();

    /* @var string */
    private $certificatePath;

    /* @var EventDispatcherInterface */
    protected $dispatcher;

    /* @var EngineInterface */
    protected $templating;

    /* @var TransactionRepositoryInterface */
    protected $transactionRepository;

    /* @var LoggerInterface */
    protected $logger;


    public function __construct(TransactionRepositoryInterface $transactionRepository, EventDispatcherInterface $dispatcher, EngineInterface $templating)
    {
        $this->transactionRepository = $transactionRepository;
        $this->dispatcher = $dispatcher;
        $this->templating = $templating;
    }

    public function authorizeAndCapture(Transaction $transaction)
    {
        // Nothing to do
    }

    /**
     * {@inheritDoc}
     */
    public function renderLinkToPayment(Transaction $transaction)
    {
        return $this->templating->render('KitanoPaymentCmcicBundle:PaymentSystem:link-to-payment.html.twig', array(
            'version' => $this->getVersion(),
            'tpe' => $this->tpe,
            'mac' => $this->computeMac($transaction),
            'lgue' => $this->getLang(),
            'societe' => $this->companyCode,
            'texte_libre' => '',
            'mail' => $this->getEmail(),
            'date' => $this->formatDate($transaction->getCreatedAt()),
            'reference' => $transaction->getOrderId(),
            'montant' => $this->formatAmount($transaction->getAmount(), $transaction->getCurrency()),
            'requestUrl' => $this->getUrl('authorize'),
        ));
    }

    /**
     * {@inheritDoc}
     */
    public function handlePaymentNotification(Request $request)
    {
        // TODO: Implement handlePaymentNotification() method.
        $requestData = $request->request;
        $transaction = $this->transactionRepository->findByOrderId($requestData->get('reference', null));
        if (null === $transaction) {
            // TODO: erreur
            if (null !== $this->logger) {
                $this->log(sprintf('Transaction not found for orderId #%s.', $requestData->get('reference', null)));
            }
        }
        
        $macData = sprintf('%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*%s*',
            $requestData->get('TPE', ''),
            $requestData->get('date', ''),
            $requestData->get('montant', ''),
            $requestData->get('reference', ''),
            $requestData->get('texte-libre', ''),
            $this->getVersion(),
            $requestData->get('code-retour', ''),
            $requestData->get('cvx', ''),
            $requestData->get('vld', ''),
            $requestData->get('brand', ''),
            $requestData->get('status3ds', ''),
            $requestData->get('numauto', ''),
            $requestData->get('motifrefus', ''),
            $requestData->get('originecb', ''),
            $requestData->get('bincb', ''),
            $requestData->get('hpancb', ''),
            $requestData->get('ipclient', ''),
            $requestData->get('originetr', ''),
            $requestData->get('veres', ''),
            $requestData->get('pares', '')
        );

        $mac = strtoupper($this->hashMac($macData));

        $acknowledgment = "version=2\ncdr=1\n";
        if ($mac == $requestData->get('MAC', '')) {
            $transaction->setSuccess(true);
            $acknowledgment = "version=2\ncdr=0\n";

            switch(strtolower($requestData->get('code-retour', ''))) {
                case 'payetest':
                case 'paiement':
                    $transaction->setState(Transaction::STATE_APPROVED);
                break;

                case 'annulation':
                    switch(strtolower($requestData->get('motifrefus', ''))) {
                        case 'refus':
                            $transaction->setState(Transaction::STATE_REFUSED);
                        break;
                        case 'interdit':
                            $transaction->setState(Transaction::STATE_BANK_BAN);
                        break;
                        case 'filtrage':
                            $transaction->setState(Transaction::STATE_FILTERED);
                        break;

                        default:
                            $transaction->setState(Transaction::STATE_FAILED);
                    }
                break;

                default:
                    $transaction->setState(Transaction::STATE_SERVER_ERROR);
            }
        }
        else {
            $transaction->setSuccess(false);
        }

        $transaction->setExtraData(array('response' => $requestData->all(), 'acknowledgment' => $acknowledgment));
        $this->transactionRepository->save($transaction);

        return new HandlePaymentResponse($transaction, new Response($acknowledgment));
    }

    public function handleBackToShop(Request $request)
    {

    }

    /**
     * {@inheritDoc}
     */
    public function capture(CaptureTransaction $transaction)
    {
        // Initialize session and set URL.
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->getUrl('capture'));

        // Set so curl_exec returns the result instead of outputting it.
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Accept any server(peer) certificate
        //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // Verify certificate
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CAINFO, $this->getCertificatePath());

        $transaction->setBaseTransaction($this->transactionRepository->findAuthorizationByOrderId($transaction->getOrderId()));
        $captureList = $this->transactionRepository->findCapturesBy(array(
            'orderId' => $transaction->getOrderId(),
            'state' => CaptureTransaction::STATE_APPROVED,
        ));

        $captureAmountCumul = 0;
        foreach($captureList as $capture) {
            $captureAmountCumul += $capture->getAmount();
        }

        $remainingAmount = (float) ($transaction->getBaseTransaction()->getAmount() - $captureAmountCumul - $transaction->getAmount());

        // Data
        $data = array(
            'version'              => $this->getVersion(),
            'TPE'                  => $this->getTpe(),
            'date'                 => $this->formatDate($transaction->getCreatedAt()),
            'date_commande'        => $this->formatDate($transaction->getBaseTransaction()->getCreatedAt()),
            'montant'              => $this->formatAmount($transaction->getBaseTransaction()->getAmount(), $transaction->getCurrency()),
            'montant_a_capturer'   => $this->formatAmount($transaction->getAmount(), $transaction->getCurrency()),
            'montant_deja_capture' => $this->formatAmount($captureAmountCumul, $transaction->getCurrency()), // TODO
            'montant_restant'      => $this->formatAmount($remainingAmount, $transaction->getCurrency()),
            'reference'            => $transaction->getOrderId(),
            'text-libre'           => '', // TODO
            'lgue'                 => $this->getLang(),
            'societe'              => $this->getCompanyCode(),
            'MAC'                  => $this->computeCaptureMac($transaction, $captureAmountCumul, $remainingAmount),
        );

        curl_setopt($ch, CURLOPT_POST, count($data));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $this->urlify($data));

        // Get the response and close the channel.
        $response = curl_exec($ch);
        curl_close($ch);

        $responseData = $this->parseCaptureResponse($response);
        if (!is_array($responseData) || !array_key_exists('lib', $responseData)) {
            $transaction->setState(CaptureTransaction::STATE_SERVER_ERROR);
        }
        else {
            switch(strtolower($responseData['lib'])) {
                case 'paiement accepte':
                    $transaction->setState(CaptureTransaction::STATE_APPROVED);
                break;

                case 'commande non authentifiee':
                    $transaction->setState(CaptureTransaction::STATE_UNKNOWN_ORDER);
                break;

                case 'commande expiree':
                    $transaction->setState(CaptureTransaction::STATE_EXPIRED);
                break;

                case 'commande grillee':
                    $transaction->setState(CaptureTransaction::STATE_ATTEMPT_LIMIT_REACHED);
                break;

                case 'autorisation refusee':
                    $transaction->setState(CaptureTransaction::STATE_REFUSED);
                break;

                case 'paiement deja accepte':
                    $transaction->setState(CaptureTransaction::STATE_ALREADY_APPROVED);
                break;

                case 'signature non valide':
                case 'la demande ne peut aboutir':
                case 'montant errone':
                case 'commercant non identifie':
                case 'date erronee':
                    $transaction->setState(CaptureTransaction::STATE_INVALID_FORMAT);
                break;

                case 'traitement en cours':
                    $transaction->setState(CaptureTransaction::STATE_APPROVING);
                break;

                case 'autre traitement en cours':
                    $transaction->setState(CaptureTransaction::STATE_BUSY);
                break;

                case 'probleme technique':
                    $transaction->setState(CaptureTransaction::STATE_SERVER_ERROR);
                break;

                default:
                    $transaction->setState(CaptureTransaction::STATE_SERVER_ERROR);
            }
        }

        $transaction->setExtraData(array(
            'rawResponse' => $response,
            'responseData' => $responseData,
            'sentData' => $data
        ));
        $this->transactionRepository->save($transaction);

        $event = new PaymentCaptureEvent($transaction);
        $this->dispatcher->dispatch(KitanoPaymentEvents::PAYMENT_CAPTURE, $event);
    }

    /**
     * @param string $response
     * @return array
     */
    private function parseCaptureResponse($response)
    {
        $tmp = explode(chr(10), $response);
        $data = array();
        array_walk($tmp, function($item, $key) use (&$data) {
            $keyValue = explode('=', $item);
            if (2 == count($keyValue)) {
                $data[$keyValue[0]] = $keyValue[1];
            }
        });

        return $data;
    }

    /**
     * @return string
     */
    private function convertKey()
    {
        // hash key
        $key = $this->key;

        $hexStrKey = substr($key, 0, 38);
        $hexFinal = "" . substr($key, 38, 2) . "00";

        $cca0 = ord($hexFinal);
        if ($cca0 > 70 && $cca0 < 97) {
            $hexStrKey .= chr($cca0 - 23) . substr($hexFinal, 1, 1);
        }
        else
        {
            if (substr($hexFinal, 1, 1) == "M") {
                $hexStrKey .= substr($hexFinal, 0, 1) . "0";
            }
            else
            {
                $hexStrKey .= substr($hexFinal, 0, 2);
            }
        }

        return pack("H*", $hexStrKey);
    }

    /**
     * @param string $mac
     * @return string
     */
    private function hashMac($mac)
    {
        return strtolower(hash_hmac('sha1', $mac, $this->convertKey()));
    }

    /**
     * Computes authorize mac key
     *
     * @param  AuthorizationTransaction $transaction
     *
     * @return string
     */
    private function computeMac(Transaction $transaction)
    {
        $data = sprintf('%s*%s*%s*%s*%s*%s*%s*%s*%s**********',
            $this->tpe,
            $this->formatDate($transaction->getCreatedAt()),
            $this->formatAmount($transaction->getAmount(), $transaction->getCurrency()),
            $transaction->getOrderId(),
            '',
            $this->getVersion(),
            $this->getLang(),
            $this->getCompanyCode(),
            $this->getEmail()
        );

        return $this->hashMac($data);
    }

    /**
     * Computes capture mac key
     *
     * @param  AuthorizationTransaction $transaction
     *
     * @return string
     */
    private function computeCaptureMac(CaptureTransaction $transaction, $captureAmountCumul, $remainingAmount)
    {
        $data = sprintf('%s*%s*%s%s%s*%s*%s*%s*%s*%s*',
            $this->getTpe(),
            $this->formatDate($transaction->getCreatedAt()),
            $this->formatAmount($transaction->getAmount(), $transaction->getCurrency()),
            $this->formatAmount($captureAmountCumul, $transaction->getCurrency()),
            $this->formatAmount($remainingAmount, $transaction->getCurrency()),
            $transaction->getOrderId(),
            '',
            $this->getVersion(),
            $this->getLang(),
            $this->getCompanyCode()
        );

        return $this->hashMac($data);
    }

    /**
     * @param array $data
     * @return string
     */
    private function urlify(array $data)
    {
        return http_build_query($data);
    }

    /**
     * @param float  $amount
     * @param string $currency
     *
     * @return string
     */
    private function formatAmount($amount, $currency)
    {
        return ((string) $amount) . $currency;
    }

    /**
     * @param \DateTime $date
     * @return string
     */
    private function formatDate(\DateTime $date)
    {
        return $date->format('d/m/Y:H:i:s');
    }

    /**
     * @return string
     */
    public function getUrl($key)
    {
        if ($this->sandbox) {
            return $this->urls['sandbox'][$key];
        }

        return $this->urls['production'][$key];
    }

    /**
     * @param array $urls
     */
    public function setUrls(array $urls)
    {
        $this->urls = $urls;
    }

    /**
     * @param string $companyCode
     */
    public function setCompanyCode($companyCode)
    {
        $this->companyCode = $companyCode;
    }

    /**
     * @return string
     */
    public function getCompanyCode()
    {
        return $this->companyCode;
    }

    /**
     * @param string $key
     */
    public function setKey($key)
    {
        $this->key = $key;
    }

    /**
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @param string $lang
     */
    public function setLang($lang)
    {
        $this->lang = $lang;
    }

    /**
     * @return string
     */
    public function getLang()
    {
        return $this->lang;
    }

    /**
     * @param string $tpe
     */
    public function setTpe($tpe)
    {
        $this->tpe = $tpe;
    }

    /**
     * @return string
     */
    public function getTpe()
    {
        return $this->tpe;
    }

    /**
     * @param string $version
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @param string $email
     */
    public function setEmail($email)
    {
        $this->email = $email;
    }

    /**
     * @return string
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * @param string $certificatePath
     */
    public function setCertificatePath($certificatePath)
    {
        $this->certificatePath = realpath($certificatePath);
    }

    /**
     * @return string
     */
    public function getCertificatePath()
    {
        return $this->certificatePath;
    }

    /**
     * @param boolean $sandbox
     */
    public function setSandbox($sandbox)
    {
        $this->sandbox = (bool) $sandbox;
    }

    /**
     * @return boolean
     */
    public function getSandbox()
    {
        return $this->sandbox;
    }

    /**
     * @param LoggerInterface $logger
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param string $message
     */
    public function log($message)
    {
        if (null !== $this->logger) {
            $this->logger->debug($message);
        }
    }
}