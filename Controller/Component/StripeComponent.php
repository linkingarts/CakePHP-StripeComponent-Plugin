<?php
/**
 * StripeComponent
 *
 * A component that handles payment processing using Stripe.
 *
 * PHP version 5
 *
 * @package		StripeComponent
 * @author		Daniel Martuszewski <daniel.martuszewski@gmail.com> forked from Gregory Gaskill <gregory@chronon.com>
 * @license		MIT License (http://www.opensource.org/licenses/mit-license.php)
 * @link		https://github.com/gcdreak/CakePHP-StripeComponent-Plugin
 */

App::uses('Component', 'Controller');

/**
 * StripeComponent
 *
 * @package		StripeComponent
 */
class StripeComponent extends Component {

    /**
     * Default Stripe mode to use: Test or Live
     *
     * @var string
     * @access public
     */
    public $mode = 'Test';

    /**
     * Default currency to use for the transaction
     *
     * @var string
     * @access public
     */
    public $currency = 'usd';

    /**
     * Default mapping of fields to be returned: local_field => stripe_field
     *
     * @var array
     * @access public
     */
    public $fields = array('stripe_id' => 'id');

    /**
     * The required Stripe secret API key
     *
     * @var string
     * @access public
     */
    public $key = null;

    /**
     * Controller startup. Loads the Stripe API library and sets options from
     * APP/Config/bootstrap.php.
     *
     * @param Controller $controller Instantiating controller
     * @return void
     * @throws CakeException
     */
    public function startup(Controller $controller) {
        $this->Controller = $controller;

        // load the stripe vendor class IF it hasn't been autoloaded (composer)
        App::import('Vendor', 'Stripe.Stripe', array(
                'file' => 'Stripe' . DS . 'lib' . DS . 'Stripe.php')
        );
        if (!class_exists('Stripe')) {
            throw new CakeException('Stripe API Library is missing or could not be loaded.');
        }

        // if mode is set in bootstrap.php, use it. otherwise, Test.
        $mode = Configure::read('Stripe.mode');
        if ($mode) {
            $this->mode = $mode;
        }

        // set the Stripe API key
        $this->key = Configure::read('Stripe.' . $this->mode . 'Secret');
        if (!$this->key) {
            throw new CakeException('Stripe API key is not set.');
        }

        // if currency is set in bootstrap.php, use it. otherwise, usd.
        $currency = Configure::read('Stripe.currency');
        if ($currency) {
            $this->currency = $currency;
        }

        // field map for charge response, or use default (set above)
        $fields = Configure::read('Stripe.fields');
        if ($fields) {
            $this->fields = $fields;
        }
    }

    /**
     * The charge method prepares data for Stripe_Charge::create and attempts a
     * transaction.
     *
     * @param array	$data Must contain 'amount' and 'stripeToken'.
     * @return array $charge if success, string $error if failure.
     * @throws CakeException
     * @throws CakeException
     * @throws Exception
     * @throws Exception
     */
    public function charge($data) {

        // $data MUST contain 'stripeToken' or 'stripeCustomer' (id) to make a charge.
        if (!isset($data['stripeToken']) && !isset($data['stripeCustomer'])) {
            throw new CakeException('The required stripeToken or stripeCustomer fields are missing.');
        }

        // if amount is missing or not numeric, abort.
        if (!isset($data['amount']) || !is_numeric($data['amount'])) {
            throw new CakeException('Amount is required and must be numeric.');
        }

        // set the (optional) description field to null if not set in $data
        if (!isset($data['description'])) {
            $data['description'] = null;
        }

        // format the amount, in cents.
        $data['amount'] = $data['amount'] * 100;

        Stripe::setApiKey($this->key);
        $error = null;

        $chargeData = array(
            'amount' => $data['amount'],
            'currency' => $this->currency,
            'description' => $data['description']
        );

        if (isset($data['stripeToken'])) {
            $chargeData['card'] = $data['stripeToken'];
        } else {
            $chargeData['customer'] = $data['stripeCustomer'];
        }

        try {
            $charge = Stripe_Charge::create($chargeData);

        } catch(Stripe_CardError $e) {
            $body = $e->getJsonBody();
            $err = $body['error'];
            CakeLog::error(
                'Charge::Stripe_CardError: ' . $err['type'] . ': ' . $err['code'] . ': ' . $err['message'],
                'stripe'
            );
            $error = $err['message'];

        } catch (Stripe_InvalidRequestError $e) {
            $body = $e->getJsonBody();
            $err = $body['error'];
            CakeLog::error(
                'Charge::Stripe_InvalidRequestError: ' . $err['type'] . ': ' . $err['message'],
                'stripe'
            );
            $error = $err['message'];

        } catch (Stripe_AuthenticationError $e) {
            CakeLog::error('Charge::Stripe_AuthenticationError: API key rejected!', 'stripe');
            $error = 'Payment processor API key error.';

        } catch (Stripe_ApiConnectionError $e) {
            CakeLog::error('Charge::Stripe_ApiConnectionError: Stripe could not be reached.', 'stripe');
            $error = 'Network communication with payment processor failed, try again later';

        } catch (Stripe_Error $e) {
            CakeLog::error('Charge::Stripe_Error: Stripe could be down.', 'stripe');
            $error = 'Payment processor error, try again later.';

        } catch (Exception $e) {
            CakeLog::error('Charge::Exception: Unknown error.', 'stripe');
            $error = 'There was an error, try again later.';
        }

        if ($error !== null) {
            // an error is always a string
            return (string)$error;
        }

        CakeLog::info('Stripe: charge id ' . $charge->id, 'stripe');

        return $this->_formatChargeResult($charge);
    }

