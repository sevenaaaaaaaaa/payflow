<?php

declare(strict_types=1);

namespace PayFlow\Domain;

use PayFlow\Store\Repository;

final class InvoiceRepository extends Repository
{
    protected static function collection(): string
    {
        return 'invoices';
    }

    protected static function idPrefix(): string
    {
        return 'inv_';
    }

    public function forOrder(string $orderId): ?array
    {
        return $this->firstBy('order_id', $orderId);
    }

    public function nextSequence(string $prefix, string $date): int
    {
        $count = 0;
        foreach ($this->all() as $invoice) {
            if (($invoice['number'] ?? '') !== '' && str_contains((string) $invoice['number'], $prefix . '-' . $date)) {
                $count++;
            }
        }

        return $count + 1;
    }

    /**
     * @return list<array>
     */
    public function recent(int $limit = 200): array
    {
        return $this->query([], $limit, 0, 'created_at', 'desc');
    }
}
