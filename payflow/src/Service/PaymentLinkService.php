<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\EventRepository;
use PayFlow\Domain\PaymentLinkRepository;
use PayFlow\Domain\ProductRepository;
use PayFlow\Support\Money;

/**
 * 临时支付链接：解析/校验/消耗。
 */
final class PaymentLinkService
{
    public function __construct(
        private readonly PaymentLinkRepository $links,
        private readonly ProductRepository $products,
        private readonly EventRepository $events,
        private readonly string $baseUrl = '',
    ) {
    }

    public function resolve(string $token): ?array
    {
        return $this->links->findByToken($token);
    }

    /**
     * @return array{ok:bool,reason?:string}
     */
    public function validate(array $link): array
    {
        return $this->links->validate($link);
    }

    public function consume(array $link): void
    {
        $this->links->consume((string) $link['id']);
        $this->events->log('payment_link.used', ['link_id' => $link['id'], 'used' => ((int) $link['used']) + 1]);
    }

    public function urlFor(array $link): string
    {
        return rtrim($this->baseUrl, '/') . '/l/' . $link['token'];
    }

    public function label(array $link): string
    {
        return Money::yuan((int) $link['amount_cents']) . ' · ' . (string) $link['title'];
    }
}
