<?php
/**
 * 2018 Alma / Nabla SAS
 *
 * THE MIT LICENSE
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
 * documentation files (the "Software"), to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and
 * to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the
 * Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
 * WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
 * CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @author    Alma / Nabla SAS <contact@getalma.eu>
 * @copyright 2018 Alma / Nabla SAS
 * @license   https://opensource.org/licenses/MIT The MIT License
 *
 */

namespace Alma\MonthlyPayments\Helpers;

use Alma\API\Client;
use Alma\API\RequestError;
use Alma\MonthlyPayments\Gateway\Config\Config;
use Alma\MonthlyPayments\Helpers;
use Alma\MonthlyPayments\Model\Data\Quote as AlmaQuote;
use Magento\Checkout\Model\Session;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Pricing\Helper\Data;

class Eligibility
{
    /**
     * @var Session
     */
    private $checkoutSession;
    /**
     * @var Data
     */
    private $pricingHelper;
    /**
     * @var Client
     */
    private $alma;
    /**
     * @var Logger
     */
    private $logger;

    /** @var bool */
    private $eligible;

    /** @var string */
    private $message;
    /**
     * @var Config
     */
    private $config;
    /**
     * @var AlmaQuote
     */
    private $quoteData;

    public function __construct(
        Session $checkoutSession,
        Data $pricingHelper,
        Helpers\AlmaClient $almaClient,
        Helpers\Logger $logger,
        Config $config,
        AlmaQuote $quoteData
    ) {

        $this->checkoutSession = $checkoutSession;
        $this->pricingHelper = $pricingHelper;
        $this->logger = $logger;

        $this->alma = $almaClient->getDefaultClient();
        $this->config = $config;

        $this->quoteData = $quoteData;
    }

    /**
     * @return bool
     *
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function checkEligibility()
    {
        $eligibilityMessage = $this->config->getEligibilityMessage();
        $nonEligibilityMessage = $this->config->getNonEligibilityMessage();
        $excludedProductsMessage = $this->config->getExcludedProductsMessage();

        if (!$this->alma) {
            $this->eligible = false;
            return false;
        }

        if (!$this->checkItemsTypes()) {
            $this->eligible = false;
            $this->message = $nonEligibilityMessage . '<br>' . $excludedProductsMessage;
            return false;
        }

        $this->message = $eligibilityMessage;
        $cartTotal = Functions::priceToCents((float)$this->checkoutSession->getQuote()->getGrandTotal());

        // Get activated plans and build a list of installments count that should be tested for eligibility
        $plansConfig = $this->config->getPaymentPlansConfig();
        $installmentsCounts = [];
        foreach ($plansConfig as $planConfig) {
            if (
                !$planConfig->isAllowed() ||
                !$planConfig->isEnabled() ||
                $cartTotal < $planConfig->minimumAmount() ||
                $cartTotal > $planConfig->maximumAmount()
            ) {
                continue;
            }

            $installmentsCounts[] = $planConfig->installmentsCount();
        }

        // TODO: pass $installmentsCounts to paymentDataFromQuote (or use Payment Data Builder?)
        // TODO: collect max of max and min of min above for easier comparison below if nothing is eligible
        // TODO: $eligibililty -> $eligibilities => process multiple results as any eligible == eligible, otherwise not

        try {
            $eligibilities = $this->alma->payments->eligibility(
                $this->quoteData->paymentDataFromQuote($this->checkoutSession->getQuote(), $installmentsCounts), true
            );
        } catch (RequestError $e) {
            $this->logger->error("Error checking payment eligibility: {$e->getMessage()}");
            $this->eligible = false;
            $this->message = $nonEligibilityMessage;
            return false;
        }

        $anyEligible = false;
        foreach ($eligibilities as $eligibility) {
            if ($eligibility->isEligible()) {
                $anyEligible = true;
                break;
            }
        }

        if (!$anyEligible) {
            $this->eligible = false;
            $this->message = $nonEligibilityMessage;

            $minAmount = $eligibility->constraints["purchase_amount"]["minimum"];
            $maxAmount = $eligibility->constraints["purchase_amount"]["maximum"];

            if ($cartTotal < $minAmount || $cartTotal > $maxAmount) {
                if ($cartTotal > $maxAmount) {
                    $price = $this->getFormattedPrice(Helpers\Functions::priceFromCents($maxAmount));
                    $this->message .= '<br>' . sprintf(__('(Maximum amount: %s)'), $price);
                } else {
                    $price = $this->getFormattedPrice(Helpers\Functions::priceFromCents($minAmount));
                    $this->message .= '<br>' . sprintf(__('(Minimum amount: %s)'), $price);
                }
            }
        } else {
            $this->eligible = true;
        }

        return $this->eligible;
    }

    /**
     * @return bool
     *
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    private function checkItemsTypes()
    {
        $quote = $this->checkoutSession->getQuote();
        $excludedProductTypes = $this->config->getExcludedProductTypes();

        foreach ($quote->getAllItems() as $item) {
            if (in_array($item->getProductType(), $excludedProductTypes)) {
                return false;
            }
        }

        return true;
    }

    private function getFormattedPrice($price)
    {
        return $this->pricingHelper->currency($price, true, false);
    }

    public function isEligible()
    {
        return $this->eligible;
    }

    public function getMessage()
    {
        return $this->message;
    }
}