    /**
     * The createCustomer method prepares data for Stripe_Customer::create and attempts to
     * create a customer.
     *
     * @param array	$data Must contain 'stripeToken'.
     * @return array $customer if success, string $error if failure.
     * @throws CakeException
     * @throws Exception
     */
    public function createCustomer($data) {

        // $data MUST contain 'stripeToken' to create customer.
        if (!isset($data['stripeToken'])) {
            throw new CakeException('The required stripeToken field is missing.');
        }

        // set the (optional) description field to null if not set in $data
        if (!isset($data['description'])) {
            $data['description'] = null;
        }

        Stripe::setApiKey($this->key);
        $error = null;

        $stripeData = array(
            'card' => $data['stripeToken'],
            'description' => $data['description']
        );

        if(isset($data['email'])) {
            $stripeData['email'] = $data['email'];
        }
        if(isset($data['plan'])) {
            $stripeData['plan'] = $data['plan'];
        }

        try {
            $customer = Stripe_Customer::create($stripeData);

        } catch (Stripe_InvalidRequestError $e) {
            $body = $e->getJsonBody();
            $err = $body['error'];
            CakeLog::error(
                'Customer::Stripe_InvalidRequestError: ' . $err['type'] . ': ' . $err['message'],
                'stripe'
            );
            $error = $err['message'];

        } catch (Stripe_AuthenticationError $e) {
            CakeLog::error('Customer::Stripe_AuthenticationError: API key rejected!', 'stripe');
            $error = 'Payment processor API key error.';

        } catch (Stripe_ApiConnectionError $e) {
            CakeLog::error('Customer::Stripe_ApiConnectionError: Stripe could not be reached.', 'stripe');
            $error = 'Network communication with payment processor failed, try again later';

        } catch (Stripe_Error $e) {
            CakeLog::error('Customer::Stripe_Error: Stripe could be down.', 'stripe');
            $error = 'Payment processor error, try again later.';

        } catch (Exception $e) {
            CakeLog::error('Customer::Exception: Unknown error.', 'stripe');
            $error = 'There was an error, try again later.';
        }

        if ($error !== null) {
            // an error is always a string
            return (string)$error;
        }

        CakeLog::info('Customer: customer id ' . $customer->id, 'stripe');

        return array('customer' => $customer->id);
    }

    /**
     * Subscribes a customer to a plan, meaning the customer will be billed monthly
     * starting from signup. If the customer already has an active subscription,
     * we'll update it to the new plan and optionally prorate the price we charge next
     * month to make up for any price changes.
     *
     * @param string $customerId Stripe customer id
     * @param array	 $subscription Must contain 'plan'.
     * @return array $subscription if success, string $error if failure.
     * @throws CakeException
     * @throws Exception
     */
    public function updateSubscription($customerId, $subscription) {

        Stripe::setApiKey($this->key);
        $error = null;

        try {
            $customer = Stripe_Customer::retrieve($customerId);
            $res = $customer->updateSubscription($subscription);

        } catch (Stripe_InvalidRequestError $e) {
            $body = $e->getJsonBody();
            $err = $body['error'];
            CakeLog::error(
                'Customer::Stripe_InvalidRequestError: ' . $err['type'] . ': ' . $err['message'],
                'stripe'
            );
            $error = $err['message'];

        } catch (Stripe_AuthenticationError $e) {
            CakeLog::error('Customer::Stripe_AuthenticationError: API key rejected!', 'stripe');
            $error = 'Payment processor API key error.';

        } catch (Stripe_ApiConnectionError $e) {
            CakeLog::error('Customer::Stripe_ApiConnectionError: Stripe could not be reached.', 'stripe');
            $error = 'Network communication with payment processor failed, try again later';

        } catch (Stripe_Error $e) {
            CakeLog::error('Customer::Stripe_Error: Stripe could be down.', 'stripe');
            $error = 'Payment processor error, try again later.';

        } catch (Exception $e) {
            CakeLog::error('Customer::Exception: Unknown error.', 'stripe');
            $error = 'There was an error, try again later.';
        }

        if ($error !== null) {
            // an error is always a string
            return (string)$error;
        }

        return $res;
    }


