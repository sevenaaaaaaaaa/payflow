<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Domain\VoucherRepository;
use RuntimeException;

/**
 * 兑换券：校验 → 生成 0 元订单并交付 → 核销。
 */
final class VoucherService
{
    public function __construct(
        private readonly VoucherRepository $vouchers,
        private readonly OrderService $orderService,
    ) {
    }

    /**
     * @return array{ok:bool,reason?:string,voucher?:array}
     */
    public function check(string $code): array
    {
        $voucher = $this->vouchers->findByCode($code);
        if ($voucher === null) {
            return ['ok' => false, 'reason' => '兑换码不存在'];
        }
        $result = $this->vouchers->validate($voucher);
        if (($result['ok'] ?? false) !== true) {
            return $result;
        }

        return ['ok' => true, 'voucher' => $voucher];
    }

    public function redeem(string $code, string $email, string $name = ''): array
    {
        $check = $this->check($code);
        if (($check['ok'] ?? false) !== true) {
            throw new RuntimeException((string) ($check['reason'] ?? '兑换失败'));
        }
        $voucher = $check['voucher'];

        $order = $this->orderService->redeemVoucher($voucher, $email, $name);
        $this->vouchers->consume((string) $voucher['id']);

        return $order;
    }
}
