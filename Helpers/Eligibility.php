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
use Alma\MonthlyPayments\Gateway\Config\PaymentPlans\PaymentPlanConfigInterface;
use Alma\MonthlyPayments\Helpers;
use Alma\MonthlyPayments\Model\Data\PaymentPlanEligibility;
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

    /**
     * Eligibility constructor.
     * @param Session $checkoutSession
     * @param Data $pricingHelper
     * @param AlmaClient $almaClient
     * @param Logger $logger
     * @param Config $config
     * @param AlmaQuote $quoteData
     */
    public function __construct(
        Session $checkoutSession,
        Data $pricingHelper,
        Helpers\AlmaClient $almaClient,
        Helpers\Logger $logger,
        Config $config,
        AlmaQuote $quoteData
    )
    {
        $this->checkoutSession = $checkoutSession;
        $this->pricingHelper = $pricingHelper;
        $this->logger = $logger;
        $this->alma = $almaClient->getDefaultClient();
        $this->config = $config;
        $this->quoteData = $quoteData;
    }

    /**
     * @param PaymentPlanConfigInterface[] $plansConfig
     * @param string                       $planKey
     *
     * @return null|PaymentPlanConfigInterface
     */
    private function getPlanConfigFromKey(array $plansConfig, string $planKey): ?PaymentPlanConfigInterface
    {
        foreach ($plansConfig as $planConfig) {
            if ($planConfig->planKey() === $planKey) {
                return $planConfig;
            }
        }

        return null;
    }

    /**
     * @return PaymentPlanEligibility[]
     *
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     * @throws RequestError
     */
    public function getPlansEligibility(): array
    {
        if (!$this->alma || !$this->checkItemsTypes()) {
            return [];
        }

        $cartTotal = Functions::priceToCents((float)$this->checkoutSession->getQuote()->getGrandTotal());

        // Get enabled plans and build a list of installments counts that should be tested for eligibility
        $enabledPlans      = $this->config->getPaymentPlansConfig()->getEnabledPlans();
        $installmentsQuery = [];
        $availablePlans    = [];
        foreach ($enabledPlans as $planKey => $planConfig) {
            if (
                $cartTotal >= $planConfig->minimumAmount() &&
                $cartTotal <= $planConfig->maximumAmount()
            ) {
                // Query eligibility for the plan's installments count & keep track of which plans are queried
                $installmentsQuery[] = [
                    'purchase_amount' => $cartTotal,
                    'installments_count' => $planConfig->installmentsCount(),
                    'deferred_days' => $planConfig->deferredDays(),
                    'deferred_month' => $planConfig->deferredMonths(),
                    'cart_total' => $cartTotal,
                ];
                $availablePlans[] = $planKey;
            }
        }

        if (empty($installmentsQuery)) {
            return [];
        }
        $eligibilities = $this->alma->payments->eligibility(
            $this->quoteData->eligibilityDataFromQuote(
                $this->checkoutSession->getQuote(),
                $installmentsQuery
            ),
            true
        );
        if (!is_array($eligibilities) && $eligibilities instanceof \Alma\API\Endpoints\Results\Eligibility) {
            $eligibilities = [$eligibilities->getPlanKey() => $eligibilities];
        }
        $plansEligibility = [];
        foreach ($availablePlans as $planKey) {
            $planConfig  = $this->getPlanConfigFromKey($enabledPlans, $planKey);
            if (!$planConfig) {
                $this->logger->info('getPlansEligibility: plan config not found in enabledPlans', [$planKey]);
                continue;
            }
            if (!array_key_exists($planConfig->almaPlanKey(), $eligibilities)) {
                $this->logger->info('getPlansEligibility: plan key not found in eligibilities', [$planConfig->almaPlanKey()]);
                continue;
            }
            $eligibility = $eligibilities[$planConfig->almaPlanKey()];
            $plansEligibility[$planConfig->planKey()] = new PaymentPlanEligibility($planConfig, $eligibility);
        }
        // TODO check solutions bellow to update AJAX payment methods on country update:
        // * https://magento.stackexchange.com/questions/160479/magento-2-how-to-refresh-payment-method-on-some-condition
        // * https://magento.stackexchange.com/questions/175806/magento2-how-to-trigger-onchange-event-on-country-region-in-shipping-address
        return array_values($plansEligibility); //TODO:check why there is cache in eligibilities from Alma if we change country in shipping checkout UI

        $this->logger->info("getPlansEligibility: plansEligibility keys before array_map", array_keys($plansEligibility));
        $arrayMap = array_map(function ($planKey) use ($plansEligibility) {
            if (is_string($planKey) && isset($plansEligibility[$planKey])) {
                $this->logger->info("getPlansEligibility: map plan returned", [$planKey]);

                return $plansEligibility[$planKey];
            }
            $this->logger->info("getPlansEligibility: plan skipped because not found into queried eligibilities", [$planKey]);
            return $this->mockUneligiblePlanConfig();
        }, $availablePlans);
        $this->logger->info("getPlansEligibility: return", $arrayMap);

        return $arrayMap;
    }

    /**
     * @return bool
     *
     * @throws InputException
     * @throws LocalizedException
     * @throws NoSuchEntityException
     *
     * TODO : Do not check Eligibility when cart is empty
     */
    public function checkEligibility()
    {
        $eligibilityMessage = $this->config->getEligibilityMessage();
        $nonEligibilityMessage = $this->config->getNonEligibilityMessage();
        $excludedProductsMessage = $this->config->getExcludedProductsMessage();

        if (!$this->checkItemsTypes()) {
            $this->eligible = false;
            $this->message = $nonEligibilityMessage . '<br>' . $excludedProductsMessage;

            return false;
        }

        try {
            $plansEligibility = $this->getPlansEligibility();
            $this->logger->info("checkEligibility", $plansEligibility);
        } catch (\Exception $e) {
            $this->logger->error("Error checking payment eligibility: {$e->getMessage()}");
            $this->eligible = false;
            $this->message = $nonEligibilityMessage;

            return false;
        }

        $this->message = $eligibilityMessage;
        $anyEligible = false;
        $minAmount = PHP_INT_MAX;
        $maxAmount = PHP_INT_MIN;
        foreach ($plansEligibility as $planEligibility) {
            $eligibility = $planEligibility->getEligibility();

            if ($eligibility->isEligible()) {
                $anyEligible = true;

                break;
            }

            $reasons = $eligibility->getReasons();
            if (key_exists('purchase_amount', $reasons) && $reasons['purchase_amount'] == 'invalid_value') {
                $minAmount = min($minAmount, $eligibility->getConstraints()['purchase_amount']['minimum']);
                $maxAmount = max($maxAmount, $eligibility->getConstraints()['purchase_amount']['maximum']);
            } else {
                $minAmount = min($minAmount, $planEligibility->getPlanConfig()->minimumAmount());
                $maxAmount = max($maxAmount, $planEligibility->getPlanConfig()->maximumAmount());
            }
        }

        if (!$anyEligible) {
            $cartTotal = Functions::priceToCents((float)$this->checkoutSession->getQuote()->getGrandTotal());
            $this->eligible = false;
            $this->message = $nonEligibilityMessage;

            if ($cartTotal > $maxAmount) {
                $price = $this->getFormattedPrice(Helpers\Functions::priceFromCents($maxAmount));
                $this->message .= '<br>' . sprintf(__('(Maximum amount: %s)'), $price);
            } elseif ($cartTotal < $minAmount) {
                $price = $this->getFormattedPrice(Helpers\Functions::priceFromCents($minAmount));
                $this->message .= '<br>' . sprintf(__('(Minimum amount: %s)'), $price);
            }
        } else {
            $this->eligible = true;
        }

        return $this->eligible;
    }

    /**
     * @return PaymentPlanEligibility[]
     */
    public function getEligiblePlans(): array
    {
        try {
            return array_filter($this->getPlansEligibility(), function ($planEligibility) {
                $eligibility = $planEligibility->getEligibility();
                $isEligible = $eligibility->isEligible();
                $this->logger->info('getEligiblePlans: Filter on', ['key' => $eligibility->getPlanKey(), 'isEligible' => $isEligible]);

                return $isEligible;
            });
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage(), $e->getTrace());
            return [];
        }
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

    /**
     * @param $price
     * @return float|string
     */
    private function getFormattedPrice($price)
    {
        return $this->pricingHelper->currency($price, true, false);
    }

    /**
     * @return bool
     */
    public function isEligible()
    {
        return $this->eligible;
    }

    /**
     * @return string
     */
    public function getMessage()
    {
        return $this->message;
    }
}
