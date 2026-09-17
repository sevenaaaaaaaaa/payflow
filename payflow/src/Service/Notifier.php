<?php

declare(strict_types=1);

namespace PayFlow\Service;

use PayFlow\Support\Money;

/**
 * 通知编排：把订单事件转成邮件（收件人 = 客户，可抄送管理员）。
 */
final class Notifier
{
    public function __construct(private readonly Mailer $mailer, private readonly array $config = [])
    {
    }

    public function orderPaid(array $order): void
    {
        $subject = '收款成功 · ' . ($order['product_name'] ?? '订单');
        $html = $this->wrap('收款成功', sprintf(
            '<p>感谢你的支持！订单 <code>%s</code> 已支付成功。</p><p>商品：<strong>%s</strong><br>金额：<strong>%s</strong><br>时间：%s</p><p><a href="%s">查看订单 / 获取内容 →</a></p>',
            pf_e((string) $order['order_no']),
            pf_e((string) ($order['product_name'] ?? '')),
            pf_e(Money::yuan((int) $order['amount_cents'])),
            pf_e((string) ($order['paid_at'] ?? date('c'))),
            pf_e($order['_delivery_url'] ?? ''),
        ));
        $this->dispatch((string) $order['email'], $subject, $html);
    }

    public function orderRefunded(array $order): void
    {
        $subject = '退款已处理 · ' . ($order['product_name'] ?? '订单');
        $html = $this->wrap('退款已处理', sprintf(
            '<p>订单 <code>%s</code> 的退款已发起，金额 %s，将原路退回。</p>',
            pf_e((string) $order['order_no']),
            pf_e(Money::yuan((int) $order['amount_cents'])),
        ));
        $this->dispatch((string) $order['email'], $subject, $html);
    }

    public function delivery(array $order, array $items): void
    {
        $list = '';
        foreach ($items as $item) {
            $list .= sprintf('<li><a href="%s">%s</a></li>', pf_e((string) $item['url']), pf_e((string) ($item['title'] ?? $item['url'])));
        }
        $subject = '你的内容已就绪 · ' . ($order['product_name'] ?? '订单');
        $html = $this->wrap('内容已交付', sprintf(
            '<p>订单 <code>%s</code> 已交付，以下是你的专属内容：</p><ul>%s</ul><p><a href="%s">打开交付页 →</a></p>',
            pf_e((string) $order['order_no']),
            $list,
            pf_e($order['_delivery_url'] ?? ''),
        ));
        $this->dispatch((string) $order['email'], $subject, $html);
    }

    public function subscriptionRenewed(array $order, array $subscription): void
    {
        $subject = '订阅续费成功 · ' . ($order['product_name'] ?? '订阅');
        $html = $this->wrap('订阅续费成功', sprintf(
            '<p>你的订阅已自动续费，订单 <code>%s</code>，金额 %s。</p><p>下次续费日：<strong>%s</strong></p><p><a href="%s">查看交付内容 →</a></p>',
            pf_e((string) $order['order_no']),
            pf_e(Money::yuan((int) $order['amount_cents'])),
            pf_e(date('Y-m-d', strtotime((string) ($subscription['current_period_end'] ?? 'now')))),
            pf_e($order['_delivery_url'] ?? ''),
        ));
        $this->dispatch((string) $order['email'], $subject, $html);
    }

    public function subscriptionExpiring(array $subscription, string $payUrl): void
    {
        $subject = '订阅即将到期提醒 · ' . ($subscription['product_name'] ?? '订阅');
        $html = $this->wrap('订阅即将到期', sprintf(
            '<p>你的「%s」将于 <strong>%s</strong> 到期，金额 %s。</p><p><a href="%s">立即续费 →</a></p>',
            pf_e((string) ($subscription['product_name'] ?? '')),
            pf_e(date('Y-m-d', strtotime((string) ($subscription['current_period_end'] ?? 'now')))),
            pf_e(Money::yuan((int) ($subscription['amount_cents'] ?? 0))),
            pf_e($payUrl),
        ));
        $this->dispatch((string) $subscription['email'], $subject, $html);
    }

    public function subscriptionPastDue(array $subscription, string $payUrl, int $attempt): void
    {
        $subject = '订阅扣费失败 · ' . ($subscription['product_name'] ?? '订阅');
        $html = $this->wrap('订阅扣费失败', sprintf(
            '<p>我们未能为你的「%s」完成续费（第 %d 次尝试）。宽限期内权益保持不变。</p><p><a href="%s">立即完成支付，避免服务中断 →</a></p>',
            pf_e((string) ($subscription['product_name'] ?? '')),
            $attempt,
            pf_e($payUrl),
        ));
        $this->dispatch((string) $subscription['email'], $subject, $html);
    }

    public function subscriptionCanceled(array $subscription): void
    {
        $subject = '订阅已取消 · ' . ($subscription['product_name'] ?? '订阅');
        $html = $this->wrap('订阅已取消', sprintf(
            '<p>由于宽限期内未完成续费，你的「%s」已取消，相关权益已停用。</p><p>如仍需使用，可随时重新订阅。</p>',
            pf_e((string) ($subscription['product_name'] ?? '')),
        ));
        $this->dispatch((string) $subscription['email'], $subject, $html);
    }

    public function payoutUpdated(array $payout, string $status): void
    {
        $subject = '提现状态更新 · ' . $status;
        $html = $this->wrap('提现状态更新', sprintf(
            '<p>你的提现申请（%s，金额 %s）状态：<strong>%s</strong>。</p>',
            pf_e((string) ($payout['id'] ?? '')),
            pf_e(Money::yuan((int) ($payout['amount_cents'] ?? 0))),
            pf_e($status),
        ));
        $this->dispatch((string) $payout['email'], $subject, $html);
    }

    public function commissionEarned(array $commission, array $referrer): void
    {
        $subject = '推荐佣金到账 · ' . Money::yuan((int) ($commission['amount_cents'] ?? 0));
        $html = $this->wrap('推荐佣金到账', sprintf(
            '<p>你推荐的一位用户完成支付，佣金 <strong>%s</strong> 已记入余额（冻结 %d 天后可提现）。</p>',
            pf_e(Money::yuan((int) ($commission['amount_cents'] ?? 0))),
            (int) ($commission['hold_days'] ?? 0),
        ));
        $this->dispatch((string) $referrer['email'], $subject, $html);
    }

    private function dispatch(string $to, string $subject, string $html): void
    {
        $this->mailer->send($to, $subject, $html, strip_tags($html));

        $admin = (string) ($this->config['notify_admin'] ?? '');
        if ($admin !== '') {
            $this->mailer->send($admin, '[PayFlow] ' . $subject, $html, strip_tags($html));
        }
    }

    private function wrap(string $title, string $body): string
    {
        return '<div style="font-family:system-ui,-apple-system,\'PingFang SC\',sans-serif;max-width:560px;margin:0 auto;padding:24px;color:#222">'
            . '<h2 style="margin:0 0 16px">' . pf_e($title) . '</h2>'
            . $body
            . '<hr style="margin:24px 0;border:none;border-top:1px solid #eee">'
            . '<p style="font-size:12px;color:#888">PayFlow · 收款 + 订阅 + 推荐裂变 + 佣金结算</p></div>';
    }
}
