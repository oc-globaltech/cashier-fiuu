<?php

namespace OcGlobalTech\CashierFiuu;

use OcGlobalTech\CashierFiuu\Concerns\ManagesCustomer;
use OcGlobalTech\CashierFiuu\Concerns\ManagesInvoices;
use OcGlobalTech\CashierFiuu\Concerns\ManagesPaymentMethods;
use OcGlobalTech\CashierFiuu\Concerns\ManagesSubscriptions;
use OcGlobalTech\CashierFiuu\Concerns\ManagesTransactions;
use OcGlobalTech\CashierFiuu\Concerns\PerformsCharges;

trait Billable
{
    use ManagesCustomer;
    use ManagesInvoices;
    use ManagesPaymentMethods;
    use ManagesSubscriptions;
    use ManagesTransactions;
    use PerformsCharges;
}
