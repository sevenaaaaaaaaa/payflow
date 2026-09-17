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
        foreach ($this->all() as $invoice) {
            if (($invoice['order_id'] ?? '') === $orderId) {
                return $invoice;
            }
        }

        return null;
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
        $list = array_values($this->all());
        usort($list, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return array_slice($list, 0, $limit);
    }
}
