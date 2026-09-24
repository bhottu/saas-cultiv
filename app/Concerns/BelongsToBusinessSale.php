<?php

namespace App\Concerns;

use App\Models\BusinessInvoice;
use App\Models\BusinessPayment;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Business models that represent money attachments (business invoices, sale payments)
 * belong to a business sale. This is the business-sales domain, which is intentionally
 * separate from the SaaS subscription billing Invoice/Payment models.
 */
trait BelongsToBusinessSale
{
    public function businessSale(): MorphTo
    {
        return $this->morphTo();
    }

    public function businessInvoice(): ?BusinessInvoice
    {
        return $this->businessSale?->invoice;
    }

    public function isFullyPaid(): bool
    {
        $sale = $this->businessSale;

        return $sale !== null && method_exists($sale, 'isFullyPaid') && $sale->isFullyPaid();
    }
}