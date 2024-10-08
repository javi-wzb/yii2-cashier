<?php

// namespace awayr\cashier;
namespace awayr\cashier;

use Carbon\Carbon;
use Exception;
use InvalidArgumentException;
use Stripe\Charge;
use Stripe\Customer;
use Stripe\Error\Card;
use Stripe\Error\InvalidRequest;
use Stripe\Invoice as StripeInvoice;
use Stripe\InvoiceItem as StripeInvoiceItem;
use Stripe\Refund as StripeRefund;
use Stripe\Token;
use Yii;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use awayr\cashier\models\SubscriptionModel;

/**
 * Class Billable
 *
 * @package awayr\cashier
 */
trait Billable
{
    /**
     * The Stripe API key.
     *
     * @var string
     */
    protected static $stripeKey;

    /**
     * Make a "one off" charge on the customer for the given amount.
     *
     * @param int $amount
     *
     * @throws Card
     */
    public function charge($amount, array $options = []): Charge
    {
        $options = array_merge([
            'currency' => $this->preferredCurrency(),
        ], $options);

        $options['amount'] = $amount;

        if (!array_key_exists('source', $options) && $this->stripe_id) {
            $options['customer'] = $this->stripe_id;
        }

        if (!array_key_exists('source', $options) && !array_key_exists('customer', $options)) {
            throw new InvalidArgumentException('No payment source provided.');
        }

        return Charge::create($options, ['api_key' => $this->getStripeKey()]);
    }

    /**
     * Refund a customer for a charge.
     *
     * @param $charge
     */
    public function refund($charge, array $options = []): StripeRefund
    {
        $options['charge'] = $charge;

        return StripeRefund::create($options, ['api_key' => $this->getStripeKey()]);
    }

    /**
     * Determines if the customer currently has a card on file.
     */
    public function hasCardOnFile(): bool
    {
        return (bool)$this->card_brand;
    }

    /**
     * Invoice the customer for the given amount.
     *
     * @param string $description
     * @param int $amount
     *
     * @return bool|StripeInvoice
     *
     * @throws Card
     */
    public function invoiceFor($description, $amount, array $options = [])
    {
        if (!$this->stripe_id) {
            throw new InvalidArgumentException('User is not a customer. See the createAsStripeCustomer method.');
        }

        $options = array_merge([
            'customer' => $this->stripe_id,
            'amount' => $amount,
            'currency' => $this->preferredCurrency(),
            'description' => $description,
        ], $options);

        StripeInvoiceItem::create(
            $options,
            ['api_key' => $this->getStripeKey()]
        );

        return $this->invoice();
    }

    /**
     * Begin creating a new subscription.
     */
    public function newSubscription(string $subscription, string $plan): SubscriptionBuilder
    {
        return new SubscriptionBuilder($this, $subscription, $plan);
    }

    /**
     * Determine if the user is on trial.
     */
    public function onTrial(string $subscription = 'default', ?string $plan = null): bool
    {
        if (func_num_args() === 0 && $this->onGenericTrial()) {
            return true;
        }
        $subscription = $this->subscription($subscription);
        if (is_null($plan)) {
            return $subscription && $subscription->onTrial();
        }

        return $subscription && $subscription->onTrial() &&
            $subscription->stripePlan === $plan;
    }

    /**
     * Determine if the user is on a "generic" trial at the user level.
     */
    public function onGenericTrial(): bool
    {
        return $this->trial_ends_at && Carbon::now()->lt(Carbon::createFromFormat('Y-m-d H:i:s', $this->trial_ends_at));
    }

    /**
     * Determine if the user has a given subscription.
     */
    public function subscribed(string $subscription = 'default', ?string $plan = null): bool
    {
        $subscription = $this->subscription($subscription);

        if (is_null($subscription)) {
            return false;
        }

        if (is_null($plan)) {
            return $subscription->valid();
        }

        return $subscription->valid() &&
            $subscription->stripe_plan === $plan;
    }

