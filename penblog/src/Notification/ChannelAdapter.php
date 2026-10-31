<?php

declare(strict_types=1);

namespace PenBlog\Notification;

use PenBlog\Auth\User;

/**
 * 05 §6 投递层:每渠道一适配器。rendered 为该渠道渲染产物
 * {title, body, link};适配器自行决定序列化方式。
 */
interface ChannelAdapter
{
    public function name(): string;

    /** @param array{title: string, body: string, link: string} $rendered */
    public function send(array $rendered, User $to): DeliveryResult;
}
