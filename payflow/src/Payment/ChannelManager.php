<?php

declare(strict_types=1);

namespace PayFlow\Payment;

use PayFlow\Payment\Channel\AlipayChannel;
use PayFlow\Payment\Channel\CryptoChannel;
use PayFlow\Payment\Channel\ManualChannel;
use PayFlow\Payment\Channel\WechatChannel;
use RuntimeException;

final class ChannelManager
{
    /** @var array<string, ChannelInterface> */
    private array $channels = [];

    public function __construct(private readonly array $config, private readonly string $baseUrl = '')
    {
        $this->register(new ManualChannel($config['manual'] ?? [], $baseUrl));
        $this->register(new AlipayChannel($config['alipay'] ?? [], $baseUrl));
        $this->register(new WechatChannel($config['wechat'] ?? [], $baseUrl));
        $this->register(new CryptoChannel($config['crypto'] ?? [], $baseUrl));
    }

    public function register(ChannelInterface $channel): void
    {
        $this->channels[$channel->id()] = $channel;
    }

    public function get(string $id): ChannelInterface
    {
        if (!isset($this->channels[$id])) {
            throw new RuntimeException("未知支付通道: {$id}");
        }

        return $this->channels[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->channels[$id]);
    }

    /**
     * 收银台可展示的通道列表（已启用的）。
     *
     * @return list<array{id:string,label:string}>
     */
    public function enabled(): array
    {
        $out = [];
        foreach ($this->channels as $channel) {
            if ($channel->isEnabled()) {
                $out[] = ['id' => $channel->id(), 'label' => $channel->label()];
            }
        }

        return $out;
    }

    /** @return array<string, ChannelInterface> */
    public function all(): array
    {
        return $this->channels;
    }
}