    /**
     * Get a subscription instance by name.
     */
    public function subscription(string $subscription = 'default'): ?SubscriptionModel
    {
        return $this->getSubscriptions()->where(['name' => $subscription])->one();
    }

    /**
     * @return mixed
     */
    public function getSubscriptions()
    {
        return $this->hasMany(SubscriptionModel::class, ['user_id' => 'id'])->orderBy(['created_at' => SORT_DESC]);
    }

    /**
     * Invoice the billable entity outside of regular billing cycle.
     *
     * @return bool|StripeInvoice
     */
    public function invoice()
    {
        if ($this->stripe_id) {
            try {
                return StripeInvoice::create(['customer' => $this->stripe_id], $this->getStripeKey())->pay();
            } catch (InvalidRequest $e) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the entity's upcoming invoice.
     */
    public function upcomingInvoice(): ?Invoice
    {
        try {
            $stripeInvoice = StripeInvoice::upcoming(
                ['customer' => $this->stripe_id],
                ['api_key' => $this->getStripeKey()]
            );

            return new Invoice($this, $stripeInvoice);
        } catch (InvalidRequest $e) {
        }
    }

    /**
     * Find an invoice by ID.
     *
     * @return Invoice|null
     */
    public function findInvoice(string $id): Invoice
    {
        try {
            return new Invoice($this, StripeInvoice::retrieve($id, $this->getStripeKey()));
        } catch (Exception $e) {
        }
    }

    /**
     * Find an invoice or throw a 404 error.
     *
     * @return Invoice
     *
     * @throws NotFoundHttpException
     */
    public function findInvoiceOrFail(string $id)
    {
        $invoice = $this->findInvoice($id);

        if (is_null($invoice)) {
            throw new NotFoundHttpException('The requested page does not exist.');
        }

        return $invoice;
    }

    /**
     * Create an invoice download Response.
     *
     * @return Response
     */
    public function downloadInvoice(string $id, array $data)
    {
        return $this->findInvoiceOrFail($id)->download($data);
    }

    /**
     * Get a collection of the entity's invoices.
     */
    public function invoices(bool $includePending = false, array $parameters = []): array
    {
        $invoices = [];

        $parameters = array_merge(['limit' => 24], $parameters);
        $stripeInvoices = StripeInvoice::all($parameters, ['api_key' => $this->getStripeKey()]);
        // $stripeInvoices = $this->asStripeCustomer()->invoices($parameters);

        // Here we will loop through the Stripe invoices and create our own custom Invoice
        // instances that have more helper methods and are generally more convenient to
        // work with than the plain Stripe objects are. Then, we'll return the array.
        if (!is_null($stripeInvoices)) {
            foreach ($stripeInvoices->data as $invoice) {
                if ($invoice->paid || $includePending) {
                    $invoices[] = new Invoice($this, $invoice);
                }
            }
        }

        return $invoices;
    }

    /**
     * Get an array of the entity's invoices.
     */
    public function invoicesIncludingPending(array $parameters = []): array
    {
        return $this->invoices(true, $parameters);
    }

    /**
     * Update customer's credit card.
     */
    public function updateCard(string $token): void
    {
        $isNewCard = true;
        $customer = $this->asStripeCustomer();
        $token = Token::retrieve($token, ['api_key' => $this->getStripeKey()]);

        //Checks if the card already exists or only the expiration date has changed and updates it
        foreach (Customer::allSources($customer->id) as $paymentMethod) {
            if ($token->card->fingerprint === $paymentMethod->fingerprint) {
                $isNewCard = false;
                if ($token->card->exp_month != $paymentMethod->exp_month || $paymentMethod->exp_year != $token->card->exp_year) {
                    Customer::updateSource($customer->id, $paymentMethod->id, ['exp_month' => $token->card->exp_month, 'exp_year' => $token->card->exp_year]);
                }
                break;
            }
        }

        if ($isNewCard) {
            $card = Customer::createSource($customer->id, ['source' => $token]);
        } else {
            $card = $paymentMethod;
        }

        $customer->invoice_settings = ['default_payment_method' => $card->id];
        $customer->save();
        $this->updateCardFromStripe();
    }

    /**
     * Synchronises the customer's card from Stripe back into the database.
     *
     * @return $this
     */
    public function updateCardFromStripe()
    {
        $customer = $this->asStripeCustomer();
        $defaultCard = null;
        foreach ($customer->sources->data as $card) {
            if ($card->id === $customer->default_source) {
                $defaultCard = $card;
                break;
            }
        }
        if ($defaultCard) {
            $this->fillCardDetails($defaultCard)->save();
        } else {
            $this->card_brand = null;
            $this->card_last_four = null;
            $this->update(false);
        }

        return $this;
    }

    /**
     * Fills the user's properties with the source from Stripe.
     *
     * @param \Stripe\Card|null $card
     *
     * @return $this
     */
    protected function fillCardDetails($card)
    {
        if ($card) {
            $this->card_brand = $card->brand;
            $this->card_last_four = $card->last4;
        }

        return $this;
    }

    /**
     * Apply a coupon to the billable entity.
     *
     * @param string $coupon
     */
    public function applyCoupon($coupon)
    {
        $customer = $this->asStripeCustomer();

        $customer->coupon = $coupon;

        $customer->save();
    }

    /**
     * Determine if the user is actively subscribed to one of the given plans.
     *
     * @param array|string $plans
     * @param string $subscription
     */
    public function subscribedToPlan($plans, $subscription = 'default'): bool
    {
        $subscription = $this->subscription($subscription);

        if (!$subscription || !$subscription->valid()) {
            return false;
        }

        foreach ((array)$plans as $plan) {
            if ($subscription->stripe_plan === $plan) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the entity is on the given plan.
     *
     * @param string $plan
     */
    public function onPlan($plan): bool
    {
        $plan = $this->getSubscriptions()->where(['stripe_plan' => $plan])->one();

        return !is_null($plan) && $plan->valid();
    }

    /**
     * Determine if the entity has a Stripe customer ID.
     */
    public function hasStripeId(): bool
    {
        return !is_null($this->stripe_id);
    }

    /**
     * Create a Stripe customer for the given user.
     */
    public function createAsStripeCustomer(string $token, array $options = []): Customer
    {
        $options = array_key_exists('email', $options)
            ? $options : array_merge($options, ['email' => $this->email]);

        // Here we will create the customer instance on Stripe and store the ID of the
        // user from Stripe. This ID will correspond with the Stripe user instances
        // and allow us to retrieve users from Stripe later when we need to work.
        $customer = Customer::create($options, $this->getStripeKey());

        $this->stripe_id = $customer->id;

        $this->save();

        // Next we will add the credit card to the user's account on Stripe using this
        // token that was provided to this method. This will allow us to bill users
        // when they subscribe to plans or we need to do one-off charges on them.
        if (!is_null($token)) {
            $this->updateCard($token);
        }

        return $customer;
    }

    /**
     * Get the Stripe customer for the user.
     */
    public function asStripeCustomer(): Customer
    {
        return Customer::retrieve(['id' => $this->stripe_id, 'expand' => ['sources', 'subscriptions']], $this->getStripeKey(), ['expand[]' => 'customer']);
    }

    /**
     * Get the Stripe supported currency used by the entity.
     */
    public function preferredCurrency(): string
    {
        return Cashier::usesCurrency();
    }

    /**
     * Get the tax percentage to apply to the subscription.
     */
    public function taxPercentage(): int
    {
        return 0;
    }

    /**
     * Get the Stripe API key.
     */
    public static function getStripeKey(): string
    {
        return static::$stripeKey ?: Yii::$app->params['stripe']['apiKey'];
    }

    /**
     * Set the Stripe API key.
     *
     * @param string $key
     */
    public static function setStripeKey($key): void
    {
        static::$stripeKey = $key;
    }
}