    /**
     * Cancels the subscription if it exists.
     *
     * @param string $customerId Stripe customer id
     * @return array $subscription if success, string $error if failure.
     * @throws CakeException
     * @throws Exception
     */
    public function cancelSubscription($customerId) {

        Stripe::setApiKey($this->key);
        $error = null;

        try {
            $customer = Stripe_Customer::retrieve($customerId);
            $res = $customer->cancelSubscription();

        } catch (Stripe_InvalidRequestError $e) {
            $body = $e->getJsonBody();
            $err = $body['error'];
            CakeLog::error(
                'Customer::Stripe_InvalidRequestError: ' . $err['type'] . ': ' . $err['message'],
                'stripe'
            );
            $error = $err['message'];

        } catch (Stripe_AuthenticationError $e) {
            CakeLog::error('Customer::Stripe_AuthenticationError: API key rejected!', 'stripe');
            $error = 'Payment processor API key error.';

        } catch (Stripe_ApiConnectionError $e) {
            CakeLog::error('Customer::Stripe_ApiConnectionError: Stripe could not be reached.', 'stripe');
            $error = 'Network communication with payment processor failed, try again later';

        } catch (Stripe_Error $e) {
            CakeLog::error('Customer::Stripe_Error: Stripe could be down.', 'stripe');
            $error = 'Payment processor error, try again later.';

        } catch (Exception $e) {
            CakeLog::error('Customer::Exception: Unknown error.', 'stripe');
            $error = 'There was an error, try again later.';
        }

        if ($error !== null) {
            // an error is always a string
            return (string)$error;
        }

        return $res;
    }

    /**
     * The retrieveCustomer method prepares data for Stripe_Customer::retrieve and attempts to
     * retrieve a customer.
     *
     * @param string $customerId.
     * @return array $customer if success, string $error if failure.
     * @throws CakeException
     * @throws Exception
     */
    public function retrieveCustomer($customerId) {

        // set the (optional) description field to null if not set in $data
        if (!isset($data['description'])) {
            $data['description'] = null;
        }

        Stripe::setApiKey($this->key);
        $error = null;

        try {
            $customer = Stripe_Customer::retrieve($customerId);

        } catch (Stripe_InvalidRequestError $e) {
            $body = $e->getJsonBody();
            $err = $body['error'];
            CakeLog::error(
                'Customer::Stripe_InvalidRequestError: ' . $err['type'] . ': ' . $err['message'],
                'stripe'
            );
            $error = $err['message'];

        } catch (Stripe_AuthenticationError $e) {
            CakeLog::error('Customer::Stripe_AuthenticationError: API key rejected!', 'stripe');
            $error = 'Payment processor API key error.';

        } catch (Stripe_ApiConnectionError $e) {
            CakeLog::error('Customer::Stripe_ApiConnectionError: Stripe could not be reached.', 'stripe');
            $error = 'Network communication with payment processor failed, try again later';

        } catch (Stripe_Error $e) {
            CakeLog::error('Customer::Stripe_Error: Stripe could be down.', 'stripe');
            $error = 'Payment processor error, try again later.';

        } catch (Exception $e) {
            CakeLog::error('Customer::Exception: Unknown error.', 'stripe');
            $error = 'There was an error, try again later.';
        }

        if ($error !== null) {
            // an error is always a string
            return (string)$error;
        }

        CakeLog::info('Customer: customer id ' . $customer->id, 'stripe');

        return $customer;
    }

    /**
     * Returns an array of fields we want from Stripe's charge object
     *
     *
     * @param object $charge A successful charge object.
     * @return array The desired fields from the charge object as an array.
     */
    protected function _formatChargeResult($charge) {
        $result = array();
        foreach ($this->fields as $local => $stripe) {
            if (is_array($stripe)) {
                foreach ($stripe as $obj => $field) {
                    $result[$local] = $charge->$obj->$field;
                }
            } else {
                $result[$local] = $charge->$stripe;
            }
        }
        return $result;
    }

}