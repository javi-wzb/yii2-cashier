<?php

namespace awayr\cashier\tests\data;

use awayr\cashier\controllers\WebhookController;

/**
 * Class CashierTestControllerStub
 *
 * @package awayr\cashier\tests\data
 */
class CashierTestControllerStub extends WebhookController
{
    protected function eventExistsOnStripe($id)
    {
        return true;
    }
}
